-- Ecollab Threads v2
-- Reddit-style discussion threads with three visibility scopes:
-- public = system-wide, server = server-wide, channel = channel-wide.
-- Safe to run repeatedly.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS threads (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(180) NOT NULL,
    body TEXT NOT NULL,
    scope ENUM('public','server','channel') NOT NULL DEFAULT 'public',
    server_id INT UNSIGNED NULL,
    channel_id INT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    is_locked TINYINT(1) NOT NULL DEFAULT 0,
    is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_threads_scope_created (scope, created_at),
    KEY idx_threads_server_created (server_id, created_at),
    KEY idx_threads_channel_created (channel_id, created_at),
    KEY idx_threads_creator (created_by),
    CONSTRAINT fk_threads_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
    CONSTRAINT fk_threads_channel FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE,
    CONSTRAINT fk_threads_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS thread_replies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    thread_id BIGINT UNSIGNED NOT NULL,
    parent_reply_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_thread_replies_thread_created (thread_id, created_at),
    KEY idx_thread_replies_parent (parent_reply_id),
    CONSTRAINT fk_thread_replies_thread FOREIGN KEY (thread_id) REFERENCES threads(id) ON DELETE CASCADE,
    CONSTRAINT fk_thread_replies_parent FOREIGN KEY (parent_reply_id) REFERENCES thread_replies(id) ON DELETE CASCADE,
    CONSTRAINT fk_thread_replies_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS thread_votes (
    thread_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    vote TINYINT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (thread_id, user_id),
    KEY idx_thread_votes_user (user_id),
    CONSTRAINT fk_thread_votes_thread FOREIGN KEY (thread_id) REFERENCES threads(id) ON DELETE CASCADE,
    CONSTRAINT fk_thread_votes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS thread_reply_votes (
    reply_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    vote TINYINT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (reply_id, user_id),
    KEY idx_thread_reply_votes_user (user_id),
    CONSTRAINT fk_thread_reply_votes_reply FOREIGN KEY (reply_id) REFERENCES thread_replies(id) ON DELETE CASCADE,
    CONSTRAINT fk_thread_reply_votes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
