-- Additive preferences so older code can still run after application rollback.
SET @custom_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_settings' AND COLUMN_NAME='custom_palette');
SET @sql := IF(@custom_exists=0, 'ALTER TABLE user_settings ADD COLUMN custom_palette VARCHAR(39) NOT NULL DEFAULT ''''', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @layout_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_settings' AND COLUMN_NAME='workspace_layout');
SET @sql := IF(@layout_exists=0, 'ALTER TABLE user_settings ADD COLUMN workspace_layout VARCHAR(300) NOT NULL DEFAULT ''''', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
