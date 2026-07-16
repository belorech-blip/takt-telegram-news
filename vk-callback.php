<?php

declare(strict_types=1);

require __DIR__ . '/src/vk.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'VK callback endpoint';
    exit;
}

try {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    $type = (string) ($payload['type'] ?? '');

    if ($type === 'confirmation') {
        header('Content-Type: text/plain; charset=utf-8');
        echo requireEnv('VK_CONFIRMATION_CODE');
        exit;
    }

    $expectedSecret = trim((string) env('VK_CALLBACK_SECRET', ''));
    $receivedSecret = trim((string) ($payload['secret'] ?? ''));
    if ($expectedSecret !== '' && !hash_equals($expectedSecret, $receivedSecret)) {
        http_response_code(403);
        echo 'forbidden';
        exit;
    }

    if (in_array($type, ['wall_post_new', 'wall_repost'], true)) {
        $object = $payload['object'] ?? [];
        $post = is_array($object['post'] ?? null) ? $object['post'] : (is_array($object) ? $object : []);
        if ($post !== []) {
            syncVkPost($post, ltrim((string) env('VK_GROUP_DOMAIN', 'razdvatakt'), '@'));
        }
    }

    header('Content-Type: text/plain; charset=utf-8');
    echo 'ok';
} catch (Throwable $error) {
    appLog('error', 'VK callback failed', ['error' => $error->getMessage()]);
    http_response_code(500);
    echo 'error';
}
