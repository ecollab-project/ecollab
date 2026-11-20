-- Move temporary-room bootstrap into a release migration for WebSocket workers.
CREATE TABLE IF NOT EXISTS temporary_voice_rooms (
 channel_id INT UNSIGNED NOT NULL PRIMARY KEY,
 server_id INT UNSIGNED NOT NULL,
 owner_id BIGINT UNSIGNED NOT NULL,
 privacy ENUM('public','private') NOT NULL DEFAULT 'public',
 empty_since DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 expires_at DATETIME NULL,
 KEY idx_temp_voice_empty (empty_since), KEY idx_temp_voice_server (server_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
