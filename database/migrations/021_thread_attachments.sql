-- Thread image attachments
CREATE TABLE IF NOT EXISTS thread_attachments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    thread_id BIGINT UNSIGNED NOT NULL,
    reply_id BIGINT UNSIGNED NULL,
    file_url VARCHAR(1000) NOT NULL,
    file_name VARCHAR(255) NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size INT UNSIGNED NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_thread_attachments_thread (thread_id),
    KEY idx_thread_attachments_reply (reply_id),
    CONSTRAINT fk_thread_attachments_thread FOREIGN KEY (thread_id) REFERENCES threads(id) ON DELETE CASCADE,
    CONSTRAINT fk_thread_attachments_reply FOREIGN KEY (reply_id) REFERENCES thread_replies(id) ON DELETE CASCADE,
    CONSTRAINT fk_thread_attachments_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
