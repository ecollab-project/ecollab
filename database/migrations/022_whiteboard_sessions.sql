-- Whiteboard session metadata and immutable saved versions.
ALTER TABLE whiteboards
    ADD COLUMN IF NOT EXISTS session_title VARCHAR(200) NOT NULL DEFAULT 'Whiteboard Session',
    ADD COLUMN IF NOT EXISTS locked TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS locked_by BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS locked_at DATETIME NULL;

CREATE TABLE IF NOT EXISTS whiteboard_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    whiteboard_id BIGINT UNSIGNED NOT NULL,
    channel_id INT UNSIGNED NOT NULL,
    version_no INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    state_json LONGTEXT NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_whiteboard_version (whiteboard_id, version_no),
    KEY idx_whiteboard_versions (whiteboard_id, created_at),
    KEY idx_channel_versions (channel_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
