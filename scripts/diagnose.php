<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function out(string $title, mixed $value): void
{
    echo PHP_EOL . '=== ' . $title . ' ===' . PHP_EOL;
    if (is_string($value)) {
        echo $value . PHP_EOL;
        return;
    }
    echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
}

try {
    out('CONFIG', [
        'app_url' => env('APP_URL'),
        'channel_username' => env('TELEGRAM_CHANNEL_USERNAME'),
        'channel_id_set' => trim((string) env('TELEGRAM_CHANNEL_ID', '')) !== '',
        'cors_allowed_origins' => env('CORS_ALLOWED_ORIGINS'),
        'media_storage_path' => env('MEDIA_STORAGE_PATH'),
        'media_public_url' => env('MEDIA_PUBLIC_URL'),
    ]);

    $webhook = telegramApi('getWebhookInfo');
    out('TELEGRAM WEBHOOK', [
        'url' => $webhook['url'] ?? null,
        'has_custom_certificate' => $webhook['has_custom_certificate'] ?? false,
        'pending_update_count' => $webhook['pending_update_count'] ?? null,
        'last_error_date' => isset($webhook['last_error_date']) ? date(DATE_ATOM, (int) $webhook['last_error_date']) : null,
        'last_error_message' => $webhook['last_error_message'] ?? null,
        'last_synchronization_error_date' => isset($webhook['last_synchronization_error_date']) ? date(DATE_ATOM, (int) $webhook['last_synchronization_error_date']) : null,
        'max_connections' => $webhook['max_connections'] ?? null,
        'allowed_updates' => $webhook['allowed_updates'] ?? null,
    ]);

    $pdo = db();
    $counts = [
        'news_total' => (int) $pdo->query('SELECT COUNT(*) FROM news')->fetchColumn(),
        'news_published' => (int) $pdo->query("SELECT COUNT(*) FROM news WHERE status = 'published'")->fetchColumn(),
        'news_processing' => (int) $pdo->query("SELECT COUNT(*) FROM news WHERE status = 'processing'")->fetchColumn(),
        'news_error' => (int) $pdo->query("SELECT COUNT(*) FROM news WHERE status = 'error'")->fetchColumn(),
        'media_total' => (int) $pdo->query('SELECT COUNT(*) FROM news_media')->fetchColumn(),
        'media_ready' => (int) $pdo->query("SELECT COUNT(*) FROM news_media WHERE status = 'ready'")->fetchColumn(),
        'media_error' => (int) $pdo->query("SELECT COUNT(*) FROM news_media WHERE status = 'error'")->fetchColumn(),
    ];
    out('DATABASE COUNTS', $counts);

    $latestNews = $pdo->query(
        "SELECT id, telegram_channel_id, telegram_message_id, media_group_id, title, status, published_at, created_at, updated_at
         FROM news
         ORDER BY id DESC
         LIMIT 5"
    )->fetchAll();
    out('LATEST NEWS', $latestNews);

    $latestMedia = $pdo->query(
        "SELECT id, news_id, media_type, status, sort_order, mime_type, file_size, public_url, preview_url, created_at
         FROM news_media
         ORDER BY id DESC
         LIMIT 10"
    )->fetchAll();
    out('LATEST MEDIA', $latestMedia);

    $mediaDir = rtrim((string) env('MEDIA_STORAGE_PATH', ''), '/\\');
    out('FILESYSTEM', [
        'media_dir_exists' => $mediaDir !== '' && is_dir($mediaDir),
        'media_dir_writable' => $mediaDir !== '' && is_dir($mediaDir) && is_writable($mediaDir),
        'log_dir_exists' => is_dir(PROJECT_ROOT . '/storage/logs'),
        'log_dir_writable' => is_dir(PROJECT_ROOT . '/storage/logs') && is_writable(PROJECT_ROOT . '/storage/logs'),
    ]);

    $logFiles = glob(PROJECT_ROOT . '/storage/logs/app-*.log') ?: [];
    rsort($logFiles);
    $latestLog = $logFiles[0] ?? null;
    if ($latestLog && is_file($latestLog)) {
        $lines = file($latestLog, FILE_IGNORE_NEW_LINES) ?: [];
        out('LAST LOG LINES', implode(PHP_EOL, array_slice($lines, -30)));
    } else {
        out('LAST LOG LINES', 'Лог-файлы пока отсутствуют.');
    }

    echo PHP_EOL . 'DIAGNOSTICS COMPLETE' . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, PHP_EOL . 'DIAGNOSTICS ERROR: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
