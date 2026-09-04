-- ============================================================
-- Ecollab ONLYOFFICE collaborative document storage
-- Channel-scoped documents with server-side file storage.
-- ============================================================

CREATE TABLE IF NOT EXISTS collab_documents (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel_id      INT UNSIGNED    NOT NULL,
    title           VARCHAR(255)    NOT NULL,
    file_name       VARCHAR(255)    NOT NULL,
    file_type       VARCHAR(20)     NOT NULL,
    storage_path    VARCHAR(500)    NOT NULL,
    document_key    VARCHAR(128)    NOT NULL,
    version         INT UNSIGNED    NOT NULL DEFAULT 1,
    created_by      INT UNSIGNED    NOT NULL,
    updated_by      INT UNSIGNED,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_document_key (document_key),
    KEY idx_channel_updated (channel_id, updated_at),
    KEY idx_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
