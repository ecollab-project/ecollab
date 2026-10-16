CREATE TABLE IF NOT EXISTS code_lab_similarity_jobs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 task_id BIGINT UNSIGNED NOT NULL,
 server_id INT UNSIGNED NOT NULL,
 requested_by INT UNSIGNED NOT NULL,
 snapshot_json MEDIUMTEXT NOT NULL,
 state VARCHAR(12) NOT NULL DEFAULT 'queued',
 result_json MEDIUMTEXT NULL,
 created_at DATETIME NOT NULL,
 started_at DATETIME NULL,
 finished_at DATETIME NULL,
 INDEX code_lab_similarity_queue(state,id),
 INDEX code_lab_similarity_task(task_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
