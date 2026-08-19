-- ============================================================
-- Ecollab WebSocket Relay Table
-- Managed by ChatServer::drainRelayTable() for event relay.
-- REST endpoints insert events here; ChatServer drains them.
-- ============================================================

CREATE TABLE IF NOT EXISTS ws_relay (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel_id  INT UNSIGNED    NOT NULL,
    payload     TEXT            NOT NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_channel_id (channel_id),
    KEY idx_created_at (created_at),
    CONSTRAINT fk_ws_relay_channel FOREIGN KEY (channel_id)
        REFERENCES channels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
COMMENT='WebSocket event relay: REST endpoints insert events here; ChatServer drains periodically';
