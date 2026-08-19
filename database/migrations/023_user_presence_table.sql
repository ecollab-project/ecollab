-- ============================================================
-- Migration 023: Create unified user_presence table
-- Consolidates per-server presence state into one table.
-- ============================================================

CREATE TABLE IF NOT EXISTS user_presence (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    server_id INT UNSIGNED NOT NULL,

    status ENUM('online', 'idle', 'offline', 'dnd') NOT NULL DEFAULT 'offline',
    current_channel_id INT UNSIGNED NULL,
    voice_room_id INT UNSIGNED NULL,
    last_activity_at DATETIME NULL,
    presence_metadata JSON NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY unique_user_server (user_id, server_id),
    KEY idx_user_id (user_id),
    KEY idx_server_id (server_id),
    KEY idx_status (status),
    KEY idx_updated_at (updated_at),

    CONSTRAINT fk_user_presence_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_presence_server
        FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_presence_channel
        FOREIGN KEY (current_channel_id) REFERENCES channels(id) ON DELETE SET NULL,
    CONSTRAINT fk_user_presence_voice_room
        FOREIGN KEY (voice_room_id) REFERENCES channels(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
