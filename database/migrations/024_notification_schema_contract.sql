-- ============================================================
-- 024_notification_schema_contract.sql
-- ============================================================
-- Canonical notification contract for Ecollab.
--
-- Current application code uses:
--   recipient_id, actor_id, type, title, body, link_url,
--   is_read, created_at, read_at
--
-- Older migration 008 created user_id/ref_id and allowed arbitrary
-- notification types. This migration upgrades those installations
-- without deleting the legacy columns, so existing data remains safe.
-- ============================================================

SET @has_recipient_id := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'notifications'
      AND column_name = 'recipient_id'
);
SET @sql := IF(
    @has_recipient_id = 0,
    'ALTER TABLE notifications ADD COLUMN recipient_id BIGINT UNSIGNED NULL AFTER id',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_actor_id := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'notifications'
      AND column_name = 'actor_id'
);
SET @sql := IF(
    @has_actor_id = 0,
    'ALTER TABLE notifications ADD COLUMN actor_id BIGINT UNSIGNED NULL AFTER recipient_id',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_link_url := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'notifications'
      AND column_name = 'link_url'
);
SET @sql := IF(
    @has_link_url = 0,
    'ALTER TABLE notifications ADD COLUMN link_url VARCHAR(255) NULL AFTER body',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_read_at := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'notifications'
      AND column_name = 'read_at'
);
SET @sql := IF(
    @has_read_at = 0,
    'ALTER TABLE notifications ADD COLUMN read_at DATETIME NULL AFTER created_at',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Copy the old recipient column into the canonical column when the legacy
-- user_id column exists. On the current schema this simply updates no rows.
SET @has_user_id := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'notifications'
      AND column_name = 'user_id'
);
SET @sql := IF(
    @has_user_id = 1,
    'UPDATE notifications SET recipient_id = user_id WHERE recipient_id IS NULL',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Legacy DM notifications used type=dm. Canonical type is message.
UPDATE notifications
SET type = 'message'
WHERE type = 'dm';

-- Normalize any legacy/custom values before enforcing the canonical enum.
UPDATE notifications
SET type = 'system'
WHERE type NOT IN (
    'message', 'mention', 'room_invite', 'class_update',
    'server_join', 'moderation', 'ai', 'system', 'match'
);

ALTER TABLE notifications
    MODIFY COLUMN recipient_id BIGINT UNSIGNED NOT NULL,
    MODIFY COLUMN actor_id BIGINT UNSIGNED NULL,
    MODIFY COLUMN type ENUM(
        'message', 'mention', 'room_invite', 'class_update',
        'server_join', 'moderation', 'ai', 'system', 'match'
    ) NOT NULL DEFAULT 'system',
    MODIFY COLUMN title VARCHAR(120) NOT NULL,
    MODIFY COLUMN body VARCHAR(500) NULL,
    MODIFY COLUMN link_url VARCHAR(255) NULL,
    MODIFY COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0,
    MODIFY COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    MODIFY COLUMN read_at DATETIME NULL;

SET @has_recipient_index := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE table_schema = DATABASE()
      AND table_name = 'notifications'
      AND index_name = 'idx_recipient_unread'
);
SET @sql := IF(
    @has_recipient_index = 0,
    'ALTER TABLE notifications ADD INDEX idx_recipient_unread (recipient_id, is_read, created_at)',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
