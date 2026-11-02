-- Server IDs are immutable. MariaDB rejects generated columns that depend on
-- an ON UPDATE CASCADE foreign-key column (migration 025 active_server_id).
-- Preserve delete cascading and restrict server-ID rewrites before adding the
-- generated unique key. This also repairs the FK on already-migrated installs.
SET @cw_update_rule = (
    SELECT UPDATE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'collab_workspaces' AND CONSTRAINT_NAME = 'fk_cw_server'
    LIMIT 1
);
SET @cw_fk_sql = IF(@cw_update_rule = 'CASCADE',
    'ALTER TABLE collab_workspaces DROP FOREIGN KEY fk_cw_server, ADD CONSTRAINT fk_cw_server_immutable FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE ON UPDATE RESTRICT',
    'SELECT 1');
PREPARE cw_fk_stmt FROM @cw_fk_sql;
EXECUTE cw_fk_stmt;
DEALLOCATE PREPARE cw_fk_stmt;
