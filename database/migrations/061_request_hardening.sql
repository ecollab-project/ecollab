-- Move request-time rate-limit DDL into migrations and index the sliding-window lookup.
CREATE TABLE IF NOT EXISTS rate_limit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    lookup_key VARCHAR(80) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_lookup_key (lookup_key),
    KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SET @ec_has_rate_index = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='rate_limit_log' AND index_name='idx_rate_window');
SET @ec_rate_sql = IF(@ec_has_rate_index=0,'ALTER TABLE rate_limit_log ADD INDEX idx_rate_window (lookup_key, created_at)','DO 0');
PREPARE ec_rate_stmt FROM @ec_rate_sql;
EXECUTE ec_rate_stmt;
DEALLOCATE PREPARE ec_rate_stmt;
