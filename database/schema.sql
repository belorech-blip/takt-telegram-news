CREATE TABLE news (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    telegram_channel_id BIGINT NOT NULL,
    telegram_message_id BIGINT NOT NULL,
    telegram_post_url VARCHAR(500) NOT NULL,
    media_group_id VARCHAR(100) NULL,
    title VARCHAR(255) NOT NULL,
    body TEXT NULL,
    published_at DATETIME NOT NULL,
    status ENUM('processing','published','hidden','error','deleted') NOT NULL DEFAULT 'processing',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_news_telegram_message (telegram_channel_id, telegram_message_id),
    KEY idx_news_status_published (status, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE news_media (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    news_id BIGINT UNSIGNED NOT NULL,
    telegram_file_id VARCHAR(255) NULL,
    telegram_file_unique_id VARCHAR(255) NULL,
    media_type ENUM('image','video','animation','document') NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    original_filename VARCHAR(255) NULL,
    mime_type VARCHAR(150) NULL,
    file_size BIGINT UNSIGNED NULL,
    storage_path VARCHAR(1000) NULL,
    public_url VARCHAR(1000) NULL,
    preview_url VARCHAR(1000) NULL,
    status ENUM('pending','ready','error') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_news_media_news FOREIGN KEY (news_id) REFERENCES news(id) ON DELETE CASCADE,
    KEY idx_news_media_news_sort (news_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
