-- Migration: private channel new-badge tracking
-- Run once against your ecollab database.
-- Adds a table that tracks when a user first viewed a channel
-- (so we can show "new" badge on unseen channels).

CREATE TABLE IF NOT EXISTS channel_seen (
    channel_id  INT UNSIGNED    NOT NULL,
    user_id     BIGINT UNSIGNED NOT NULL,
    first_seen_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (channel_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Tracks when each user first accessed a channel (for new-badge)';
