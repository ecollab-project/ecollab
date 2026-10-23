-- Additive replacement for former request-time DDL. Existing rows are retained.
CREATE TABLE IF NOT EXISTS jarred_pending_actions (
            user_id BIGINT UNSIGNED NOT NULL,
            conversation_id BIGINT UNSIGNED NOT NULL,
            action_type VARCHAR(50) NOT NULL,
            step VARCHAR(50) NOT NULL,
            server_id INT UNSIGNED NOT NULL,
            payload_json TEXT NULL,
            expires_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, conversation_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS temporary_voice_rooms (
            channel_id INT UNSIGNED NOT NULL PRIMARY KEY,
            server_id INT UNSIGNED NOT NULL,
            owner_id BIGINT UNSIGNED NOT NULL,
            privacy ENUM('public','private') NOT NULL DEFAULT 'public',
            empty_since DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NULL,
            KEY idx_temp_voice_empty (empty_since),
            KEY idx_temp_voice_server (server_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
