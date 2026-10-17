-- Optional experiment. Apply before enabling BOOK_RECOMMENDATION_EVENTS_ENABLED.
CREATE TABLE IF NOT EXISTS recommendation_books (
    work_key VARCHAR(64) NOT NULL PRIMARY KEY,
    features_json JSON NOT NULL,
    first_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recommendation_book_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    work_key VARCHAR(64) NOT NULL,
    event_type ENUM('click','useful') NOT NULL,
    event_day DATE NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_book_daily_event (user_id, work_key, event_type, event_day),
    KEY ix_book_event_time (created_at),
    CONSTRAINT fk_recommendation_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_recommendation_event_book FOREIGN KEY (work_key) REFERENCES recommendation_books(work_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
