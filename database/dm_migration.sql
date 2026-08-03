-- ============================================================
--  Ecollab — DM & Notifications migration
--  Run once against your existing database
-- ============================================================

-- 1. Direct-message conversations (one row per pair, regardless of who initiated)
CREATE TABLE IF NOT EXISTS dm_conversations (
    id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    user_a        INT UNSIGNED    NOT NULL,   -- always the lower user_id
    user_b        INT UNSIGNED    NOT NULL,   -- always the higher user_id
    last_message  TEXT,
    last_msg_at   DATETIME,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pair (user_a, user_b),
    INDEX idx_a (user_a),
    INDEX idx_b (user_b)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. DM messages
CREATE TABLE IF NOT EXISTS dm_messages (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    conversation_id INT UNSIGNED    NOT NULL,
    sender_id       INT UNSIGNED    NOT NULL,
    body            TEXT            NOT NULL,
    is_deleted      TINYINT(1)      NOT NULL DEFAULT 0,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_conv (conversation_id, created_at),
    CONSTRAINT fk_dm_conv FOREIGN KEY (conversation_id) REFERENCES dm_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Per-user DM read cursor
CREATE TABLE IF NOT EXISTS dm_reads (
    user_id         INT UNSIGNED    NOT NULL,
    conversation_id INT UNSIGNED    NOT NULL,
    last_read_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, conversation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. General notification queue
--    type examples: 'connection_request', 'connection_accepted', 'dm', 'mention'
CREATE TABLE IF NOT EXISTS notifications (
    id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED    NOT NULL,          -- recipient
    type        VARCHAR(40)     NOT NULL,
    title       VARCHAR(120)    NOT NULL,
    body        VARCHAR(512),
    ref_id      INT UNSIGNED,                      -- e.g. friendship.id, dm_messages.id
    is_read     TINYINT(1)      NOT NULL DEFAULT 0,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_user_unread (user_id, is_read, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
