-- Reconcile the settings schema created by 002_core_schema.sql with the
-- settings fields introduced by 021_user_settings.sql.
--
-- 002 creates user_settings first, so 021's CREATE TABLE IF NOT EXISTS is a
-- no-op on databases migrated in order. This migration adds the missing
-- columns without removing or renaming the legacy columns (including
-- allow_dm), which are still used by existing DM authorization code.

ALTER TABLE user_settings
    ADD COLUMN connection_requests TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN direct_messages TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN activity_status TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN read_receipts TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN screenshot_alerts TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN ai_matching TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN profile_visibility ENUM('everyone','servers','connections') NOT NULL DEFAULT 'everyone',
    ADD COLUMN avatar_gradient VARCHAR(32) NOT NULL DEFAULT '#a855f7,#ec4899',
    ADD COLUMN compact_mode TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN reduce_motion TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN high_contrast TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN screen_reader_mode TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN notification_desktop TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN notification_messages TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN notification_mentions TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN notification_matches TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN notification_sound TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN input_device VARCHAR(255) NULL,
    ADD COLUMN output_device VARCHAR(255) NULL,
    ADD COLUMN mic_volume TINYINT UNSIGNED NOT NULL DEFAULT 100,
    ADD COLUMN output_volume TINYINT UNSIGNED NOT NULL DEFAULT 100,
    ADD COLUMN noise_suppression TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN echo_cancellation TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN auto_gain_control TINYINT(1) NOT NULL DEFAULT 1;
