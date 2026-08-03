-- ============================================================
-- Ecollab Collaboration Tools Schema
-- Tools: Shared Notes, Task Board, Code Sandbox,
--        Study Timer, Quiz Builder, Group Calendar
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ────────────────────────────────────────────────────────────
-- SHARED NOTES  (live collaborative markdown notes per channel)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS collab_notes (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel_id   INT UNSIGNED    NOT NULL,
    title        VARCHAR(200)    NOT NULL DEFAULT 'Untitled Document',
    content      MEDIUMTEXT,
    -- revision: monotonically increases with every committed op (used for OT)
    revision     INT UNSIGNED    NOT NULL DEFAULT 0,
    -- version: increments on every write (legacy + conflict detection)
    version      INT UNSIGNED    NOT NULL DEFAULT 1,
    created_by   INT UNSIGNED    NOT NULL,
    updated_by   INT UNSIGNED,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_channel (channel_id),
    KEY idx_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- OT op log: every committed op stored here so latecomers can be transformed
-- Pruned automatically to last 500 ops per note
CREATE TABLE IF NOT EXISTS collab_note_ops (
    id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    note_id   BIGINT UNSIGNED NOT NULL,
    user_id   INT UNSIGNED    NOT NULL,
    username  VARCHAR(100)    NOT NULL,
    -- op_json: JSON array of {retain:N} | {insert:"str"} | {delete:N} components
    op_json   MEDIUMTEXT      NOT NULL,
    revision  INT UNSIGNED    NOT NULL,
    ts        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_note_revision (note_id, revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ────────────────────────────────────────────────────────────
-- TASK BOARD  (Kanban per channel)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS collab_boards (
    id           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    channel_id   INT UNSIGNED    NOT NULL,
    name         VARCHAR(200)    NOT NULL DEFAULT 'Task Board',
    created_by   INT UNSIGNED    NOT NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_channel (channel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS collab_columns (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    board_id  INT UNSIGNED NOT NULL,
    title     VARCHAR(100) NOT NULL,
    color     VARCHAR(30)  NOT NULL DEFAULT '#a855f7',
    position  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_board (board_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS collab_tasks (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    column_id    INT UNSIGNED    NOT NULL,
    board_id     INT UNSIGNED    NOT NULL,
    title        VARCHAR(300)    NOT NULL,
    description  TEXT,
    priority     ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
    due_date     DATE,
    assignee_id  INT UNSIGNED,
    position     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    done         TINYINT(1) NOT NULL DEFAULT 0,
    created_by   INT UNSIGNED NOT NULL,
    created_at   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_column (column_id),
    KEY idx_board (board_id),
    KEY idx_assignee (assignee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- CODE SANDBOX  (shared code snippets per channel, with run history)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS collab_snippets (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel_id   INT UNSIGNED    NOT NULL,
    title        VARCHAR(200)    NOT NULL DEFAULT 'Untitled Snippet',
    language     VARCHAR(40)     NOT NULL DEFAULT 'javascript',
    code         MEDIUMTEXT,
    version      INT UNSIGNED    NOT NULL DEFAULT 1,
    created_by   INT UNSIGNED    NOT NULL,
    updated_by   INT UNSIGNED,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_channel (channel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS collab_snippet_runs (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    snippet_id  BIGINT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED    NOT NULL,
    output      TEXT,
    error       TEXT,
    duration_ms SMALLINT UNSIGNED,
    ran_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_snippet (snippet_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- STUDY TIMER  (shared Pomodoro sessions per channel)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS collab_timers (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel_id     INT UNSIGNED NOT NULL,
    mode           ENUM('focus','short_break','long_break') NOT NULL DEFAULT 'focus',
    duration_min   TINYINT UNSIGNED NOT NULL DEFAULT 25,
    started_at     DATETIME,
    paused_at      DATETIME,
    elapsed_sec    MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
    state          ENUM('idle','running','paused','done') NOT NULL DEFAULT 'idle',
    started_by     INT UNSIGNED,
    round          TINYINT UNSIGNED NOT NULL DEFAULT 1,
    total_rounds   TINYINT UNSIGNED NOT NULL DEFAULT 4,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_channel (channel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS collab_timer_log (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel_id INT UNSIGNED    NOT NULL,
    user_id    INT UNSIGNED    NOT NULL,
    mode       ENUM('focus','short_break','long_break') NOT NULL,
    duration_min TINYINT UNSIGNED NOT NULL,
    completed  TINYINT(1) NOT NULL DEFAULT 0,
    logged_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_channel_user (channel_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- QUIZ BUILDER  (channel quizzes with live results)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS collab_quizzes (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel_id   INT UNSIGNED NOT NULL,
    title        VARCHAR(200) NOT NULL,
    description  TEXT,
    time_limit   TINYINT UNSIGNED,           -- minutes per question (NULL = no limit)
    state        ENUM('draft','live','closed') NOT NULL DEFAULT 'draft',
    created_by   INT UNSIGNED NOT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at   DATETIME,
    closed_at    DATETIME,
    PRIMARY KEY (id),
    KEY idx_channel (channel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS collab_quiz_questions (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    quiz_id      INT UNSIGNED NOT NULL,
    question     TEXT NOT NULL,
    type         ENUM('mcq','true_false','short_answer') NOT NULL DEFAULT 'mcq',
    options      JSON,                        -- ["A","B","C","D"]
    correct      VARCHAR(500) NOT NULL,       -- correct answer text / index
    points       TINYINT UNSIGNED NOT NULL DEFAULT 1,
    position     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_quiz (quiz_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS collab_quiz_attempts (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    quiz_id     INT UNSIGNED    NOT NULL,
    user_id     INT UNSIGNED    NOT NULL,
    answers     JSON,                         -- { question_id: "answer" }
    score       TINYINT UNSIGNED,
    max_score   TINYINT UNSIGNED,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_quiz_user (quiz_id, user_id),
    KEY idx_quiz (quiz_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- GROUP CALENDAR  (channel-scoped events)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS collab_events (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel_id   INT UNSIGNED    NOT NULL,
    title        VARCHAR(200)    NOT NULL,
    description  TEXT,
    type         ENUM('study','deadline','meeting','exam','social','other') NOT NULL DEFAULT 'study',
    color        VARCHAR(30)     NOT NULL DEFAULT '#a855f7',
    start_time   DATETIME        NOT NULL,
    end_time     DATETIME        NOT NULL,
    all_day      TINYINT(1)      NOT NULL DEFAULT 0,
    recurring    ENUM('none','daily','weekly','monthly') NOT NULL DEFAULT 'none',
    created_by   INT UNSIGNED    NOT NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_channel_time (channel_id, start_time),
    KEY idx_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS collab_event_rsvps (
    event_id   BIGINT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED    NOT NULL,
    status     ENUM('going','maybe','not_going') NOT NULL DEFAULT 'going',
    rsvped_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
