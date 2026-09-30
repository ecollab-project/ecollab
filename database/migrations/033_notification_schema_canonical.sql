-- ============================================================
-- 033_notification_schema_canonical.sql
-- ============================================================
-- Canonical notification schema is the one defined by migration 002:
-- recipient_id, actor_id, type, title, body, link_url, icon,
-- is_read, created_at, read_at.
--
-- Migration 008 historically created a conflicting user_id/ref_id schema.
-- This migration safely normalizes installations that ended up with that
-- legacy shape without changing already-correct databases.
--
-- IMPORTANT: Guarded dynamic statements use DO 0 instead of SELECT 1.
-- SELECT 1 leaves a result set behind when executed through PDO, which
-- causes MySQL/MariaDB error 2014 when the migration runner prepares the
-- next statement on the same connection.
-- ============================================================

SET @notif_has_user_id = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'user_id'
);
SET @notif_has_recipient_id = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'recipient_id'
);

SET @notif_sql = IF(
    @notif_has_user_id = 1 AND @notif_has_recipient_id = 0,
    'ALTER TABLE notifications CHANGE COLUMN user_id recipient_id BIGINT UNSIGNED NOT NULL',
    'DO 0'
);
PREPARE notif_stmt FROM @notif_sql;
EXECUTE notif_stmt;
DEALLOCATE PREPARE notif_stmt;

SET @notif_has_user_id = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'user_id'
);
SET @notif_has_recipient_id = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'recipient_id'
);

SET @notif_sql = IF(
    @notif_has_user_id = 1 AND @notif_has_recipient_id = 1,
    'ALTER TABLE notifications DROP COLUMN user_id',
    'DO 0'
);
PREPARE notif_stmt FROM @notif_sql;
EXECUTE notif_stmt;
DEALLOCATE PREPARE notif_stmt;

SET @notif_has_actor = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'actor_id'
);
SET @notif_sql = IF(
    @notif_has_actor = 0,
    'ALTER TABLE notifications ADD COLUMN actor_id BIGINT UNSIGNED NULL AFTER recipient_id',
    'DO 0'
);
PREPARE notif_stmt FROM @notif_sql;
EXECUTE notif_stmt;
DEALLOCATE PREPARE notif_stmt;

SET @notif_has_link = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'link_url'
);
SET @notif_sql = IF(
    @notif_has_link = 0,
    'ALTER TABLE notifications ADD COLUMN link_url VARCHAR(255) NULL AFTER body',
    'DO 0'
);
PREPARE notif_stmt FROM @notif_sql;
EXECUTE notif_stmt;
DEALLOCATE PREPARE notif_stmt;

SET @notif_has_icon = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'icon'
);
SET @notif_sql = IF(
    @notif_has_icon = 0,
    'ALTER TABLE notifications ADD COLUMN icon VARCHAR(10) NOT NULL DEFAULT ''🔔'' AFTER link_url',
    'DO 0'
);
PREPARE notif_stmt FROM @notif_sql;
EXECUTE notif_stmt;
DEALLOCATE PREPARE notif_stmt;

SET @notif_has_read_at = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'read_at'
);
SET @notif_sql = IF(
    @notif_has_read_at = 0,
    'ALTER TABLE notifications ADD COLUMN read_at DATETIME NULL AFTER created_at',
    'DO 0'
);
PREPARE notif_stmt FROM @notif_sql;
EXECUTE notif_stmt;
DEALLOCATE PREPARE notif_stmt;

-- Keep legacy ref_id when it exists. The canonical API no longer depends on it,
-- but dropping it would destroy historical notification reference data.
