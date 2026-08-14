<?php

declare(strict_types=1);

const VK_CALLBACK_GROUP_ID = 231882067;
const VK_CALLBACK_CONFIRMATION = 'fe070f64';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'VK callback endpoint';
    exit;
}

try {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    $type = (string) ($payload['type'] ?? '');
    $groupId = (int) ($payload['group_id'] ?? 0);

    if ($groupId !== 0 && $groupId !== VK_CALLBACK_GROUP_ID) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'forbidden';
        exit;
    }

    // VK требует вернуть только строку подтверждения, без лишнего вывода.
    if ($type === 'confirmation') {
        header('Content-Type: text/plain; charset=utf-8');
        echo VK_CALLBACK_CONFIRMATION;
        exit;
    }

    require __DIR__ . '/src/vk-service.php';

    $expectedSecret = trim((string) env('VK_CALLBACK_SECRET', ''));
    $receivedSecret = trim((string) ($payload['secret'] ?? ''));
    if ($expectedSecret !== '' && !hash_equals($expectedSecret, $receivedSecret)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'forbidden';
        exit;
    }

    if (in_array($type, ['wall_post_new', 'wall_repost'], true)) {
        $object = $payload['object'] ?? [];
        $post = is_array($object['post'] ?? null) ? $object['post'] : (is_array($object) ? $object : []);

        if ($post !== []) {
            if (vkServiceConfigured()) {
                // Новый пост сохраняем тем же кодом, которым работает сверка стены.
                syncVkServicePost($post, ltrim((string) env('VK_GROUP_DOMAIN', 'razdvatakt'), '@'));
                // Сразу обновляем список актуальных трёх, чтобы новый пост появился без ожидания TTL.
                try {
                    $result = syncVkServicePosts(3);
                    $statePath = PROJECT_ROOT . '/storage/vk-sync-runtime.json';
                    file_put_contents($statePath, json_encode([
                        'last_success' => time(),
                        'result' => $result,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
                } catch (Throwable $syncError) {
                    appLog('warning', 'VK callback full refresh failed', ['error' => $syncError->getMessage()]);
                }
            } else {
                // До подключения сервисного ключа сохраняем то, что пришло самим Callback API.
                syncVkPost($post, ltrim((string) env('VK_GROUP_DOMAIN', 'razdvatakt'), '@'));
            }
        }
    }

    header('Content-Type: text/plain; charset=utf-8');
    echo 'ok';
} catch (Throwable $error) {
    if (function_exists('appLog')) {
        appLog('error', 'VK callback failed', ['error' => $error->getMessage()]);
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'error';
}
