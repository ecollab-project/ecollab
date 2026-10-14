-- Individual code is never shared as a coworkspace document.
CREATE TABLE IF NOT EXISTS code_lab_tasks (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 server_id INT UNSIGNED NOT NULL,
 created_by INT UNSIGNED NOT NULL,
 title VARCHAR(160) NOT NULL,
 instructions TEXT NOT NULL,
 language VARCHAR(16) NOT NULL,
 kind VARCHAR(16) NOT NULL DEFAULT 'assignment',
 starter_code MEDIUMTEXT NOT NULL,
 opens_at DATETIME NULL,
 due_at DATETIME NULL,
 duration_minutes INT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX code_lab_server (server_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS code_lab_attempts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 task_id BIGINT UNSIGNED NOT NULL,
 user_id INT UNSIGNED NOT NULL,
 code MEDIUMTEXT NOT NULL,
 started_at DATETIME NOT NULL,
 submitted_at DATETIME NULL,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 score DECIMAL(5,2) NULL,
 feedback TEXT NULL,
 reviewed_by INT UNSIGNED NULL,
 reviewed_at DATETIME NULL,
 UNIQUE KEY code_lab_private_attempt (task_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS code_lab_playgrounds (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 server_id INT UNSIGNED NOT NULL,
 user_id INT UNSIGNED NOT NULL,
 language VARCHAR(16) NOT NULL,
 code MEDIUMTEXT NOT NULL,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 updated_at DATETIME NOT NULL,
 UNIQUE KEY code_lab_private_playground (server_id, user_id, language)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
