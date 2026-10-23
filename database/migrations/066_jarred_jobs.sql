CREATE TABLE IF NOT EXISTS jarred_jobs (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 recipient_id BIGINT UNSIGNED NOT NULL,
 conversation_id BIGINT UNSIGNED NOT NULL,
 source_message_id BIGINT UNSIGNED NOT NULL,
 active_server_id INT UNSIGNED NULL,
 surface_json TEXT NOT NULL,
 state ENUM('queued','running','done','failed','cancelled') NOT NULL DEFAULT 'queued',
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 lease_token VARCHAR(64) NULL,
 action_checked TINYINT NOT NULL DEFAULT 0,
 response_message_id BIGINT UNSIGNED NULL,
 action_json TEXT NULL,
 usage_json TEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 finished_at DATETIME NULL,
 UNIQUE KEY jarred_source(source_message_id),
 KEY jarred_queue(state,id),
 KEY jarred_conversation(conversation_id,state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS jarred_queue_gate (id TINYINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB;
INSERT IGNORE INTO jarred_queue_gate(id) VALUES(1);
