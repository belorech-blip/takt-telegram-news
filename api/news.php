<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

try {
    applyCors();

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        jsonResponse(['ok' => false, 'error' => 'Method not allowed'], 405);
    }

    $defaultLimit = max(1, (int) env('NEWS_API_DEFAULT_LIMIT', '6'));
    $maxLimit = max($defaultLimit, (int) env('NEWS_API_MAX_LIMIT', '24'));
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : $defaultLimit;
    $limit = max(1, min($limit, $maxLimit));

    $sql = "
        SELECT
            n.id,
            n.title,
            n.body,
            n.telegram_post_url,
            n.published_at,
            m.media_type,
            m.public_url AS media_url,
            m.preview_url
        FROM news n
        LEFT JOIN news_media m ON m.id = (
            SELECT nm.id
            FROM news_media nm
            WHERE nm.news_id = n.id AND nm.status = 'ready'
            ORDER BY nm.sort_order ASC, nm.id ASC
            LIMIT 1
        )
        WHERE n.status = 'published'
        ORDER BY n.published_at DESC, n.id DESC
        LIMIT :limit
    ";

    $statement = db()->prepare($sql);
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->execute();

    $items = [];
    foreach ($statement->fetchAll() as $row) {
        $date = new DateTimeImmutable($row['published_at'], new DateTimeZone(env('APP_TIMEZONE', 'Asia/Yekaterinburg') ?? 'Asia/Yekaterinburg'));
        $items[] = [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'excerpt' => excerptFromBody((string) ($row['body'] ?? '')),
            'published_at' => $date->format(DATE_ATOM),
            'telegram_url' => $row['telegram_post_url'],
            'media' => $row['media_url'] ? [
                'type' => $row['media_type'],
                'url' => $row['media_url'],
                'preview_url' => $row['preview_url'],
            ] : null,
        ];
    }

    header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
    jsonResponse([
        'ok' => true,
        'count' => count($items),
        'items' => $items,
    ]);
} catch (Throwable $error) {
    appLog('error', 'News API failed', ['error' => $error->getMessage()]);
    jsonResponse(['ok' => false, 'error' => 'News API unavailable'], 500);
}

function applyCors(): void
{
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    $allowed = array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')))));

    if ($origin !== '' && in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
    }
}

function excerptFromBody(string $body): string
{
    $body = trim(preg_replace('/\s+/u', ' ', strip_tags($body)) ?? $body);
    if ($body === '') {
        return '';
    }

    return mb_strlen($body) > 180 ? rtrim(mb_substr($body, 0, 177)) . '…' : $body;
}
