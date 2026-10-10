-- ============================================================
-- Resource-level access for Coworkspace documents and whiteboards
-- ============================================================

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=DATABASE() AND table_name='collab_documents' AND column_name='visibility');
SET @sql := IF(@col_exists=0,'ALTER TABLE collab_documents ADD COLUMN visibility ENUM(''public'',''private'') NOT NULL DEFAULT ''public'' AFTER workspace_id','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=DATABASE() AND table_name='collab_whiteboards' AND column_name='visibility');
SET @sql := IF(@col_exists=0,'ALTER TABLE collab_whiteboards ADD COLUMN visibility ENUM(''public'',''private'') NOT NULL DEFAULT ''public'' AFTER workspace_id','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS collab_resource_permissions (
    resource_type ENUM('document','whiteboard') NOT NULL,
    resource_id BIGINT UNSIGNED NOT NULL,
    workspace_id BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    permission ENUM('view','edit') NOT NULL DEFAULT 'view',
    granted_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (resource_type,resource_id,user_id),
    KEY idx_resource (resource_type,resource_id),
    KEY idx_workspace_user (workspace_id,user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS collab_resource_invites (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    resource_type ENUM('document','whiteboard') NOT NULL,
    resource_id BIGINT UNSIGNED NOT NULL,
    workspace_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    permission ENUM('view','edit') NOT NULL DEFAULT 'view',
    created_by INT UNSIGNED NOT NULL,
    expires_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_token_hash (token_hash),
    KEY idx_resource (resource_type,resource_id),
    KEY idx_workspace (workspace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
