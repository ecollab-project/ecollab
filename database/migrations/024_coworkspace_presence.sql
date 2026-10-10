-- ============================================================
-- 024_coworkspace_presence.sql
-- Live presence for persistent Coworkspaces.
-- A row is active while last_seen_at is within the heartbeat window.
-- ============================================================

CREATE TABLE IF NOT EXISTS collab_workspace_presence (
    workspace_id BIGINT UNSIGNED NOT NULL,
    user_id      INT UNSIGNED NOT NULL,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (workspace_id, user_id),
    KEY idx_workspace_seen (workspace_id, last_seen_at),
    KEY idx_user_seen (user_id, last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
