-- ============================================================
-- Ecollab Coding Buddy — isolated coding-session membership.
-- Membership is intentionally independent from channels,
-- collaboration rooms, projects, and workspaces.
--
-- IMPORTANT: users.id is BIGINT UNSIGNED, so every user foreign
-- key below must match it exactly. INT UNSIGNED causes MySQL
-- errno 150 (foreign key constraint incorrectly formed).
-- ============================================================

CREATE TABLE IF NOT EXISTS coding_sessions (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_id           BIGINT UNSIGNED NOT NULL,
    created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    invite_token       VARCHAR(128)    NULL,
    invite_expires_at  DATETIME        NULL,
    code_state         LONGTEXT        NOT NULL,
    version            INT UNSIGNED    NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_coding_sessions_invite_token (invite_token),
    KEY idx_coding_sessions_owner (owner_id),
    CONSTRAINT fk_coding_sessions_owner
        FOREIGN KEY (owner_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coding_session_participants (
    session_id  BIGINT UNSIGNED NOT NULL,
    user_id     BIGINT UNSIGNED NOT NULL,
    status      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    role        ENUM('editor','viewer') NOT NULL DEFAULT 'viewer',
    joined_at   DATETIME NULL,
    PRIMARY KEY (session_id, user_id),
    KEY idx_coding_participants_user (user_id, status),
    KEY idx_coding_participants_session_status (session_id, status),
    CONSTRAINT fk_coding_participants_session
        FOREIGN KEY (session_id) REFERENCES coding_sessions(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_coding_participants_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
