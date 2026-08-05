-- ============================================================
-- 007_voice_presence.sql
-- ============================================================
-- Adds voice_channel_id to users so the WebSocket server can
-- track which voice channel a user currently occupies.
--
-- COMPATIBILITY FIX: the original version of this file used
--   ADD CONSTRAINT IF NOT EXISTS `fk_user_voice_channel` ...
-- which is NOT valid MySQL 8 syntax (only MariaDB 10.6+ supports
-- "IF NOT EXISTS" at the constraint level). On MySQL 8 this would
-- fatal with a syntax error and abort the entire migration run.
--
-- This version uses the guarded information_schema + PREPARE/
-- EXECUTE pattern (same as 017_user_plan_id.sql) so it works
-- identically on MySQL 8+ and MariaDB 10.6+, and is safe to
-- re-run on a database that already has the column/constraint.
-- ============================================================

-- ── Add voice_channel_id column (idempotent on both engines) ──
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `voice_channel_id` INT UNSIGNED NULL DEFAULT NULL AFTER `last_active_at`;

-- ── Add index for voice channel lookups (guarded) ───────────
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE table_schema = DATABASE()
      AND table_name   = 'users'
      AND index_name   = 'idx_voice_channel'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE users ADD INDEX idx_voice_channel (voice_channel_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── Add FK constraint (guarded — works on MySQL 8 and MariaDB) ──
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE table_schema    = DATABASE()
      AND table_name      = 'users'
      AND constraint_name = 'fk_user_voice_channel'
      AND constraint_type = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE users ADD CONSTRAINT fk_user_voice_channel FOREIGN KEY (voice_channel_id) REFERENCES channels(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── WebRTC signaling: no table needed ───────────────────────
-- Signaling goes through WebSocket memory only (see
-- ChatServer::handleWebRtcSignal). This is intentional.
