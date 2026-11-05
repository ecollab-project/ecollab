CREATE TABLE IF NOT EXISTS code_lab_live_sessions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 server_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 task_id BIGINT UNSIGNED NULL,
 active_user_id BIGINT UNSIGNED NULL,
 adapter_token VARCHAR(64) NULL,
 created_at DATETIME NOT NULL,
 expires_at DATETIME NOT NULL,
 UNIQUE KEY code_lab_live_user(active_user_id),
 KEY code_lab_live_owner(user_id,server_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
