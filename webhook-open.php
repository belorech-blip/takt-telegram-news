<?php

declare(strict_types=1);

// Временный упрощённый вход для Telegram.
// Он подставляет текущий TELEGRAM_WEBHOOK_SECRET из серверного .env
// внутрь запроса и передаёт обработку в основной webhook.php.
// Благодаря этому пользователю не нужно копировать или синхронизировать
// второй токен вручную. Проверка канала остаётся в webhook.php.

$envFile = __DIR__ . '/.env';
$secret = '';

if (is_file($envFile) && is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if ($key === 'TELEGRAM_WEBHOOK_SECRET') {
            $secret = trim($value, "\"'");
            break;
        }
    }
}

if ($secret === '') {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Server webhook secret is not configured'], JSON_UNESCAPED_UNICODE);
    exit;
}

$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] = $secret;

require __DIR__ . '/webhook.php';
