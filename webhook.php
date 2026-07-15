<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$expectedSecret = requireEnv('TELEGRAM_WEBHOOK_SECRET');
$receivedSecret = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
if ($receivedSecret === '' || !hash_equals($expectedSecret, $receivedSecret)) {
    appLog('warning', 'Webhook rejected: invalid secret');
    jsonResponse(['ok' => false, 'error' => 'Unauthorized'], 401);
}

try {
    $raw = file_get_contents('php://input');
    $update = json_decode($raw ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    $post = $update['channel_post'] ?? $update['edited_channel_post'] ?? null;

    if (!is_array($post)) {
        jsonResponse(['ok' => true, 'ignored' => true]);
    }

    $chat = $post['chat'] ?? [];
    $channelId = (int) ($chat['id'] ?? 0);
    $channelUsername = ltrim((string) ($chat['username'] ?? ''), '@');
    $expectedUsername = ltrim((string) env('TELEGRAM_CHANNEL_USERNAME', 'razdvatakt'), '@');
    $expectedChannelId = trim((string) env('TELEGRAM_CHANNEL_ID', ''));

    if ($channelId === 0 || $channelUsername === '' || strcasecmp($channelUsername, $expectedUsername) !== 0) {
        appLog('warning', 'Webhook ignored: unexpected channel', [
            'channel_id' => $channelId,
            'username' => $channelUsername,
        ]);
        jsonResponse(['ok' => true, 'ignored' => true]);
    }

    if ($expectedChannelId !== '' && (string) $channelId !== $expectedChannelId) {
        appLog('warning', 'Webhook ignored: channel ID mismatch', ['channel_id' => $channelId]);
        jsonResponse(['ok' => true, 'ignored' => true]);
    }

    $messageId = (int) ($post['message_id'] ?? 0);
    if ($messageId <= 0) {
        throw new RuntimeException('Telegram message_id отсутствует');
    }

    $text = trim((string) ($post['text'] ?? $post['caption'] ?? ''));
    $mediaGroupId = isset($post['media_group_id']) ? (string) $post['media_group_id'] : null;
    $publishedAt = date('Y-m-d H:i:s', (int) ($post['date'] ?? time()));
    $title = titleFromText($text);
    $postUrl = 'https://t.me/' . $channelUsername . '/' . $messageId;
    $mediaItems = extractMediaItems($post);

    $pdo = db();
    $pdo->beginTransaction();

    if ($mediaGroupId !== null) {
        $find = $pdo->prepare('SELECT * FROM news WHERE telegram_channel_id = :channel_id AND media_group_id = :media_group_id FOR UPDATE');
        $find->execute(['channel_id' => $channelId, 'media_group_id' => $mediaGroupId]);
    } else {
        $find = $pdo->prepare('SELECT * FROM news WHERE telegram_channel_id = :channel_id AND telegram_message_id = :message_id FOR UPDATE');
        $find->execute(['channel_id' => $channelId, 'message_id' => $messageId]);
    }

    $existing = $find->fetch();
    if ($existing) {
        $anchorMessageId = min((int) $existing['telegram_message_id'], $messageId);
        $effectiveText = $text !== '' ? $text : (string) ($existing['body'] ?? '');
        $effectiveTitle = $text !== '' ? $title : (string) $existing['title'];
        $effectiveStatus = in_array($existing['status'], ['hidden', 'deleted'], true) ? $existing['status'] : 'processing';

        $updateNews = $pdo->prepare(
            'UPDATE news SET telegram_message_id = :message_id, telegram_post_url = :post_url, title = :title, body = :body, published_at = LEAST(published_at, :published_at), status = :status WHERE id = :id'
        );
        $updateNews->execute([
            'message_id' => $anchorMessageId,
            'post_url' => 'https://t.me/' . $channelUsername . '/' . $anchorMessageId,
            'title' => $effectiveTitle,
            'body' => $effectiveText,
            'published_at' => $publishedAt,
            'status' => $effectiveStatus,
            'id' => $existing['id'],
        ]);
        $newsId = (int) $existing['id'];
    } else {
        $insertNews = $pdo->prepare(
            'INSERT INTO news (telegram_channel_id, telegram_message_id, telegram_post_url, media_group_id, title, body, published_at, status) VALUES (:channel_id, :message_id, :post_url, :media_group_id, :title, :body, :published_at, :status)'
        );
        $insertNews->execute([
            'channel_id' => $channelId,
            'message_id' => $messageId,
            'post_url' => $postUrl,
            'media_group_id' => $mediaGroupId,
            'title' => $title,
            'body' => $text,
            'published_at' => $publishedAt,
            'status' => 'processing',
        ]);
        $newsId = (int) $pdo->lastInsertId();
    }

    $pdo->commit();

    $downloadErrors = [];
    foreach ($mediaItems as $index => $media) {
        try {
            saveMedia($newsId, $media, $publishedAt, $index);
        } catch (Throwable $mediaError) {
            $downloadErrors[] = $mediaError->getMessage();
            appLog('error', 'Media download failed', [
                'news_id' => $newsId,
                'file_unique_id' => $media['file_unique_id'] ?? null,
                'error' => $mediaError->getMessage(),
            ]);
        }
    }

    $statusCheck = $pdo->prepare("SELECT COUNT(*) FROM news_media WHERE news_id = :news_id AND status = 'ready'");
    $statusCheck->execute(['news_id' => $newsId]);
    $readyMediaCount = (int) $statusCheck->fetchColumn();

    $finalStatus = ($mediaItems === [] || $readyMediaCount > 0) ? 'published' : 'error';
    $publish = $pdo->prepare("UPDATE news SET status = CASE WHEN status IN ('hidden','deleted') THEN status ELSE :status END WHERE id = :id");
    $publish->execute(['status' => $finalStatus, 'id' => $newsId]);

    appLog('info', 'Telegram post processed', [
        'update_id' => $update['update_id'] ?? null,
        'news_id' => $newsId,
        'message_id' => $messageId,
        'media_count' => count($mediaItems),
        'errors' => $downloadErrors,
    ]);

    jsonResponse([
        'ok' => true,
        'news_id' => $newsId,
        'channel_id' => $channelId,
        'message_id' => $messageId,
        'media_saved' => $readyMediaCount,
    ]);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    appLog('error', 'Webhook processing failed', ['error' => $error->getMessage()]);
    jsonResponse(['ok' => false, 'error' => 'Webhook processing failed'], 500);
}

function extractMediaItems(array $post): array
{
    if (!empty($post['photo']) && is_array($post['photo'])) {
        $photo = end($post['photo']);
        return [[
            '_type' => 'image',
            'file_id' => (string) ($photo['file_id'] ?? ''),
            'file_unique_id' => (string) ($photo['file_unique_id'] ?? ''),
            'file_size' => isset($photo['file_size']) ? (int) $photo['file_size'] : null,
            'mime_type' => 'image/jpeg',
            'file_name' => null,
        ]];
    }

    foreach (['video', 'animation', 'document'] as $type) {
        if (!empty($post[$type]) && is_array($post[$type])) {
            $item = $post[$type];
            return [[
                '_type' => $type === 'video' ? 'video' : $type,
                'file_id' => (string) ($item['file_id'] ?? ''),
                'file_unique_id' => (string) ($item['file_unique_id'] ?? ''),
                'file_size' => isset($item['file_size']) ? (int) $item['file_size'] : null,
                'mime_type' => (string) ($item['mime_type'] ?? ''),
                'file_name' => isset($item['file_name']) ? (string) $item['file_name'] : null,
            ]];
        }
    }

    return [];
}

function saveMedia(int $newsId, array $media, string $publishedAt, int $sortOrder): void
{
    $fileId = (string) ($media['file_id'] ?? '');
    $fileUniqueId = (string) ($media['file_unique_id'] ?? '');
    if ($fileId === '' || $fileUniqueId === '') {
        throw new RuntimeException('Telegram media file_id отсутствует');
    }

    $pdo = db();
    $existing = $pdo->prepare('SELECT id, status FROM news_media WHERE news_id = :news_id AND telegram_file_unique_id = :file_unique_id');
    $existing->execute(['news_id' => $newsId, 'file_unique_id' => $fileUniqueId]);
    $row = $existing->fetch();
    if ($row && $row['status'] === 'ready') {
        return;
    }

    $extension = extensionForMedia($media);
    $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $fileUniqueId) ?: hash('sha256', $fileUniqueId);
    $relativePath = date('Y/m', strtotime($publishedAt)) . '/' . $safeId . '.' . $extension;
    $storageRoot = rtrim(requireEnv('MEDIA_STORAGE_PATH'), '/\\');
    $destination = $storageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

    if (!is_file($destination) || filesize($destination) === 0) {
        downloadTelegramFile($fileId, $destination);
    }

    $publicUrl = publicMediaUrl($relativePath);
    $mediaType = in_array($media['_type'], ['image', 'video', 'animation', 'document'], true) ? $media['_type'] : 'document';

    if ($row) {
        $statement = $pdo->prepare(
            "UPDATE news_media SET telegram_file_id = :file_id, media_type = :media_type, sort_order = :sort_order, original_filename = :filename, mime_type = :mime_type, file_size = :file_size, storage_path = :storage_path, public_url = :public_url, status = 'ready' WHERE id = :id"
        );
        $statement->execute([
            'file_id' => $fileId,
            'media_type' => $mediaType,
            'sort_order' => $sortOrder,
            'filename' => $media['file_name'],
            'mime_type' => $media['mime_type'],
            'file_size' => $media['file_size'] ?? filesize($destination),
            'storage_path' => $destination,
            'public_url' => $publicUrl,
            'id' => $row['id'],
        ]);
    } else {
        $statement = $pdo->prepare(
            "INSERT INTO news_media (news_id, telegram_file_id, telegram_file_unique_id, media_type, sort_order, original_filename, mime_type, file_size, storage_path, public_url, status) VALUES (:news_id, :file_id, :file_unique_id, :media_type, :sort_order, :filename, :mime_type, :file_size, :storage_path, :public_url, 'ready')"
        );
        $statement->execute([
            'news_id' => $newsId,
            'file_id' => $fileId,
            'file_unique_id' => $fileUniqueId,
            'media_type' => $mediaType,
            'sort_order' => $sortOrder,
            'filename' => $media['file_name'],
            'mime_type' => $media['mime_type'],
            'file_size' => $media['file_size'] ?? filesize($destination),
            'storage_path' => $destination,
            'public_url' => $publicUrl,
        ]);
    }
}
