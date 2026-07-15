<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $webhookUrl = rtrim(requireEnv('APP_URL'), '/') . '/webhook.php';
    $result = telegramApi('setWebhook', [
        'url' => $webhookUrl,
        'secret_token' => requireEnv('TELEGRAM_WEBHOOK_SECRET'),
        'allowed_updates' => json_encode(['channel_post', 'edited_channel_post'], JSON_THROW_ON_ERROR),
        'drop_pending_updates' => 'false',
    ]);

    echo "Webhook установлен: {$webhookUrl}" . PHP_EOL;
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Ошибка: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
