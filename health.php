<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

try {
    $databaseOk = (int) db()->query('SELECT 1')->fetchColumn() === 1;
    $required = [
        'APP_URL',
        'TELEGRAM_BOT_TOKEN',
        'TELEGRAM_WEBHOOK_SECRET',
        'TELEGRAM_CHANNEL_USERNAME',
        'DB_HOST',
        'DB_NAME',
        'DB_USER',
        'DB_PASSWORD',
        'MEDIA_STORAGE_PATH',
        'MEDIA_PUBLIC_URL',
    ];

    $missing = [];
    foreach ($required as $key) {
        if (trim((string) env($key, '')) === '') {
            $missing[] = $key;
        }
    }

    jsonResponse([
        'ok' => $databaseOk && $missing === [],
        'php' => PHP_VERSION,
        'database' => $databaseOk ? 'ok' : 'error',
        'missing_config' => $missing,
        'time' => date(DATE_ATOM),
    ], $databaseOk && $missing === [] ? 200 : 503);
} catch (Throwable $error) {
    appLog('error', 'Health check failed', ['error' => $error->getMessage()]);
    jsonResponse([
        'ok' => false,
        'database' => 'error',
        'error' => 'Health check failed',
    ], 503);
}
