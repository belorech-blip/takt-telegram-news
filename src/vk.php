<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function vkApi(string $method, array $params = []): array
{
    $params['access_token'] = requireEnv('VK_ACCESS_TOKEN');
    $params['v'] = env('VK_API_VERSION', '5.199');

    $url = 'https://api.vk.com/method/' . rawurlencode($method) . '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'TAKT-News-Sync/1.0',
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false || $error !== '') {
        throw new RuntimeException('Ошибка соединения с VK API: ' . $error);
    }

    $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if ($status >= 400 || isset($data['error'])) {
        $message = (string) ($data['error']['error_msg'] ?? "HTTP {$status}");
        throw new RuntimeException('VK API вернул ошибку: ' . $message);
    }

    return is_array($data['response'] ?? null) ? $data['response'] : [];
}

function ensureVkSchema(): void
{
    $pdo = db();
    $database = requireEnv('DB_NAME');

    $columnExists = static function (string $table, string $column) use ($pdo, $database): bool {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table AND COLUMN_NAME = :column'
        );
        $statement->execute(['schema' => $database, 'table' => $table, 'column' => $column]);
        return (int) $statement->fetchColumn() > 0;
    };

    $indexExists = static function (string $table, string $index) use ($pdo, $database): bool {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table AND INDEX_NAME = :index'
        );
        $statement->execute(['schema' => $database, 'table' => $table, 'index' => $index]);
        return (int) $statement->fetchColumn() > 0;
    };

    foreach ([
        'source' => "ALTER TABLE news ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'telegram' AFTER id",
        'source_owner_id' => 'ALTER TABLE news ADD COLUMN source_owner_id BIGINT NULL AFTER source',
        'source_post_id' => 'ALTER TABLE news ADD COLUMN source_post_id BIGINT NULL AFTER source_owner_id',
        'source_url' => 'ALTER TABLE news ADD COLUMN source_url VARCHAR(500) NULL AFTER source_post_id',
    ] as $column => $sql) {
        if (!$columnExists('news', $column)) {
            $pdo->exec($sql);
        }
    }

    $nullableCheck = $pdo->prepare(
        "SELECT COLUMN_NAME, IS_NULLABLE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = 'news'
           AND COLUMN_NAME IN ('telegram_channel_id','telegram_message_id','telegram_post_url')"
    );
    $nullableCheck->execute(['schema' => $database]);
    $nullable = [];
    foreach ($nullableCheck->fetchAll() as $row) {
        $nullable[(string) $row['COLUMN_NAME']] = (string) $row['IS_NULLABLE'];
    }
    if (($nullable['telegram_channel_id'] ?? 'NO') !== 'YES' || ($nullable['telegram_message_id'] ?? 'NO') !== 'YES' || ($nullable['telegram_post_url'] ?? 'NO') !== 'YES') {
        $pdo->exec(
            'ALTER TABLE news
             MODIFY telegram_channel_id BIGINT NULL,
             MODIFY telegram_message_id BIGINT NULL,
             MODIFY telegram_post_url VARCHAR(500) NULL'
        );
    }

    if (!$indexExists('news', 'uq_news_source_post')) {
        $pdo->exec('ALTER TABLE news ADD UNIQUE KEY uq_news_source_post (source, source_owner_id, source_post_id)');
    }

    foreach ([
        'source_media_id' => 'ALTER TABLE news_media ADD COLUMN source_media_id VARCHAR(255) NULL AFTER news_id',
        'source_remote_url' => 'ALTER TABLE news_media ADD COLUMN source_remote_url VARCHAR(1500) NULL AFTER source_media_id',
    ] as $column => $sql) {
        if (!$columnExists('news_media', $column)) {
            $pdo->exec($sql);
        }
    }

    if (!$indexExists('news_media', 'uq_news_media_source')) {
        $pdo->exec('ALTER TABLE news_media ADD UNIQUE KEY uq_news_media_source (news_id, source_media_id)');
    }
}

function syncVkPosts(int $limit = 10): array
{
    ensureVkSchema();

    $limit = max(1, min($limit, 100));
    $domain = ltrim(trim((string) env('VK_GROUP_DOMAIN', 'razdvatakt')), '@');
    if ($domain === '') {
        throw new RuntimeException('Не заполнен VK_GROUP_DOMAIN');
    }

    $response = vkApi('wall.get', [
        'domain' => $domain,
        'count' => max(20, $limit),
        'filter' => 'owner',
        'extended' => 0,
    ]);

    $items = is_array($response['items'] ?? null) ? $response['items'] : [];
    usort($items, static fn(array $a, array $b): int => ((int) ($b['date'] ?? 0)) <=> ((int) ($a['date'] ?? 0)));
    $items = array_slice($items, 0, $limit);

    $summary = ['requested' => $limit, 'received' => count($items), 'published' => 0, 'errors' => []];
    foreach ($items as $post) {
        try {
            syncVkPost($post, $domain);
            $summary['published']++;
        } catch (Throwable $error) {
            $summary['errors'][] = [
                'post_id' => $post['id'] ?? null,
                'error' => $error->getMessage(),
            ];
            appLog('error', 'VK post sync failed', [
                'post_id' => $post['id'] ?? null,
                'error' => $error->getMessage(),
            ]);
        }
    }

    appLog('info', 'VK synchronization complete', $summary);
    return $summary;
}

function syncVkPost(array $post, string $domain): int
{
    $ownerId = (int) ($post['owner_id'] ?? 0);
    $postId = (int) ($post['id'] ?? 0);
    if ($ownerId === 0 || $postId <= 0) {
        throw new RuntimeException('VK post owner_id/id отсутствует');
    }

    $text = trim((string) ($post['text'] ?? ''));
    if ($text === '' && !empty($post['copy_history'][0]['text'])) {
        $text = trim((string) $post['copy_history'][0]['text']);
    }

    $attachments = is_array($post['attachments'] ?? null) ? $post['attachments'] : [];
    if ($attachments === [] && !empty($post['copy_history'][0]['attachments']) && is_array($post['copy_history'][0]['attachments'])) {
        $attachments = $post['copy_history'][0]['attachments'];
    }

    $publishedAt = date('Y-m-d H:i:s', (int) ($post['date'] ?? time()));
    $title = titleFromText($text);
    $sourceUrl = "https://vk.com/wall{$ownerId}_{$postId}";

    $pdo = db();
    $find = $pdo->prepare("SELECT id, status FROM news WHERE source = 'vk' AND source_owner_id = :owner_id AND source_post_id = :post_id LIMIT 1");
    $find->execute(['owner_id' => $ownerId, 'post_id' => $postId]);
    $existing = $find->fetch();

    if ($existing) {
        $statement = $pdo->prepare(
            "UPDATE news SET source_url = :source_url, title = :title, body = :body, published_at = :published_at,
             status = CASE WHEN status IN ('hidden','deleted') THEN status ELSE 'processing' END WHERE id = :id"
        );
        $statement->execute([
            'source_url' => $sourceUrl,
            'title' => $title,
            'body' => $text,
            'published_at' => $publishedAt,
            'id' => $existing['id'],
        ]);
        $newsId = (int) $existing['id'];
    } else {
        $statement = $pdo->prepare(
            "INSERT INTO news (source, source_owner_id, source_post_id, source_url, title, body, published_at, status)
             VALUES ('vk', :owner_id, :post_id, :source_url, :title, :body, :published_at, 'processing')"
        );
        $statement->execute([
            'owner_id' => $ownerId,
            'post_id' => $postId,
            'source_url' => $sourceUrl,
            'title' => $title,
            'body' => $text,
            'published_at' => $publishedAt,
        ]);
        $newsId = (int) $pdo->lastInsertId();
    }

    $mediaItems = vkExtractMediaItems($attachments);
    $errors = [];
    foreach ($mediaItems as $index => $media) {
        try {
            saveVkMedia($newsId, $media, $publishedAt, $index);
        } catch (Throwable $error) {
            $errors[] = $error->getMessage();
            appLog('error', 'VK media download failed', [
                'news_id' => $newsId,
                'source_media_id' => $media['source_media_id'] ?? null,
                'error' => $error->getMessage(),
            ]);
        }
    }

    $check = $pdo->prepare("SELECT COUNT(*) FROM news_media WHERE news_id = :news_id AND status = 'ready'");
    $check->execute(['news_id' => $newsId]);
    $readyCount = (int) $check->fetchColumn();
    $finalStatus = ($mediaItems === [] || $readyCount > 0) ? 'published' : 'error';

    $publish = $pdo->prepare("UPDATE news SET status = CASE WHEN status IN ('hidden','deleted') THEN status ELSE :status END WHERE id = :id");
    $publish->execute(['status' => $finalStatus, 'id' => $newsId]);

    appLog('info', 'VK post processed', [
        'news_id' => $newsId,
        'post_id' => $postId,
        'media_count' => count($mediaItems),
        'media_ready' => $readyCount,
        'errors' => $errors,
    ]);

    return $newsId;
}

function vkExtractMediaItems(array $attachments): array
{
    $result = [];

    foreach ($attachments as $attachment) {
        $type = (string) ($attachment['type'] ?? '');
        $object = is_array($attachment[$type] ?? null) ? $attachment[$type] : [];

        if ($type === 'photo') {
            $size = vkLargestImage($object['sizes'] ?? []);
            if ($size === null || empty($size['url'])) {
                continue;
            }
            $result[] = [
                'source_media_id' => 'photo_' . ($object['owner_id'] ?? 0) . '_' . ($object['id'] ?? 0),
                '_type' => 'image',
                'url' => (string) $size['url'],
                'preview_url' => null,
                'mime_type' => 'image/jpeg',
                'extension' => 'jpg',
            ];
            continue;
        }

        if ($type === 'video' || $type === 'clip') {
            $ownerId = (int) ($object['owner_id'] ?? 0);
            $videoId = (int) ($object['id'] ?? 0);
            if ($ownerId === 0 || $videoId <= 0) {
                continue;
            }

            $accessKey = trim((string) ($object['access_key'] ?? ''));
            $videoRef = $ownerId . '_' . $videoId . ($accessKey !== '' ? '_' . $accessKey : '');
            $videoResponse = vkApi('video.get', ['videos' => $videoRef, 'count' => 1]);
            $video = is_array($videoResponse['items'][0] ?? null) ? $videoResponse['items'][0] : $object;
            $videoUrl = vkBestVideoUrl($video['files'] ?? []);
            $preview = vkLargestImage($video['image'] ?? $video['first_frame'] ?? $object['image'] ?? []);

            if ($videoUrl === null) {
                if ($preview !== null && !empty($preview['url'])) {
                    $result[] = [
                        'source_media_id' => 'video_preview_' . $ownerId . '_' . $videoId,
                        '_type' => 'image',
                        'url' => (string) $preview['url'],
                        'preview_url' => null,
                        'mime_type' => 'image/jpeg',
                        'extension' => 'jpg',
                    ];
                }
                appLog('warning', 'VK video has no downloadable MP4, preview used', ['video' => $videoRef]);
                continue;
            }

            $result[] = [
                'source_media_id' => 'video_' . $ownerId . '_' . $videoId,
                '_type' => 'video',
                'url' => $videoUrl,
                'preview_url' => $preview !== null ? (string) ($preview['url'] ?? '') : null,
                'mime_type' => 'video/mp4',
                'extension' => 'mp4',
            ];
            continue;
        }

        if ($type === 'doc' && !empty($object['url'])) {
            $extension = strtolower((string) ($object['ext'] ?? 'bin'));
            $isImage = in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
            $isVideo = in_array($extension, ['mp4', 'mov', 'webm'], true);
            if (!$isImage && !$isVideo) {
                continue;
            }
            $result[] = [
                'source_media_id' => 'doc_' . ($object['owner_id'] ?? 0) . '_' . ($object['id'] ?? 0),
                '_type' => $isVideo ? 'video' : 'image',
                'url' => (string) $object['url'],
                'preview_url' => null,
                'mime_type' => $isVideo ? 'video/mp4' : 'image/' . ($extension === 'jpg' ? 'jpeg' : $extension),
                'extension' => $extension === 'jpeg' ? 'jpg' : $extension,
            ];
        }
    }

    return $result;
}

function vkLargestImage(mixed $sizes): ?array
{
    if (!is_array($sizes) || $sizes === []) {
        return null;
    }

    $valid = array_values(array_filter($sizes, static fn($item): bool => is_array($item) && !empty($item['url'])));
    if ($valid === []) {
        return null;
    }

    usort($valid, static function (array $a, array $b): int {
        $areaA = (int) ($a['width'] ?? 0) * (int) ($a['height'] ?? 0);
        $areaB = (int) ($b['width'] ?? 0) * (int) ($b['height'] ?? 0);
        return $areaB <=> $areaA;
    });

    return $valid[0];
}

function vkBestVideoUrl(mixed $files): ?string
{
    if (!is_array($files)) {
        return null;
    }

    $variants = [];
    foreach ($files as $key => $url) {
        if (is_string($url) && preg_match('/^mp4_(\d+)$/', (string) $key, $match)) {
            $variants[(int) $match[1]] = $url;
        }
    }

    if ($variants === []) {
        return null;
    }

    krsort($variants, SORT_NUMERIC);
    return (string) reset($variants);
}

function saveVkMedia(int $newsId, array $media, string $publishedAt, int $sortOrder): void
{
    $sourceMediaId = trim((string) ($media['source_media_id'] ?? ''));
    $remoteUrl = trim((string) ($media['url'] ?? ''));
    if ($sourceMediaId === '' || $remoteUrl === '') {
        throw new RuntimeException('VK media ID/URL отсутствует');
    }

    $pdo = db();
    $existing = $pdo->prepare('SELECT id, status, storage_path FROM news_media WHERE news_id = :news_id AND source_media_id = :source_media_id LIMIT 1');
    $existing->execute(['news_id' => $newsId, 'source_media_id' => $sourceMediaId]);
    $row = $existing->fetch();
    if ($row && $row['status'] === 'ready' && is_file((string) $row['storage_path'])) {
        return;
    }

    $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $sourceMediaId) ?: hash('sha256', $sourceMediaId);
    $extension = preg_replace('/[^a-z0-9]/', '', strtolower((string) ($media['extension'] ?? 'bin'))) ?: 'bin';
    $relativePath = date('Y/m', strtotime($publishedAt)) . '/' . $safeId . '.' . $extension;
    $storageRoot = rtrim(requireEnv('MEDIA_STORAGE_PATH'), '/\\');
    $destination = $storageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

    if (!is_file($destination) || filesize($destination) === 0) {
        downloadVkUrl($remoteUrl, $destination, ($media['_type'] ?? '') === 'video' ? 600 : 180);
    }

    $previewUrl = null;
    $previewRemoteUrl = trim((string) ($media['preview_url'] ?? ''));
    if ($previewRemoteUrl !== '') {
        $previewRelative = date('Y/m', strtotime($publishedAt)) . '/' . $safeId . '-preview.jpg';
        $previewDestination = $storageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $previewRelative);
        if (!is_file($previewDestination) || filesize($previewDestination) === 0) {
            downloadVkUrl($previewRemoteUrl, $previewDestination, 180);
        }
        $previewUrl = publicMediaUrl($previewRelative);
    }

    $params = [
        'news_id' => $newsId,
        'source_media_id' => $sourceMediaId,
        'remote_url' => $remoteUrl,
        'media_type' => ($media['_type'] ?? '') === 'video' ? 'video' : 'image',
        'sort_order' => $sortOrder,
        'mime_type' => $media['mime_type'] ?? null,
        'file_size' => filesize($destination) ?: null,
        'storage_path' => $destination,
        'public_url' => publicMediaUrl($relativePath),
        'preview_url' => $previewUrl,
    ];

    if ($row) {
        $statement = $pdo->prepare(
            "UPDATE news_media SET source_remote_url = :remote_url, media_type = :media_type, sort_order = :sort_order,
             mime_type = :mime_type, file_size = :file_size, storage_path = :storage_path, public_url = :public_url,
             preview_url = :preview_url, status = 'ready' WHERE id = :id"
        );
        $params['id'] = $row['id'];
        unset($params['news_id'], $params['source_media_id']);
    } else {
        $statement = $pdo->prepare(
            "INSERT INTO news_media (news_id, source_media_id, source_remote_url, media_type, sort_order, mime_type,
             file_size, storage_path, public_url, preview_url, status)
             VALUES (:news_id, :source_media_id, :remote_url, :media_type, :sort_order, :mime_type,
             :file_size, :storage_path, :public_url, :preview_url, 'ready')"
        );
    }

    $statement->execute($params);
}

function downloadVkUrl(string $url, string $destination, int $timeout): void
{
    $dir = dirname($destination);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Не удалось создать каталог медиа');
    }

    $fp = fopen($destination, 'wb');
    if ($fp === false) {
        throw new RuntimeException('Не удалось открыть файл для записи');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 TAKT-News-Sync/1.0',
    ]);
    $ok = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    fclose($fp);

    if ($ok === false || $status >= 400 || !is_file($destination) || filesize($destination) === 0) {
        @unlink($destination);
        throw new RuntimeException('Не удалось скачать файл VK: ' . ($error !== '' ? $error : "HTTP {$status}"));
    }
}

function setEnvFileValue(string $key, string $value): void
{
    $path = PROJECT_ROOT . '/.env';
    $content = is_file($path) ? (string) file_get_contents($path) : '';
    $line = $key . '=' . $value;

    if (preg_match('/^' . preg_quote($key, '/') . '=.*/m', $content)) {
        $content = (string) preg_replace('/^' . preg_quote($key, '/') . '=.*/m', $line, $content);
    } else {
        $content = rtrim($content) . PHP_EOL . $line . PHP_EOL;
    }

    if (file_put_contents($path, $content, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось записать .env');
    }

    putenv($line);
    $_ENV[$key] = $value;
}
