-- 029_user_settings_missing_columns.sql
-- Idempotent repair for columns expected by the current settings code.
-- IMPORTANT: this migration intentionally contains no MySQL DELIMITER blocks;
-- the Ecollab migration runner executes one SQL statement at a time.

ALTER TABLE user_settings ADD COLUMN IF NOT EXISTS `connection_requests` TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE user_settings ADD COLUMN IF NOT EXISTS `direct_messages` TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE user_settings ADD COLUMN IF NOT EXISTS `activity_status` TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE user_settings ADD COLUMN IF NOT EXISTS `read_receipts` TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE user_settings ADD COLUMN IF NOT EXISTS `screenshot_alerts` TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE user_settings ADD COLUMN IF NOT EXISTS `ai_matching` TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE user_settings ADD COLUMN IF NOT EXISTS `profile_visibility` ENUM('everyone','servers','connections') NOT NULL DEFAULT 'everyone';
ALTER TABLE user_settings ADD COLUMN IF NOT EXISTS `avatar_gradient` VARCHAR(32) NOT NULL DEFAULT '#a855f7,#ec4899';
ALTER TABLE user_settings ADD COLUMN IF NOT EXISTS `compact_mode` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE user_settings ADD COLUMN IF NOT EXISTS `reduce_motion` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE user_settings ADD COLUMN IF NOT EXISTS `high_contrast` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE user_settings ADD COLUMN IF NOT EXISTS `screen_reader_mode` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE user_settings ADD COLUMN IF NOT EXISTS `notification_desktop` TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE user_settings ADD COLUMN IF NOT EXISTS `notification_messages` TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE user_settings ADD COLUMN IF NOT EXISTS `notification_mentions` TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE user_settings ADD COLUMN IF NOT EXISTS `notification_matches` TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE user_settings ADD COLUMN IF NOT EXISTS `notification_sound` TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE user_settings ADD COLUMN IF NOT EXISTS `input_device` VARCHAR(255) NULL;
ALTER TABLE user_settings ADD COLUMN IF NOT EXISTS `output_device` VARCHAR(255) NULL;
ALTER TABLE user_settings ADD COLUMN IF NOT EXISTS `mic_volume` TINYINT UNSIGNED NOT NULL DEFAULT 100;
ALTER TABLE user_settings ADD COLUMN IF NOT EXISTS `output_volume` TINYINT UNSIGNED NOT NULL DEFAULT 100;
ALTER TABLE user_settings ADD COLUMN IF NOT EXISTS `noise_suppression` TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE user_settings ADD COLUMN IF NOT EXISTS `echo_cancellation` TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE user_settings ADD COLUMN IF NOT EXISTS `auto_gain_control` TINYINT(1) NOT NULL DEFAULT 1;
