-- Private facilitator drafts. Original uploaded files are never stored in public uploads.
CREATE TABLE IF NOT EXISTS code_lab_module_drafts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 server_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 active_user_id BIGINT UNSIGNED NULL,
 file_name VARCHAR(200) NOT NULL,
 language VARCHAR(16) NOT NULL,
 difficulty VARCHAR(16) NOT NULL,
 module_text MEDIUMTEXT NULL,
 source_chars INT UNSIGNED NOT NULL,
 used_chars INT UNSIGNED NOT NULL,
 state VARCHAR(16) NOT NULL DEFAULT 'queued',
 draft_json MEDIUMTEXT NULL,
 published_task_id BIGINT UNSIGNED NULL,
 worker_token CHAR(32) NULL,
 lease_until DATETIME NULL,
 error_code INT NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 UNIQUE KEY code_lab_module_one_active(active_user_id),
 KEY code_lab_module_queue(state,id),
 KEY code_lab_module_owner(user_id,server_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
