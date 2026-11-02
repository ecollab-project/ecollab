-- Document reports share the existing server moderation queue.
SET @column_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content_reports' AND COLUMN_NAME = 'document_id');
SET @column_sql = IF(@column_exists = 0, 'ALTER TABLE `content_reports` ADD COLUMN `document_id` BIGINT UNSIGNED NULL', 'SELECT 1');
PREPARE column_stmt FROM @column_sql;
EXECUTE column_stmt;
DEALLOCATE PREPARE column_stmt;
SET @index_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content_reports' AND INDEX_NAME = 'uq_document_reporter');
SET @index_sql = IF(@index_exists = 0, 'CREATE UNIQUE INDEX uq_document_reporter ON content_reports (reporter_id, document_id)', 'SELECT 1');
PREPARE index_stmt FROM @index_sql;
EXECUTE index_stmt;
DEALLOCATE PREPARE index_stmt;
