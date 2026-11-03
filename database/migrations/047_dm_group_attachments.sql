-- DM and group-message attachments
-- Adds one optional attachment per DM/group message. Text remains optional only when an attachment exists.

SET @column_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dm_messages' AND COLUMN_NAME = 'attachment_path');
SET @column_sql = IF(@column_exists = 0, 'ALTER TABLE `dm_messages` ADD COLUMN `attachment_path` VARCHAR(500) NULL AFTER body', 'SELECT 1');
PREPARE column_stmt FROM @column_sql;
EXECUTE column_stmt;
DEALLOCATE PREPARE column_stmt;

SET @column_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dm_messages' AND COLUMN_NAME = 'attachment_name');
SET @column_sql = IF(@column_exists = 0, 'ALTER TABLE `dm_messages` ADD COLUMN `attachment_name` VARCHAR(255) NULL AFTER attachment_path', 'SELECT 1');
PREPARE column_stmt FROM @column_sql;
EXECUTE column_stmt;
DEALLOCATE PREPARE column_stmt;

SET @column_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dm_messages' AND COLUMN_NAME = 'attachment_size');
SET @column_sql = IF(@column_exists = 0, 'ALTER TABLE `dm_messages` ADD COLUMN `attachment_size` BIGINT UNSIGNED NULL AFTER attachment_name', 'SELECT 1');
PREPARE column_stmt FROM @column_sql;
EXECUTE column_stmt;
DEALLOCATE PREPARE column_stmt;

SET @column_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dm_messages' AND COLUMN_NAME = 'attachment_mime');
SET @column_sql = IF(@column_exists = 0, 'ALTER TABLE `dm_messages` ADD COLUMN `attachment_mime` VARCHAR(150) NULL AFTER attachment_size', 'SELECT 1');
PREPARE column_stmt FROM @column_sql;
EXECUTE column_stmt;
DEALLOCATE PREPARE column_stmt;
