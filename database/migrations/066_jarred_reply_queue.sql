-- Durable reply jobs reference existing messages; user prompts are not copied.
CREATE TABLE IF NOT EXISTS jarred_reply_queue_guard (
 id TINYINT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=InnoDB;
INSERT IGNORE INTO jarred_reply_queue_guard(id) VALUES(1);
CREATE TABLE IF NOT EXISTS jarred_reply_jobs (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 conversation_id BIGINT UNSIGNED NOT NULL,
 recipient_id BIGINT UNSIGNED NOT NULL,
 active_conversation_id BIGINT UNSIGNED NULL,
 request_message_id BIGINT UNSIGNED NULL,
 reply_message_id BIGINT UNSIGNED NULL,
 prompt_hash CHAR(64) NOT NULL,
 server_id BIGINT UNSIGNED NULL,
 surface_json TEXT NOT NULL,
 state VARCHAR(16) NOT NULL DEFAULT 'preparing',
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 worker_token CHAR(32) NULL,
 lease_until DATETIME NULL,
 error_code INT NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 UNIQUE KEY jarred_one_active_turn(active_conversation_id),
 UNIQUE KEY jarred_request_once(request_message_id),
 KEY jarred_queue_claim(state,id),
 KEY jarred_queue_owner(user_id,conversation_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
