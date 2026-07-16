<?php

declare(strict_types=1);

require __DIR__ . '/src/vk.php';

$lockFile = PROJECT_ROOT . '/storage/vk-setup.lock';
$success = false;
$error = '';
$result = null;

if (is_file($lockFile)) {
    $success = true;
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $token = trim((string) ($_POST['vk_token'] ?? ''));
        if ($token === '') {
            throw new RuntimeException('Вставьте токен VK');
        }

        setEnvFileValue('VK_ACCESS_TOKEN', $token);
        setEnvFileValue('VK_GROUP_DOMAIN', 'razdvatakt');
        setEnvFileValue('VK_API_VERSION', '5.199');

        $result = syncVkPosts(3);
        if (($result['published'] ?? 0) < 1) {
            throw new RuntimeException('VK подключён, но ни один пост не был импортирован. Проверьте права токена.');
        }

        if (!is_dir(dirname($lockFile))) {
            @mkdir(dirname($lockFile), 0775, true);
        }
        file_put_contents($lockFile, date(DATE_ATOM), LOCK_EX);
        $success = true;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        appLog('error', 'VK browser setup failed', ['error' => $error]);
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
        *{box-sizing:border-box}body{margin:0;background:#f4f5f7;color:#111;font-family:Inter,Arial,sans-serif;min-height:100vh;display:grid;place-items:center;padding:24px}.card{width:min(640px,100%);background:#fff;border:1px solid #e5e7eb;border-radius:24px;padding:36px;box-shadow:0 24px 80px rgba(0,0,0,.08)}h1{font-size:36px;line-height:1.05;margin:0 0 14px;letter-spacing:-.04em}p{color:#666;line-height:1.5;margin:0 0 24px}.field{display:block;width:100%;min-height:54px;padding:14px 16px;border:1px solid #d8dbe0;border-radius:14px;font:inherit;margin-bottom:14px}.button{width:100%;min-height:54px;border:0;border-radius:14px;background:#ff3434;color:#fff;font:600 17px/1 Inter,Arial,sans-serif;cursor:pointer}.error{padding:14px 16px;border-radius:12px;background:#fff0f0;color:#a40000;margin-bottom:18px}.ok{padding:22px;border-radius:16px;background:#effaf3;color:#146c35;margin-bottom:18px}.link{display:inline-flex;margin-top:8px;color:#111;font-weight:600}.small{font-size:14px;color:#888;margin-top:14px}
    </style>
</head>
<body>
<main class="card">
<?php if ($success): ?>
    <div class="ok">VK подключён. Последние публикации импортированы.</div>
    <h1>Готово</h1>
    <p>Блок на Tilda теперь получает новости из сообщества VK. Кнопка «Подробнее» ведёт на исходную запись VK.</p>
    <a class="link" href="/api/news.php?limit=3" target="_blank" rel="noopener">Открыть API новостей →</a>
    <p class="small">Страница настройки автоматически заблокирована после успешного подключения.</p>
<?php else: ?>
    <h1>Подключить VK</h1>
    <p>Вставьте ключ доступа сообщества «ТАКТ Девелопмент». После нажатия система проверит доступ, скачает медиа и импортирует последние три поста.</p>
    <?php if ($error !== ''): ?><div class="error"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
        <input class="field" type="password" name="vk_token" placeholder="Токен VK" required autofocus>
        <button class="button" type="submit">Подключить и импортировать</button>
    </form>
<?php endif; ?>
</main>
</body>
</html>
