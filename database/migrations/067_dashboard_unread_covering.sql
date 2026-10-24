-- MariaDB 10.11: covering reads for exact per-channel unread counts.
-- New additive index only. The application query also works without this index.
-- LOCK=NONE refuses a blocking table-copy fallback; brief metadata locks still apply.
ALTER TABLE messages
    ADD INDEX IF NOT EXISTS dashboard_unread (channel_id, is_deleted, created_at, sender_id),
    ALGORITHM=INPLACE, LOCK=NONE;
