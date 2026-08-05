-- ============================================================
-- Ecollab Chat — Additive Schema (compatible with schema.txt)
-- Run AFTER the main schema.txt migration.
-- All tables use IF NOT EXISTS to be safe on re-runs.
-- ============================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ── message_reactions ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS `message_reactions` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id` BIGINT UNSIGNED NOT NULL,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `emoji`      VARCHAR(12)     NOT NULL,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_msg_user_emoji` (`message_id`, `user_id`, `emoji`),
  KEY `idx_message_id` (`message_id`),
  KEY `idx_user_id`    (`user_id`),
  CONSTRAINT `fk_mr_message` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mr_user`    FOREIGN KEY (`user_id`)    REFERENCES `users`    (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── message_attachments ────────────────────────────────────
CREATE TABLE IF NOT EXISTS `message_attachments` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id`  BIGINT UNSIGNED NOT NULL,
  `file_name`   VARCHAR(255)    NOT NULL,
  `file_path`   VARCHAR(512)    NOT NULL,
  `file_size`   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `mime_type`   VARCHAR(127)    NOT NULL DEFAULT 'application/octet-stream',
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_message_id` (`message_id`),
  CONSTRAINT `fk_ma_message` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── message_reads ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `message_reads` (
  `user_id`      BIGINT UNSIGNED NOT NULL,
  `channel_id`   INT UNSIGNED    NOT NULL,
  `last_read_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `channel_id`),
  KEY `idx_channel_id` (`channel_id`),
  CONSTRAINT `fk_mrd_user`    FOREIGN KEY (`user_id`)    REFERENCES `users`    (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mrd_channel` FOREIGN KEY (`channel_id`) REFERENCES `channels` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── whiteboards ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `whiteboards` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `channel_id`  INT UNSIGNED    NOT NULL,
  `state_json`  LONGTEXT        NOT NULL,
  `created_by`  BIGINT UNSIGNED NOT NULL,
  `updated_by`  BIGINT UNSIGNED     NULL DEFAULT NULL,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_channel_id` (`channel_id`),
  CONSTRAINT `fk_wb_channel`     FOREIGN KEY (`channel_id`) REFERENCES `channels` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wb_created_by`  FOREIGN KEY (`created_by`) REFERENCES `users`    (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wb_updated_by`  FOREIGN KEY (`updated_by`) REFERENCES `users`    (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Columns that may be missing from messages table ────────
ALTER TABLE `messages`
  MODIFY COLUMN `content_type` ENUM('text','image','file','code','poll') NOT NULL DEFAULT 'text',
  ADD COLUMN IF NOT EXISTS `is_pinned`    TINYINT(1)       NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `is_edited`    TINYINT(1)       NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `reaction_count` INT UNSIGNED   NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `deleted_at`   DATETIME             NULL DEFAULT NULL;

-- ── Columns that may be missing from channels table ────────
ALTER TABLE `channels`
  ADD COLUMN IF NOT EXISTS `is_locked`   TINYINT(1)  NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `member_count` INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `created_by`  BIGINT UNSIGNED NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `position`    INT          NOT NULL DEFAULT 0;

-- ── is_online / last_active_at on users ───────────────────
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `is_online`       TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `last_active_at`  DATETIME       NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `avatar_color_gradient` VARCHAR(64) NOT NULL DEFAULT '#a855f7,#ec4899';

SET foreign_key_checks = 1;
