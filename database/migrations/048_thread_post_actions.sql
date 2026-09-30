-- Thread post actions: bookmark and report
ALTER TABLE threads
  ADD COLUMN is_bookmarked TINYINT(1) NOT NULL DEFAULT 0 AFTER is_pinned;

CREATE TABLE IF NOT EXISTS thread_reports (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  thread_id BIGINT UNSIGNED NOT NULL,
  reporter_id BIGINT UNSIGNED NOT NULL,
  reported_user_id BIGINT UNSIGNED NOT NULL,
  reason VARCHAR(255) NOT NULL DEFAULT 'other',
  status ENUM('pending','reviewing','resolved','dismissed') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_thread_reports_thread (thread_id),
  KEY idx_thread_reports_status (status),
  UNIQUE KEY uq_thread_reporter (thread_id, reporter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
