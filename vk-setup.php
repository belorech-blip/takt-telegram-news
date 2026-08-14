<?php

declare(strict_types=1);

require __DIR__ . '/src/vk-service.php';

$error = '';
$success = false;
$result = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $appId = trim((string) ($_POST['vk_app_id'] ?? ''));
        $serviceToken = trim((string) ($_POST['vk_service_token'] ?? ''));

        if ($appId === '' || !ctype_digit($appId)) {
            throw new RuntimeException('Укажите ID приложения VK');
        }
        if ($serviceToken === '') {
            throw new RuntimeException('Вставьте сервисный ключ доступа VK');
        }

        ensureVkSchema();
        setEnvFileValue('VK_APP_ID', $appId);
        setEnvFileValue('VK_SERVICE_TOKEN', $serviceToken);
        setEnvFileValue('VK_GROUP_ID', '231882067');
        setEnvFileValue('VK_GROUP_DOMAIN', 'razdvatakt');
        setEnvFileValue('VK_API_VERSION', '5.199');

        // Проверяем именно тот метод, который нужен для автоматического чтения последних записей.
        $probe = vkServiceApi('wall.get', [
            'owner_id' => -231882067,
            'count' => 3,
            'filter' => 'owner',
            'extended' => 0,
        ]);

        if (!is_array($probe['items'] ?? null) || $probe['items'] === []) {
            throw new RuntimeException('VK принял ключ, но не вернул записи стены');
        }

        $result = syncVkServicePosts(3);
        if (($result['published'] ?? 0) < 1) {
            throw new RuntimeException('VK подключён, но последние записи не удалось сохранить');
        }

        $success = true;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        appLog('error', 'VK service setup failed', ['error' => $error]);
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
        *{box-sizing:border-box}body{margin:0;background:#f4f5f7;color:#111;font-family:Inter,Arial,sans-serif;min-height:100vh;display:grid;place-items:center;padding:24px}.card{width:min(720px,100%);background:#fff;border:1px solid #e5e7eb;border-radius:24px;padding:36px;box-shadow:0 24px 80px rgba(0,0,0,.08)}h1{font-size:36px;line-height:1.05;margin:0 0 14px;letter-spacing:-.04em}p{color:#666;line-height:1.55;margin:0 0 20px}.field{display:block;width:100%;min-height:54px;padding:14px 16px;border:1px solid #d8dbe0;border-radius:14px;font:inherit;margin-bottom:12px}.button{width:100%;min-height:54px;border:0;border-radius:14px;background:#ff3434;color:#fff;font:600 17px/1 Inter,Arial,sans-serif;cursor:pointer}.error{padding:14px 16px;border-radius:12px;background:#fff0f0;color:#a40000;margin-bottom:18px}.ok{padding:18px;border-radius:16px;background:#effaf3;color:#146c35;margin-bottom:18px}.note{padding:18px;border-radius:16px;background:#f6f7f9;color:#333;margin-bottom:20px}.link{display:inline-flex;margin-top:8px;color:#111;font-weight:600}.small{font-size:14px;color:#888;margin-top:14px}code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.92em}
    </style>
</head>
<body>
<main class="card">
    <h1>Синхронизация VK</h1>
    <?php if ($error !== ''): ?><div class="error"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>

    <?php if ($success): ?>
        <div class="ok">Готово. Сайт теперь сам сверяет последние записи стены VK и выводит актуальные три.</div>
        <p>При открытии блока API проверяет VK не чаще одного раза в минуту. Текст, дата, фотографии и галереи сохраняются на нашем сервере. Callback API остаётся включён как быстрый канал для новых публикаций.</p>
        <a class="link" href="/api/news.php?limit=3" target="_blank" rel="noopener">Открыть API последних трёх →</a>
        <?php if (is_array($result)): ?>
            <p class="small">Получено: <?= (int) ($result['received'] ?? 0) ?> · сохранено: <?= (int) ($result['published'] ?? 0) ?></p>
        <?php endif; ?>
    <?php else: ?>
        <div class="note"><b>Нужен не ключ сообщества.</b> Для чтения текущей стены используется сервисный ключ отдельного приложения VK. Это позволяет системе при каждом запуске самой определить, какие три записи сейчас последние.</div>
        <p>Введите ID приложения и его сервисный ключ доступа. После сохранения система сразу прочитает стену <code>razdvatakt</code>, перезапишет неудачные пустые импорты реальными данными и скачает медиа на наш хостинг.</p>
        <form method="post" autocomplete="off">
            <input class="field" type="text" inputmode="numeric" name="vk_app_id" placeholder="ID приложения VK" required>
            <input class="field" type="password" name="vk_service_token" placeholder="Сервисный ключ доступа VK" required>
            <button class="button" type="submit">Подключить и загрузить последние 3</button>
        </form>
        <p class="small">Ключ сохраняется только в серверном <code>.env</code> и не отправляется в Tilda.</p>
    <?php endif; ?>
</main>
</body>
</html>
