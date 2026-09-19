-- ============================================================
-- 031_message_bookmarks.sql
-- ============================================================
-- Personal, per-user message bookmarks. The "Bookmarks" sidebar
-- nav view previously substituted channel-wide pinned messages
-- (is_pinned=1) as a stand-in — that's shared/moderation data, not
-- a personal save-for-later list, and the code said as much in its
-- own comment. This adds the real table.
-- ============================================================

CREATE TABLE IF NOT EXISTS message_bookmarks (
    user_id    BIGINT UNSIGNED NOT NULL,
    message_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, message_id),
    KEY idx_mb_user (user_id, created_at),
    CONSTRAINT fk_mb_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_mb_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
