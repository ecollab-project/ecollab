-- channel_access_requests: tracks users who request to join a private channel
CREATE TABLE IF NOT EXISTS channel_access_requests (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel_id  INT UNSIGNED    NOT NULL,
    user_id     BIGINT UNSIGNED NOT NULL,
    status      ENUM('pending','accepted','declined') NOT NULL DEFAULT 'pending',
    requested_at DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at  DATETIME       NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_channel_user (channel_id, user_id),
    KEY idx_channel (channel_id),
    KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Access requests for private channels';
