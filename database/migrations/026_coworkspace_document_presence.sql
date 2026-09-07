-- ============================================================
-- Ecollab Coworkspace Documents — server-wide document scope
-- and per-document live presence.
-- ============================================================

-- Existing documents keep channel_id for backwards compatibility, but
-- new/managed documents are authorized through workspace_id.
UPDATE collab_documents d
INNER JOIN channels c ON c.id = d.channel_id
INNER JOIN collab_workspaces w
    ON w.server_id = c.server_id
   AND w.archived = 0
SET d.workspace_id = w.id
WHERE d.workspace_id IS NULL;

ALTER TABLE collab_documents
    ADD KEY idx_workspace_type_updated (workspace_id, file_type, updated_at);

CREATE TABLE IF NOT EXISTS collab_document_presence (
    document_id BIGINT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED    NOT NULL,
    mode        ENUM('editing','viewing') NOT NULL DEFAULT 'viewing',
    last_seen   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (document_id, user_id),
    KEY idx_document_seen (document_id, last_seen),
    KEY idx_user_seen (user_id, last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
