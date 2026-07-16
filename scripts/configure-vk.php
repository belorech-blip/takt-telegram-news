<?php

declare(strict_types=1);

require __DIR__ . '/../src/vk.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    echo "Вставьте токен VK и нажмите Enter:" . PHP_EOL;
    $token = trim((string) fgets(STDIN));
    if ($token === '') {
        throw new RuntimeException('Токен VK не введён');
    }

    setEnvFileValue('VK_ACCESS_TOKEN', $token);
    setEnvFileValue('VK_GROUP_DOMAIN', 'razdvatakt');
    setEnvFileValue('VK_API_VERSION', '5.199');

    echo "Проверяю доступ к сообществу VK..." . PHP_EOL;
    $result = syncVkPosts(3);

    echo "Готово." . PHP_EOL;
    echo "Получено постов: " . $result['received'] . PHP_EOL;
    echo "Опубликовано в API: " . $result['published'] . PHP_EOL;
    if ($result['errors'] !== []) {
        echo "Ошибки:" . PHP_EOL;
        foreach ($result['errors'] as $error) {
            echo '- пост ' . ($error['post_id'] ?? '?') . ': ' . ($error['error'] ?? 'неизвестная ошибка') . PHP_EOL;
        }
    }

    echo PHP_EOL;
    echo "API: https://new.devtakt.ru/api/news.php?limit=3" . PHP_EOL;
    echo "Команда для CRON: /opt/php/8.3/bin/php " . PROJECT_ROOT . "/scripts/sync-vk.php 10" . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Ошибка: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
