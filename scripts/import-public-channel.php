<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    if (!class_exists(DOMDocument::class)) {
        throw new RuntimeException('PHP DOM extension is required.');
    }

    $limit = max(1, min((int) ($argv[1] ?? 3), 20));
    $username = ltrim(requireEnv('TELEGRAM_CHANNEL_USERNAME'), '@');
    $chat = telegramApi('getChat', ['chat_id' => '@' . $username]);
    $channelId = (int) ($chat['id'] ?? 0);
    if ($channelId === 0) {
        throw new RuntimeException('Telegram did not return channel ID.');
    }

    $html = httpGet('https://t.me/s/' . rawurlencode($username));
    $posts = parsePosts($html, $username);
    usort($posts, static fn(array $a, array $b): int => $b['message_id'] <=> $a['message_id']);
    $posts = array_reverse(array_slice($posts, 0, $limit));

    if ($posts === []) {
        throw new RuntimeException('No posts found in the public Telegram feed.');
    }

    foreach ($posts as $post) {
        importPost($channelId, $username, $post);
        echo sprintf("Imported #%d (%d media): %s%s", $post['message_id'], count($post['media']), titleFromText($post['text']), PHP_EOL);
    }

    echo 'Done: ' . count($posts) . PHP_EOL;
    echo rtrim(requireEnv('APP_URL'), '/') . '/api/news.php?limit=' . $limit . PHP_EOL;
} catch (Throwable $error) {
    appLog('error', 'Public import failed', ['error' => $error->getMessage()]);
    fwrite(STDERR, 'Error: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

function httpGet(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 TAKT-News-Importer/1.0',
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false || $error !== '' || $status >= 400) {
        throw new RuntimeException('HTTP download failed: ' . ($error !== '' ? $error : "HTTP {$status}"));
    }
    return (string) $body;
}

function parsePosts(string $html, string $username): array
{
    $old = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($old);
    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query('//*[@data-post]');
    $posts = [];

    if ($nodes === false) {
        return [];
    }

    foreach ($nodes as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        $dataPost = $node->getAttribute('data-post');
        if (!preg_match('~^' . preg_quote($username, '~') . '/(\d+)$~i', $dataPost, $m)) {
            continue;
        }
        $messageId = (int) $m[1];
        if (isset($posts[$messageId])) {
            continue;
        }

        $textNode = queryFirst($xpath, './/*[contains(concat(" ", normalize-space(@class), " "), " tgme_widget_message_text ")]', $node);
        $text = $textNode ? cleanText($textNode->textContent) : '';
        $timeNode = queryFirst($xpath, './/time[@datetime]', $node);
        $time = $timeNode instanceof DOMElement ? strtotime($timeNode->getAttribute('datetime')) : false;

        $posts[$messageId] = [
            'message_id' => $messageId,
            'text' => $text,
            'published_at' => date('Y-m-d H:i:s', $time !== false ? $time : time()),
            'media' => parseMedia($xpath, $node),
        ];
    }
    return array_values($posts);
}

function queryFirst(DOMXPath $xpath, string $query, DOMNode $context): ?DOMNode
{
    $nodes = $xpath->query($query, $context);
    return $nodes !== false && $nodes->length > 0 ? $nodes->item(0) : null;
}

function cleanText(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[\x{00A0}\t ]+/u', ' ', $text) ?? $text;
    return trim(preg_replace('/\R{3,}/u', "\n\n", $text) ?? $text);
}

function parseMedia(DOMXPath $xpath, DOMElement $node): array
{
    $items = [];
    $seen = [];

    $styled = $xpath->query('.//*[@style]', $node);
    if ($styled !== false) {
        foreach ($styled as $item) {
            if (!$item instanceof DOMElement || !str_contains($item->getAttribute('class'), 'message_photo')) {
                continue;
            }
            if (preg_match('~background-image\s*:\s*url\([\'\"]?(.*?)[\'\"]?\)~i', html_entity_decode($item->getAttribute('style')), $m)) {
                addMedia($items, $seen, $m[1], 'image', null);
            }
        }
    }

    $videos = $xpath->query('.//video | .//source[@src] | .//*[@data-video]', $node);
    if ($videos !== false) {
        foreach ($videos as $item) {
            if (!$item instanceof DOMElement) {
                continue;
            }
            $url = $item->getAttribute('src') ?: $item->getAttribute('data-video');
            addMedia($items, $seen, $url, 'video', $item->getAttribute('poster') ?: null);
        }
    }

    return $items;
}

function addMedia(array &$items, array &$seen, string $url, string $type, ?string $preview): void
{
    $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($url === '' || !str_starts_with($url, 'https://') || isset($seen[$url])) {
        return;
    }
    $seen[$url] = true;
    $items[] = ['type' => $type, 'url' => $url, 'preview_url' => $preview];
}

function importPost(int $channelId, string $username, array $post): void
{
    $pdo = db();
    $messageId = (int) $post['message_id'];
    $statement = $pdo->prepare(
        "INSERT INTO news (telegram_channel_id, telegram_message_id, telegram_post_url, title, body, published_at, status)
         VALUES (:channel_id, :message_id, :url, :title, :body, :published_at, 'processing')
         ON DUPLICATE KEY UPDATE telegram_post_url=VALUES(telegram_post_url), title=VALUES(title), body=VALUES(body), published_at=VALUES(published_at), status=IF(status IN ('hidden','deleted'),status,'processing')"
    );
    $statement->execute([
        'channel_id' => $channelId,
        'message_id' => $messageId,
        'url' => 'https://t.me/' . $username . '/' . $messageId,
        'title' => titleFromText($post['text']),
        'body' => $post['text'],
        'published_at' => $post['published_at'],
    ]);

    $find = $pdo->prepare('SELECT id FROM news WHERE telegram_channel_id=:channel_id AND telegram_message_id=:message_id');
    $find->execute(['channel_id' => $channelId, 'message_id' => $messageId]);
    $newsId = (int) $find->fetchColumn();
    $ready = 0;

    foreach ($post['media'] as $index => $media) {
        savePublicMedia($newsId, $messageId, $post['published_at'], $media, $index);
        $ready++;
    }

    $status = $post['media'] === [] || $ready > 0 ? 'published' : 'error';
    $update = $pdo->prepare("UPDATE news SET status=IF(status IN ('hidden','deleted'),status,:status) WHERE id=:id");
    $update->execute(['status' => $status, 'id' => $newsId]);
}

function savePublicMedia(int $newsId, int $messageId, string $publishedAt, array $media, int $sortOrder): void
{
    $url = (string) $media['url'];
    $type = $media['type'] === 'video' ? 'video' : 'image';
    $base = 'post-' . $messageId . '-' . $sortOrder;
    $file = downloadMedia($url, $publishedAt, $base, $type);
    $previewUrl = null;

    if (!empty($media['preview_url'])) {
        $preview = downloadMedia((string) $media['preview_url'], $publishedAt, $base . '-preview', 'image');
        $previewUrl = publicMediaUrl($preview['relative_path']);
    }

    $statement = db()->prepare(
        "INSERT INTO news_media (news_id, telegram_file_unique_id, media_type, sort_order, original_filename, mime_type, file_size, storage_path, public_url, preview_url, status)
         VALUES (:news_id,:unique_id,:media_type,:sort_order,:filename,:mime_type,:file_size,:storage_path,:public_url,:preview_url,'ready')
         ON DUPLICATE KEY UPDATE media_type=VALUES(media_type),sort_order=VALUES(sort_order),mime_type=VALUES(mime_type),file_size=VALUES(file_size),storage_path=VALUES(storage_path),public_url=VALUES(public_url),preview_url=VALUES(preview_url),status='ready'"
    );
    $statement->execute([
        'news_id' => $newsId,
        'unique_id' => 'public_' . hash('sha256', $url),
        'media_type' => $type,
        'sort_order' => $sortOrder,
        'filename' => basename((string) parse_url($url, PHP_URL_PATH)),
        'mime_type' => $file['mime_type'],
        'file_size' => $file['file_size'],
        'storage_path' => $file['storage_path'],
        'public_url' => publicMediaUrl($file['relative_path']),
        'preview_url' => $previewUrl,
    ]);
}

function downloadMedia(string $url, string $publishedAt, string $baseName, string $expectedType): array
{
    $temp = tempnam(sys_get_temp_dir(), 'takt-');
    $fp = $temp !== false ? fopen($temp, 'wb') : false;
    if ($temp === false || $fp === false) {
        throw new RuntimeException('Could not create temporary media file.');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 180, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_USERAGENT => 'Mozilla/5.0']);
    $ok = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $mime = strtolower((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    $error = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if ($ok === false || $status >= 400 || filesize($temp) === 0) {
        @unlink($temp);
        throw new RuntimeException('Media download failed: ' . ($error ?: "HTTP {$status}"));
    }

    $mime = preg_replace('/;.*$/', '', $mime) ?: 'application/octet-stream';
    $extension = mediaExtension($mime, $url, $expectedType);
    $relative = date('Y/m', strtotime($publishedAt)) . '/' . $baseName . '.' . $extension;
    $destination = rtrim(requireEnv('MEDIA_STORAGE_PATH'), '/\\') . '/' . $relative;
    if (!is_dir(dirname($destination))) {
        mkdir(dirname($destination), 0775, true);
    }
    if (!rename($temp, $destination)) {
        @unlink($temp);
        throw new RuntimeException('Could not store imported media.');
    }

    return ['relative_path' => $relative, 'storage_path' => $destination, 'mime_type' => $mime, 'file_size' => filesize($destination)];
}

function mediaExtension(string $mime, string $url, string $expectedType): string
{
    $map = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','video/mp4'=>'mp4','video/webm'=>'webm','video/quicktime'=>'mov'];
    if (isset($map[$mime])) {
        return $map[$mime];
    }
    $ext = strtolower((string) pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
    return preg_match('/^[a-z0-9]{2,5}$/', $ext) ? $ext : ($expectedType === 'video' ? 'mp4' : 'jpg');
}
