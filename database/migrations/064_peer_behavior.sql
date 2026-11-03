-- Aggregate learning signals only; no private text or source code.
CREATE TABLE IF NOT EXISTS peer_activity_days (
 user_id BIGINT UNSIGNED NOT NULL,
 feature VARCHAR(96) NOT NULL,
 event_day DATE NOT NULL,
 PRIMARY KEY (user_id, feature, event_day),
 INDEX peer_activity_retention (event_day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS peer_behavior_profiles (
 user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 features_json JSON NOT NULL,
 event_count INT UNSIGNED NOT NULL,
 refreshed_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
