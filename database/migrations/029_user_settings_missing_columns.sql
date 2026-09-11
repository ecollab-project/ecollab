-- ============================================================
-- 029_user_settings_missing_columns.sql
-- ============================================================
-- ROOT CAUSE: 002_core_schema.sql creates `user_settings` with only
-- 10 columns (notification_msgs, theme, language, timezone, etc).
-- 021_user_settings.sql then does `CREATE TABLE IF NOT EXISTS
-- user_settings (...)` with 24 additional columns (ai_matching,
-- profile_visibility, avatar_gradient, accessibility + audio-device
-- settings, etc) — but since migration 002 already created the
-- table, 021's CREATE TABLE IF NOT EXISTS is a silent no-op on any
-- database that ran migrations in numeric order. Those 24 columns
-- were never actually added, which is why any query against them
-- (e.g. `SELECT ai_matching FROM user_settings`) throws "Unknown
-- column" and surfaces as a 500 to the client.
--
-- This migration adds every column 021 expected but 002 never
-- created, guarded via information_schema so it's safe to run
-- against a fresh install (where 021 already succeeded) or an
-- existing broken one (where it didn't). Same guarded pattern as
-- 007_voice_presence.sql.
-- ============================================================

DELIMITER $$
CREATE PROCEDURE ecollab_add_user_settings_column(
    IN p_column VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    DECLARE col_exists INT DEFAULT 0;
    SELECT COUNT(*) INTO col_exists
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'user_settings'
      AND column_name = p_column;

    IF col_exists = 0 THEN
        SET @sql = CONCAT('ALTER TABLE user_settings ADD COLUMN ', p_column, ' ', p_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

CALL ecollab_add_user_settings_column('connection_requests', "TINYINT(1) NOT NULL DEFAULT 1");
CALL ecollab_add_user_settings_column('direct_messages', "TINYINT(1) NOT NULL DEFAULT 1");
CALL ecollab_add_user_settings_column('activity_status', "TINYINT(1) NOT NULL DEFAULT 1");
CALL ecollab_add_user_settings_column('read_receipts', "TINYINT(1) NOT NULL DEFAULT 1");
CALL ecollab_add_user_settings_column('screenshot_alerts', "TINYINT(1) NOT NULL DEFAULT 1");
CALL ecollab_add_user_settings_column('ai_matching', "TINYINT(1) NOT NULL DEFAULT 1");
CALL ecollab_add_user_settings_column('profile_visibility', "ENUM('everyone','servers','connections') NOT NULL DEFAULT 'everyone'");
CALL ecollab_add_user_settings_column('avatar_gradient', "VARCHAR(32) NOT NULL DEFAULT '#a855f7,#ec4899'");
CALL ecollab_add_user_settings_column('compact_mode', "TINYINT(1) NOT NULL DEFAULT 0");
CALL ecollab_add_user_settings_column('reduce_motion', "TINYINT(1) NOT NULL DEFAULT 0");
CALL ecollab_add_user_settings_column('high_contrast', "TINYINT(1) NOT NULL DEFAULT 0");
CALL ecollab_add_user_settings_column('screen_reader_mode', "TINYINT(1) NOT NULL DEFAULT 0");
CALL ecollab_add_user_settings_column('notification_desktop', "TINYINT(1) NOT NULL DEFAULT 1");
CALL ecollab_add_user_settings_column('notification_messages', "TINYINT(1) NOT NULL DEFAULT 1");
CALL ecollab_add_user_settings_column('notification_mentions', "TINYINT(1) NOT NULL DEFAULT 1");
CALL ecollab_add_user_settings_column('notification_matches', "TINYINT(1) NOT NULL DEFAULT 1");
CALL ecollab_add_user_settings_column('notification_sound', "TINYINT(1) NOT NULL DEFAULT 1");
CALL ecollab_add_user_settings_column('input_device', "VARCHAR(255) NULL");
CALL ecollab_add_user_settings_column('output_device', "VARCHAR(255) NULL");
CALL ecollab_add_user_settings_column('mic_volume', "TINYINT UNSIGNED NOT NULL DEFAULT 100");
CALL ecollab_add_user_settings_column('output_volume', "TINYINT UNSIGNED NOT NULL DEFAULT 100");
CALL ecollab_add_user_settings_column('noise_suppression', "TINYINT(1) NOT NULL DEFAULT 1");
CALL ecollab_add_user_settings_column('echo_cancellation', "TINYINT(1) NOT NULL DEFAULT 1");
CALL ecollab_add_user_settings_column('auto_gain_control', "TINYINT(1) NOT NULL DEFAULT 1");

DROP PROCEDURE ecollab_add_user_settings_column;
