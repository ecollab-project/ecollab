-- ============================================================
-- 032_dm_call_history.sql
-- ============================================================
-- Logs every DM voice/video call attempt (answered, missed,
-- declined, or ended) so the conversation can show it the way
-- every call app does — as an entry in the message history, not
-- just an ephemeral popup that vanishes with no record.
--
-- callee_id is nullable to allow future group-call logging
-- (group_id set instead), even though group calls aren't wired up
-- yet on the frontend.
-- ============================================================

CREATE TABLE IF NOT EXISTS dm_call_history (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    caller_id         BIGINT UNSIGNED NOT NULL,
    callee_id         BIGINT UNSIGNED NULL,
    group_id          BIGINT UNSIGNED NULL,
    conversation_id   INT UNSIGNED NULL,
    is_video          TINYINT(1) NOT NULL DEFAULT 0,
    status            ENUM('ringing','answered','missed','declined','ended') NOT NULL DEFAULT 'ringing',
    started_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    answered_at       DATETIME NULL,
    ended_at          DATETIME NULL,
    duration_seconds  INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_dmch_caller (caller_id, started_at),
    KEY idx_dmch_callee (callee_id, started_at),
    KEY idx_dmch_group (group_id, started_at),
    KEY idx_dmch_conversation (conversation_id, started_at),
    CONSTRAINT fk_dmch_caller FOREIGN KEY (caller_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_dmch_callee FOREIGN KEY (callee_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_dmch_group FOREIGN KEY (group_id) REFERENCES dm_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
