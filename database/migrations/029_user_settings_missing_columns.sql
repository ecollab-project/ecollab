-- ============================================================
-- 029_user_settings_missing_columns.sql
-- ============================================================
-- ROOT CAUSE: 002_core_schema.sql creates `user_settings` with only
-- a subset of the columns later expected by 021_user_settings.sql.
-- Since 021 uses CREATE TABLE IF NOT EXISTS, it is a no-op when the
-- table already exists. This migration adds the missing columns.
--
-- IMPORTANT: Do not use MySQL/MariaDB client DELIMITER directives
-- here. database/migrate.php executes individual SQL statements by
-- splitting on semicolons, so DELIMITER/procedure syntax is not
-- compatible with the migration runner.
--
-- Each column is guarded through information_schema, making this
-- migration safe for both a fresh database and an existing database
-- that already contains some of these columns.
-- ============================================================

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'user_settings' AND column_name = 'connection_requests');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE user_settings ADD COLUMN connection_requests TINYINT(1) NOT NULL DEFAULT 1', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'user_settings' AND column_name = 'direct_messages');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE user_settings ADD COLUMN direct_messages TINYINT(1) NOT NULL DEFAULT 1', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'user_settings' AND column_name = 'activity_status');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE user_settings ADD COLUMN activity_status TINYINT(1) NOT NULL DEFAULT 1', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'user_settings' AND column_name = 'read_receipts');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE user_settings ADD COLUMN read_receipts TINYINT(1) NOT NULL DEFAULT 1', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'user_settings' AND column_name = 'screenshot_alerts');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE user_settings ADD COLUMN screenshot_alerts TINYINT(1) NOT NULL DEFAULT 1', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'user_settings' AND column_name = 'ai_matching');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE user_settings ADD COLUMN ai_matching TINYINT(1) NOT NULL DEFAULT 1', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'user_settings' AND column_name = 'profile_visibility');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE user_settings ADD COLUMN profile_visibility ENUM(''everyone'',''servers'',''connections'') NOT NULL DEFAULT ''everyone''', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'user_settings' AND column_name = 'avatar_gradient');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE user_settings ADD COLUMN avatar_gradient VARCHAR(32) NOT NULL DEFAULT ''#a855f7,#ec4899''', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'user_settings' AND column_name = 'compact_mode');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE user_settings ADD COLUMN compact_mode TINYINT(1) NOT NULL DEFAULT 0', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'user_settings' AND column_name = 'reduce_motion');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE user_settings ADD COLUMN reduce_motion TINYINT(1) NOT NULL DEFAULT 0', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'user_settings' AND column_name = 'high_contrast');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE user_settings ADD COLUMN high_contrast TINYINT(1) NOT NULL DEFAULT 0', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'user_settings' AND column_name = 'screen_reader_mode');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE user_settings ADD COLUMN screen_reader_mode TINYINT(1) NOT NULL DEFAULT 0', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'user_settings' AND column_name = 'notification_desktop');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE user_settings ADD COLUMN notification_desktop TINYINT(1) NOT NULL DEFAULT 1', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'user_settings' AND column_name = 'notification_messages');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE user_settings ADD COLUMN notification_messages TINYINT(1) NOT NULL DEFAULT 1', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'user_settings' AND column_name = 'notification_mentions');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE user_settings ADD COLUMN notification_mentions TINYINT(1) NOT NULL DEFAULT 1', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'user_settings' AND column_name = 'notification_matches');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE user_settings ADD COLUMN notification_matches TINYINT(1) NOT NULL DEFAULT 1', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'user_settings' AND column_name = 'notification_sound');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE user_settings ADD COLUMN notification_sound TINYINT(1) NOT NULL DEFAULT 1', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'user_settings' AND column_name = 'input_device');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE user_settings ADD COLUMN input_device VARCHAR(255) NULL', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'user_settings' AND column_name = 'output_device');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE user_settings ADD COLUMN output_device VARCHAR(255) NULL', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'user_settings' AND column_name = 'mic_volume');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE user_settings ADD COLUMN mic_volume TINYINT UNSIGNED NOT NULL DEFAULT 100', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'user_settings' AND column_name = 'output_volume');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE user_settings ADD COLUMN output_volume TINYINT UNSIGNED NOT NULL DEFAULT 100', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'user_settings' AND column_name = 'noise_suppression');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE user_settings ADD COLUMN noise_suppression TINYINT(1) NOT NULL DEFAULT 1', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'user_settings' AND column_name = 'echo_cancellation');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE user_settings ADD COLUMN echo_cancellation TINYINT(1) NOT NULL DEFAULT 1', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'user_settings' AND column_name = 'auto_gain_control');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE user_settings ADD COLUMN auto_gain_control TINYINT(1) NOT NULL DEFAULT 1', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
