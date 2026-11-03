-- Add comment as a distinct resource permission.
ALTER TABLE collab_resource_permissions
  MODIFY COLUMN permission ENUM('view','comment','edit') NOT NULL DEFAULT 'view';

ALTER TABLE collab_resource_invites
  MODIFY COLUMN permission ENUM('view','comment','edit') NOT NULL DEFAULT 'view';

SET @column_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'collab_documents' AND COLUMN_NAME = 'public_permission');
SET @column_sql = IF(@column_exists = 0, 'ALTER TABLE `collab_documents` ADD COLUMN `public_permission` ENUM(''view'',''comment'',''edit'') NOT NULL DEFAULT ''view''', 'SELECT 1');
PREPARE column_stmt FROM @column_sql;
EXECUTE column_stmt;
DEALLOCATE PREPARE column_stmt;

SET @column_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'collab_whiteboards' AND COLUMN_NAME = 'public_permission');
SET @column_sql = IF(@column_exists = 0, 'ALTER TABLE `collab_whiteboards` ADD COLUMN `public_permission` ENUM(''view'',''comment'',''edit'') NOT NULL DEFAULT ''view''', 'SELECT 1');
PREPARE column_stmt FROM @column_sql;
EXECUTE column_stmt;
DEALLOCATE PREPARE column_stmt;
