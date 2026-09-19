-- ============================================================
-- 038_whiteboard_sessions.sql
-- Allow multiple independent whiteboard sessions per Coworkspace.
-- Existing single-board data is preserved; its title is backfilled.
-- ============================================================

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE table_schema=DATABASE() AND table_name='collab_whiteboards'
      AND index_name='uq_collab_whiteboard_workspace');
SET @sql := IF(@idx_exists > 0,
    'ALTER TABLE collab_whiteboards DROP INDEX uq_collab_whiteboard_workspace',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema=DATABASE() AND table_name='collab_whiteboards' AND column_name='title');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE collab_whiteboards ADD COLUMN title VARCHAR(200) NOT NULL DEFAULT ''Whiteboard Session'' AFTER workspace_id',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema=DATABASE() AND table_name='collab_whiteboards' AND column_name='description');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE collab_whiteboards ADD COLUMN description VARCHAR(500) NULL AFTER title',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema=DATABASE() AND table_name='collab_whiteboards' AND column_name='created_by');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE collab_whiteboards ADD COLUMN created_by BIGINT UNSIGNED NULL AFTER state_json',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE collab_whiteboards
SET title=CASE WHEN title='' OR title='Whiteboard Session' THEN CONCAT('Whiteboard Session ',id) ELSE title END,
    created_by=COALESCE(created_by,updated_by)
WHERE title='' OR title='Whiteboard Session' OR created_by IS NULL;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE table_schema=DATABASE() AND table_name='collab_whiteboards'
      AND index_name='idx_collab_whiteboard_workspace');
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE collab_whiteboards ADD KEY idx_collab_whiteboard_workspace (workspace_id,updated_at)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
