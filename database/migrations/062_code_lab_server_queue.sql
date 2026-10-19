-- Shared, durable round-robin cursor. No runtime DDL or worker per server.
CREATE TABLE IF NOT EXISTS code_lab_dispatch_cursor (
    id TINYINT UNSIGNED PRIMARY KEY,
    last_server_id INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO code_lab_dispatch_cursor(id,last_server_id) VALUES(1,0);
CREATE INDEX code_lab_server_queue ON code_lab_jobs(state,server_id,created_at,id);
