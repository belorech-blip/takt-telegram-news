<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'service' => 'TAKT Telegram News',
    'status' => 'running',
    'health' => '/health.php',
    'api' => '/api/news.php',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
