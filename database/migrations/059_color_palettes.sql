-- Appearance mode remains independent of the named color palette.
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'user_settings' AND column_name = 'color_palette');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE user_settings ADD COLUMN color_palette VARCHAR(40) NOT NULL DEFAULT ''forest-mint''', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
