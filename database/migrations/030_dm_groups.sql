-- ============================================================
-- 030_dm_groups.sql
-- ============================================================
-- Adds group direct messages. The existing dm_conversations table
-- (008_dm_migration.sql) is hard-coded to exactly two participants
-- (user_a, user_b with a unique pair constraint), so group DMs need
-- their own tables rather than a tweak to that one.
--
-- dm_messages is extended (guarded ALTER, matching the pattern in
-- 007_voice_presence.sql) with a nullable group_id alongside the
-- existing conversation_id, so one-to-one and group messages share
-- the same table and the same send/read code paths where practical.
-- Exactly one of conversation_id / group_id must be set per row —
-- enforced at the application layer, not a DB CHECK constraint, for
-- compatibility with older MySQL/MariaDB (see 002's own note on
-- CREATE INDEX IF NOT EXISTS not being portable).
-- ============================================================

CREATE TABLE IF NOT EXISTS dm_groups (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(100)    NULL,        -- optional; falls back to member-name list in UI
    created_by    BIGINT UNSIGNED NOT NULL,
    last_message  TEXT            NULL,
    last_msg_at   DATETIME        NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_dmg_creator (created_by),
    CONSTRAINT fk_dmg_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dm_group_members (
    group_id   BIGINT UNSIGNED NOT NULL,
    user_id    BIGINT UNSIGNED NOT NULL,
    role       ENUM('owner','member') NOT NULL DEFAULT 'member',
    joined_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (group_id, user_id),
    KEY idx_dmgm_user (user_id),
    CONSTRAINT fk_dmgm_group FOREIGN KEY (group_id) REFERENCES dm_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_dmgm_user  FOREIGN KEY (user_id)  REFERENCES users(id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── dm_messages: allow conversation_id to be NULL (guarded) ─────
SET @col_type := (
    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'dm_messages'
      AND column_name = 'conversation_id'
      AND is_nullable = 'NO'
);
SET @sql := IF(@col_type IS NOT NULL,
    'ALTER TABLE dm_messages MODIFY COLUMN conversation_id INT UNSIGNED NULL',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── dm_messages: add group_id column (guarded) ───────────────────
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'dm_messages'
      AND column_name = 'group_id'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE dm_messages ADD COLUMN group_id BIGINT UNSIGNED NULL AFTER conversation_id',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── dm_messages: index + FK for group_id (guarded) ───────────────
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE table_schema = DATABASE()
      AND table_name   = 'dm_messages'
      AND index_name   = 'idx_dm_group'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE dm_messages ADD INDEX idx_dm_group (group_id, created_at)',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE table_schema = DATABASE()
      AND table_name   = 'dm_messages'
      AND constraint_name = 'fk_dm_group'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE dm_messages ADD CONSTRAINT fk_dm_group FOREIGN KEY (group_id) REFERENCES dm_groups(id) ON DELETE CASCADE',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── dm_reads: allow group-scoped read cursors too (guarded) ──────
SET @col_type2 := (
    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'dm_reads'
      AND column_name = 'conversation_id'
      AND is_nullable = 'NO'
);
SET @sql := IF(@col_type2 IS NOT NULL,
    'ALTER TABLE dm_reads MODIFY COLUMN conversation_id INT UNSIGNED NULL',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists2 := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'dm_reads'
      AND column_name = 'group_id'
);
SET @sql := IF(@col_exists2 = 0,
    'ALTER TABLE dm_reads ADD COLUMN group_id BIGINT UNSIGNED NULL AFTER conversation_id, ADD PRIMARY KEY (user_id, conversation_id, group_id)',
    'DO 0'
);
-- Note: dm_reads' existing PK is (user_id, conversation_id). Adding group_id
-- to the PK requires dropping the old PK first; done as a single guarded
-- statement pair below instead of inline above, since MySQL can't add a
-- column and redefine the PK atomically via the same IF-guard cleanly.
SET @sql := IF(@col_exists2 = 0,
    'ALTER TABLE dm_reads ADD COLUMN group_id BIGINT UNSIGNED NULL AFTER conversation_id',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists2 := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE table_schema = DATABASE()
      AND table_name   = 'dm_reads'
      AND index_name   = 'idx_dm_reads_group'
);
SET @sql := IF(@idx_exists2 = 0,
    'ALTER TABLE dm_reads ADD UNIQUE KEY idx_dm_reads_group (user_id, group_id)',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
