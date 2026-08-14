<?php

declare(strict_types=1);

require_once __DIR__ . '/vk.php';

function vkServiceConfigured(): bool
{
    return trim((string) env('VK_SERVICE_TOKEN', '')) !== '';
}

function vkServiceApi(string $method, array $params = []): array
{
    $token = trim((string) env('VK_SERVICE_TOKEN', ''));
    if ($token === '') {
        throw new RuntimeException('Не настроен сервисный ключ VK (VK_SERVICE_TOKEN)');
    }

    $params['access_token'] = $token;
    $params['v'] = env('VK_API_VERSION', '5.199');

    $url = 'https://api.vk.com/method/' . rawurlencode($method) . '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'TAKT-News-Service-Sync/2.0',
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false || $error !== '') {
        throw new RuntimeException('Ошибка соединения с VK API: ' . $error);
    }

    $data = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
    if ($status >= 400 || isset($data['error'])) {
        $message = (string) ($data['error']['error_msg'] ?? "HTTP {$status}");
        throw new RuntimeException('VK API вернул ошибку: ' . $message);
    }

    return is_array($data['response'] ?? null) ? $data['response'] : [];
}

function syncVkServicePosts(int $limit = 3): array
{
    ensureVkSchema();

    $limit = max(1, min($limit, 20));
    $groupId = abs((int) env('VK_GROUP_ID', '231882067'));
    $domain = ltrim(trim((string) env('VK_GROUP_DOMAIN', 'razdvatakt')), '@');

    $response = vkServiceApi('wall.get', [
        'owner_id' => -$groupId,
        'count' => max(10, $limit),
        'filter' => 'owner',
        'extended' => 0,
    ]);

    $items = is_array($response['items'] ?? null) ? $response['items'] : [];
    $items = array_values(array_filter($items, static function (array $post) use ($groupId): bool {
        return (int) ($post['owner_id'] ?? 0) === -$groupId
            && (int) ($post['id'] ?? 0) > 0
            && (int) ($post['marked_as_ads'] ?? 0) !== 1;
    }));

    // Пин не должен влиять на понятие «последние»: сортируем строго по фактической дате.
    usort($items, static fn(array $a, array $b): int => ((int) ($b['date'] ?? 0)) <=> ((int) ($a['date'] ?? 0)));
    $items = array_slice($items, 0, $limit);

    if ($items === []) {
        throw new RuntimeException('VK не вернул записи стены');
    }

    $summary = [
        'requested' => $limit,
        'received' => count($items),
        'published' => 0,
        'post_ids' => [],
        'errors' => [],
    ];

    foreach ($items as $post) {
        $postId = (int) ($post['id'] ?? 0);
        $summary['post_ids'][] = $postId;

        try {
            syncVkServicePost($post, $domain);
            $summary['published']++;
        } catch (Throwable $error) {
            $summary['errors'][] = [
                'post_id' => $postId,
                'error' => $error->getMessage(),
            ];
            appLog('error', 'VK service post sync failed', [
                'post_id' => $postId,
                'error' => $error->getMessage(),
            ]);
        }
    }

    writeVkLatestState(-$groupId, $summary['post_ids']);
    hideBrokenVkPlaceholderImports();

    appLog('info', 'VK service synchronization complete', $summary);
    return $summary;
}

function syncVkLatestThrottled(int $limit = 3, int $ttlSeconds = 60): array
{
    if (!vkServiceConfigured()) {
        return ['configured' => false, 'skipped' => true, 'reason' => 'service_token_missing'];
    }

    $ttlSeconds = max(10, $ttlSeconds);
    $statePath = PROJECT_ROOT . '/storage/vk-sync-runtime.json';
    $lockPath = PROJECT_ROOT . '/storage/vk-sync.lock';

    if (!is_dir(dirname($lockPath))) {
        @mkdir(dirname($lockPath), 0775, true);
    }

    $lastSync = 0;
    if (is_file($statePath)) {
        $state = json_decode((string) file_get_contents($statePath), true);
        $lastSync = (int) ($state['last_success'] ?? 0);
    }

    if ($lastSync > 0 && time() - $lastSync < $ttlSeconds) {
        return ['configured' => true, 'skipped' => true, 'reason' => 'fresh', 'last_success' => $lastSync];
    }

    $handle = fopen($lockPath, 'c+');
    if ($handle === false) {
        return ['configured' => true, 'skipped' => true, 'reason' => 'lock_unavailable'];
    }

    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return ['configured' => true, 'skipped' => true, 'reason' => 'already_running'];
    }

    try {
        $result = syncVkServicePosts($limit);
        file_put_contents($statePath, json_encode([
            'last_success' => time(),
            'result' => $result,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
        return ['configured' => true, 'skipped' => false, 'result' => $result];
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function syncVkServicePost(array $post, string $domain): int
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
    $sourceUrl = "https://vk.ru/wall{$ownerId}_{$postId}";

    $pdo = db();
    $find = $pdo->prepare("SELECT id FROM news WHERE source = 'vk' AND source_owner_id = :owner_id AND source_post_id = :post_id LIMIT 1");
    $find->execute(['owner_id' => $ownerId, 'post_id' => $postId]);
    $existing = $find->fetch();

    if ($existing) {
        $statement = $pdo->prepare(
            "UPDATE news SET source_url = :source_url, title = :title, body = :body, published_at = :published_at, status = 'processing' WHERE id = :id"
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

    $mediaItems = vkExtractServiceMediaItems($attachments);
    $saved = 0;
    $errors = [];

    foreach ($mediaItems as $index => $media) {
        try {
            saveVkMedia($newsId, $media, $publishedAt, $index);
            $saved++;
        } catch (Throwable $error) {
            $errors[] = $error->getMessage();
            appLog('error', 'VK service media download failed', [
                'news_id' => $newsId,
                'source_media_id' => $media['source_media_id'] ?? null,
                'error' => $error->getMessage(),
            ]);
        }
    }

    // Текстовый пост без вложений допустим. Если вложения были, но ни одно не сохранилось — не публикуем битую карточку.
    $status = ($mediaItems === [] || $saved > 0) ? 'published' : 'error';
    $publish = $pdo->prepare('UPDATE news SET status = :status WHERE id = :id');
    $publish->execute(['status' => $status, 'id' => $newsId]);

    appLog('info', 'VK service post processed', [
        'news_id' => $newsId,
        'post_id' => $postId,
        'media_count' => count($mediaItems),
        'media_saved' => $saved,
        'errors' => $errors,
    ]);

    return $newsId;
}

function vkExtractServiceMediaItems(array $attachments): array
{
    $result = [];

    foreach ($attachments as $attachment) {
        $type = (string) ($attachment['type'] ?? '');
        $object = is_array($attachment[$type] ?? null) ? $attachment[$type] : [];

        if ($type === 'photo') {
            $size = vkLargestImage($object['sizes'] ?? []);
            if ($size !== null && !empty($size['url'])) {
                $result[] = [
                    'source_media_id' => 'photo_' . ($object['owner_id'] ?? 0) . '_' . ($object['id'] ?? 0),
                    '_type' => 'image',
                    'url' => (string) $size['url'],
                    'preview_url' => null,
                    'mime_type' => 'image/jpeg',
                    'extension' => 'jpg',
                ];
            }
            continue;
        }

        if ($type === 'video' || $type === 'clip') {
            $ownerId = (int) ($object['owner_id'] ?? 0);
            $videoId = (int) ($object['id'] ?? 0);
            if ($ownerId === 0 || $videoId <= 0) {
                continue;
            }

            $video = $object;
            $accessKey = trim((string) ($object['access_key'] ?? ''));
            $videoRef = $ownerId . '_' . $videoId . ($accessKey !== '' ? '_' . $accessKey : '');

            try {
                $videoResponse = vkServiceApi('video.get', ['videos' => $videoRef, 'count' => 1]);
                if (is_array($videoResponse['items'][0] ?? null)) {
                    $video = $videoResponse['items'][0];
                }
            } catch (Throwable $error) {
                appLog('warning', 'VK video.get unavailable, using attachment preview', [
                    'video' => $videoRef,
                    'error' => $error->getMessage(),
                ]);
            }

            $videoUrl = vkBestVideoUrl($video['files'] ?? []);
            $preview = vkLargestImage($video['image'] ?? $video['first_frame'] ?? $object['image'] ?? []);

            if ($videoUrl !== null) {
                $result[] = [
                    'source_media_id' => 'video_' . $ownerId . '_' . $videoId,
                    '_type' => 'video',
                    'url' => $videoUrl,
                    'preview_url' => $preview !== null ? (string) ($preview['url'] ?? '') : null,
                    'mime_type' => 'video/mp4',
                    'extension' => 'mp4',
                ];
            } elseif ($preview !== null && !empty($preview['url'])) {
                // Для карточки новостей изображение-превью надёжнее, чем пустой блок.
                $result[] = [
                    'source_media_id' => 'video_preview_' . $ownerId . '_' . $videoId,
                    '_type' => 'image',
                    'url' => (string) $preview['url'],
                    'preview_url' => null,
                    'mime_type' => 'image/jpeg',
                    'extension' => 'jpg',
                ];
            }
            continue;
        }

        if ($type === 'doc' && !empty($object['url'])) {
            $extension = strtolower((string) ($object['ext'] ?? 'bin'));
            $isImage = in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
            $isVideo = in_array($extension, ['mp4', 'mov', 'webm'], true);
            if ($isImage || $isVideo) {
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
    }

    return $result;
}

function writeVkLatestState(int $ownerId, array $postIds): void
{
    $postIds = array_values(array_unique(array_map('intval', $postIds)));
    $path = PROJECT_ROOT . '/storage/vk-latest.json';

    if (!is_dir(dirname($path))) {
        @mkdir(dirname($path), 0775, true);
    }

    file_put_contents($path, json_encode([
        'owner_id' => $ownerId,
        'post_ids' => $postIds,
        'updated_at' => date(DATE_ATOM),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
}

function readVkLatestState(): array
{
    $path = PROJECT_ROOT . '/storage/vk-latest.json';
    if (!is_file($path)) {
        return [];
    }

    $state = json_decode((string) file_get_contents($path), true);
    return is_array($state) ? $state : [];
}

function hideBrokenVkPlaceholderImports(): void
{
    $pdo = db();
    $pdo->exec(
        "UPDATE news n
         SET n.status = 'hidden'
         WHERE n.source = 'vk'
           AND TRIM(COALESCE(n.title, '')) = 'Новость компании'
           AND TRIM(COALESCE(n.body, '')) = 'Новость компании'
           AND NOT EXISTS (
               SELECT 1 FROM news_media m WHERE m.news_id = n.id AND m.status = 'ready'
           )"
    );
}
