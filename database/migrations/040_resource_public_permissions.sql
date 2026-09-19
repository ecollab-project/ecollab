-- Public resources are visible to Coworkspace members, but default to view-only.
-- Owners can explicitly grant edit access through resource permissions.

SET @has_doc_public_perm := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='collab_documents' AND COLUMN_NAME='public_permission');
SET @sql_doc_public_perm := IF(@has_doc_public_perm=0,'ALTER TABLE collab_documents ADD COLUMN public_permission ENUM(\'view\',\'edit\') NOT NULL DEFAULT \'view\' AFTER visibility','SELECT 1');
PREPARE stmt_doc_public_perm FROM @sql_doc_public_perm; EXECUTE stmt_doc_public_perm; DEALLOCATE PREPARE stmt_doc_public_perm;

SET @has_wb_public_perm := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='collab_whiteboards' AND COLUMN_NAME='public_permission');
SET @sql_wb_public_perm := IF(@has_wb_public_perm=0,'ALTER TABLE collab_whiteboards ADD COLUMN public_permission ENUM(\'view\',\'edit\') NOT NULL DEFAULT \'view\' AFTER visibility','SELECT 1');
PREPARE stmt_wb_public_perm FROM @sql_wb_public_perm; EXECUTE stmt_wb_public_perm; DEALLOCATE PREPARE stmt_wb_public_perm;
