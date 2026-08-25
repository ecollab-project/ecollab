-- Reconcile the user_settings table created by migration 002 with the
-- additional settings expected by migration 021 and API/profile/settings.php.
--
-- Migration 021 used CREATE TABLE IF NOT EXISTS. Because migration 002 already
-- creates user_settings, 021 is a no-op on correctly ordered installations.
-- This migration is intentionally additive: it preserves the legacy columns
-- (including allow_dm) while adding the settings required by the Settings UI.

ALTER TABLE user_settings
    ADD COLUMN connection_requests TINYINT(1) NOT NULL DEFAULT 1 AFTER user_id,
    ADD COLUMN direct_messages TINYINT(1) NOT NULL DEFAULT 1 AFTER connection_requests,
    ADD COLUMN activity_status TINYINT(1) NOT NULL DEFAULT 1 AFTER direct_messages,
    ADD COLUMN read_receipts TINYINT(1) NOT NULL DEFAULT 1 AFTER activity_status,
    ADD COLUMN screenshot_alerts TINYINT(1) NOT NULL DEFAULT 1 AFTER read_receipts,
    ADD COLUMN ai_matching TINYINT(1) NOT NULL DEFAULT 1 AFTER screenshot_alerts,
    ADD COLUMN profile_visibility ENUM('everyone','servers','connections') NOT NULL DEFAULT 'everyone' AFTER ai_matching,
    ADD COLUMN avatar_gradient VARCHAR(32) NOT NULL DEFAULT '#a855f7,#ec4899' AFTER profile_visibility,
    ADD COLUMN compact_mode TINYINT(1) NOT NULL DEFAULT 0 AFTER theme,
    ADD COLUMN reduce_motion TINYINT(1) NOT NULL DEFAULT 0 AFTER compact_mode,
    ADD COLUMN high_contrast TINYINT(1) NOT NULL DEFAULT 0 AFTER reduce_motion,
    ADD COLUMN screen_reader_mode TINYINT(1) NOT NULL DEFAULT 0 AFTER high_contrast,
    ADD COLUMN notification_desktop TINYINT(1) NOT NULL DEFAULT 1 AFTER screen_reader_mode,
    ADD COLUMN notification_messages TINYINT(1) NOT NULL DEFAULT 1 AFTER notification_desktop,
    ADD COLUMN notification_mentions TINYINT(1) NOT NULL DEFAULT 1 AFTER notification_messages,
    ADD COLUMN notification_matches TINYINT(1) NOT NULL DEFAULT 1 AFTER notification_mentions,
    ADD COLUMN notification_sound TINYINT(1) NOT NULL DEFAULT 1 AFTER notification_matches,
    ADD COLUMN input_device VARCHAR(255) NULL AFTER notification_sound,
    ADD COLUMN output_device VARCHAR(255) NULL AFTER input_device,
    ADD COLUMN mic_volume TINYINT UNSIGNED NOT NULL DEFAULT 100 AFTER output_device,
    ADD COLUMN output_volume TINYINT UNSIGNED NOT NULL DEFAULT 100 AFTER mic_volume,
    ADD COLUMN noise_suppression TINYINT(1) NOT NULL DEFAULT 1 AFTER output_volume,
    ADD COLUMN echo_cancellation TINYINT(1) NOT NULL DEFAULT 1 AFTER noise_suppression,
    ADD COLUMN auto_gain_control TINYINT(1) NOT NULL DEFAULT 1 AFTER echo_cancellation;
