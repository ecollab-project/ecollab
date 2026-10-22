-- Explicit replacement for unchanged historical 025, selected by migrate.php.
-- Stored expression depends only on archived, never the cascading server FK.
-- No workspace rows or foreign keys are rewritten. Unexpected shapes fail closed.
-- DDL is not transactional: schema replacement is ONE atomic InnoDB ALTER.
SET @cw_lock_name = CONCAT('ec-cw025-', LEFT(SHA2(DATABASE(), 256), 32));
SET @cw_locked = GET_LOCK(@cw_lock_name, 10);
SET @cw_sql = IF(COALESCE(@cw_locked, 0) = 1, 'DO 0', 'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''Coworkspace migration lock unavailable''');
PREPARE cw_stmt FROM @cw_sql;
EXECUTE cw_stmt;
DEALLOCATE PREPARE cw_stmt;

SET @cw_base_ok = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='collab_workspaces' AND column_name IN ('server_id','archived') AND is_nullable='NO');
SET @cw_innodb = EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='collab_workspaces' AND engine='InnoDB');
SET @cw_sql = IF(@cw_base_ok=2 AND @cw_innodb, 'DO 0', 'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''Complete migration024 with non-null server/archive columns and InnoDB required''');
PREPARE cw_stmt FROM @cw_sql;
EXECUTE cw_stmt;
DEALLOCATE PREPARE cw_stmt;

SET @cw_old_expr = (SELECT LOWER(REPLACE(REPLACE(REPLACE(REPLACE(generation_expression, '`', ''), ' ', ''), CHAR(10), ''), CHAR(13), '')) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='collab_workspaces' AND column_name='active_server_id');
SET @cw_slot_expr = (SELECT LOWER(REPLACE(REPLACE(REPLACE(REPLACE(generation_expression, '`', ''), ' ', ''), CHAR(10), ''), CHAR(13), '')) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='collab_workspaces' AND column_name='active_slot');
SET @cw_index = (SELECT GROUP_CONCAT(CONCAT(column_name, ':', non_unique, ':', COALESCE(sub_part, 'full')) ORDER BY seq_in_index SEPARATOR ',') FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='collab_workspaces' AND index_name='uk_coworkspace_active_server');
SET @cw_bad_old = EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='collab_workspaces' AND column_name='active_server_id' AND (@cw_old_expr <> 'if(archived=0,server_id,null)' OR extra NOT LIKE '%STORED GENERATED%'));
SET @cw_bad_slot = EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='collab_workspaces' AND column_name='active_slot' AND (@cw_slot_expr <> 'if(archived=0,1,null)' OR extra NOT LIKE '%STORED GENERATED%' OR data_type <> 'tinyint' OR column_type NOT LIKE '%unsigned'));
SET @cw_bad_dependency = EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='collab_workspaces' AND column_name='active_server_id' AND index_name <> 'uk_coworkspace_active_server');
SET @cw_bad_index = @cw_index IS NOT NULL AND @cw_index NOT IN ('active_server_id:0:full', 'server_id:0:full,active_slot:0:full');
SET @cw_sql = IF(@cw_bad_old OR @cw_bad_slot OR @cw_bad_dependency OR @cw_bad_index, 'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''Unexpected coworkspace constraint shape: manual review required''', 'DO 0');
PREPARE cw_stmt FROM @cw_sql;
EXECUTE cw_stmt;
DEALLOCATE PREPARE cw_stmt;

SET @cw_duplicates = EXISTS(SELECT server_id FROM collab_workspaces WHERE archived=0 GROUP BY server_id HAVING COUNT(*)>1);
SET @cw_sql = IF(@cw_duplicates, 'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''Duplicate active coworkspaces: resolve explicitly before migration''', 'DO 0');
PREPARE cw_stmt FROM @cw_sql;
EXECUTE cw_stmt;
DEALLOCATE PREPARE cw_stmt;

-- Build one ALTER, retaining the old unique guard until its replacement succeeds.
SET @cw_alter = CONCAT_WS(', ',
    IF(@cw_old_expr IS NOT NULL, 'DROP COLUMN active_server_id', NULL),
    IF(@cw_slot_expr IS NULL, 'ADD COLUMN active_slot TINYINT UNSIGNED GENERATED ALWAYS AS (IF(archived=0,1,NULL)) STORED', NULL),
    IF(@cw_index = 'active_server_id:0:full', 'DROP INDEX uk_coworkspace_active_server', NULL),
    IF(@cw_index IS NULL OR @cw_index = 'active_server_id:0:full', 'ADD UNIQUE KEY uk_coworkspace_active_server (server_id, active_slot)', NULL));
SET @cw_sql = IF(@cw_alter='', 'DO 0', CONCAT('ALTER TABLE collab_workspaces ', @cw_alter));
PREPARE cw_stmt FROM @cw_sql;
EXECUTE cw_stmt;
DEALLOCATE PREPARE cw_stmt;
SELECT RELEASE_LOCK(@cw_lock_name);
