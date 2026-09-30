-- Facilitator-owned server permission settings.
CREATE TABLE IF NOT EXISTS facilitator_server_permissions (
  server_id INT UNSIGNED NOT NULL,
  allow_member_invites TINYINT(1) NOT NULL DEFAULT 1,
  allow_member_messages TINYINT(1) NOT NULL DEFAULT 1,
  allow_voice TINYINT(1) NOT NULL DEFAULT 1,
  allow_polls TINYINT(1) NOT NULL DEFAULT 1,
  allow_files TINYINT(1) NOT NULL DEFAULT 1,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (server_id),
  CONSTRAINT fk_fsp_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_fsp_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
