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

    $statement = db()->prepare(
        "SELECT id, title, body, telegram_post_url, published_at
         FROM news
         WHERE status = 'published'
         ORDER BY published_at DESC, id DESC
         LIMIT :limit"
    );
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->execute();
    $newsRows = $statement->fetchAll();

    $mediaByNewsId = [];
    if ($newsRows !== []) {
        $ids = array_map(static fn(array $row): int => (int) $row['id'], $newsRows);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $mediaStatement = db()->prepare(
            "SELECT news_id, media_type, public_url, preview_url, mime_type, file_size, sort_order
             FROM news_media
             WHERE status = 'ready' AND news_id IN ({$placeholders})
             ORDER BY news_id ASC, sort_order ASC, id ASC"
        );
        $mediaStatement->execute($ids);

        foreach ($mediaStatement->fetchAll() as $mediaRow) {
            $newsId = (int) $mediaRow['news_id'];
            $mediaByNewsId[$newsId][] = [
                'type' => $mediaRow['media_type'],
                'url' => $mediaRow['public_url'],
                'preview_url' => $mediaRow['preview_url'],
                'mime_type' => $mediaRow['mime_type'],
                'file_size' => $mediaRow['file_size'] !== null ? (int) $mediaRow['file_size'] : null,
            ];
        }
    }

    $items = [];
    foreach ($newsRows as $row) {
        $newsId = (int) $row['id'];
        $media = $mediaByNewsId[$newsId] ?? [];
        $date = new DateTimeImmutable(
            $row['published_at'],
            new DateTimeZone(env('APP_TIMEZONE', 'Asia/Yekaterinburg') ?? 'Asia/Yekaterinburg')
        );

        $items[] = [
            'id' => $newsId,
            'title' => $row['title'],
            'excerpt' => excerptFromBody((string) ($row['body'] ?? '')),
            'published_at' => $date->format(DATE_ATOM),
            'telegram_url' => $row['telegram_post_url'],
            'media_type' => aggregateMediaType($media),
            'primary_media' => $media[0] ?? null,
            'media' => $media,
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
    // API содержит только публичные новости и не использует cookies/авторизацию.
    // Открытый CORS нужен для опубликованного сайта и предпросмотра Tilda.
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}

function excerptFromBody(string $body): string
{
    $body = trim(preg_replace('/\s+/u', ' ', strip_tags($body)) ?? $body);
    if ($body === '') {
        return '';
    }

    return mb_strlen($body) > 180 ? rtrim(mb_substr($body, 0, 177)) . '…' : $body;
}

function aggregateMediaType(array $media): string
{
    if ($media === []) {
        return 'none';
    }

    if (count($media) === 1) {
        return (string) $media[0]['type'];
    }

    $types = array_values(array_unique(array_map(static fn(array $item): string => (string) $item['type'], $media)));
    return count($types) === 1 ? 'gallery' : 'mixed';
}
