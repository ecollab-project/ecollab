CREATE TABLE IF NOT EXISTS realtime_outbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    kind ENUM('channel','dm','group') NOT NULL,
    target_id BIGINT UNSIGNED NOT NULL,
    message_id BIGINT UNSIGNED NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_at DATETIME NULL,
    last_error VARCHAR(200) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY event_message (kind,message_id),
    KEY pending_delivery (published_at,available_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
