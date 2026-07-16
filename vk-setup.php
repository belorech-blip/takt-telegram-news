<?php

declare(strict_types=1);

require __DIR__ . '/src/vk.php';

$error = '';
$success = false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        ensureVkSchema();
        setEnvFileValue('VK_GROUP_DOMAIN', 'razdvatakt');
        setEnvFileValue('VK_API_VERSION', '5.199');
        $success = true;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        appLog('error', 'VK callback setup failed', ['error' => $error]);
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Подключение VK — ТАКТ</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f4f5f7;color:#111;font-family:Inter,Arial,sans-serif;min-height:100vh;display:grid;place-items:center;padding:24px}.card{width:min(680px,100%);background:#fff;border:1px solid #e5e7eb;border-radius:24px;padding:36px;box-shadow:0 24px 80px rgba(0,0,0,.08)}h1{font-size:36px;line-height:1.05;margin:0 0 14px;letter-spacing:-.04em}p{color:#666;line-height:1.55;margin:0 0 20px}.button{width:100%;min-height:54px;border:0;border-radius:14px;background:#ff3434;color:#fff;font:600 17px/1 Inter,Arial,sans-serif;cursor:pointer}.error{padding:14px 16px;border-radius:12px;background:#fff0f0;color:#a40000;margin-bottom:18px}.ok{padding:18px;border-radius:16px;background:#effaf3;color:#146c35;margin-bottom:18px}.note{padding:18px;border-radius:16px;background:#f6f7f9;color:#333;margin-bottom:20px}.link{display:inline-flex;margin-top:8px;color:#111;font-weight:600}.small{font-size:14px;color:#888;margin-top:14px}
    </style>
</head>
<body>
<main class="card">
    <h1>Подключение VK</h1>
    <?php if ($error !== ''): ?><div class="error"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($success): ?>
        <div class="ok">Система подготовлена для приёма новых записей через Callback API.</div>
        <p>Ключ доступа сообщества не требуется. После включения события «Добавление записи на стену» новые посты будут автоматически сохраняться в базе и появляться на сайте.</p>
        <a class="link" href="/api/news.php?limit=3" target="_blank" rel="noopener">Открыть API новостей →</a>
    <?php else: ?>
        <div class="note">Токен сообщества VK не подходит для чтения стены методом <b>wall.get</b>. Поэтому импорт через токен отключён. Для новых публикаций используем Callback API — он уже настроен на <b>/vk-callback.php</b>.</div>
        <p>Нажмите кнопку ниже один раз. Затем в VK откройте «Callback API → Типы событий» и включите событие добавления записи на стену.</p>
        <form method="post">
            <button class="button" type="submit">Активировать приём новых постов</button>
        </form>
        <p class="small">Последние старые записи импортируем отдельно без токена сообщества.</p>
    <?php endif; ?>
</main>
</body>
</html>
