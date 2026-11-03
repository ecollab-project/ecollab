-- Whiteboard session metadata and immutable saved versions.
SET @column_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whiteboards' AND COLUMN_NAME = 'session_title');
SET @column_sql = IF(@column_exists = 0, 'ALTER TABLE `whiteboards` ADD COLUMN `session_title` VARCHAR(200) NOT NULL DEFAULT ''Whiteboard Session''', 'SELECT 1');
PREPARE column_stmt FROM @column_sql;
EXECUTE column_stmt;
DEALLOCATE PREPARE column_stmt;

SET @column_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whiteboards' AND COLUMN_NAME = 'locked');
SET @column_sql = IF(@column_exists = 0, 'ALTER TABLE `whiteboards` ADD COLUMN `locked` TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE column_stmt FROM @column_sql;
EXECUTE column_stmt;
DEALLOCATE PREPARE column_stmt;

SET @column_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whiteboards' AND COLUMN_NAME = 'locked_by');
SET @column_sql = IF(@column_exists = 0, 'ALTER TABLE `whiteboards` ADD COLUMN `locked_by` BIGINT UNSIGNED NULL', 'SELECT 1');
PREPARE column_stmt FROM @column_sql;
EXECUTE column_stmt;
DEALLOCATE PREPARE column_stmt;

SET @column_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whiteboards' AND COLUMN_NAME = 'locked_at');
SET @column_sql = IF(@column_exists = 0, 'ALTER TABLE `whiteboards` ADD COLUMN `locked_at` DATETIME NULL', 'SELECT 1');
PREPARE column_stmt FROM @column_sql;
EXECUTE column_stmt;
DEALLOCATE PREPARE column_stmt;

CREATE TABLE IF NOT EXISTS whiteboard_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    whiteboard_id BIGINT UNSIGNED NOT NULL,
    channel_id INT UNSIGNED NOT NULL,
    version_no INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    state_json LONGTEXT NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_whiteboard_version (whiteboard_id, version_no),
    KEY idx_whiteboard_versions (whiteboard_id, created_at),
    KEY idx_channel_versions (channel_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
