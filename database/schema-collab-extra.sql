-- ============================================================
-- Ecollab Extra Collaboration Tools Schema
-- Tools: Flashcards, Mind Map, Peer Review,
--        Chat Summary, Study Goals, Resource Library
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ────────────────────────────────────────────────────────────
-- FLASHCARDS  (study decks with flip-card reviews)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS collab_decks (
    id           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    channel_id   INT UNSIGNED    NOT NULL,
    title        VARCHAR(200)    NOT NULL,
    description  TEXT,
    created_by   INT UNSIGNED    NOT NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_channel (channel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS collab_flashcards (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    deck_id      INT UNSIGNED    NOT NULL,
    front        TEXT            NOT NULL,
    back         TEXT            NOT NULL,
    hint         VARCHAR(500),
    position     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_by   INT UNSIGNED    NOT NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_deck (deck_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS collab_flashcard_reviews (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    card_id     BIGINT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED    NOT NULL,
    rating      TINYINT UNSIGNED NOT NULL DEFAULT 3,   -- 1=Hard 2=OK 3=Easy
    reviewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_card_user (card_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- MIND MAP  (branching idea nodes per channel)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS collab_mindmaps (
    id           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    channel_id   INT UNSIGNED    NOT NULL,
    title        VARCHAR(200)    NOT NULL DEFAULT 'Mind Map',
    graph_json   MEDIUMTEXT,          -- {nodes:[{id,label,x,y,color}], edges:[{src,dst}]}
    version      INT UNSIGNED    NOT NULL DEFAULT 1,
    created_by   INT UNSIGNED    NOT NULL,
    updated_by   INT UNSIGNED,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_channel (channel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- PEER REVIEW  (structured feedback on submissions)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS collab_review_requests (
    id           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    channel_id   INT UNSIGNED    NOT NULL,
    author_id    INT UNSIGNED    NOT NULL,
    title        VARCHAR(200)    NOT NULL,
    content      MEDIUMTEXT,
    file_url     VARCHAR(500),
    state        ENUM('open','closed') NOT NULL DEFAULT 'open',
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_channel (channel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS collab_review_feedback (
    id           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    request_id   INT UNSIGNED     NOT NULL,
    reviewer_id  INT UNSIGNED     NOT NULL,
    comment      TEXT             NOT NULL,
    rating       TINYINT UNSIGNED,           -- optional 1–5 stars
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_request (request_id),
    KEY idx_reviewer (reviewer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- CHAT SUMMARY  (AI-generated periodic summaries)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS collab_summaries (
    id           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    channel_id   INT UNSIGNED    NOT NULL,
    summary      MEDIUMTEXT      NOT NULL,
    from_msg_id  BIGINT UNSIGNED NOT NULL,
    to_msg_id    BIGINT UNSIGNED NOT NULL,
    message_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    generated_by INT UNSIGNED    NOT NULL,
    generated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_channel (channel_id),
    KEY idx_generated_at (channel_id, generated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- STUDY GOALS  (personal + group goals with progress tracking)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS collab_goals (
    id           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    channel_id   INT UNSIGNED    NOT NULL,
    user_id      INT UNSIGNED    NOT NULL,
    title        VARCHAR(300)    NOT NULL,
    description  TEXT,
    scope        ENUM('personal','group') NOT NULL DEFAULT 'group',
    target_date  DATE,
    progress     TINYINT UNSIGNED NOT NULL DEFAULT 0,  -- 0-100 %
    status       ENUM('active','completed','abandoned') NOT NULL DEFAULT 'active',
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_channel_user (channel_id, user_id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS collab_goal_milestones (
    id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    goal_id   INT UNSIGNED    NOT NULL,
    label     VARCHAR(300)    NOT NULL,
    done      TINYINT(1)      NOT NULL DEFAULT 0,
    position  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_goal (goal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS collab_goal_reactions (
    goal_id   INT UNSIGNED NOT NULL,
    user_id   INT UNSIGNED NOT NULL,
    emoji     VARCHAR(10)  NOT NULL DEFAULT '👍',
    reacted_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (goal_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- RESOURCE LIBRARY  (shared links, files, references)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS collab_resources (
    id           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    channel_id   INT UNSIGNED    NOT NULL,
    title        VARCHAR(300)    NOT NULL,
    url          VARCHAR(2048),
    description  TEXT,
    type         ENUM('link','pdf','video','image','file','note','other') NOT NULL DEFAULT 'link',
    tags         VARCHAR(500),
    upvotes      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    added_by     INT UNSIGNED    NOT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_channel (channel_id),
    KEY idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS collab_resource_votes (
    resource_id INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    voted_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (resource_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS collab_resource_comments (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    resource_id INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    comment     TEXT         NOT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_resource (resource_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
