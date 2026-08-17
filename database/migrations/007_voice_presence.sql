-- ============================================================
-- 007_voice_presence.sql
-- ============================================================
-- Adds voice_channel_id to users so the WebSocket server can
-- track which voice channel a user currently occupies.
--
-- Compatibility: MySQL has no ADD CONSTRAINT IF NOT EXISTS, so the
-- foreign key below is guarded through information_schema.
--
-- IMPORTANT: The no-op branch uses DO 0 rather than SELECT 1.
-- SELECT 1 leaves a result set behind when executed through a
-- prepared statement, which can cause PDO error 2014:
-- "Cannot execute queries while other unbuffered queries are active."
-- ============================================================

-- ── Add voice_channel_id column (guarded) ───────────────────
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND column_name = 'voice_channel_id'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE users ADD COLUMN voice_channel_id INT UNSIGNED NULL DEFAULT NULL AFTER last_active_at',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── Add index for voice channel lookups (guarded) ───────────
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE table_schema = DATABASE()
      AND table_name   = 'users'
      AND index_name   = 'idx_voice_channel'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE users ADD INDEX idx_voice_channel (voice_channel_id)',
    'DO 0'
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
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── WebRTC signaling: no table needed ───────────────────────
-- Signaling goes through WebSocket memory only (see
-- ChatServer::handleWebRtcSignal). This is intentional.
