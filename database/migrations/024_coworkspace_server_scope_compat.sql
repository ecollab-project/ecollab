-- Indexed VIRTUAL uniqueness preserves server deletion cascades on both
-- engines. MySQL forbids cascade actions on a STORED expression's base FK.
-- Convert previously stored derived values without removing source rows.
SET @cw_stored = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'collab_workspaces'
      AND COLUMN_NAME = 'active_server_id' AND EXTRA LIKE '%STORED%');
SET @cw_storage_sql = IF(@cw_stored > 0,
    'ALTER TABLE collab_workspaces DROP INDEX uk_coworkspace_active_server, DROP COLUMN active_server_id, ADD COLUMN active_server_id INT UNSIGNED GENERATED ALWAYS AS (IF(archived = 0, server_id, NULL)) VIRTUAL, ADD UNIQUE KEY uk_coworkspace_active_server (active_server_id)',
    'SELECT 1');
PREPARE cw_storage_stmt FROM @cw_storage_sql;
EXECUTE cw_storage_stmt;
DEALLOCATE PREPARE cw_storage_stmt;

-- Server primary keys are immutable. Retain the canonical delete cascade and
-- remove update cascading before migration 025 adds its generated expression.
SET @cw_fk_name = (SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'collab_workspaces'
      AND CONSTRAINT_NAME IN ('fk_cw_server', 'fk_cw_server_immutable', 'fk_cw_server_virtual')
    ORDER BY CONSTRAINT_NAME LIMIT 1);
SET @cw_update_rule = (SELECT UPDATE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'collab_workspaces' AND CONSTRAINT_NAME = @cw_fk_name);
SET @cw_delete_rule = (SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'collab_workspaces' AND CONSTRAINT_NAME = @cw_fk_name);
SET @cw_fk_sql = IF(@cw_fk_name IS NOT NULL AND (@cw_update_rule <> 'RESTRICT' OR @cw_delete_rule <> 'CASCADE'),
    CONCAT('ALTER TABLE collab_workspaces DROP FOREIGN KEY `', @cw_fk_name, '`, ADD CONSTRAINT fk_cw_server_virtual FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE ON UPDATE RESTRICT'),
    'SELECT 1');
PREPARE cw_fk_stmt FROM @cw_fk_sql;
EXECUTE cw_fk_stmt;
DEALLOCATE PREPARE cw_fk_stmt;
