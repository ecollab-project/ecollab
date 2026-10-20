-- eCollab role elevation workflow: Student -> Facilitator by admin approval.
CREATE TABLE IF NOT EXISTS facilitator_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  proof_path VARCHAR(500) NOT NULL,
  proof_original_name VARCHAR(255) DEFAULT NULL,
  reason TEXT DEFAULT NULL,
  status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  reviewed_by BIGINT UNSIGNED DEFAULT NULL,
  review_note TEXT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_facilitator_requests_user (user_id,status),
  KEY idx_facilitator_requests_status (status,created_at),
  CONSTRAINT fk_facreq_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_facreq_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_user_id BIGINT UNSIGNED DEFAULT NULL,
  target_user_id BIGINT UNSIGNED NOT NULL,
  action VARCHAR(64) NOT NULL,
  old_role VARCHAR(32) DEFAULT NULL,
  new_role VARCHAR(32) DEFAULT NULL,
  metadata_json JSON DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_role_audit_target (target_user_id,created_at),
  KEY idx_role_audit_actor (actor_user_id,created_at),
  CONSTRAINT fk_roleaudit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_roleaudit_target FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
