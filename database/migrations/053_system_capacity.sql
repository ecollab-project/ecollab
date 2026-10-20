-- Capacity references are workload-specific evidence, never login limits.
CREATE TABLE IF NOT EXISTS capacity_test_records (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tested_users INT UNSIGNED NOT NULL,
    result VARCHAR(12) NOT NULL,
    completed TINYINT(1) NOT NULL DEFAULT 0,
    tested_on DATE NOT NULL,
    workload VARCHAR(160) NOT NULL,
    hold_seconds INT UNSIGNED NOT NULL,
    api_success_percent DECIMAL(8,5) NULL,
    http_p95_ms DECIMAL(12,3) NULL,
    delivery_p95_ms DECIMAL(12,3) NULL,
    notes TEXT NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS capacity_settings (
    id TINYINT UNSIGNED PRIMARY KEY,
    baseline_test_id BIGINT UNSIGNED NULL,
    operational_target INT UNSIGNED NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS capacity_settings_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id INT UNSIGNED NOT NULL,
    baseline_test_id BIGINT UNSIGNED NULL,
    operational_target INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS capacity_samples (
    sampled_at DATETIME PRIMARY KEY,
    active_users INT UNSIGNED NOT NULL,
    presence_users INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Evidence supplied by the administrator on 2026-10-13; not a live benchmark.
INSERT IGNORE INTO capacity_test_records
(id,tested_users,result,completed,tested_on,workload,hold_seconds,api_success_percent,http_p95_ms,delivery_p95_ms,notes)
VALUES
(1,300,'passed',1,'2026-10-13','Chat reads, writes and realtime delivery',300,99.99780,438.714,2265.900,
 'Administrator-provided k6 result: 120s ramp, 300s hold, 10s ramp-down; 45410 API samples, 1 failure; 300/300 logins; 3403 successful socket iterations; 4743 measured own-message deliveries. Excludes AI, calls and document editing.'),
(2,350,'failed',0,'2026-10-13','Chat reads, writes and realtime delivery',300,99.79671,1085.003,3362.250,
 'Administrator-provided k6 result: stopped prematurely; socket success 98.583%, delivery p95 exceeded 3000ms. This is not a validated capacity.');
INSERT IGNORE INTO capacity_settings (id,baseline_test_id,operational_target) VALUES (1,1,NULL);
