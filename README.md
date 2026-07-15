# TAKT Telegram News

Готовый проект для автоматического переноса публикаций из Telegram-канала `@razdvatakt` в блок новостей на сайте Tilda.

## Структура для ISPmanager

```text
takt-telegram-news/
├── api/
│   └── news.php
├── media/                  ← сюда автоматически сохраняются фото и видео
├── src/
│   └── bootstrap.php
├── storage/
│   └── logs/
├── scripts/
│   └── set-webhook.php
├── database/
│   └── schema.sql
├── .htaccess
├── .env.example
├── .gitignore
├── health.php
├── index.php
├── webhook.php
└── README.md
```

Проект рассчитан на стандартную корневую директорию ISPmanager:

```text
/www/new.devtakt.ru/
```

Корневую директорию сайта в панели менять не требуется. Всё содержимое репозитория загружается непосредственно в `/www/new.devtakt.ru/`.

## Развёртывание

1. Загрузить всё содержимое репозитория в:

```text
/www/new.devtakt.ru/
```

2. Скопировать `.env.example` в `.env` и заполнить:

- токен Telegram-бота;
- секрет webhook;
- имя базы данных;
- пользователя базы;
- пароль базы.

Пути к медиа указывать не требуется. Проект автоматически использует:

```text
/www/new.devtakt.ru/media
https://new.devtakt.ru/media
```

3. Создать MySQL-базу и импортировать:

```text
database/schema.sql
```

4. Проверить:

```text
https://new.devtakt.ru/health.php
```

5. Установить webhook через SSH:

```bash
php scripts/set-webhook.php
```

## Адреса

```text
https://new.devtakt.ru/health.php
https://new.devtakt.ru/webhook.php
https://new.devtakt.ru/api/news.php
```

## Требования

- PHP 8.2+
- MySQL 8+ или MariaDB 10.5+
- HTTPS
- PHP extensions: `curl`, `pdo_mysql`, `json`, `mbstring`, `fileinfo`

## Безопасность

- `.env` не хранится в GitHub.
- Токен Telegram-бота не должен попадать в исходный код.
- `.htaccess` запрещает доступ к `src`, `storage`, `scripts`, `database` и конфигурационным файлам.
