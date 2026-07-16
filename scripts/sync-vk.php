<?php

declare(strict_types=1);

require __DIR__ . '/../src/vk.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $limit = isset($argv[1]) ? (int) $argv[1] : 10;
    $result = syncVkPosts($limit);

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit($result['errors'] === [] ? 0 : 2);
} catch (Throwable $error) {
    appLog('error', 'VK synchronization failed', ['error' => $error->getMessage()]);
    fwrite(STDERR, 'Ошибка: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
