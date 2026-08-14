<?php

declare(strict_types=1);

require __DIR__ . '/../src/vk.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$links = array_slice($argv, 1);
if ($links === []) {
    $links = [
        'https://vk.ru/wall-231882067_49',
        'https://vk.ru/wall-231882067_48',
        'https://vk.ru/wall-231882067_46',
    ];
}

ensureVkSchema();

$done = 0;
$errors = [];

foreach ($links as $link) {
    try {
        $post = fetchPublicVkPost($link);
        $newsId = savePublicVkPost($post);
        $done++;
        echo sprintf("Imported %s -> news #%d (%d media)\n", $post['url'], $newsId, count($post['media']));
    } catch (Throwable $e) {
        $errors[] = ['url' => $link, 'error' => $e->getMessage()];
        echo sprintf("ERROR %s: %s\n", $link, $e->getMessage());
    }
}

echo "Done: {$done}/" . count($links) . PHP_EOL;
if ($errors !== []) {
    exit(1);
}

function fetchPublicVkPost(string $url): array
{
    if (!preg_match('~wall(-?\d+)_(\d+)~', $url, $m)) {
        throw new RuntimeException('Не удалось распознать owner_id/post_id');
    }

    $ownerId = (int) $m[1];
    $postId = (int) $m[2];
    $canonical = "https://vk.ru/wall{$ownerId}_{$postId}";

    $candidates = [
        "https://m.vk.com/wall{$ownerId}_{$postId}",
        "https://vk.com/wall{$ownerId}_{$postId}",
        $canonical,
    ];

    $html = '';
    $finalUrl = '';
    foreach ($candidates as $candidate) {
        [$status, $body, $resolved] = httpGet($candidate);
        if ($status >= 200 && $status < 400 && strlen($body) > 500) {
            $html = $body;
            $finalUrl = $resolved ?: $candidate;
            break;
        }
    }

    if ($html === '') {
        throw new RuntimeException('VK не отдал публичную страницу поста');
    }

    $meta = extractMeta($html);
    $description = trim(html_entity_decode((string) ($meta['og:description'] ?? $meta['description'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $titleMeta = trim(html_entity_decode((string) ($meta['og:title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    // VK часто кладёт текст поста в og:description. Если там только служебный текст,
    // пытаемся вытащить wall_post_text из HTML мобильной версии.
    $text = $description;
    if (preg_match('~<div[^>]+class="[^"]*wall_post_text[^"]*"[^>]*>(.*?)</div>~si', $html, $tm)) {
        $candidateText = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $tm[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($candidateText !== '') {
            $text = $candidateText;
        }
    }

    if ($text === '' || preg_match('/^VK\b|^ВКонтакте\b/u', $text)) {
        $text = $titleMeta !== '' ? $titleMeta : 'Новость компании';
    }

    $publishedTs = time();
    foreach ([
        '~"date"\s*:\s*(\d{10})~',
        '~data-date="(\d{10})"~',
        '~"published_at"\s*:\s*(\d{10})~',
    ] as $pattern) {
        if (preg_match($pattern, $html, $dm)) {
            $publishedTs = (int) $dm[1];
            break;
        }
    }

    $media = [];
    $imageUrls = [];
    foreach (['og:image', 'twitter:image'] as $key) {
        if (!empty($meta[$key])) {
            $imageUrls[] = html_entity_decode((string) $meta[$key], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }

    // Дополнительно ищем крупные CDN-изображения в HTML. Отбрасываем аватары/иконки по URL.
    if (preg_match_all('~https?:\\?/\\?/[A-Za-z0-9._-]*(?:userapi|vkuseraudio|vkcdn|vk\.me|vk\.com)[^"\'<>\\ ]+~i', $html, $im)) {
        foreach ($im[0] as $raw) {
            $candidate = str_replace(['\\/', '&amp;'], ['/', '&'], html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (preg_match('~\.(?:jpe?g|png|webp)(?:\?|$)~i', $candidate) && !preg_match('~(?:avatar|emoji|icon|logo)~i', $candidate)) {
                $imageUrls[] = $candidate;
            }
        }
    }

    $imageUrls = array_values(array_unique(array_filter($imageUrls, static fn(string $v): bool => filter_var($v, FILTER_VALIDATE_URL) !== false)));
    $imageUrls = array_slice($imageUrls, 0, 10);

    foreach ($imageUrls as $index => $imageUrl) {
        $media[] = [
            'source_media_id' => "public_{$ownerId}_{$postId}_{$index}",
            '_type' => 'image',
            'url' => $imageUrl,
            'preview_url' => null,
            'mime_type' => 'image/jpeg',
            'extension' => imageExtensionFromUrl($imageUrl),
        ];
    }

    return [
        'owner_id' => $ownerId,
        'post_id' => $postId,
        'url' => $canonical,
        'resolved_url' => $finalUrl,
        'text' => $text,
        'published_at' => date('Y-m-d H:i:s', $publishedTs),
        'media' => $media,
    ];
}

function savePublicVkPost(array $post): int
{
    $pdo = db();
    $ownerId = (int) $post['owner_id'];
    $postId = (int) $post['post_id'];
    $sourceUrl = (string) $post['url'];
    $text = trim((string) $post['text']);
    $title = titleFromText($text);
    $publishedAt = (string) $post['published_at'];

    $find = $pdo->prepare("SELECT id, status FROM news WHERE source = 'vk' AND source_owner_id = :owner_id AND source_post_id = :post_id LIMIT 1");
    $find->execute(['owner_id' => $ownerId, 'post_id' => $postId]);
    $existing = $find->fetch();

    if ($existing) {
        $stmt = $pdo->prepare("UPDATE news SET source_url=:url, title=:title, body=:body, published_at=:published_at, status='processing' WHERE id=:id");
        $stmt->execute(['url' => $sourceUrl, 'title' => $title, 'body' => $text, 'published_at' => $publishedAt, 'id' => $existing['id']]);
        $newsId = (int) $existing['id'];
    } else {
        $stmt = $pdo->prepare("INSERT INTO news (source, source_owner_id, source_post_id, source_url, title, body, published_at, status) VALUES ('vk',:owner_id,:post_id,:url,:title,:body,:published_at,'processing')");
        $stmt->execute(['owner_id' => $ownerId, 'post_id' => $postId, 'url' => $sourceUrl, 'title' => $title, 'body' => $text, 'published_at' => $publishedAt]);
        $newsId = (int) $pdo->lastInsertId();
    }

    $saved = 0;
    foreach ($post['media'] as $index => $media) {
        try {
            saveVkMedia($newsId, $media, $publishedAt, $index);
            $saved++;
        } catch (Throwable $e) {
            appLog('error', 'Manual VK media import failed', ['news_id' => $newsId, 'error' => $e->getMessage()]);
        }
    }

    $status = ($post['media'] === [] || $saved > 0) ? 'published' : 'error';
    $publish = $pdo->prepare('UPDATE news SET status=:status WHERE id=:id');
    $publish->execute(['status' => $status, 'id' => $newsId]);

    return $newsId;
}

function httpGet(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/126 Safari/537.36',
        CURLOPT_HTTPHEADER => ['Accept-Language: ru-RU,ru;q=0.9,en;q=0.7'],
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('HTTP ошибка: ' . $error);
    }

    return [$status, (string) $body, $final];
}

function extractMeta(string $html): array
{
    $result = [];
    if (preg_match_all('~<meta\s+[^>]*(?:property|name)=["\']([^"\']+)["\'][^>]*content=["\']([^"\']*)["\'][^>]*>~si', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $result[strtolower(trim($match[1]))] = $match[2];
        }
    }
    if (preg_match_all('~<meta\s+[^>]*content=["\']([^"\']*)["\'][^>]*(?:property|name)=["\']([^"\']+)["\'][^>]*>~si', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $result[strtolower(trim($match[2]))] = $match[1];
        }
    }
    return $result;
}

function imageExtensionFromUrl(string $url): string
{
    $path = strtolower((string) parse_url($url, PHP_URL_PATH));
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg','jpeg','png','webp'], true) ? ($ext === 'jpeg' ? 'jpg' : $ext) : 'jpg';
}
