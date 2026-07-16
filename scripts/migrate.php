<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $files = glob(__DIR__ . '/../database/migrations/*.sql') ?: [];
    sort($files, SORT_STRING);

    foreach ($files as $file) {
        $sql = file_get_contents($file);
        if ($sql === false || trim($sql) === '') {
            continue;
        }
        db()->exec($sql);
        echo 'Applied: ' . basename($file) . PHP_EOL;
    }

    echo 'Migrations complete.' . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Migration error: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
