-- A nullable conversation cursor cannot be part of a PRIMARY KEY in MySQL.
-- Two unique scopes retain idempotent per-user DM/group upserts while allowing
-- the other scope's ID to be NULL. Existing read timestamps are preserved.
SET @dm_primary = (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dm_reads'
      AND INDEX_NAME = 'PRIMARY' AND COLUMN_NAME = 'conversation_id');
SET @dm_conv_unique = (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dm_reads'
      AND INDEX_NAME = 'uq_dm_reads_conversation');
SET @dm_key_sql = IF(@dm_primary > 0,
    IF(@dm_conv_unique = 0,
       'ALTER TABLE dm_reads DROP PRIMARY KEY, ADD UNIQUE KEY uq_dm_reads_conversation (user_id, conversation_id)',
       'ALTER TABLE dm_reads DROP PRIMARY KEY'),
    IF(@dm_conv_unique = 0,
       'ALTER TABLE dm_reads ADD UNIQUE KEY uq_dm_reads_conversation (user_id, conversation_id)',
       'SELECT 1'));
PREPARE dm_key_stmt FROM @dm_key_sql;
EXECUTE dm_key_stmt;
DEALLOCATE PREPARE dm_key_stmt;
SET @dm_conv_type = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dm_reads'
      AND COLUMN_NAME = 'conversation_id' AND IS_NULLABLE = 'NO');
SET @dm_nullable_sql = IF(@dm_conv_type IS NOT NULL,
    CONCAT('ALTER TABLE dm_reads MODIFY COLUMN conversation_id ', @dm_conv_type, ' NULL'),
    'SELECT 1');
PREPARE dm_nullable_stmt FROM @dm_nullable_sql;
EXECUTE dm_nullable_stmt;
DEALLOCATE PREPARE dm_nullable_stmt;
