ALTER TABLE code_lab_tasks ADD COLUMN grading_mode VARCHAR(16) NOT NULL DEFAULT 'manual';
ALTER TABLE code_lab_tasks ADD COLUMN runtime_id INT UNSIGNED NULL;
ALTER TABLE code_lab_attempts ADD COLUMN auto_score DECIMAL(5,2) NULL;
CREATE TABLE IF NOT EXISTS code_lab_test_cases (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 task_id BIGINT UNSIGNED NOT NULL,
 position INT UNSIGNED NOT NULL,
 input_data TEXT NOT NULL,
 expected_output TEXT NOT NULL,
 hidden TINYINT(1) NOT NULL DEFAULT 1,
 weight INT UNSIGNED NOT NULL DEFAULT 1,
 UNIQUE KEY code_lab_case_position(task_id,position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS code_lab_jobs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 server_id INT UNSIGNED NOT NULL,
 user_id INT UNSIGNED NOT NULL,
 attempt_id BIGINT UNSIGNED NULL,
 language VARCHAR(16) NOT NULL,
 runtime_id INT UNSIGNED NULL,
 kind VARCHAR(12) NOT NULL,
 source_code MEDIUMTEXT NOT NULL,
 stdin TEXT NOT NULL,
 state VARCHAR(12) NOT NULL DEFAULT 'queued',
 result_json MEDIUMTEXT NULL,
 created_at DATETIME NOT NULL,
 started_at DATETIME NULL,
 finished_at DATETIME NULL,
 UNIQUE KEY code_lab_grade_once(attempt_id),
 INDEX code_lab_queue(state,id),
 INDEX code_lab_user_jobs(user_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS code_lab_run_gates (
 user_id INT UNSIGNED PRIMARY KEY,
 window_started DATETIME NOT NULL,
 request_count INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
