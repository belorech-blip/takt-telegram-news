<?php

declare(strict_types=1);

require __DIR__ . '/../src/vk-service.php';

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

    // Перед выдачей блока сверяемся с самой стеной VK. Синхронизация троттлится,
    // поэтому обычные просмотры страницы не создают лишних запросов к VK.
    $syncInfo = null;
    if (vkServiceConfigured()) {
        try {
            $syncInfo = syncVkLatestThrottled(max(3, $limit), 60);
        } catch (Throwable $syncError) {
            // Если VK временно недоступен, сайт продолжает показывать последний успешный снимок.
            appLog('warning', 'VK live sync before API response failed', ['error' => $syncError->getMessage()]);
            $syncInfo = ['configured' => true, 'error' => $syncError->getMessage()];
        }
    }

    $hasSourceColumns = newsHasSourceColumns();
    $sourceSelect = $hasSourceColumns
        ? "source, COALESCE(source_url, telegram_post_url) AS post_url, source_owner_id, source_post_id"
        : "'telegram' AS source, telegram_post_url AS post_url, NULL AS source_owner_id, NULL AS source_post_id";

    $where = "status = 'published'";
    $bindings = [];

    if ($hasSourceColumns) {
        $where .= " AND source = 'vk'";

        $latestState = readVkLatestState();
        $latestIds = array_values(array_filter(array_map('intval', (array) ($latestState['post_ids'] ?? [])), static fn(int $id): bool => $id > 0));
        $latestOwnerId = (int) ($latestState['owner_id'] ?? 0);

        if ($latestOwnerId !== 0 && $latestIds !== []) {
            $where .= ' AND source_owner_id = :latest_owner_id';
            $bindings[':latest_owner_id'] = $latestOwnerId;

            $postPlaceholders = [];
            foreach ($latestIds as $index => $postId) {
                $placeholder = ':latest_post_' . $index;
                $postPlaceholders[] = $placeholder;
                $bindings[$placeholder] = $postId;
            }
            $where .= ' AND source_post_id IN (' . implode(',', $postPlaceholders) . ')';
        } else {
            // Не показываем старые неудачные HTML-импорты без реального контента.
            $where .= " AND NOT (
                TRIM(COALESCE(title, '')) = 'Новость компании'
                AND TRIM(COALESCE(body, '')) = 'Новость компании'
                AND NOT EXISTS (
                    SELECT 1 FROM news_media nm_bad WHERE nm_bad.news_id = news.id AND nm_bad.status = 'ready'
                )
            )";
        }
    } else {
        $where .= ' AND 1 = 0';
    }

    $statement = db()->prepare(
        "SELECT id, title, body, {$sourceSelect}, published_at
         FROM news
         WHERE {$where}
         ORDER BY published_at DESC, id DESC
         LIMIT :limit"
    );

    foreach ($bindings as $placeholder => $value) {
        $statement->bindValue($placeholder, $value, PDO::PARAM_INT);
    }
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
        $sourceUrl = (string) ($row['post_url'] ?? '');

        $items[] = [
            'id' => $newsId,
            'source' => (string) ($row['source'] ?? 'vk'),
            'source_post_id' => isset($row['source_post_id']) ? (int) $row['source_post_id'] : null,
            'title' => $row['title'],
            'excerpt' => excerptFromBody((string) ($row['body'] ?? '')),
            'published_at' => $date->format(DATE_ATOM),
            'source_url' => $sourceUrl,
            // Оставляем старое поле только для совместимости с уже опубликованным Tilda-блоком.
            'telegram_url' => $sourceUrl,
            'media_type' => aggregateMediaType($media),
            'primary_media' => $media[0] ?? null,
            'media' => $media,
        ];
    }

    header('Cache-Control: public, max-age=30, stale-while-revalidate=120');
    jsonResponse([
        'ok' => true,
        'count' => count($items),
        'source' => 'vk',
        'live_sync_configured' => vkServiceConfigured(),
        'items' => $items,
    ]);
} catch (Throwable $error) {
    appLog('error', 'News API failed', ['error' => $error->getMessage()]);
    jsonResponse(['ok' => false, 'error' => 'News API unavailable'], 500);
}

function newsHasSourceColumns(): bool
{
    try {
        $statement = db()->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = \'news\' AND COLUMN_NAME = \'source_url\''
        );
        $statement->execute(['schema' => requireEnv('DB_NAME')]);
        return (int) $statement->fetchColumn() > 0;
    } catch (Throwable) {
        return false;
    }
}

function applyCors(): void
{
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
