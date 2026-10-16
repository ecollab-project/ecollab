CREATE TABLE IF NOT EXISTS push_subscriptions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NOT NULL,
 endpoint_hash CHAR(64) NOT NULL,
 subscription_json TEXT NOT NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 UNIQUE KEY push_endpoint(endpoint_hash),
 INDEX push_user(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS push_deliveries (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 notification_id BIGINT UNSIGNED NOT NULL,
 subscription_id BIGINT UNSIGNED NOT NULL,
 state VARCHAR(12) NOT NULL DEFAULT 'queued',
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 available_at DATETIME NOT NULL,
 started_at DATETIME NULL,
 finished_at DATETIME NULL,
 UNIQUE KEY push_once(notification_id,subscription_id),
 INDEX push_queue(state,available_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS code_lab_deadline_notices (
 task_id BIGINT UNSIGNED NOT NULL,
 user_id INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL,
 PRIMARY KEY(task_id,user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
