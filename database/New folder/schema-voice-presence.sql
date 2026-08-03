-- ── Voice presence tracking ────────────────────────────────────────────────
-- Run after schema-chat-addon.sql

-- Add voice_channel_id to track which voice channel a user is currently in
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `voice_channel_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `last_active_at`,
  ADD CONSTRAINT IF NOT EXISTS `fk_user_voice_channel` 
    FOREIGN KEY (`voice_channel_id`) REFERENCES `channels` (`id`) ON DELETE SET NULL;

-- ── WebRTC signaling log (optional, for debugging) ─────────────────────────
-- No table needed — signaling goes through WebSocket memory only
