-- Document reports share the existing server moderation queue.
ALTER TABLE content_reports ADD COLUMN IF NOT EXISTS document_id BIGINT UNSIGNED NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_document_reporter ON content_reports (reporter_id, document_id);
