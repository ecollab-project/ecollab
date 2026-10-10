-- Explicit deployment migration. Tokens contain hashes only; no user document content.
CREATE TABLE IF NOT EXISTS collab_wopi_tokens (
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    document_id BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    can_write TINYINT(1) NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    KEY idx_expiry (expires_at),
    KEY idx_document (document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS collab_document_versions (
    document_id BIGINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    storage_path VARCHAR(500) NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (document_id, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
