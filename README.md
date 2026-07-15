# TAKT Telegram News

Готовый проект для автоматического переноса публикаций из Telegram-канала `@razdvatakt` в блок новостей на сайте Tilda.

## Готовая структура

```text
takt-telegram-news/
├── public/                 ← корневая директория сайта
│   ├── api/
│   │   └── news.php
│   ├── media/              ← сюда автоматически сохраняются фото и видео
│   ├── .htaccess
│   ├── health.php
│   ├── index.php
│   └── webhook.php
├── src/
│   └── bootstrap.php
├── storage/
│   └── logs/
├── scripts/
│   └── set-webhook.php
├── database/
│   └── schema.sql
├── .env.example
├── .gitignore
└── README.md
```

Ничего вручную переносить между папками не нужно. На сервер загружается вся папка проекта целиком.

## Развёртывание на ISPmanager

1. Загрузить всё содержимое репозитория в:

```text
/www/news.gktakt.ru/
```

2. Для сайта `news.gktakt.ru` указать корневую директорию:

```text
/www/news.gktakt.ru/public
```

3. Скопировать `.env.example` в `.env` и заполнить:

- токен Telegram-бота;
- секрет webhook;
- имя базы данных;
- пользователя базы;
- пароль базы.

Пути к медиа указывать не требуется. Проект автоматически использует:

```text
/www/news.gktakt.ru/public/media
https://news.gktakt.ru/media
```

4. Создать MySQL-базу и импортировать файл:

```text
database/schema.sql
```

5. Проверить:

```text
https://news.gktakt.ru/health.php
```

6. Установить webhook через SSH:

```bash
php scripts/set-webhook.php
```

## Адреса

```text
https://news.gktakt.ru/health.php
https://news.gktakt.ru/webhook.php
https://news.gktakt.ru/api/news.php
```

## Требования

- PHP 8.2+
- MySQL 8+ или MariaDB 10.5+
- HTTPS
- PHP extensions: `curl`, `pdo_mysql`, `json`, `mbstring`, `fileinfo`

## Безопасность

- `.env` не хранится в GitHub.
- Токен Telegram-бота не должен попадать в исходный код.
- Корнем сайта обязательно должна быть папка `public`.
