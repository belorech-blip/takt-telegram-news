# TAKT Telegram News

Готовый проект для автоматического переноса публикаций из Telegram-канала `@razdvatakt` в блок новостей на сайте Tilda.

Поддерживаются:

- одна фотография;
- одно видео;
- галерея фотографий;
- смешанная галерея из фотографий и видео.

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
│   ├── import-public-channel.php
│   ├── migrate.php
│   └── set-webhook.php
├── database/
│   ├── migrations/
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

2. Скопировать `.env.example` в `.env` и заполнить токен Telegram-бота, секрет webhook и доступы к базе.

3. Создать MySQL-базу и импортировать:

```text
database/schema.sql
```

4. Проверить:

```text
https://new.devtakt.ru/health.php
```

5. Установить webhook:

```bash
php scripts/set-webhook.php
```

Webhook использует одно параллельное соединение, чтобы элементы одного Telegram-альбома сохранялись в правильном порядке.

## Обновление существующей установки

```bash
git pull origin main
php scripts/migrate.php
php scripts/set-webhook.php
```

## Импорт последних старых публикаций

Например, последние три публикации публичного канала:

```bash
php scripts/import-public-channel.php 3
```

Импортёр копирует доступные фотографии и видео с публичной страницы Telegram на собственный хостинг. Его можно запускать повторно: существующие записи обновятся без создания дублей.

## API

```text
https://new.devtakt.ru/api/news.php
https://new.devtakt.ru/api/news.php?limit=3
```

Каждая новость содержит:

- `media_type`: `none`, `image`, `video`, `gallery` или `mixed`;
- `primary_media`: первое медиа для обложки карточки;
- `media`: полный массив фотографий и видео.

## Требования

- PHP 8.2+
- MySQL 8+ или MariaDB 10.5+
- HTTPS
- PHP extensions: `curl`, `pdo_mysql`, `json`, `mbstring`, `fileinfo`, `dom`

## Безопасность

- `.env` не хранится в GitHub.
- Токен Telegram-бота не должен попадать в исходный код.
- `.htaccess` запрещает доступ к `src`, `storage`, `scripts`, `database` и конфигурационным файлам.
