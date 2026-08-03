-- ============================================================
-- Ecollab Enhanced Data Security Schema
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ── Security audit log ──────────────────────────────────────
-- Every auth event, permission change, and sensitive data
-- access is written here. Append-only (no DELETE in app code).
CREATE TABLE IF NOT EXISTS security_audit_log (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      BIGINT UNSIGNED,              -- NULL for pre-auth events
    session_id   VARCHAR(128),
    event_type   VARCHAR(60)     NOT NULL,     -- see AUDIT_* constants
    event_status ENUM('success','failure','blocked') NOT NULL DEFAULT 'success',
    ip_address   VARCHAR(45)     NOT NULL,
    user_agent   VARCHAR(500),
    resource     VARCHAR(200),                 -- URL / action targeted
    detail       JSON,                         -- extra context (sanitised)
    risk_score   TINYINT UNSIGNED NOT NULL DEFAULT 0, -- 0-100
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user_id   (user_id),
    KEY idx_event     (event_type, event_status),
    KEY idx_ip        (ip_address),
    KEY idx_created   (created_at),
    KEY idx_risk      (risk_score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Account lockout tracker ──────────────────────────────────
CREATE TABLE IF NOT EXISTS account_lockouts (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        BIGINT UNSIGNED,
    identifier     VARCHAR(255) NOT NULL,  -- email or username
    ip_address     VARCHAR(45)  NOT NULL,
    failed_count   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until   DATETIME,               -- NULL = not locked
    lock_reason    VARCHAR(100),
    last_attempt   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_identifier (identifier),
    KEY idx_ip        (ip_address),
    KEY idx_locked    (locked_until),
    KEY idx_user_id   (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── IP block list ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ip_blocks (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip_address   VARCHAR(45)  NOT NULL,
    cidr_prefix  TINYINT UNSIGNED,          -- NULL = exact match only
    reason       VARCHAR(200),
    blocked_by   BIGINT UNSIGNED,           -- user_id of admin
    expires_at   DATETIME,                  -- NULL = permanent
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_ip (ip_address),
    KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Encrypted PII store ──────────────────────────────────────
-- Stores AES-256-GCM ciphertexts of user PII fields.
-- The plaintext columns in `users` remain for search/display;
-- this table stores encrypted copies for compliance (GDPR/FERPA).
CREATE TABLE IF NOT EXISTS user_encrypted_pii (
    user_id          BIGINT UNSIGNED NOT NULL,
    -- Each field: nonce(12) + tag(16) + ciphertext, base64-encoded
    email_enc        TEXT,
    full_name_enc    TEXT,
    phone_enc        TEXT,
    student_id_enc   TEXT,
    key_version      TINYINT UNSIGNED NOT NULL DEFAULT 1, -- for key rotation
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Key rotation log ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS encryption_key_versions (
    version      TINYINT UNSIGNED NOT NULL,
    key_hash     VARCHAR(64)  NOT NULL,   -- SHA-256 of derived key (NOT the key)
    algorithm    VARCHAR(30)  NOT NULL DEFAULT 'AES-256-GCM',
    created_by   BIGINT UNSIGNED,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    retired_at   DATETIME,
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Failed login analytics (fast lookup, kept short-term) ────
CREATE TABLE IF NOT EXISTS failed_login_analytics (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip_address   VARCHAR(45)  NOT NULL,
    identifier   VARCHAR(255) NOT NULL,   -- hashed email/username
    user_agent   VARCHAR(300),
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ip         (ip_address),
    KEY idx_identifier (identifier),
    KEY idx_attempted  (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Permission change log ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS permission_change_log (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    target_id    BIGINT UNSIGNED NOT NULL,  -- user whose role changed
    changed_by   BIGINT UNSIGNED NOT NULL,  -- admin who made the change
    old_role     VARCHAR(30),
    new_role     VARCHAR(30),
    reason       VARCHAR(300),
    ip_address   VARCHAR(45),
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_target  (target_id),
    KEY idx_changer (changed_by),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
