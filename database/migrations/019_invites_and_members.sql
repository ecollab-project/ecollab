-- Ecollab Phase 4.6: server/channel invites and member management
-- Safe to run repeatedly.

CREATE TABLE IF NOT EXISTS server_invites (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    server_id INT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    max_uses INT UNSIGNED NOT NULL DEFAULT 0,
    use_count INT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_server_invite_token (token_hash),
    KEY idx_server_invites_server (server_id),
    KEY idx_server_invites_active (server_id, revoked_at, expires_at),
    CONSTRAINT fk_server_invites_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
    CONSTRAINT fk_server_invites_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS channel_invites (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel_id INT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    max_uses INT UNSIGNED NOT NULL DEFAULT 0,
    use_count INT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_channel_invite_token (token_hash),
    KEY idx_channel_invites_channel (channel_id),
    KEY idx_channel_invites_active (channel_id, revoked_at, expires_at),
    CONSTRAINT fk_channel_invites_channel FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE,
    CONSTRAINT fk_channel_invites_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
