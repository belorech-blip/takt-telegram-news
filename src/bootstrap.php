<?php

declare(strict_types=1);

const PROJECT_ROOT = __DIR__ . '/..';

function loadEnv(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        throw new RuntimeException('Не удалось прочитать .env');
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        if ($value !== '' && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
            $value = substr($value, 1, -1);
        }

        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

loadEnv(PROJECT_ROOT . '/.env');
date_default_timezone_set(env('APP_TIMEZONE', 'Asia/Yekaterinburg') ?? 'Asia/Yekaterinburg');

function requireEnv(string $key): string
{
    $value = trim((string) env($key, ''));
    if ($value === '') {
        throw new RuntimeException("Не заполнена переменная окружения {$key}");
    }
    return $value;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        requireEnv('DB_HOST'),
        env('DB_PORT', '3306'),
        requireEnv('DB_NAME')
    );

    $pdo = new PDO($dsn, requireEnv('DB_USER'), requireEnv('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function appLog(string $level, string $message, array $context = []): void
{
    $dir = PROJECT_ROOT . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $line = json_encode([
        'time' => date(DATE_ATOM),
        'level' => $level,
        'message' => $message,
        'context' => $context,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
}

function telegramApi(string $method, array $params = []): array
{
    $url = 'https://api.telegram.org/bot' . requireEnv('TELEGRAM_BOT_TOKEN') . '/' . $method;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
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

    $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if ($status >= 400 || !($data['ok'] ?? false)) {
        throw new RuntimeException('Telegram API вернул ошибку: ' . ($data['description'] ?? "HTTP {$status}"));
    }

    return $data['result'] ?? [];
}

function downloadTelegramFile(string $fileId, string $destination): array
{
    $file = telegramApi('getFile', ['file_id' => $fileId]);
    $filePath = (string) ($file['file_path'] ?? '');
    if ($filePath === '') {
        throw new RuntimeException('Telegram не вернул file_path');
    }

    $url = 'https://api.telegram.org/file/bot' . requireEnv('TELEGRAM_BOT_TOKEN') . '/' . $filePath;
    $dir = dirname($destination);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Не удалось создать каталог медиа');
    }

    $fp = fopen($destination, 'wb');
    if ($fp === false) {
        throw new RuntimeException('Не удалось открыть файл для записи');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $ok = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    fclose($fp);

    if ($ok === false || $status >= 400) {
        @unlink($destination);
        throw new RuntimeException('Не удалось скачать файл Telegram: ' . ($error !== '' ? $error : "HTTP {$status}"));
    }

    return $file;
}

function titleFromText(?string $text): string
{
    $text = trim((string) $text);
    if ($text === '') {
        return 'Новость компании';
    }

    $lines = preg_split('/\R/u', $text) ?: [];
    $title = '';
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '') {
            $title = $line;
            break;
        }
    }

    $title = preg_replace('/\s+/u', ' ', strip_tags($title)) ?? $title;
    if (mb_strlen($title) > 110) {
        $title = rtrim(mb_substr($title, 0, 107)) . '…';
    }

    return $title !== '' ? $title : 'Новость компании';
}

function extensionForMedia(array $media, string $telegramFilePath = ''): string
{
    $extension = strtolower((string) pathinfo($telegramFilePath, PATHINFO_EXTENSION));
    if ($extension !== '' && preg_match('/^[a-z0-9]{2,5}$/', $extension)) {
        return $extension;
    }

    $mime = strtolower((string) ($media['mime_type'] ?? ''));
    return match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
        default => (($media['_type'] ?? '') === 'image' ? 'jpg' : 'bin'),
    };
}

function publicMediaUrl(string $relativePath): string
{
    return rtrim(requireEnv('MEDIA_PUBLIC_URL'), '/') . '/' . ltrim(str_replace(DIRECTORY_SEPARATOR, '/', $relativePath), '/');
}
