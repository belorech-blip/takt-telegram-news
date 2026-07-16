<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $webhookUrl = rtrim(requireEnv('APP_URL'), '/') . '/webhook.php';
    $apiUrl = 'https://api.telegram.org/bot' . requireEnv('TELEGRAM_BOT_TOKEN') . '/setWebhook';

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'url' => $webhookUrl,
            'secret_token' => requireEnv('TELEGRAM_WEBHOOK_SECRET'),
            'allowed_updates' => json_encode(['channel_post', 'edited_channel_post'], JSON_THROW_ON_ERROR),
            'drop_pending_updates' => 'false',
        ]),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false || $error !== '') {
        throw new RuntimeException('Ошибка соединения с Telegram API: ' . $error);
    }

    $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if ($status >= 400 || !($response['ok'] ?? false)) {
        throw new RuntimeException('Telegram API вернул ошибку: ' . ($response['description'] ?? "HTTP {$status}"));
    }

    echo "Webhook установлен: {$webhookUrl}" . PHP_EOL;
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Ошибка: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
