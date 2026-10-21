-- Private shared snapshot. No room names or counts are served without current authorization.
CREATE TABLE IF NOT EXISTS livekit_count_snapshot (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    counts_json MEDIUMTEXT NOT NULL,
    collected_at BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
