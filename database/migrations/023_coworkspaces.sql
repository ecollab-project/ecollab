-- ============================================================
-- Ecollab persistent Coworkspaces
-- A Coworkspace is a persistent collaboration container owned by
-- a host, with public/private visibility, members, roles and tools.
-- ============================================================

CREATE TABLE IF NOT EXISTS collab_workspaces (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel_id        INT UNSIGNED    NOT NULL,
    name              VARCHAR(200)    NOT NULL,
    visibility        ENUM('public','private') NOT NULL DEFAULT 'public',
    host_id           INT UNSIGNED    NOT NULL,
    allow_create_documents TINYINT(1) NOT NULL DEFAULT 1,
    allow_edit_documents   TINYINT(1) NOT NULL DEFAULT 1,
    allow_whiteboard       TINYINT(1) NOT NULL DEFAULT 1,
    allow_member_invites   TINYINT(1) NOT NULL DEFAULT 0,
    archived          TINYINT(1) NOT NULL DEFAULT 0,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_channel_visibility (channel_id, visibility, archived),
    KEY idx_host (host_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS collab_workspace_members (
    workspace_id BIGINT UNSIGNED NOT NULL,
    user_id      INT UNSIGNED    NOT NULL,
    role         ENUM('host','editor','member','viewer') NOT NULL DEFAULT 'member',
    joined_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (workspace_id, user_id),
    KEY idx_user (user_id),
    KEY idx_workspace_role (workspace_id, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS collab_workspace_access_requests (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    workspace_id BIGINT UNSIGNED NOT NULL,
    user_id      INT UNSIGNED    NOT NULL,
    status       ENUM('pending','approved','denied') NOT NULL DEFAULT 'pending',
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_workspace_user (workspace_id, user_id),
    KEY idx_workspace_status (workspace_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Documents remain channel-compatible for existing data. New documents may
-- additionally belong to a Coworkspace through this nullable foreign key.
ALTER TABLE collab_documents
    ADD COLUMN workspace_id BIGINT UNSIGNED NULL AFTER channel_id,
    ADD KEY idx_workspace_updated (workspace_id, updated_at);

-- Whiteboards are still channel-scoped by design; the workspace layer controls
-- access to the whiteboard when opened from a Coworkspace.
