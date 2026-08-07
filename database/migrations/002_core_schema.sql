-- ============================================================
-- ECOLLAB — Complete Database Setup
-- One file to rule them all.
-- Run order: DB creation → Schema → Addons → Seeds
-- Engine: InnoDB | Charset: utf8mb4
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;
SET SQL_MODE = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- ============================================================
-- DATABASE
-- ============================================================
-- NOTE: The CREATE DATABASE / USE statements that originally
-- appeared here have been removed. The migration runner
-- (database/migrate.php) connects via PDO using the database
-- name configured in .env (DB_NAME), and all DDL below operates
-- on that connection's current database automatically.
--
-- A hardcoded "USE ecollab_v2" would silently redirect every
-- statement in this file to a database named "ecollab_v2"
-- regardless of what the application is actually configured to
-- use — breaking any installation with a different DB_NAME
-- (e.g. "ecollab", "ecollab_prod", "ecollab_staging").
--
-- If you need to create the database itself (first-time setup
-- only), run:
--   CREATE DATABASE IF NOT EXISTS <your_db_name>
--     CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- using the database name from your .env file, BEFORE running
-- database/migrate.php.
-- ============================================================

-- ============================================================
-- ECOLLAB – Production MySQL Database Schema
-- Platform: AI-Powered Peer Learning & Collaboration
-- Institution: Fatima University (Computing Department)
-- Generated from frontend UI analysis of all 7 HTML files
-- Engine: InnoDB | Charset: utf8mb4
-- ============================================================



-- ============================================================
-- 1. SUBSCRIPTION PLANS
--    (Inferred from Pricing section: Free / Pro / Campus)
-- ============================================================


-- ============================================================
-- 2. INSTITUTIONS
--    (Inferred: "Fatima Computing", university SSO)
-- ============================================================
CREATE TABLE institutions (
  id            INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  name          VARCHAR(120)   NOT NULL,               -- e.g. Fatima University
  domain        VARCHAR(80)    NOT NULL,               -- e.g. fatima.edu.ph
  sso_provider  VARCHAR(50)    NULL,                   -- microsoft, google, saml
  logo_url      VARCHAR(500)   NULL,
  is_active     TINYINT(1)     NOT NULL DEFAULT 1,
  created_at    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_domain (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='University/institution records';


-- ============================================================
-- 3. COURSES (Academic Programs)
--    (Inferred from signup step 2 dropdowns)
-- ============================================================
CREATE TABLE academic_programs (
  id             SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  institution_id INT UNSIGNED      NOT NULL,
  code           VARCHAR(20)       NOT NULL,           -- BSCS, BSIT, BSIS, BSCOE
  name           VARCHAR(120)      NOT NULL,
  abbreviation   VARCHAR(15)       NOT NULL,
  is_active      TINYINT(1)        NOT NULL DEFAULT 1,
  created_at     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_prog_code_inst (institution_id, code),
  KEY idx_institution (institution_id),
  CONSTRAINT fk_prog_institution FOREIGN KEY (institution_id)
    REFERENCES institutions(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='BS Computer Science, BS IT, etc.';


-- ============================================================
-- 4. USERS
--    (Inferred from login, signup, admin users table,
--     and role system: Student / Facilitator / Moderator / Admin)
-- ============================================================
CREATE TABLE users (
  id                   BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  institution_id       INT UNSIGNED      NULL,
  username             VARCHAR(50)       NOT NULL,
  email                VARCHAR(120)      NOT NULL,
  student_id           VARCHAR(30)       NULL,               -- university student number
  password_hash        VARCHAR(255)      NULL,               -- NULL if SSO-only
  full_name            VARCHAR(120)      NOT NULL,
  avatar_url           VARCHAR(500)      NULL,
  avatar_color_gradient VARCHAR(80)      NULL,               -- e.g. "#FF2D75,#9F3BFF"
  role                 ENUM('student','facilitator','moderator','admin','super_admin')
                                         NOT NULL DEFAULT 'student',
  status               ENUM('active','offline','idle','banned','suspended','deactivated')
                                         NOT NULL DEFAULT 'offline',
  email_verified       TINYINT(1)        NOT NULL DEFAULT 0,
  is_online            TINYINT(1)        NOT NULL DEFAULT 0,
  last_seen_at         DATETIME          NULL,
  bio                  TEXT              NULL,
  tokens_balance       INT UNSIGNED      NOT NULL DEFAULT 0,  -- platform token economy
  is_verified          TINYINT(1)        NOT NULL DEFAULT 0,  -- blue verified chip
  sso_provider         VARCHAR(30)       NULL,                -- google, microsoft
  sso_uid              VARCHAR(120)      NULL,
  remember_token       VARCHAR(100)      NULL,
  created_at           DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at           DATETIME          NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_email (email),
  UNIQUE KEY uq_username (username),
  UNIQUE KEY uq_student_id (institution_id, student_id),
  KEY idx_role (role),
  KEY idx_status (status),
  KEY idx_institution (institution_id),
  KEY idx_sso (sso_provider, sso_uid),
  CONSTRAINT fk_user_institution FOREIGN KEY (institution_id)
    REFERENCES institutions(id) ON DELETE SET NULL ON UPDATE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='All platform users across all roles';


-- ============================================================
-- 5. USER PROFILES (Extended)
--    (Inferred from signup step 2 & 3, student dashboard profile card)
-- ============================================================
CREATE TABLE user_profiles (
  user_id          BIGINT UNSIGNED  NOT NULL,
  academic_program_id SMALLINT UNSIGNED NULL,
  year_level       TINYINT UNSIGNED NULL,              -- 1–4
  study_style      ENUM('solo','group','mixed') NULL,
  primary_goal     ENUM('pass_exams','build_projects','find_study_partners',
                        'improve_skills','network_collaborate') NULL,
  total_study_hours DECIMAL(8,2)   NOT NULL DEFAULT 0,
  contribution_points INT UNSIGNED  NOT NULL DEFAULT 0, -- leaderboard points
  current_streak_days INT UNSIGNED  NOT NULL DEFAULT 0,
  weekly_goal_hours DECIMAL(5,2)   NOT NULL DEFAULT 20,
  timezone         VARCHAR(50)     NULL DEFAULT 'Asia/Manila',
  github_url       VARCHAR(255)    NULL,
  linkedin_url     VARCHAR(255)    NULL,
  portfolio_url    VARCHAR(255)    NULL,
  updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  KEY idx_program (academic_program_id),
  CONSTRAINT fk_profile_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_profile_program FOREIGN KEY (academic_program_id)
    REFERENCES academic_programs(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Extended student/facilitator profile data';


-- ============================================================
-- 6. INTEREST TAGS
--    (Inferred from signup step 3 tag chips: AI, Web Dev, etc.)
-- ============================================================
CREATE TABLE interest_tags (
  id         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(60)       NOT NULL,
  slug       VARCHAR(60)       NOT NULL,
  category   VARCHAR(40)       NULL,                   -- e.g. "technology", "science"
  created_at DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Selectable interest tags from signup';

CREATE TABLE user_interests (
  user_id         BIGINT UNSIGNED   NOT NULL,
  interest_tag_id SMALLINT UNSIGNED NOT NULL,
  created_at      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, interest_tag_id),
  KEY idx_tag (interest_tag_id),
  CONSTRAINT fk_ui_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ui_tag FOREIGN KEY (interest_tag_id)
    REFERENCES interest_tags(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Many-to-many: users and interest tags';


-- ============================================================
-- 7. SERVERS (Study Communities / Channels)
--    (Inferred from student dashboard "Servers" section,
--     chat sidebar server list, admin Servers page)
-- ============================================================
CREATE TABLE servers (
  id              INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  owner_id        BIGINT UNSIGNED   NOT NULL,
  institution_id  INT UNSIGNED      NULL,
  name            VARCHAR(80)       NOT NULL,
  slug            VARCHAR(80)       NOT NULL,
  description     TEXT              NULL,
  icon_emoji      VARCHAR(10)       NULL,
  icon_url        VARCHAR(500)      NULL,
  category        VARCHAR(40)       NULL,              -- cs, it, math, general, etc.
  type            ENUM('public','private','institution') NOT NULL DEFAULT 'public',
  status          ENUM('active','archived','suspended') NOT NULL DEFAULT 'active',
  member_count    INT UNSIGNED      NOT NULL DEFAULT 0, -- cached counter
  is_verified     TINYINT(1)        NOT NULL DEFAULT 0,
  created_at      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_slug (slug),
  KEY idx_owner (owner_id),
  KEY idx_category (category),
  KEY idx_type (type),
  CONSTRAINT fk_server_owner FOREIGN KEY (owner_id)
    REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_server_institution FOREIGN KEY (institution_id)
    REFERENCES institutions(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Study communities / Discord-like servers';

CREATE TABLE server_members (
  id          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  server_id   INT UNSIGNED     NOT NULL,
  user_id     BIGINT UNSIGNED  NOT NULL,
  server_role ENUM('owner','admin','moderator','member') NOT NULL DEFAULT 'member',
  nickname    VARCHAR(50)      NULL,
  joined_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_server_user (server_id, user_id),
  KEY idx_user (user_id),
  CONSTRAINT fk_sm_server FOREIGN KEY (server_id)
    REFERENCES servers(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_sm_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Server membership junction table';

CREATE TABLE server_tags (
  server_id       INT UNSIGNED      NOT NULL,
  interest_tag_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (server_id, interest_tag_id),
  CONSTRAINT fk_st_server FOREIGN KEY (server_id)
    REFERENCES servers(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_st_tag FOREIGN KEY (interest_tag_id)
    REFERENCES interest_tags(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Tags associated with a server';


-- ============================================================
-- 8. CHANNELS (Text / Voice within a Server)
--    (Inferred from chat UI: #general, #announcements, voice rooms,
--     facilitator dashboard "Channels" page)
-- ============================================================
CREATE TABLE channels (
  id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  server_id   INT UNSIGNED    NOT NULL,
  name        VARCHAR(60)     NOT NULL,
  slug        VARCHAR(60)     NOT NULL,
  type        ENUM('text','voice','announcement','whiteboard','study_room')
                              NOT NULL DEFAULT 'text',
  description VARCHAR(255)    NULL,
  position    SMALLINT        NOT NULL DEFAULT 0,      -- display order
  is_private  TINYINT(1)      NOT NULL DEFAULT 0,
  is_locked   TINYINT(1)      NOT NULL DEFAULT 0,
  member_count INT UNSIGNED   NOT NULL DEFAULT 0,      -- cached for voice
  created_by  BIGINT UNSIGNED NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_channel_slug (server_id, slug),
  KEY idx_server (server_id),
  KEY idx_type (type),
  CONSTRAINT fk_ch_server FOREIGN KEY (server_id)
    REFERENCES servers(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ch_creator FOREIGN KEY (created_by)
    REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Text and voice channels within servers';


-- ============================================================
-- 9. STUDY ROOMS
--    (Inferred from student dashboard "Rooms" page,
--     join room / create room modals, voice channel view)
-- ============================================================
CREATE TABLE study_rooms (
  id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  channel_id      INT UNSIGNED    NULL,               -- optional link to a channel
  server_id       INT UNSIGNED    NULL,
  host_id         BIGINT UNSIGNED NULL,
  name            VARCHAR(80)     NOT NULL,
  join_code       VARCHAR(12)     NULL,               -- used in "join room by code"
  description     TEXT            NULL,
  subject         VARCHAR(80)     NULL,               -- e.g. "CS 305 - Neural Networks"
  icon_emoji      VARCHAR(10)     NULL DEFAULT '🏠',   -- added: real app code (UserService.php) queries this
  max_members     INT             NOT NULL DEFAULT 25, -- added: real app code queries this (distinct from max_participants below)
  created_by      BIGINT UNSIGNED NULL,                -- added: needed by the addon seed data's INSERT column list
  type            ENUM('open','private','invite_only') NOT NULL DEFAULT 'open',
  status          ENUM('active','scheduled','ended','archived') NOT NULL DEFAULT 'active',
  max_participants TINYINT UNSIGNED NOT NULL DEFAULT 10,
  participant_count TINYINT UNSIGNED NOT NULL DEFAULT 0, -- cached
  has_voice       TINYINT(1)      NOT NULL DEFAULT 0,
  has_video       TINYINT(1)      NOT NULL DEFAULT 0,
  has_whiteboard  TINYINT(1)      NOT NULL DEFAULT 0,
  scheduled_at    DATETIME        NULL,
  ended_at        DATETIME        NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_join_code (join_code),
  KEY idx_host (host_id),
  KEY idx_status (status),
  KEY idx_server (server_id),
  CONSTRAINT fk_room_host FOREIGN KEY (host_id)
    REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_room_channel FOREIGN KEY (channel_id)
    REFERENCES channels(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_room_server FOREIGN KEY (server_id)
    REFERENCES servers(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Live/scheduled study rooms (voice, video, whiteboard)';

CREATE TABLE study_room_participants (
  id          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  room_id     INT UNSIGNED     NOT NULL,
  session_id  BIGINT UNSIGNED  NULL,                   -- added: needed by the addon seed data's INSERT column list
  user_id     BIGINT UNSIGNED  NOT NULL,
  role        ENUM('host','speaker','listener') NOT NULL DEFAULT 'listener',
  is_muted    TINYINT(1)       NOT NULL DEFAULT 0,
  is_video_on TINYINT(1)       NOT NULL DEFAULT 0,
  is_active   TINYINT(1)       NOT NULL DEFAULT 1,     -- added: real app code (UserService.php) queries srp.is_active
  joined_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  left_at     DATETIME         NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_room_user_active (room_id, user_id, left_at),
  KEY idx_user (user_id),
  CONSTRAINT fk_rp_room FOREIGN KEY (room_id)
    REFERENCES study_rooms(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_rp_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Participants in a study room session';


-- ============================================================
-- 10. MESSAGES (Text Channels & Direct Messages)
--     (Inferred from chat-sample4.html full chat UI)
-- ============================================================
CREATE TABLE messages (
  id           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  channel_id   INT UNSIGNED     NULL,                 -- NULL if direct message
  room_id      INT UNSIGNED     NULL,                 -- NULL if channel message
  sender_id    BIGINT UNSIGNED  NOT NULL,
  parent_id    BIGINT UNSIGNED  NULL,                 -- for thread replies
  content      TEXT             NOT NULL,
  content_type ENUM('text','image','file','system','ai_response')
                                NOT NULL DEFAULT 'text',
  is_edited    TINYINT(1)       NOT NULL DEFAULT 0,
  is_deleted   TINYINT(1)       NOT NULL DEFAULT 0,
  is_pinned    TINYINT(1)       NOT NULL DEFAULT 0,
  reaction_count INT UNSIGNED   NOT NULL DEFAULT 0,   -- cached
  created_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at   DATETIME         NULL,
  PRIMARY KEY (id),
  KEY idx_channel (channel_id),
  KEY idx_room (room_id),
  KEY idx_sender (sender_id),
  KEY idx_parent (parent_id),
  KEY idx_created (created_at),
  CONSTRAINT fk_msg_channel FOREIGN KEY (channel_id)
    REFERENCES channels(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_msg_room FOREIGN KEY (room_id)
    REFERENCES study_rooms(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_msg_sender FOREIGN KEY (sender_id)
    REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_msg_parent FOREIGN KEY (parent_id)
    REFERENCES messages(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Chat messages for channels and rooms';

CREATE TABLE direct_messages (
  id           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  sender_id    BIGINT UNSIGNED  NOT NULL,
  recipient_id BIGINT UNSIGNED  NOT NULL,
  content      TEXT             NOT NULL,
  content_type ENUM('text','image','file') NOT NULL DEFAULT 'text',
  is_read      TINYINT(1)       NOT NULL DEFAULT 0,
  is_edited    TINYINT(1)       NOT NULL DEFAULT 0,
  is_deleted   TINYINT(1)       NOT NULL DEFAULT 0,
  created_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sender (sender_id),
  KEY idx_recipient (recipient_id),
  KEY idx_conversation (sender_id, recipient_id, created_at),
  CONSTRAINT fk_dm_sender FOREIGN KEY (sender_id)
    REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_dm_recipient FOREIGN KEY (recipient_id)
    REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Direct/private messages between users';

CREATE TABLE message_reactions (
  id         BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  message_id BIGINT UNSIGNED  NOT NULL,
  user_id    BIGINT UNSIGNED  NOT NULL,
  emoji      VARCHAR(10)      NOT NULL,
  created_at DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reaction (message_id, user_id, emoji),
  KEY idx_user (user_id),
  CONSTRAINT fk_react_message FOREIGN KEY (message_id)
    REFERENCES messages(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_react_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Emoji reactions on messages';

CREATE TABLE message_attachments (
  id          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  message_id  BIGINT UNSIGNED  NOT NULL,
  file_name   VARCHAR(255)     NOT NULL,
  file_path   VARCHAR(500)     NOT NULL,
  file_size   BIGINT UNSIGNED  NOT NULL,              -- bytes
  mime_type   VARCHAR(100)     NOT NULL,
  created_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_message (message_id),
  CONSTRAINT fk_att_message FOREIGN KEY (message_id)
    REFERENCES messages(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Files attached to messages';


-- ============================================================
-- 11. SUBJECT CLASSES (Facilitator-managed)
--     (Inferred from facilitator dashboard channel system,
--      student enroll modal, course cards CS 305, CS 201 etc.)
-- ============================================================
CREATE TABLE subject_classes (
  id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  facilitator_id  BIGINT UNSIGNED  NOT NULL,
  server_id       INT UNSIGNED     NULL,              -- linked server/community
  institution_id  INT UNSIGNED     NULL,
  subject_code    VARCHAR(20)      NOT NULL,          -- CS 305, CS 201, CS 210, etc.
  subject_name    VARCHAR(120)     NOT NULL,
  section         VARCHAR(20)      NULL,
  semester        VARCHAR(20)      NULL,              -- 1st Sem, 2nd Sem
  school_year     VARCHAR(10)      NULL,              -- 2025-2026
  description     TEXT             NULL,
  enroll_code     VARCHAR(12)      NULL,              -- code for students to join
  max_students    SMALLINT UNSIGNED NOT NULL DEFAULT 50,
  student_count   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  status          ENUM('active','archived','upcoming') NOT NULL DEFAULT 'active',
  created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_enroll_code (enroll_code),
  KEY idx_facilitator (facilitator_id),
  KEY idx_server (server_id),
  CONSTRAINT fk_class_facilitator FOREIGN KEY (facilitator_id)
    REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_class_server FOREIGN KEY (server_id)
    REFERENCES servers(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_class_institution FOREIGN KEY (institution_id)
    REFERENCES institutions(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Facilitator-run subject classes (CS 305, CS 201, etc.)';

CREATE TABLE class_enrollments (
  id          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  class_id    INT UNSIGNED     NOT NULL,
  student_id  BIGINT UNSIGNED  NOT NULL,
  status      ENUM('enrolled','dropped','completed','pending') NOT NULL DEFAULT 'enrolled',
  grade       DECIMAL(5,2)     NULL,
  enrolled_at DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_enrollment (class_id, student_id),
  KEY idx_student (student_id),
  CONSTRAINT fk_enr_class FOREIGN KEY (class_id)
    REFERENCES subject_classes(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_enr_student FOREIGN KEY (student_id)
    REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Student enrollment in subject classes';


-- ============================================================
-- 12. STUDY SESSIONS (Time Tracking)
--     (Inferred from student dashboard activity chart,
--      weekly hours, "20h goal" tracker)
-- ============================================================
CREATE TABLE study_sessions (
  id             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id        BIGINT UNSIGNED  NOT NULL,
  room_id        INT UNSIGNED     NULL,
  class_id       INT UNSIGNED     NULL,
  subject_label  VARCHAR(80)      NULL,               -- e.g. "CS 305 Chapter 6"
  started_at     DATETIME         NOT NULL,
  ended_at       DATETIME         NULL,
  duration_mins  INT UNSIGNED     NULL,               -- computed on end
  notes          TEXT             NULL,
  created_at     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user_date (user_id, started_at),
  KEY idx_room (room_id),
  CONSTRAINT fk_ss_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ss_room FOREIGN KEY (room_id)
    REFERENCES study_rooms(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ss_class FOREIGN KEY (class_id)
    REFERENCES subject_classes(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Per-user study session time tracking';


-- ============================================================
-- 13. NOTES (Student notes editor)
--     (Inferred from student dashboard "Notes" page:
--      new note modal, note cards with title/preview/edit/delete)
-- ============================================================
CREATE TABLE notes (
  id          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED  NOT NULL,
  class_id    INT UNSIGNED     NULL,
  title       VARCHAR(200)     NOT NULL,
  content     LONGTEXT         NULL,
  is_pinned   TINYINT(1)       NOT NULL DEFAULT 0,
  created_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at  DATETIME         NULL,
  PRIMARY KEY (id),
  KEY idx_user (user_id),
  KEY idx_class (class_id),
  FULLTEXT KEY ft_notes (title, content),
  CONSTRAINT fk_note_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_note_class FOREIGN KEY (class_id)
    REFERENCES subject_classes(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Student personal notes with CRUD';


-- ============================================================
-- 14. FILES / UPLOADS
--     (Inferred from student "Files" page, upload modal,
--      file rows with Download button)
-- ============================================================
CREATE TABLE uploaded_files (
  id           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  uploader_id  BIGINT UNSIGNED  NOT NULL,
  class_id     INT UNSIGNED     NULL,
  server_id    INT UNSIGNED     NULL,
  channel_id   INT UNSIGNED     NULL,
  file_name    VARCHAR(255)     NOT NULL,
  original_name VARCHAR(255)    NOT NULL,
  file_path    VARCHAR(500)     NOT NULL,
  file_size    BIGINT UNSIGNED  NOT NULL,              -- bytes
  mime_type    VARCHAR(100)     NOT NULL,
  description  TEXT             NULL,
  download_count INT UNSIGNED   NOT NULL DEFAULT 0,
  is_public    TINYINT(1)       NOT NULL DEFAULT 0,
  created_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at   DATETIME         NULL,
  PRIMARY KEY (id),
  KEY idx_uploader (uploader_id),
  KEY idx_class (class_id),
  KEY idx_server (server_id),
  CONSTRAINT fk_file_uploader FOREIGN KEY (uploader_id)
    REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_file_class FOREIGN KEY (class_id)
    REFERENCES subject_classes(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_file_server FOREIGN KEY (server_id)
    REFERENCES servers(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_file_channel FOREIGN KEY (channel_id)
    REFERENCES channels(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Files uploaded by users to classes, servers, or channels';


-- ============================================================
-- 15. AI ASSISTANT LOGS
--     (Inferred from AI widget in sidebar, AI chat panel,
--      prompts like "Summarize Neural Networks Ch.5",
--      admin AI System Health monitor)
-- ============================================================
CREATE TABLE ai_sessions (
  id             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id        BIGINT UNSIGNED  NOT NULL,
  class_id       INT UNSIGNED     NULL,               -- context class if any
  session_title  VARCHAR(120)     NULL,               -- auto-generated or user-set
  message_count  INT UNSIGNED     NOT NULL DEFAULT 0,
  token_cost     INT UNSIGNED     NOT NULL DEFAULT 0,
  created_at     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user (user_id),
  CONSTRAINT fk_ais_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI assistant conversation sessions';

CREATE TABLE ai_messages (
  id          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  session_id  BIGINT UNSIGNED  NOT NULL,
  role        ENUM('user','assistant') NOT NULL,
  content     TEXT             NOT NULL,
  token_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_session (session_id),
  CONSTRAINT fk_aim_session FOREIGN KEY (session_id)
    REFERENCES ai_sessions(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Individual messages in AI sessions';

CREATE TABLE ai_quick_prompts (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  label       VARCHAR(80)       NOT NULL,             -- "Summarize Ch.5", "Quiz me"
  prompt_text TEXT              NOT NULL,
  category    VARCHAR(40)       NULL,
  is_active   TINYINT(1)        NOT NULL DEFAULT 1,
  sort_order  TINYINT UNSIGNED  NOT NULL DEFAULT 0,
  created_at  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Pre-built AI quick prompt chips on dashboard';


-- ============================================================
-- 16. NOTIFICATIONS
--     (Inferred from notification bell with badge count,
--      notification dropdown panel in all dashboards)
-- ============================================================
CREATE TABLE notifications (
  id           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  recipient_id BIGINT UNSIGNED  NOT NULL,
  actor_id     BIGINT UNSIGNED  NULL,                 -- who triggered it
  type         ENUM('message','mention','room_invite','class_update',
                    'server_join','moderation','ai','system','match')
                                NOT NULL DEFAULT 'system',
  title        VARCHAR(120)     NOT NULL,
  body         VARCHAR(500)     NULL,
  link_url     VARCHAR(255)     NULL,
  is_read      TINYINT(1)       NOT NULL DEFAULT 0,
  created_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at      DATETIME         NULL,
  PRIMARY KEY (id),
  KEY idx_recipient_unread (recipient_id, is_read, created_at),
  KEY idx_actor (actor_id),
  CONSTRAINT fk_notif_recipient FOREIGN KEY (recipient_id)
    REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_notif_actor FOREIGN KEY (actor_id)
    REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='In-app notification system';


-- ============================================================
-- 17. AI PEER MATCHING
--     (Inferred from landing page "AI-Powered Matching",
--      study style + interests + goals used in matching)
-- ============================================================
CREATE TABLE peer_matches (
  id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_a_id       BIGINT UNSIGNED  NOT NULL,
  user_b_id       BIGINT UNSIGNED  NOT NULL,
  match_score     DECIMAL(5,2)     NOT NULL,          -- 0.00-100.00
  match_reason    TEXT             NULL,              -- AI explanation
  status          ENUM('pending','accepted','rejected','expired')
                                   NOT NULL DEFAULT 'pending',
  initiated_by    ENUM('ai','user') NOT NULL DEFAULT 'ai',
  created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  responded_at    DATETIME         NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_match_pair (user_a_id, user_b_id),
  KEY idx_user_b (user_b_id),
  KEY idx_status (status),
  CONSTRAINT fk_match_a FOREIGN KEY (user_a_id)
    REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_match_b FOREIGN KEY (user_b_id)
    REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI-generated peer study partner matches';


-- ============================================================
-- 18. CALENDAR EVENTS / TASKS
--     (Inferred from student dashboard calendar widget,
--      scheduled sessions, event dots on calendar)
-- ============================================================
CREATE TABLE calendar_events (
  id            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED  NOT NULL,
  class_id      INT UNSIGNED     NULL,
  room_id       INT UNSIGNED     NULL,
  title         VARCHAR(120)     NOT NULL,
  description   TEXT             NULL,
  event_type    ENUM('study_session','exam','deadline','room_scheduled',
                     'class_event','personal') NOT NULL DEFAULT 'personal',
  starts_at     DATETIME         NOT NULL,
  ends_at       DATETIME         NULL,
  is_all_day    TINYINT(1)       NOT NULL DEFAULT 0,
  color_hex     VARCHAR(7)       NULL,               -- e.g. #e91e8c
  created_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user_date (user_id, starts_at),
  KEY idx_class (class_id),
  CONSTRAINT fk_cal_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_cal_class FOREIGN KEY (class_id)
    REFERENCES subject_classes(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_cal_room FOREIGN KEY (room_id)
    REFERENCES study_rooms(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Per-user calendar events and scheduled sessions';


-- ============================================================
-- 19. WHITEBOARD SESSIONS
--     (Inferred from student dashboard Whiteboard tab,
--      canvas tools: pen, eraser, color, size)
-- ============================================================
CREATE TABLE whiteboard_sessions (
  id           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  room_id      INT UNSIGNED     NULL,
  class_id     INT UNSIGNED     NULL,
  created_by   BIGINT UNSIGNED  NOT NULL,
  title        VARCHAR(80)      NULL,
  canvas_data  LONGTEXT         NULL,                -- JSON stroke data or base64
  thumbnail    VARCHAR(500)     NULL,
  status       ENUM('active','saved','archived') NOT NULL DEFAULT 'active',
  created_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_room (room_id),
  KEY idx_creator (created_by),
  CONSTRAINT fk_wb_room FOREIGN KEY (room_id)
    REFERENCES study_rooms(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_wb_creator FOREIGN KEY (created_by)
    REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Collaborative whiteboard sessions';


-- ============================================================
-- 20. PLATFORM ROLES & PERMISSIONS (RBAC)
--     (Inferred from admin "Roles & Permissions" page,
--      permission toggles, role cards with permission tags)
-- ============================================================
CREATE TABLE roles (
  id          TINYINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  name        VARCHAR(40)       NOT NULL,             -- Student, Facilitator, Moderator, Admin
  slug        VARCHAR(40)       NOT NULL,
  description TEXT              NULL,
  color_hex   VARCHAR(7)        NULL,
  is_system   TINYINT(1)        NOT NULL DEFAULT 0,  -- system roles can't be deleted
  created_at  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Platform roles (RBAC)';

CREATE TABLE permissions (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(60)       NOT NULL,
  slug        VARCHAR(60)       NOT NULL,
  category    VARCHAR(40)       NULL,                -- general, moderation, channels, rooms...
  description VARCHAR(255)      NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Granular platform permissions';

CREATE TABLE role_permissions (
  role_id       TINYINT UNSIGNED  NOT NULL,
  permission_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id)
    REFERENCES roles(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id)
    REFERENCES permissions(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Role-permission junction table';


-- ============================================================
-- 21. MODERATION ACTIONS
--     (Inferred from admin moderation queue: ban, kick, mute, warn,
--      moderation log with types and reasons)
-- ============================================================
CREATE TABLE moderation_actions (
  id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  moderator_id    BIGINT UNSIGNED  NOT NULL,
  target_user_id  BIGINT UNSIGNED  NOT NULL,
  server_id       INT UNSIGNED     NULL,
  action_type     ENUM('ban','kick','mute','warn','unmute','unban','delete_message')
                                   NOT NULL,
  reason          TEXT             NULL,
  duration_mins   INT UNSIGNED     NULL,             -- NULL = permanent
  expires_at      DATETIME         NULL,
  message_ref_id  BIGINT UNSIGNED  NULL,             -- if action is about a message
  is_active       TINYINT(1)       NOT NULL DEFAULT 1,
  created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_target (target_user_id),
  KEY idx_moderator (moderator_id),
  KEY idx_server (server_id),
  KEY idx_type (action_type),
  CONSTRAINT fk_mod_moderator FOREIGN KEY (moderator_id)
    REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_mod_target FOREIGN KEY (target_user_id)
    REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_mod_server FOREIGN KEY (server_id)
    REFERENCES servers(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='All moderation actions (ban, mute, kick, warn)';


-- ============================================================
-- 22. CONTENT REPORTS (Moderation queue)
--     (Inferred from moderation queue items: flagged messages,
--      reported content in study rooms)
-- ============================================================
CREATE TABLE content_reports (
  id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  reporter_id     BIGINT UNSIGNED  NOT NULL,
  reported_user_id BIGINT UNSIGNED NULL,
  message_id      BIGINT UNSIGNED  NULL,
  server_id       INT UNSIGNED     NULL,
  room_id         INT UNSIGNED     NULL,
  reason          ENUM('spam','harassment','inappropriate','phishing','other')
                                   NOT NULL DEFAULT 'other',
  description     TEXT             NULL,
  status          ENUM('pending','reviewing','resolved','dismissed')
                                   NOT NULL DEFAULT 'pending',
  resolved_by     BIGINT UNSIGNED  NULL,
  resolved_at     DATETIME         NULL,
  resolution_note TEXT             NULL,
  created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_reporter (reporter_id),
  KEY idx_reported_user (reported_user_id),
  KEY idx_status (status),
  CONSTRAINT fk_report_reporter FOREIGN KEY (reporter_id)
    REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_report_resolver FOREIGN KEY (resolved_by)
    REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='User-submitted content/behavior reports';


-- ============================================================
-- 23. ACTIVITY LOGS (System + User audit trail)
--     (Inferred from admin "Activity Logs" page,
--      system log panel with timestamped entries)
-- ============================================================
CREATE TABLE activity_logs (
  id           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id      BIGINT UNSIGNED  NULL,                -- NULL for system events
  action       VARCHAR(80)      NOT NULL,            -- "user.login", "room.create", etc.
  entity_type  VARCHAR(40)      NULL,                -- "user", "room", "message", etc.
  entity_id    BIGINT UNSIGNED  NULL,
  description  VARCHAR(500)     NULL,
  ip_address   VARCHAR(45)      NULL,
  user_agent   VARCHAR(500)     NULL,
  level        ENUM('info','warning','error','critical') NOT NULL DEFAULT 'info',
  metadata     JSON             NULL,                -- extra context data
  created_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user (user_id),
  KEY idx_action (action),
  KEY idx_level (level),
  KEY idx_created (created_at),
  CONSTRAINT fk_log_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Full audit log of platform events and user actions';


-- ============================================================
-- 24. FEEDBACK / BUG REPORTS
--     (Inferred from admin "Feedback" page with types:
--      bug report, feature request, star rating)
-- ============================================================
CREATE TABLE feedback (
  id          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED  NULL,
  type        ENUM('bug','feature_request','general','praise') NOT NULL DEFAULT 'general',
  title       VARCHAR(200)     NOT NULL,
  description TEXT             NOT NULL,
  rating      TINYINT UNSIGNED NULL,                 -- 1-5 star rating
  status      ENUM('open','reviewing','resolved','closed') NOT NULL DEFAULT 'open',
  resolved_by BIGINT UNSIGNED  NULL,
  created_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user (user_id),
  KEY idx_type (type),
  KEY idx_status (status),
  CONSTRAINT fk_fb_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='User-submitted feedback and bug reports';


-- ============================================================
-- 25. USER SETTINGS & PREFERENCES
--     (Inferred from settings dropdown, profile chip,
--      system-level toggles in admin settings)
-- ============================================================
CREATE TABLE user_settings (
  user_id             BIGINT UNSIGNED  NOT NULL,
  notification_msgs   TINYINT(1)       NOT NULL DEFAULT 1,
  notification_rooms  TINYINT(1)       NOT NULL DEFAULT 1,
  notification_ai     TINYINT(1)       NOT NULL DEFAULT 1,
  notification_system TINYINT(1)       NOT NULL DEFAULT 1,
  theme               ENUM('dark','light','system') NOT NULL DEFAULT 'dark',
  language            VARCHAR(10)      NOT NULL DEFAULT 'en',
  timezone            VARCHAR(50)      NOT NULL DEFAULT 'Asia/Manila',
  show_online_status  TINYINT(1)       NOT NULL DEFAULT 1,
  allow_dm            TINYINT(1)       NOT NULL DEFAULT 1,
  updated_at          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_settings_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Per-user settings and notification preferences';


-- ============================================================
-- 26. PLATFORM ANALYTICS (Admin dashboard stats)
--     (Inferred from admin dashboard stat cards:
--      total users, active rooms, messages/day, AI matches,
--      user growth chart, heatmap)
-- ============================================================
CREATE TABLE platform_analytics_daily (
  id                    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  date                  DATE          NOT NULL,
  total_users           INT UNSIGNED  NOT NULL DEFAULT 0,
  new_registrations     INT UNSIGNED  NOT NULL DEFAULT 0,
  active_users          INT UNSIGNED  NOT NULL DEFAULT 0,
  total_messages        INT UNSIGNED  NOT NULL DEFAULT 0,
  total_rooms_active    INT UNSIGNED  NOT NULL DEFAULT 0,
  total_study_hours     DECIMAL(10,2) NOT NULL DEFAULT 0,
  ai_sessions_count     INT UNSIGNED  NOT NULL DEFAULT 0,
  ai_matches_made       INT UNSIGNED  NOT NULL DEFAULT 0,
  files_uploaded        INT UNSIGNED  NOT NULL DEFAULT 0,
  new_servers           INT UNSIGNED  NOT NULL DEFAULT 0,
  moderation_actions    INT UNSIGNED  NOT NULL DEFAULT 0,
  created_at            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Daily platform-wide analytics snapshots';

CREATE TABLE user_analytics_weekly (
  id                  BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id             BIGINT UNSIGNED  NOT NULL,
  week_start          DATE             NOT NULL,
  study_hours         DECIMAL(6,2)     NOT NULL DEFAULT 0,
  messages_sent       INT UNSIGNED     NOT NULL DEFAULT 0,
  rooms_joined        INT UNSIGNED     NOT NULL DEFAULT 0,
  notes_created       INT UNSIGNED     NOT NULL DEFAULT 0,
  files_uploaded      INT UNSIGNED     NOT NULL DEFAULT 0,
  ai_queries          INT UNSIGNED     NOT NULL DEFAULT 0,
  contribution_pts    INT UNSIGNED     NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_week (user_id, week_start),
  CONSTRAINT fk_ua_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Per-user weekly activity analytics for dashboard charts';


-- ============================================================
-- 27. FACILITATOR STATS SNAPSHOT
--     (Inferred from facilitator dashboard stat cards:
--      enrolled students, active sessions, avg score, drop rate)
-- ============================================================
CREATE TABLE facilitator_stats (
  facilitator_id     BIGINT UNSIGNED  NOT NULL,
  class_id           INT UNSIGNED     NOT NULL,
  total_students     SMALLINT         NOT NULL DEFAULT 0,
  active_students    SMALLINT         NOT NULL DEFAULT 0,
  avg_score          DECIMAL(5,2)     NULL,
  very_active_count  SMALLINT         NOT NULL DEFAULT 0,
  inactive_count     SMALLINT         NOT NULL DEFAULT 0,
  drop_rate          DECIMAL(5,2)     NULL,
  avg_study_hours    DECIMAL(5,2)     NULL,
  updated_at         DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (facilitator_id, class_id),
  CONSTRAINT fk_fst_facilitator FOREIGN KEY (facilitator_id)
    REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_fst_class FOREIGN KEY (class_id)
    REFERENCES subject_classes(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Cached stats per facilitator-class pair';


-- ============================================================
-- 28. SSO TOKENS (OAuth sessions)
--     (Inferred from Google & Microsoft SSO buttons on login/signup)
-- ============================================================
CREATE TABLE sso_tokens (
  id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id         BIGINT UNSIGNED  NOT NULL,
  provider        ENUM('google','microsoft') NOT NULL,
  provider_uid    VARCHAR(120)     NOT NULL,
  access_token    TEXT             NULL,
  refresh_token   TEXT             NULL,
  expires_at      DATETIME         NULL,
  created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_provider_uid (provider, provider_uid),
  KEY idx_user (user_id),
  CONSTRAINT fk_sso_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='OAuth tokens for Google and Microsoft SSO';


-- ============================================================
-- 29. TOKEN TRANSACTIONS (Platform token economy)
--     (Inferred from "Limited Tokens" in Free plan,
--      token_balance in users table)
-- ============================================================
CREATE TABLE token_transactions (
  id           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id      BIGINT UNSIGNED  NOT NULL,
  type         ENUM('earned','spent','refunded','granted','deducted')
                                NOT NULL,
  amount       INT              NOT NULL,             -- can be negative
  balance_after INT UNSIGNED    NOT NULL,
  reason       VARCHAR(120)     NULL,                -- "AI query", "Study streak", etc.
  reference_id BIGINT UNSIGNED  NULL,                -- e.g. ai_session id
  created_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user (user_id),
  KEY idx_type (type),
  CONSTRAINT fk_tok_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Platform token economy ledger';


-- ============================================================
-- 30. USER SUBSCRIPTIONS
--     (Inferred from pricing page: plan selection, billing)
-- ============================================================


-- ============================================================
-- 31. SYSTEM SETTINGS (Admin-controlled global settings)
--     (Inferred from admin "Settings" page with multiple tabs)
-- ============================================================
CREATE TABLE system_settings (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  category    VARCHAR(40)       NOT NULL,            -- general, ai, security, notifications
  key_name    VARCHAR(60)       NOT NULL,
  value       TEXT              NULL,
  label       VARCHAR(80)       NULL,
  description TEXT              NULL,
  updated_by  BIGINT UNSIGNED   NULL,
  updated_at  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_category_key (category, key_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Global platform configuration managed by admins';



-- ============================================================
-- EXTRA: message_reads  (required by ChannelService.php)
-- ============================================================
CREATE TABLE message_reads (
  user_id      BIGINT UNSIGNED  NOT NULL,
  channel_id   INT UNSIGNED     NOT NULL,
  last_read_at DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, channel_id),
  KEY idx_channel (channel_id),
  CONSTRAINT fk_mr_user    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_mr_channel FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Per-user read position per channel';


-- ============================================================
-- EXTRA: channel_members  (required by ChannelService.php)
-- ============================================================
CREATE TABLE channel_members (
  channel_id  INT UNSIGNED    NOT NULL,
  user_id     BIGINT UNSIGNED NOT NULL,
  added_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (channel_id, user_id),
  KEY idx_user (user_id),
  CONSTRAINT fk_cm_channel FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_cm_user    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Explicit membership for private channels';



-- ============================================================
-- user_hobbies  (signup step 5 — hobby taxonomy + AI matching)
-- ============================================================
CREATE TABLE IF NOT EXISTS user_hobbies (
  id               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  user_id          BIGINT UNSIGNED NOT NULL,
  hobby            VARCHAR(60)     NOT NULL,
  genre            VARCHAR(60)     NULL,
  title            VARCHAR(120)    NULL,
  hours_per_month  SMALLINT        NOT NULL DEFAULT 0,
  playstyle        VARCHAR(60)     NULL,
  experience_level VARCHAR(60)     NULL,
  created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_hobby (user_id, hobby),
  KEY idx_hobby (hobby),
  CONSTRAINT fk_uh_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================
-- END OF SCHEMA
-- Tables: 31 primary + junction tables
-- ============================================================


-- ============================================================
-- ADDON: OAUTH / SSO INDEX
-- ============================================================
-- Ecollab OAuth / SSO — Schema notes
-- The schema already contains sso_provider and sso_uid on the
-- users table, and the sso_tokens table.
-- This file just ensures the index exists and the DB is correct.
-- Run against ecollab_v2.

CREATE INDEX IF NOT EXISTS idx_users_sso ON users (sso_provider, sso_uid);

-- ============================================================
-- ADDON: CHAT TABLES (message_reactions, attachments, reads,
--         whiteboards, column additions)
-- ============================================================
-- Ecollab Chat — Additive Schema (compatible with schema.txt)
-- Run AFTER the main schema.txt migration.
-- All tables use IF NOT EXISTS to be safe on re-runs.


-- ── message_reactions ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS `message_reactions` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id` BIGINT UNSIGNED NOT NULL,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `emoji`      VARCHAR(12)     NOT NULL,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_msg_user_emoji` (`message_id`, `user_id`, `emoji`),
  KEY `idx_message_id` (`message_id`),
  KEY `idx_user_id`    (`user_id`),
  CONSTRAINT `fk_mr_message` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mr_user`    FOREIGN KEY (`user_id`)    REFERENCES `users`    (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── message_attachments ────────────────────────────────────
CREATE TABLE IF NOT EXISTS `message_attachments` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id`  BIGINT UNSIGNED NOT NULL,
  `file_name`   VARCHAR(255)    NOT NULL,
  `file_path`   VARCHAR(512)    NOT NULL,
  `file_size`   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `mime_type`   VARCHAR(127)    NOT NULL DEFAULT 'application/octet-stream',
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_message_id` (`message_id`),
  CONSTRAINT `fk_ma_message` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── message_reads ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `message_reads` (
  `user_id`      BIGINT UNSIGNED NOT NULL,
  `channel_id`   INT UNSIGNED    NOT NULL,
  `last_read_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `channel_id`),
  KEY `idx_channel_id` (`channel_id`),
  CONSTRAINT `fk_mrd_user`    FOREIGN KEY (`user_id`)    REFERENCES `users`    (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mrd_channel` FOREIGN KEY (`channel_id`) REFERENCES `channels` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── whiteboards ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `whiteboards` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `channel_id`  INT UNSIGNED    NOT NULL,
  `state_json`  LONGTEXT        NOT NULL,
  `created_by`  BIGINT UNSIGNED NOT NULL,
  `updated_by`  BIGINT UNSIGNED     NULL DEFAULT NULL,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_channel_id` (`channel_id`),
  CONSTRAINT `fk_wb_channel`     FOREIGN KEY (`channel_id`) REFERENCES `channels` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wb_created_by`  FOREIGN KEY (`created_by`) REFERENCES `users`    (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wb_updated_by`  FOREIGN KEY (`updated_by`) REFERENCES `users`    (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Columns that may be missing from messages table ────────
ALTER TABLE `messages`
  MODIFY COLUMN `content_type` ENUM('text','image','file','code','poll') NOT NULL DEFAULT 'text',
  ADD COLUMN IF NOT EXISTS `is_pinned`    TINYINT(1)       NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `is_edited`    TINYINT(1)       NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `reaction_count` INT UNSIGNED   NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `deleted_at`   DATETIME             NULL DEFAULT NULL;

-- ── Columns that may be missing from channels table ────────
ALTER TABLE `channels`
  ADD COLUMN IF NOT EXISTS `is_locked`   TINYINT(1)  NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `member_count` INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `created_by`  BIGINT UNSIGNED NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `position`    INT          NOT NULL DEFAULT 0;

-- ── is_online / last_active_at on users ───────────────────
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `is_online`       TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `last_active_at`  DATETIME       NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `avatar_color_gradient` VARCHAR(64) NOT NULL DEFAULT '#a855f7,#ec4899';



-- ============================================================
-- ADDON: MISSING TABLES FOR ChannelService
--        (message_reads, channel_members, user_hobbies — safe
--         IF NOT EXISTS guards, won't fail on re-run)
-- ============================================================
-- Ecollab – Missing Tables Required by ChannelService
-- Run against ecollab_v2 AFTER the main schema.


-- message_reads: tracks last-read position per user per channel
-- Used by ChannelService::markRead() and unread_count subquery
CREATE TABLE IF NOT EXISTS message_reads (
  user_id      BIGINT UNSIGNED  NOT NULL,
  channel_id   INT UNSIGNED     NOT NULL,
  last_read_at DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, channel_id),
  KEY idx_channel (channel_id),
  CONSTRAINT fk_mr_user    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_mr_channel FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Tracks per-user read position in each channel for unread counts';


-- channel_members: private channel access control
-- Used by ChannelService::getChannelsForUser() for is_private=1 channels
CREATE TABLE IF NOT EXISTS channel_members (
  channel_id  INT UNSIGNED    NOT NULL,
  user_id     BIGINT UNSIGNED NOT NULL,
  added_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (channel_id, user_id),
  KEY idx_user (user_id),
  CONSTRAINT fk_cm_channel FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_cm_user    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Explicit membership list for private channels';


-- EXTRA: user_hobbies  (required by signup step 5 / AuthService)
CREATE TABLE IF NOT EXISTS user_hobbies (
  id               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  user_id          BIGINT UNSIGNED NOT NULL,
  hobby            VARCHAR(60)     NOT NULL  COMMENT 'Main hobby (Gaming, Music, …)',
  genre            VARCHAR(60)     NULL      COMMENT 'Sub-genre/category',
  title            VARCHAR(120)    NULL      COMMENT 'Specific title or focus',
  hours_per_month  SMALLINT        NOT NULL DEFAULT 0,
  playstyle        VARCHAR(60)     NULL,
  experience_level VARCHAR(60)     NULL,
  created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_hobby (user_id, hobby),
  KEY idx_hobby (hobby),
  CONSTRAINT fk_uh_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Per-user hobby data with engagement metrics for AI matching';


-- 6. INTEREST TAGS (moved ahead of the "EXTRA" auto-increment block below,
--    since user_interests hardcodes interest_tag_id 1-10 expecting these
--    exact rows to claim those IDs first)
INSERT INTO interest_tags (id, name, slug, category)
VALUES
  (1,  'AI',           'ai',           'technology'),
  (2,  'Web Dev',      'web-dev',      'technology'),
  (3,  'Cybersecurity','cybersecurity','technology'),
  (4,  'Data Science', 'data-science', 'technology'),
  (5,  'Mobile Apps',  'mobile-apps',  'technology'),
  (6,  'Game Dev',     'game-dev',     'technology'),
  (7,  'Cloud',        'cloud',        'technology'),
  (8,  'DevOps',       'devops',       'technology'),
  (9,  'Algorithms',   'algorithms',   'computer-science'),
  (10, 'Databases',    'databases',    'computer-science');


-- EXTRA: missing interest_tags seed rows for new slugs
-- Run after schema + seeds. Uses INSERT IGNORE deliberately: several of
-- these slugs (ai, cybersecurity, data-science, devops, game-dev) already
-- exist from the explicit-id block above and are safely skipped here;
-- only the genuinely new collab/goal/availability/academic/creative slugs
-- get inserted.
INSERT IGNORE INTO interest_tags (name, slug, category) VALUES
  -- Collaboration style
  ('Solo Learning', 'solo-learning', 'collab'),
  ('Team Projects', 'team-projects', 'collab'),
  ('Hackathons', 'hackathons', 'collab'),
  ('Study Groups', 'study-groups', 'collab'),
  ('Mentoring', 'mentoring', 'collab'),
  ('Peer Tutoring', 'peer-tutoring', 'collab'),
  -- Goals
  ('Pass Exams', 'pass-exams', 'goal'),
  ('Build a Portfolio', 'build-portfolio', 'goal'),
  ('Learn New Skills', 'learn-new-skills', 'goal'),
  ('Find Teammates', 'find-teammates', 'goal'),
  ('Networking', 'networking', 'goal'),
  ('Freelancing', 'freelancing', 'goal'),
  ('Startup Building', 'startup-building', 'goal'),
  -- Availability
  ('Weekday Mornings', 'weekday-mornings', 'availability'),
  ('Weekday Evenings', 'weekday-evenings', 'availability'),
  ('Weekends', 'weekends', 'availability'),
  ('Late Nights', 'late-nights', 'availability'),
  ('Flexible', 'flexible', 'availability'),
  -- Tech
  ('AI', 'ai', 'tech'),
  ('Web Development', 'web-dev', 'tech'),
  ('Mobile Development', 'mobile-dev', 'tech'),
  ('Cybersecurity', 'cybersecurity', 'tech'),
  ('Data Science', 'data-science', 'tech'),
  ('UI/UX Design', 'ui-ux', 'tech'),
  ('Cloud Computing', 'cloud', 'tech'),
  ('DevOps', 'devops', 'tech'),
  ('Game Development', 'game-dev', 'tech'),
  -- Academic
  ('Mathematics', 'mathematics', 'academic'),
  ('Programming', 'programming', 'academic'),
  ('Research', 'research', 'academic'),
  ('Science', 'science', 'academic'),
  ('Engineering', 'engineering', 'academic'),
  ('Business', 'business', 'academic'),
  ('Public Speaking', 'public-speaking', 'academic'),
  ('Writing', 'writing', 'academic'),
  -- Creative
  ('Graphic Design', 'graphic-design', 'creative'),
  ('Video Editing', 'video-editing', 'creative'),
  ('Photography', 'photography', 'creative'),
  ('Music', 'music', 'creative'),
  ('Animation', 'animation', 'creative'),
  ('Content Creation', 'content-creation', 'creative');


-- ============================================================
-- SEED DATA — Base Platform Data
-- ============================================================
-- ECOLLAB – Seed Data
-- Realistic sample data matching the frontend UI



-- 1. SUBSCRIPTION PLANS


-- 2. INSTITUTIONS
INSERT INTO institutions (id, name, domain, sso_provider, is_active)
VALUES
  (1, 'Our Lady of Fatima University', 'fatima.edu.ph', 'microsoft', 1),
  (2, 'Ecollab Demo Institution',      'demo.ecollab.io', 'google', 1);


-- 3. ACADEMIC PROGRAMS
INSERT INTO academic_programs (id, institution_id, code, name, abbreviation)
VALUES
  (1, 1, 'BSCS',  'Bachelor of Science in Computer Science',     'BS CompSci'),
  (2, 1, 'BSIT',  'Bachelor of Science in Information Technology', 'BS IT'),
  (3, 1, 'BSIS',  'Bachelor of Science in Information Systems',  'BS IS'),
  (4, 1, 'BSCOE', 'Bachelor of Science in Computer Engineering', 'BS CompEng');


-- 4. USERS
-- Roles: admin, facilitator, moderator, student (x many)
-- Passwords are bcrypt hashes of "Password123!"
INSERT INTO users
  (id, institution_id, username, email, student_id,
   password_hash, full_name, avatar_color_gradient, role, status,
   email_verified, is_online, tokens_balance, is_verified)
VALUES
  -- Admin
  (1, 1, 'super_admin',     'admin@fatima.edu.ph',         'ADMIN-001',
   '$2y$12$/c2ioXP8IYPhxSyEOL61p.5cpTWpgxDr6x/yDFj2wXYyhNnQiQblu', 'System Administrator',
   '#ff4fd8,#7c5cff', 'admin', 'active', 1, 1, 9999, 1),

  -- Moderator
  (2, 1, 'mod_carlos',      'carlos.reyes@fatima.edu.ph',  'MOD-001',
   '$2y$12$/c2ioXP8IYPhxSyEOL61p.5cpTWpgxDr6x/yDFj2wXYyhNnQiQblu',   'Carlos Reyes',
   '#00d4ff,#3b82f6', 'moderator', 'active', 1, 1, 500, 1),

  -- Facilitators
  (3, 1, 'adam_smith',      'adam.smith@fatima.edu.ph',    'FAC-001',
   '$2y$12$/c2ioXP8IYPhxSyEOL61p.5cpTWpgxDr6x/yDFj2wXYyhNnQiQblu',  'Adam Smith',
   '#ef4444,#dc2626', 'facilitator', 'active', 1, 1, 300, 1),
  (4, 1, 'prof_santos',     'maria.santos@fatima.edu.ph',  'FAC-002',
   '$2y$12$/c2ioXP8IYPhxSyEOL61p.5cpTWpgxDr6x/yDFj2wXYyhNnQiQblu',  'Maria Santos',
   '#7c5cff,#ff4fd8', 'facilitator', 'active', 1, 0, 300, 1),

  -- Students
  (5, 1, 'fatima_student',  'fatima.student@fatima.edu.ph','2025-001',
   '$2y$12$/c2ioXP8IYPhxSyEOL61p.5cpTWpgxDr6x/yDFj2wXYyhNnQiQblu',  'Fatima Santos',
   '#ff4fd8,#7c5cff', 'student', 'active', 1, 1, 80, 0),
  (6, 1, 'john_doe',        'john.doe@fatima.edu.ph',      '2025-002',
   '$2y$12$/c2ioXP8IYPhxSyEOL61p.5cpTWpgxDr6x/yDFj2wXYyhNnQiQblu',  'John Doe',
   '#3b82f6,#00d4ff', 'student', 'active', 1, 1, 150, 0),
  (7, 1, 'sara_kim',        'sara.kim@fatima.edu.ph',      '2025-003',
   '$2y$12$/c2ioXP8IYPhxSyEOL61p.5cpTWpgxDr6x/yDFj2wXYyhNnQiQblu',  'Sara Kim',
   '#22c55e,#16a34a', 'student', 'active', 1, 0, 60, 0),
  (8, 1, 'mike_lee',        'mike.lee@fatima.edu.ph',      '2025-004',
   '$2y$12$/c2ioXP8IYPhxSyEOL61p.5cpTWpgxDr6x/yDFj2wXYyhNnQiQblu',  'Mike Lee',
   '#f59e0b,#ef4444', 'student', 'active', 1, 1, 40, 0),
  (9, 1, 'david_wilson',    'david.wilson@fatima.edu.ph',  '2025-005',
   '$2y$12$/c2ioXP8IYPhxSyEOL61p.5cpTWpgxDr6x/yDFj2wXYyhNnQiQblu',  'David Wilson',
   '#7c5cff,#ff4fd8', 'student', 'active', 1, 0, 200, 0),
  (10,1, 'leyla_ahmed',     'leyla.ahmed@fatima.edu.ph',   '2025-006',
   '$2y$12$/c2ioXP8IYPhxSyEOL61p.5cpTWpgxDr6x/yDFj2wXYyhNnQiQblu',  'Leyla Ahmed',
   '#e91e8c,#7c3aed', 'student', 'offline', 1, 0, 20, 0);


-- 5. USER PROFILES
INSERT INTO user_profiles
  (user_id, academic_program_id, year_level, study_style, primary_goal,
   total_study_hours, contribution_points, current_streak_days, weekly_goal_hours)
VALUES
  (5,  1, 3, 'mixed',  'build_projects',       127.5, 980,  5, 20),
  (6,  1, 3, 'group',  'find_study_partners',  210.0, 1540, 12, 25),
  (7,  2, 2, 'solo',   'pass_exams',            88.0, 620,  3, 15),
  (8,  1, 4, 'group',  'network_collaborate',  315.0, 2100, 21, 30),
  (9,  2, 2, 'mixed',  'improve_skills',        64.0, 430,  0, 12),
  (10, 1, 1, 'solo',   'pass_exams',            22.0, 180,  2, 10);


-- New interest tag slugs for 5-step signup (collab, goal, availability, tech, academic, creative)
INSERT IGNORE INTO interest_tags (name, slug, category) VALUES
  ('Solo Learning',    'solo-learning',    'collab'),
  ('Team Projects',    'team-projects',    'collab'),
  ('Hackathons',       'hackathons',       'collab'),
  ('Study Groups',     'study-groups',     'collab'),
  ('Mentoring',        'mentoring',        'collab'),
  ('Peer Tutoring',    'peer-tutoring',    'collab'),
  ('Pass Exams',       'pass-exams',       'goal'),
  ('Build a Portfolio','build-portfolio',  'goal'),
  ('Learn New Skills', 'learn-new-skills', 'goal'),
  ('Find Teammates',   'find-teammates',   'goal'),
  ('Networking',       'networking',       'goal'),
  ('Freelancing',      'freelancing',      'goal'),
  ('Startup Building', 'startup-building', 'goal'),
  ('Weekday Mornings', 'weekday-mornings', 'availability'),
  ('Weekday Evenings', 'weekday-evenings', 'availability'),
  ('Weekends',         'weekends',         'availability'),
  ('Late Nights',      'late-nights',      'availability'),
  ('Flexible',         'flexible',         'availability'),
  ('Web Development',  'web-dev',          'tech'),
  ('Mobile Development','mobile-dev',      'tech'),
  ('UI/UX Design',     'ui-ux',            'tech'),
  ('Cloud Computing',  'cloud',            'tech'),
  ('Game Development', 'game-dev',         'tech'),
  ('Mathematics',      'mathematics',      'academic'),
  ('Programming',      'programming',      'academic'),
  ('Research',         'research',         'academic'),
  ('Science',          'science',          'academic'),
  ('Engineering',      'engineering',      'academic'),
  ('Business',         'business',         'academic'),
  ('Public Speaking',  'public-speaking',  'academic'),
  ('Writing',          'writing',          'academic'),
  ('Graphic Design',   'graphic-design',   'creative'),
  ('Video Editing',    'video-editing',    'creative'),
  ('Photography',      'photography',      'creative'),
  ('Animation',        'animation',        'creative'),
  ('Content Creation', 'content-creation', 'creative');

INSERT INTO user_interests (user_id, interest_tag_id) VALUES
  (5, 1),(5, 2),(5, 4),
  (6, 1),(6, 9),(6, 3),
  (7, 2),(7, 5),
  (8, 6),(8, 7),(8, 8),
  (9, 4),(9, 10),
  (10, 1),(10, 2);


-- 7. SERVERS
INSERT INTO servers
  (id, owner_id, institution_id, name, slug, description, icon_emoji,
   category, type, status, member_count)
VALUES
  (1, 3, 1, 'CS 305 – Neural Networks',   'cs-305-neural-networks',
   'Study group for CS 305 Advanced Machine Learning',     '🧠', 'cs', 'institution', 'active', 42),
  (2, 3, 1, 'CS 201 – Data Structures',   'cs-201-data-structures',
   'DSA practice, quizzes, and whiteboard sessions',       '🌲', 'cs', 'institution', 'active', 38),
  (3, 4, 1, 'CS 210 – Database Systems',  'cs-210-database-systems',
   'SQL, normalization, ER diagrams',                      '🗄', 'cs', 'institution', 'active', 29),
  (4, 6, 1, 'Competitive Coding PH',       'competitive-coding-ph',
   'LeetCode, HackerRank, and contest prep',               '⚡', 'cs', 'public',      'active', 117),
  (5, 5, 1, 'IT Career Lounge',            'it-career-lounge',
   'Resume tips, internship shares, networking',           '💼', 'general', 'public', 'active', 85),
  (6, 8, 1, 'Game Dev Philippines',        'game-dev-ph',
   'Unity, Unreal, Godot – build together',                '🎮', 'cs', 'public',      'active', 63);

INSERT INTO server_members (server_id, user_id, server_role) VALUES
  (1, 3, 'owner'),(1, 5, 'member'),(1, 6, 'member'),(1, 7, 'member'),(1, 8, 'member'),
  (2, 3, 'owner'),(2, 5, 'member'),(2, 6, 'member'),(2, 9, 'member'),
  (3, 4, 'owner'),(3, 7, 'member'),(3, 9, 'member'),(3, 10, 'member'),
  (4, 6, 'owner'),(4, 5, 'member'),(4, 8, 'member'),
  (5, 5, 'owner'),(5, 7, 'member'),(5, 10, 'member'),
  (6, 8, 'owner'),(6, 9, 'member');

INSERT INTO server_tags (server_id, interest_tag_id) VALUES
  (1, 1),(1, 4),
  (2, 9),
  (3, 10),
  (4, 9),(4, 1),
  (5, 2),(5, 5),
  (6, 6);


-- 8. CHANNELS
INSERT INTO channels
  (id, server_id, name, slug, type, description, position, created_by)
VALUES
  -- CS 305 channels
  (1,  1, 'general',       'general',       'text',       'General discussion',       0, 3),
  (2,  1, 'announcements', 'announcements', 'announcement','Important class notices',  1, 3),
  (3,  1, 'study-hall',    'study-hall',    'voice',      'Voice study room',          2, 3),
  (4,  1, 'whiteboard',    'whiteboard',    'whiteboard', 'Shared whiteboard',         3, 3),
  -- CS 201 channels
  (5,  2, 'general',       'general',       'text',       'General DSA chat',          0, 3),
  (6,  2, 'resources',     'resources',     'text',       'Share files & links',       1, 3),
  (7,  2, 'dsa-room',      'dsa-room',      'voice',      'Live DSA session',          2, 3),
  -- CS 210 channels
  (8,  3, 'general',       'general',       'text',       'DB general chat',           0, 4),
  (9,  3, 'sql-lab',       'sql-lab',       'text',       'SQL exercises',             1, 4);


-- 9. STUDY ROOMS
INSERT INTO study_rooms
  (id, channel_id, server_id, host_id, name, join_code, subject,
   type, status, max_participants, participant_count,
   has_voice, has_video, has_whiteboard)
VALUES
  (1, 3, 1, 6, 'Neural Networks Ch.5 Deep Dive', 'ROOM-CS305',
   'CS 305 – Chapter 5: CNNs', 'open', 'active', 8, 4, 1, 0, 1),
  (2, 7, 2, 5, 'DSA Midterm Crunch',              'ROOM-CS201',
   'CS 201 – Trees & Graphs Review', 'open', 'active', 6, 3, 1, 0, 0),
  (3, NULL, NULL, 8, 'Game Dev Collab – Unity 6', 'ROOM-GAME1',
   'Unity 6 Project Sprint', 'private', 'active', 4, 2, 1, 1, 1),
  (4, NULL, NULL, 6, 'LeetCode Daily',             'ROOM-LC01',
   'Daily LeetCode Problems', 'open', 'ended', 10, 0, 0, 0, 0);

INSERT INTO study_room_participants
  (room_id, user_id, role, is_muted, joined_at)
VALUES
  (1, 6, 'host',     0, NOW()),
  (1, 5, 'speaker',  0, NOW()),
  (1, 7, 'listener', 1, NOW()),
  (1, 8, 'listener', 1, NOW()),
  (2, 5, 'host',     0, NOW()),
  (2, 6, 'speaker',  0, NOW()),
  (2, 9, 'listener', 1, NOW());


-- 10. MESSAGES
INSERT INTO messages (id, channel_id, sender_id, content, content_type) VALUES
  (1, 1, 3,  'Welcome to CS 305! Please check the announcements for the syllabus.', 'text'),
  (2, 1, 5,  'Thank you, Prof! Looking forward to the CNNs chapter.', 'text'),
  (3, 1, 6,  'Can someone share the Chapter 5 notes?', 'text'),
  (4, 1, 7,  'I uploaded them in the resources section!', 'text'),
  (5, 2, 3,  'Midterm exam will be on May 28. Coverage: Chapters 1-6.', 'text'),
  (6, 5, 5,  'Quick question – when to use BST vs Heap?', 'text'),
  (7, 5, 6,  'BST for ordered data, Heap for priority queues!', 'text'),
  (8, 8, 4,  'Lab 3 submission deadline is this Friday.', 'text');


-- 11. DIRECT MESSAGES
INSERT INTO direct_messages (sender_id, recipient_id, content, is_read) VALUES
  (6, 5, 'Hey! Are you joining the study room tonight?', 1),
  (5, 6, 'Yes! Starting at 8PM. Join ROOM-CS305 😊', 1),
  (6, 5, 'Perfect, see you there!', 0),
  (3, 5, 'Hi, great work on your last quiz Fatima!', 0);


-- 12. SUBJECT CLASSES
INSERT INTO subject_classes
  (id, facilitator_id, server_id, institution_id, subject_code, subject_name,
   section, semester, school_year, enroll_code, max_students, student_count, status)
VALUES
  (1, 3, 1, 1, 'CS 305', 'Advanced Machine Learning', 'BSCS-3A', '2nd Sem', '2025-2026', 'ENR-CS305A', 50, 42, 'active'),
  (2, 3, 2, 1, 'CS 201', 'Data Structures & Algorithms', 'BSCS-3B', '2nd Sem', '2025-2026', 'ENR-CS201B', 45, 38, 'active'),
  (3, 4, 3, 1, 'CS 210', 'Database Management Systems', 'BSCS-3A', '2nd Sem', '2025-2026', 'ENR-CS210A', 40, 29, 'active'),
  (4, 4, NULL,1, 'CS 410', 'Software Engineering', 'BSCS-4A', '2nd Sem', '2025-2026', 'ENR-CS410A', 40, 25, 'active'),
  (5, 3, NULL,1, 'CS 101', 'Introduction to Programming', 'BSCS-1A', '2nd Sem', '2025-2026', 'ENR-CS101A', 55, 51, 'active');

INSERT INTO class_enrollments (class_id, student_id, status) VALUES
  (1, 5, 'enrolled'),(1, 6, 'enrolled'),(1, 7, 'enrolled'),(1, 8, 'enrolled'),
  (2, 5, 'enrolled'),(2, 6, 'enrolled'),(2, 9, 'enrolled'),
  (3, 7, 'enrolled'),(3, 9, 'enrolled'),(3, 10, 'enrolled'),
  (4, 8, 'enrolled'),(4, 6, 'enrolled'),
  (5, 10,'enrolled');


-- 13. STUDY SESSIONS
INSERT INTO study_sessions
  (user_id, room_id, class_id, subject_label, started_at, ended_at, duration_mins)
VALUES
  (6, 1, 1, 'CS 305 Chapter 5 CNNs',    DATE_SUB(NOW(), INTERVAL 2 HOUR),  NOW(), 120),
  (5, 2, 2, 'CS 201 Trees & Graphs',    DATE_SUB(NOW(), INTERVAL 90 MINUTE), NOW(), 90),
  (6, NULL,2, 'CS 201 Solo Review',     DATE_SUB(NOW(), INTERVAL 1 DAY),   DATE_SUB(NOW(), INTERVAL 20 HOUR), 240),
  (8, 3, NULL,'Unity 6 Collab',         DATE_SUB(NOW(), INTERVAL 3 HOUR),  DATE_SUB(NOW(), INTERVAL 1 HOUR), 120),
  (7, NULL,3, 'CS 210 Normalization',   DATE_SUB(NOW(), INTERVAL 2 DAY),   DATE_SUB(NOW(), INTERVAL 44 HOUR), 90);


-- 14. NOTES
INSERT INTO notes (user_id, class_id, title, content) VALUES
  (6, 1, 'CNN Architecture Summary',
   'Convolutional Layers → Pooling → Activation → Fully Connected\nKey: LeNet, VGG, ResNet progression'),
  (6, 2, 'BST vs AVL Tree',
   'BST: O(h) ops. AVL: Self-balancing, O(log n) guaranteed.'),
  (5, 1, 'Neural Networks Ch.5 Key Points',
   'Filters detect local features. Stride controls output size.'),
  (5, 2, 'Graph Traversal Notes',
   'BFS: level-by-level, uses Queue. DFS: depth-first, uses Stack/Recursion.'),
  (7, 3, 'Normalization Forms',
   '1NF: Atomic values\n2NF: No partial deps\n3NF: No transitive deps\nBCNF: Every determinant is a candidate key');


-- 15. UPLOADED FILES
INSERT INTO uploaded_files
  (uploader_id, class_id, server_id, file_name, original_name,
   file_path, file_size, mime_type, description, is_public)
VALUES
  (7, 1, 1, 'cs305_ch5_notes.pdf', 'CS305 Chapter 5 Notes.pdf',
   '/uploads/classes/1/cs305_ch5_notes.pdf', 2048576, 'application/pdf',
   'Chapter 5 lecture notes – CNNs', 1),
  (6, 2, 2, 'dsa_cheatsheet.pdf', 'DSA Cheat Sheet.pdf',
   '/uploads/classes/2/dsa_cheatsheet.pdf', 512000, 'application/pdf',
   'Quick reference for Big-O and data structures', 1),
  (5, 3, 3, 'er_diagram_lab3.png', 'ER Diagram Lab 3.png',
   '/uploads/classes/3/er_diagram_lab3.png', 204800, 'image/png',
   'ER Diagram for Lab 3 submission', 0);


-- 16. AI SESSIONS & MESSAGES
INSERT INTO ai_sessions (id, user_id, class_id, session_title, message_count, token_cost)
VALUES
  (1, 6, 1, 'Neural Networks Review', 4, 320),
  (2, 5, 2, 'DSA Study Plan',         2, 180),
  (3, 7, 3, 'SQL Normalization Help', 3, 240);

INSERT INTO ai_messages (session_id, role, content, token_count) VALUES
  (1, 'user',      'Summarize Neural Networks Ch.5', 8),
  (1, 'assistant', 'Chapter 5 covers CNNs. Key concepts: Convolutional layers detect local features, Pooling reduces dimensionality, popular architectures include LeNet, VGG, and ResNet.', 38),
  (1, 'user',      'Quiz me on DSA', 5),
  (1, 'assistant', 'Q1: Time complexity of merge sort worst case? A) O(n) B) O(n log n) C) O(n²) D) O(log n)', 28),
  (2, 'user',      'Create my study plan for this week', 8),
  (2, 'assistant', 'Your Study Plan: Mon-Tue: CS 305 Chapter 6 CNNs (2h/day). Wed: CS 201 Trees & Graphs. Thu: CS 210 Normalization. Fri: Review + Quizzes.', 40),
  (3, 'user',      'Explain BCNF normalization', 6),
  (3, 'assistant', 'BCNF (Boyce-Codd Normal Form): For every functional dependency X→Y, X must be a superkey. Stronger than 3NF.', 30);

INSERT INTO ai_quick_prompts (label, prompt_text, category, sort_order) VALUES
  ('Summarize Neural Networks Ch.5', 'Summarize Neural Networks Ch.5',         'study',    1),
  ('Quiz me on DSA',                  'Quiz me on DSA',                          'quiz',     2),
  ('Create my study plan',            'Create my study plan for this week',      'planning', 3),
  ('Explain Big-O Notation',          'Explain Big-O Notation with examples',    'study',    4),
  ('Help me with SQL JOINs',          'Help me understand SQL JOINs with examples', 'study', 5);


-- 17. NOTIFICATIONS
INSERT INTO notifications
  (recipient_id, actor_id, type, title, body, is_read)
VALUES
  (5, 3,    'class_update', 'Midterm Schedule Posted',
   'Adam Smith posted the midterm exam schedule for CS 305.', 0),
  (5, 6,    'room_invite',  'Room Invite',
   'John Doe invited you to Neural Networks Ch.5 Deep Dive.', 0),
  (6, NULL, 'system',       'Welcome to Ecollab!',
   'Your account is ready. Explore study rooms and connect with peers.', 1),
  (6, 5,    'message',      'New Message from Fatima Santos',
   'Yes! Starting at 8PM. Join ROOM-CS305 😊', 0),
  (7, 3,    'class_update', 'Lab 3 Deadline Reminder',
   'Lab 3 for CS 210 is due this Friday.', 0);


-- 18. PEER MATCHES (AI)
INSERT INTO peer_matches
  (user_a_id, user_b_id, match_score, match_reason, status)
VALUES
  (5, 6,  94.5, 'Shared interests in AI and Data Science. Both prefer group study. Same program year.', 'accepted'),
  (5, 7,  78.2, 'Similar courses enrolled. Complementary study schedules.', 'pending'),
  (6, 8,  88.0, 'Both prefer group collaboration and share DevOps/Cloud interests.', 'accepted'),
  (7, 10, 71.5, 'Both are in lower years and studying similar beginner topics.', 'pending');


-- 19. CALENDAR EVENTS
INSERT INTO calendar_events
  (user_id, class_id, room_id, title, event_type, starts_at, ends_at, color_hex)
VALUES
  (6, 1, 1, 'Neural Networks Study Room',  'study_session', DATE_ADD(NOW(), INTERVAL 2 HOUR), DATE_ADD(NOW(), INTERVAL 4 HOUR), '#e91e8c'),
  (6, 1, NULL,'CS 305 Midterm Exam',       'exam',          DATE_ADD(NOW(), INTERVAL 9 DAY),  DATE_ADD(NOW(), INTERVAL 9 DAY),  '#ef4444'),
  (5, 2, 2, 'DSA Review Session',          'study_session', DATE_ADD(NOW(), INTERVAL 1 DAY),  DATE_ADD(NOW(), INTERVAL 1 DAY),  '#7c3aed'),
  (7, 3, NULL,'CS 210 Lab 3 Deadline',     'deadline',      DATE_ADD(NOW(), INTERVAL 5 DAY),  NULL, '#f59e0b'),
  (5, NULL,NULL,'Competitive Coding Contest','personal',     DATE_ADD(NOW(), INTERVAL 7 DAY),  DATE_ADD(NOW(), INTERVAL 7 DAY),  '#06b6d4');


-- 20. WHITEBOARD SESSIONS
INSERT INTO whiteboard_sessions (room_id, class_id, created_by, title, status)
VALUES
  (1, 1, 6, 'CNN Architecture Diagram',    'active'),
  (2, 2, 5, 'BST Deletion Walkthrough',    'saved'),
  (NULL,3, 7, 'ER Diagram – Library DB',  'saved');


-- 21. ROLES & PERMISSIONS
INSERT INTO roles (id, name, slug, description, color_hex, is_system) VALUES
  (1, 'Student',     'student',     'Regular enrolled students',       '#22c55e', 1),
  (2, 'Facilitator', 'facilitator', 'Course facilitators and teachers','#f59e0b', 1),
  (3, 'Moderator',   'moderator',   'Community moderators',            '#00d4ff', 1),
  (4, 'Admin',       'admin',       'Platform administrators',         '#ff4fd8', 1),
  (5, 'Super Admin', 'super_admin', 'Full system access',              '#7c5cff', 1);

INSERT INTO permissions (id, name, slug, category, description) VALUES
  (1,  'View Channels',      'channels.view',       'channels',    'View text channels'),
  (2,  'Send Messages',      'messages.send',       'channels',    'Send messages in channels'),
  (3,  'Delete Messages',    'messages.delete',     'moderation',  'Delete any message'),
  (4,  'Manage Channels',    'channels.manage',     'channels',    'Create/edit channels'),
  (5,  'Kick Members',       'members.kick',        'moderation',  'Kick users from server'),
  (6,  'Ban Members',        'members.ban',         'moderation',  'Ban users from platform'),
  (7,  'Mute Members',       'members.mute',        'moderation',  'Mute users'),
  (8,  'View Dashboard',     'admin.dashboard',     'admin',       'Access admin dashboard'),
  (9,  'Manage Users',       'admin.users',         'admin',       'CRUD on users'),
  (10, 'Manage Roles',       'admin.roles',         'admin',       'Assign and edit roles'),
  (11, 'View Analytics',     'analytics.view',      'analytics',   'View platform analytics'),
  (12, 'Create Rooms',       'rooms.create',        'rooms',       'Create study rooms'),
  (13, 'Manage Rooms',       'rooms.manage',        'rooms',       'Admin control over rooms'),
  (14, 'Upload Files',       'files.upload',        'files',       'Upload files to platform'),
  (15, 'Use AI Assistant',   'ai.use',              'ai',          'Access AI assistant');

INSERT INTO role_permissions (role_id, permission_id) VALUES
  -- Student: basic access
  (1,1),(1,2),(1,12),(1,14),(1,15),
  -- Facilitator: + channel management, analytics
  (2,1),(2,2),(2,4),(2,11),(2,12),(2,14),(2,15),
  -- Moderator: + moderation actions
  (3,1),(3,2),(3,3),(3,5),(3,7),(3,12),(3,14),(3,15),
  -- Admin: all except super_admin
  (4,1),(4,2),(4,3),(4,4),(4,5),(4,6),(4,7),(4,8),(4,9),(4,10),(4,11),(4,12),(4,13),(4,14),(4,15),
  -- Super Admin: all
  (5,1),(5,2),(5,3),(5,4),(5,5),(5,6),(5,7),(5,8),(5,9),(5,10),(5,11),(5,12),(5,13),(5,14),(5,15);


-- 22. MODERATION ACTIONS
INSERT INTO moderation_actions
  (moderator_id, target_user_id, server_id, action_type, reason, is_active)
VALUES
  (2, 9, 4, 'warn',  'Spamming links in #general channel', 1),
  (1, 10, NULL,'mute','Repeated off-topic messages in class channel', 1);


-- 23. CONTENT REPORTS
INSERT INTO content_reports
  (reporter_id, reported_user_id, server_id, reason, description, status)
VALUES
  (6, 9,  4, 'spam',        'User repeatedly posting promotional links in #general', 'pending'),
  (5, NULL,1, 'inappropriate','Flagged message with possible phishing content',       'reviewing');


-- 24. ACTIVITY LOGS
INSERT INTO activity_logs (user_id, action, entity_type, entity_id, description, level)
VALUES
  (6,    'user.login',        'user',    6,  'John Doe logged in', 'info'),
  (5,    'user.register',     'user',    5,  'New user registered: Fatima Santos', 'info'),
  (2,    'moderation.warn',   'user',    9,  'Warned David Wilson in Competitive Coding PH', 'warning'),
  (NULL, 'system.backup',     NULL,      NULL,'System backup completed', 'info'),
  (NULL, 'system.cleanup',    NULL,      NULL,'System cleanup completed', 'info'),
  (6,    'room.create',       'room',    1,  'Study room "Neural Networks Ch.5 Deep Dive" created', 'info'),
  (5,    'file.upload',       'file',    1,  'Uploaded cs305_ch5_notes.pdf', 'info'),
  (1,    'admin.user_view',   'user',    6,  'Admin viewed user profile: John Doe', 'info');


-- 25. FEEDBACK
INSERT INTO feedback (user_id, type, title, description, rating, status) VALUES
  (6, 'feature_request', 'Add LaTeX support in notes editor',
   'It would be great to write math equations using LaTeX syntax in notes.', NULL, 'open'),
  (5, 'bug',             'AI chat sometimes freezes on mobile',
   'When using the AI assistant on mobile, the chat input gets stuck after 3 messages.', NULL, 'reviewing'),
  (7, 'praise',          'Love the whiteboard feature!',
   'The whiteboard tool is incredibly helpful for collaborative studying. Great work!', 5, 'resolved');


-- 26. USER SETTINGS
INSERT INTO user_settings
  (user_id, notification_msgs, notification_rooms, notification_ai,
   notification_system, theme, language, show_online_status, allow_dm)
VALUES
  (5,  1, 1, 1, 1, 'dark', 'en', 1, 1),
  (6,  1, 1, 1, 1, 'dark', 'en', 1, 1),
  (7,  1, 1, 0, 1, 'dark', 'en', 0, 1),
  (8,  1, 0, 1, 1, 'dark', 'en', 1, 1),
  (9,  0, 0, 0, 1, 'dark', 'en', 0, 0),
  (10, 1, 1, 1, 1, 'dark', 'en', 1, 1);


-- 27. PLATFORM ANALYTICS (Daily snapshot)
INSERT INTO platform_analytics_daily
  (date, total_users, new_registrations, active_users,
   total_messages, total_rooms_active, total_study_hours,
   ai_sessions_count, ai_matches_made, files_uploaded)
VALUES
  (CURDATE() - INTERVAL 6 DAY, 1180, 18, 620, 8400, 32, 1240.5, 89, 12, 34),
  (CURDATE() - INTERVAL 5 DAY, 1192, 12, 701, 9100, 35, 1380.0, 97, 14, 28),
  (CURDATE() - INTERVAL 4 DAY, 1205, 13, 688, 8750, 30, 1295.0, 85, 11, 22),
  (CURDATE() - INTERVAL 3 DAY, 1218, 15, 740, 9420, 38, 1460.0, 104,16, 41),
  (CURDATE() - INTERVAL 2 DAY, 1234, 22, 795, 10200, 42, 1580.5, 118,19, 55),
  (CURDATE() - INTERVAL 1 DAY, 1241, 8,  812, 10800, 45, 1640.0, 122,21, 48),
  (CURDATE(),                   1248, 7,  834, 11200, 47, 1720.5, 130,24, 52);

INSERT INTO user_analytics_weekly
  (user_id, week_start, study_hours, messages_sent, rooms_joined,
   notes_created, files_uploaded, ai_queries, contribution_pts)
VALUES
  (6, DATE_SUB(CURDATE(), INTERVAL DAYOFWEEK(CURDATE())-2 DAY), 6.4, 84, 5, 3, 1, 12, 340),
  (5, DATE_SUB(CURDATE(), INTERVAL DAYOFWEEK(CURDATE())-2 DAY), 4.2, 52, 3, 5, 1,  8, 220),
  (8, DATE_SUB(CURDATE(), INTERVAL DAYOFWEEK(CURDATE())-2 DAY), 9.1, 120,6, 2, 0, 18, 510),
  (7, DATE_SUB(CURDATE(), INTERVAL DAYOFWEEK(CURDATE())-2 DAY), 3.5, 38, 2, 4, 1,  5, 160);


-- 28. FACILITATOR STATS
INSERT INTO facilitator_stats
  (facilitator_id, class_id, total_students, active_students,
   avg_score, very_active_count, inactive_count, drop_rate, avg_study_hours)
VALUES
  (3, 1, 42, 38, 82.5, 18, 4, 2.4, 6.8),
  (3, 2, 38, 35, 78.0, 14, 3, 1.8, 5.2),
  (4, 3, 29, 26, 85.1, 11, 3, 3.1, 4.9),
  (4, 4, 25, 22, 79.8,  9, 3, 2.0, 5.5);


-- 29. SUBSCRIPTION RECORDS


-- 30. TOKEN TRANSACTIONS
INSERT INTO token_transactions (user_id, type, amount, balance_after, reason) VALUES
  (6, 'earned',  50, 200, 'Study streak reward – 7 days'),
  (6, 'spent',  -10, 190, 'AI query – Neural Networks summary'),
  (6, 'earned', 100, 290, 'Weekly study goal achieved'),
  (5, 'earned',  30,  80, 'First study room session'),
  (8, 'earned', 200, 200, 'Top contributor of the week');


-- 31. SSO TOKENS
INSERT INTO sso_tokens (user_id, provider, provider_uid)
VALUES
  (6, 'microsoft', 'ms_uid_john_doe_fatima'),
  (5, 'google',    'google_uid_fatima_santos');


-- 32. SYSTEM SETTINGS
INSERT INTO system_settings (category, key_name, value, label) VALUES
  ('general',       'platform_name',        'Ecollab',                   'Platform Name'),
  ('general',       'institution_domain',   'fatima.edu.ph',             'Institution Domain'),
  ('general',       'maintenance_mode',     '0',                         'Maintenance Mode'),
  ('ai',            'matching_enabled',     '1',                         'AI Matching Enabled'),
  ('ai',            'matching_accuracy',    '98.7',                      'Matching Accuracy (%)'),
  ('ai',            'avg_response_time',    '1.2',                       'Avg AI Response Time (s)'),
  ('ai',            'system_uptime',        '99.9',                      'System Uptime (%)'),
  ('security',      'max_login_attempts',   '5',                         'Max Login Attempts'),
  ('security',      'session_timeout_mins', '60',                        'Session Timeout (mins)'),
  ('notifications', 'email_enabled',        '1',                         'Email Notifications'),
  ('notifications', 'push_enabled',         '1',                         'Push Notifications'),
  ('tokens',        'study_streak_reward',  '50',                        'Tokens per Study Streak'),
  ('tokens',        'ai_query_cost',        '10',                        'Token Cost per AI Query');



-- END OF SEED DATA


-- ============================================================
-- SEED DATA — Chat Extension
--   (servers, channels, messages, reactions, reads, whiteboards)
-- ============================================================
-- Ecollab Chat — Seed Data Extension
-- Run AFTER seeds.txt and schema-chat-addon.sql
-- Populates: servers, channels, channel_members, messages,
--            message_reactions, message_reads, whiteboards


-- ── Patch real bcrypt hashes (Password123!) ────────────────
-- Only patch if still using placeholder hashes
UPDATE users SET password_hash = '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TiLwRh3FXV.a/XvPmGJt4q0lWjPy'
WHERE password_hash LIKE '$2y$12$xL3..hashed.%';

-- ── Update avatar_color_gradient column ────────────────────
UPDATE users SET avatar_color_gradient = '#ff4fd8,#7c5cff' WHERE id = 1;
UPDATE users SET avatar_color_gradient = '#00d4ff,#3b82f6' WHERE id = 2;
UPDATE users SET avatar_color_gradient = '#ef4444,#dc2626' WHERE id = 3;
UPDATE users SET avatar_color_gradient = '#7c5cff,#ff4fd8' WHERE id = 4;
UPDATE users SET avatar_color_gradient = '#a855f7,#ec4899' WHERE id = 5;
UPDATE users SET avatar_color_gradient = '#3b82f6,#00d4ff' WHERE id = 6;
UPDATE users SET avatar_color_gradient = '#22c55e,#16a34a' WHERE id = 7;
UPDATE users SET avatar_color_gradient = '#f59e0b,#ef4444' WHERE id = 8;
UPDATE users SET avatar_color_gradient = '#8b5cf6,#6d28d9' WHERE id = 9;
UPDATE users SET avatar_color_gradient = '#ec4899,#db2777' WHERE id = 10;
-- ══════════════════════════════════════════════════════════════
-- REMOVED: a second, schema-incompatible "CS Hub / IT Alpha / Study
-- Lounge" servers+channels+messages seed dataset previously sat here.
-- It used a `created_by` column on `servers` that does not exist on
-- the real table (which requires `owner_id`, NOT NULL, FK'd to users)
-- and would have failed with an unknown-column/constraint error. The
-- schema-correct "CS 305 / CS 201 / CS 210" dataset above (SERVERS,
-- section 7) is the one that actually matches the real `servers`
-- table definition and is kept as the sole seed dataset for servers/
-- channels/messages. Removed in full rather than renumbered, since it
-- was not just mis-numbered but schema-incompatible.
-- ══════════════════════════════════════════════════════════════

-- ============================================================
-- END OF ECOLLAB DATABASE SETUP
-- ============================================================


-- ============================================================
-- ADDON: DASHBOARD TABLES
-- (student_notes, user_achievements, quiz_attempts, quizzes,
--  friendships, activity_logs, study_rooms, study_room_sessions,
--  study_room_participants, server_interest_tags)
-- ============================================================

-- Ecollab Dashboard — Additive Schema
-- Run AFTER schema.txt and schema-chat-addon.sql
-- Uses IF NOT EXISTS and ADD COLUMN IF NOT EXISTS for safety.


-- ── student_notes ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `student_notes` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`             BIGINT UNSIGNED NOT NULL,
  `academic_program_id` BIGINT UNSIGNED     NULL DEFAULT NULL,
  `title`               VARCHAR(255)    NOT NULL,
  `content`             TEXT                NULL,
  `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `fk_sn_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── user_achievements ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `user_achievements` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        BIGINT UNSIGNED NOT NULL,
  `achievement_key` VARCHAR(80)    NOT NULL,
  `label`          VARCHAR(120)    NOT NULL,
  `description`    VARCHAR(255)        NULL,
  `icon`           VARCHAR(10)     NOT NULL DEFAULT '🏆',
  `earned_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_achievement` (`user_id`, `achievement_key`),
  CONSTRAINT `fk_ua_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── quiz_attempts ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `quiz_attempts` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`             BIGINT UNSIGNED NOT NULL,
  `quiz_id`             BIGINT UNSIGNED NOT NULL,
  `score_percentage`    DECIMAL(5,2)    NOT NULL DEFAULT 0.00,
  `answers_json`        JSON                NULL,
  `started_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at`        DATETIME            NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_id`  (`user_id`),
  KEY `idx_quiz_id`  (`quiz_id`),
  CONSTRAINT `fk_qa_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── quizzes ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `quizzes` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `academic_program_id` BIGINT UNSIGNED     NULL,
  `channel_id`          BIGINT UNSIGNED     NULL,
  `title`               VARCHAR(255)    NOT NULL,
  `time_limit_minutes`  INT             NOT NULL DEFAULT 30,
  `status`              ENUM('draft','active','closed') NOT NULL DEFAULT 'active',
  `created_by`          BIGINT UNSIGNED NOT NULL,
  `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_channel` (`channel_id`),
  KEY `idx_program` (`academic_program_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── friendships ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `friendships` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `requester_id` BIGINT UNSIGNED NOT NULL,
  `addressee_id` BIGINT UNSIGNED NOT NULL,
  `status`       ENUM('pending','accepted','blocked') NOT NULL DEFAULT 'pending',
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_friendship` (`requester_id`, `addressee_id`),
  KEY `idx_addressee` (`addressee_id`),
  CONSTRAINT `fk_fr_req`  FOREIGN KEY (`requester_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fr_addr` FOREIGN KEY (`addressee_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── activity_logs (for admin dashboard) ──────────────────────────────
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT UNSIGNED     NULL,
  `action`     VARCHAR(500)    NOT NULL,
  `level`      ENUM('info','warn','error','success') NOT NULL DEFAULT 'info',
  `ip_address` VARCHAR(45)         NULL,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id`   (`user_id`),
  KEY `idx_level`     (`level`),
  KEY `idx_created`   (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── study_room_sessions ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `study_room_sessions` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `room_id`         BIGINT UNSIGNED     NULL,
  `channel_id`      BIGINT UNSIGNED     NULL,
  `title`           VARCHAR(255)    NOT NULL,
  `description`     TEXT                NULL,
  `status`          ENUM('scheduled','active','ended') NOT NULL DEFAULT 'scheduled',
  `scheduled_start` DATETIME        NOT NULL,
  `scheduled_end`   DATETIME            NULL,
  `created_by`      BIGINT UNSIGNED NOT NULL,
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_room`    (`room_id`),
  KEY `idx_channel` (`channel_id`),
  KEY `idx_start`   (`scheduled_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── study_room_participants ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `study_room_participants` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `room_id`    BIGINT UNSIGNED     NULL,
  `session_id` BIGINT UNSIGNED     NULL,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `is_active`  TINYINT(1)      NOT NULL DEFAULT 1,
  `joined_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_room`    (`room_id`),
  KEY `idx_session` (`session_id`),
  KEY `idx_user`    (`user_id`),
  CONSTRAINT `fk_srp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── study_rooms (if not already present) ─────────────────────────────
CREATE TABLE IF NOT EXISTS `study_rooms` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `server_id`   BIGINT UNSIGNED     NULL,
  `channel_id`  BIGINT UNSIGNED     NULL,
  `name`        VARCHAR(120)    NOT NULL,
  `description` TEXT                NULL,
  `icon_emoji`  VARCHAR(10)         NULL DEFAULT '🏠',
  `max_members` INT             NOT NULL DEFAULT 25,
  `status`      ENUM('active','archived') NOT NULL DEFAULT 'active',
  `created_by`  BIGINT UNSIGNED NOT NULL,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_server`  (`server_id`),
  KEY `idx_channel` (`channel_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── server_interest_tags (many-to-many) ───────────────────────────────
CREATE TABLE IF NOT EXISTS `server_interest_tags` (
  `server_id`       BIGINT UNSIGNED NOT NULL,
  `interest_tag_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`server_id`, `interest_tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Add missing columns to existing tables ────────────────────────────

-- users
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `current_activity` VARCHAR(120) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `last_seen_at`     DATETIME     NULL DEFAULT NULL;

-- user_profiles
ALTER TABLE `user_profiles`
  ADD COLUMN IF NOT EXISTS `progress_percentage` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS `hours_spent`         DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS `bio`                 TEXT             NULL;

-- notifications
ALTER TABLE `notifications`
  ADD COLUMN IF NOT EXISTS `icon` VARCHAR(10) NOT NULL DEFAULT '🔔',
  ADD COLUMN IF NOT EXISTS `type` VARCHAR(40) NOT NULL DEFAULT 'general';



-- ============================================================
-- SEED DATA — Dashboard Extension
-- (quizzes, achievements, friendships, study rooms, sessions,
--  participants, notes, activity logs, notifications)
-- ============================================================

-- Ecollab Dashboard — Seed Data Extension
-- Run AFTER seeds.txt, seeds-chat.sql, schema-dashboard-addon.sql
-- Populates: student_notes, user_achievements, quiz_attempts,
--            quizzes, friendships, study_rooms, study_room_sessions,
--            study_room_participants, activity_logs


-- ── Quizzes ───────────────────────────────────────────────────────────────
INSERT INTO quizzes (id, academic_program_id, channel_id, title, time_limit_minutes, status, created_by)
VALUES
  (1, 1, 1, 'Neural Networks Basics',    30, 'active', 3),
  (2, 1, 1, 'Backpropagation Deep Dive', 45, 'active', 3),
  (3, 2, 4, 'Data Structures Review',    20, 'active', 4),
  (4, 2, 4, 'Graph Algorithms',          30, 'closed', 4)
ON DUPLICATE KEY UPDATE title = VALUES(title);

-- ── Quiz attempts ─────────────────────────────────────────────────────────
INSERT INTO quiz_attempts (id, user_id, quiz_id, score_percentage, started_at, completed_at)
VALUES
  (1, 5, 1, 82.00, DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_SUB(NOW(), INTERVAL 5 DAY)),
  (2, 5, 2, 75.00, DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY)),
  (3, 6, 1, 91.00, DATE_SUB(NOW(), INTERVAL 4 DAY), DATE_SUB(NOW(), INTERVAL 4 DAY)),
  (4, 6, 3, 88.00, DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY)),
  (5, 7, 1, 67.00, DATE_SUB(NOW(), INTERVAL 6 DAY), DATE_SUB(NOW(), INTERVAL 6 DAY)),
  (6, 8, 2, 94.00, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY)),
  (7, 9, 3, 78.00, DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY)),
  (8,10, 4, 84.00, DATE_SUB(NOW(), INTERVAL 7 DAY), DATE_SUB(NOW(), INTERVAL 7 DAY))
ON DUPLICATE KEY UPDATE score_percentage = VALUES(score_percentage);

-- ── Achievements ──────────────────────────────────────────────────────────
INSERT INTO user_achievements (user_id, achievement_key, label, description, icon, earned_at)
VALUES
  (5, 'first_login',       'First Login',         'Welcome to Ecollab!',                '🎯', DATE_SUB(NOW(), INTERVAL 30 DAY)),
  (5, 'consistent_learner','Consistent Learner',  'Study for 7 days in a row',           '🔥', DATE_SUB(NOW(), INTERVAL 19 DAY)),
  (5, 'neural_explorer',   'Neural Explorer',     'Complete 10 Neural Networks sessions','🧠', DATE_SUB(NOW(), INTERVAL 20 DAY)),
  (5, 'active_participant','Active Participant',   'Send 50 messages in study rooms',     '💬', DATE_SUB(NOW(), INTERVAL 18 DAY)),
  (5, 'team_player',       'Team Player',         'Join 10 group sessions',              '👫', DATE_SUB(NOW(), INTERVAL 17 DAY)),
  (6, 'first_login',       'First Login',         'Welcome to Ecollab!',                '🎯', DATE_SUB(NOW(), INTERVAL 28 DAY)),
  (6, 'quiz_master',       'Quiz Master',         'Complete 10 quizzes',                 '📝', DATE_SUB(NOW(), INTERVAL 10 DAY)),
  (7, 'first_login',       'First Login',         'Welcome to Ecollab!',                '🎯', DATE_SUB(NOW(), INTERVAL 25 DAY)),
  (8, 'first_login',       'First Login',         'Welcome to Ecollab!',                '🎯', DATE_SUB(NOW(), INTERVAL 22 DAY)),
  (9, 'first_login',       'First Login',         'Welcome to Ecollab!',                '🎯', DATE_SUB(NOW(), INTERVAL 20 DAY))
ON DUPLICATE KEY UPDATE label = VALUES(label);

-- ── Friendships ───────────────────────────────────────────────────────────
INSERT INTO friendships (requester_id, addressee_id, status)
VALUES
  (5, 6,  'accepted'),
  (5, 7,  'accepted'),
  (5, 8,  'accepted'),
  (5, 9,  'accepted'),
  (6, 7,  'accepted'),
  (6, 10, 'accepted'),
  (7, 8,  'accepted'),
  (8, 9,  'accepted'),
  (9, 10, 'accepted')
ON DUPLICATE KEY UPDATE status = 'accepted';

-- ── Study rooms ───────────────────────────────────────────────────────────
INSERT INTO study_rooms (id, server_id, channel_id, name, description, icon_emoji, max_members, status, created_by)
VALUES
  (1, 1, 1, 'CS 305 Neural Networks Study Group', 'Discuss algorithms, backpropagation, and more', '🧠', 25, 'active', 3),
  (2, 1, 4, 'Data Structures Practice Group',     'Practice problems and share solutions',          '💻', 20, 'active', 4),
  (3, 1, 1, 'Group Project Team Alpha',           'AI Chatbot Development project',                 '🚀', 10, 'active', 5),
  (4, 2, 5, 'IT Project Sprint Room',             'Daily standups and sprint planning',             '⚡', 15, 'active', 2),
  (5, 3, 8, 'Study Lounge Open Room',             'Open study space for everyone',                  '📚', 30, 'active', 5)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ── Study room sessions ───────────────────────────────────────────────────
INSERT INTO study_room_sessions (id, room_id, channel_id, title, description, status, scheduled_start, scheduled_end, created_by)
VALUES
  (1, 1, 1, 'Backpropagation Study Session',  'Chapter 4 deep dive',   'scheduled', DATE_ADD(NOW(), INTERVAL 1 DAY),   DATE_ADD(NOW(), INTERVAL 1 DAY + INTERVAL 2 HOUR),  3),
  (2, 1, 1, 'Chapter 5 Q&A Session',          'Office hours style',    'scheduled', DATE_ADD(NOW(), INTERVAL 3 DAY),   DATE_ADD(NOW(), INTERVAL 3 DAY + INTERVAL 1 HOUR),  3),
  (3, 2, 4, 'Graph Algorithms Practice',      'Leetcode + theory',     'scheduled', DATE_ADD(NOW(), INTERVAL 2 DAY),   DATE_ADD(NOW(), INTERVAL 2 DAY + INTERVAL 2 HOUR),  4),
  (4, 3, 1, 'AI Project Sprint Planning',     'Sprint 3 kickoff',      'scheduled', DATE_ADD(NOW(), INTERVAL 4 DAY),   DATE_ADD(NOW(), INTERVAL 4 DAY + INTERVAL 1 HOUR),  5),
  (5, 1, 1, 'Neural Networks Q&A (past)',     'Past session',          'ended',     DATE_SUB(NOW(), INTERVAL 2 DAY),   DATE_SUB(NOW(), INTERVAL 2 DAY - INTERVAL 2 HOUR),  3),
  (6, 2, 4, 'Trees & Graphs Review (past)',   'Past session',          'ended',     DATE_SUB(NOW(), INTERVAL 4 DAY),   DATE_SUB(NOW(), INTERVAL 4 DAY - INTERVAL 1 HOUR),  4)
ON DUPLICATE KEY UPDATE title = VALUES(title);

-- ── Study room participants ───────────────────────────────────────────────
INSERT INTO study_room_participants (room_id, session_id, user_id, is_active, joined_at)
VALUES
  -- Room 1 active members
  (1, 1, 5, 1, DATE_SUB(NOW(), INTERVAL 30 MINUTE)),
  (1, 1, 6, 1, DATE_SUB(NOW(), INTERVAL 25 MINUTE)),
  (1, 1, 7, 1, DATE_SUB(NOW(), INTERVAL 20 MINUTE)),
  (1, 1, 8, 0, DATE_SUB(NOW(), INTERVAL 1 HOUR)),
  -- Room 2 active members
  (2, 3, 6, 1, DATE_SUB(NOW(), INTERVAL 45 MINUTE)),
  (2, 3, 9, 1, DATE_SUB(NOW(), INTERVAL 40 MINUTE)),
  (2, 3, 10,1, DATE_SUB(NOW(), INTERVAL 35 MINUTE)),
  -- Past session participants (session 5)
  (1, 5, 5, 0, DATE_SUB(NOW(), INTERVAL 2 DAY)),
  (1, 5, 6, 0, DATE_SUB(NOW(), INTERVAL 2 DAY)),
  (1, 5, 7, 0, DATE_SUB(NOW(), INTERVAL 2 DAY)),
  (1, 5, 8, 0, DATE_SUB(NOW(), INTERVAL 2 DAY)),
  (1, 5, 9, 0, DATE_SUB(NOW(), INTERVAL 2 DAY)),
  -- Past session 6
  (2, 6, 6, 0, DATE_SUB(NOW(), INTERVAL 4 DAY)),
  (2, 6, 9, 0, DATE_SUB(NOW(), INTERVAL 4 DAY)),
  (2, 6, 10,0, DATE_SUB(NOW(), INTERVAL 4 DAY))
ON DUPLICATE KEY UPDATE is_active = VALUES(is_active);

-- ── Student notes ─────────────────────────────────────────────────────────
INSERT INTO student_notes (user_id, academic_program_id, title, content, created_at, updated_at)
VALUES
  (5, 1, 'Neural Networks — Key Concepts',
   'Backpropagation: Chain rule applied to compute gradients.\nActivation functions: ReLU vs Sigmoid vs Tanh.\nGradient descent: batch, mini-batch, stochastic.\nOverfitting: dropout, regularization, early stopping.',
   DATE_SUB(NOW(), INTERVAL 2 DAY), NOW()),

  (5, 1, 'Backpropagation Step-by-Step',
   'Forward pass: compute output.\nLoss function: MSE or cross-entropy.\nBackward pass: compute dL/dW for each layer.\nUpdate: W = W - lr * dL/dW',
   DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY)),

  (5, 2, 'DSA — Two Pointers Technique',
   'Use two pointers for O(n) solutions on sorted arrays.\nStart from both ends, or same position moving at different speeds.\nExamples: two-sum, palindrome check, remove duplicates.',
   DATE_SUB(NOW(), INTERVAL 7 DAY), DATE_SUB(NOW(), INTERVAL 7 DAY)),

  (6, 1, 'Gradient Descent Variants',
   'Batch GD: all data per step, slow but stable.\nSGD: one sample per step, noisy.\nMini-batch: balance of both.\nAdam: adaptive learning rates.',
   DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY)),

  (7, 2, 'Binary Trees Cheatsheet',
   'BST: left < root < right.\nInorder traversal = sorted output.\nHeight: O(log n) balanced, O(n) worst.\nBalanced: AVL, Red-Black trees.',
   DATE_SUB(NOW(), INTERVAL 4 DAY), DATE_SUB(NOW(), INTERVAL 4 DAY))
ON DUPLICATE KEY UPDATE content = VALUES(content);

-- ── Activity logs ─────────────────────────────────────────────────────────
INSERT INTO activity_logs (user_id, action, level, ip_address, created_at)
VALUES
  (1,  'Platform initialized',                         'success', '127.0.0.1',   DATE_SUB(NOW(), INTERVAL 30 DAY)),
  (5,  'User registered: Fatima_Student',              'success', '192.168.1.10', DATE_SUB(NOW(), INTERVAL 28 DAY)),
  (6,  'User registered: John_Doe',                    'success', '192.168.1.11', DATE_SUB(NOW(), INTERVAL 26 DAY)),
  (3,  'Facilitator created channel: CS 305',          'info',    '192.168.1.3',  DATE_SUB(NOW(), INTERVAL 20 DAY)),
  (NULL,'System backup completed: 2.3GB archived',     'info',    '127.0.0.1',   DATE_SUB(NOW(), INTERVAL 10 DAY)),
  (5,  'User logged in',                               'info',    '192.168.1.10', DATE_SUB(NOW(), INTERVAL 1 DAY)),
  (6,  'User logged in',                               'info',    '192.168.1.11', DATE_SUB(NOW(), INTERVAL 1 DAY)),
  (NULL,'High memory usage detected (67%)',             'warn',    '127.0.0.1',   DATE_SUB(NOW(), INTERVAL 12 HOUR)),
  (1,  'New user registered: Sara_Kim',                'success', '192.168.1.20', DATE_SUB(NOW(), INTERVAL 5 HOUR)),
  (1,  'Report submitted in #general by Fatima_Student','warn',   '192.168.1.10', DATE_SUB(NOW(), INTERVAL 3 HOUR)),
  (NULL,'Auto-scaling triggered, capacity increased',   'info',    '127.0.0.1',   DATE_SUB(NOW(), INTERVAL 2 HOUR)),
  (5,  'John_Doe logged in',                           'info',    '192.168.1.11', DATE_SUB(NOW(), INTERVAL 30 MINUTE))
ON DUPLICATE KEY UPDATE action = VALUES(action);

-- ── Mark some users as online for demo ────────────────────────────────────
UPDATE users SET is_online = 1, last_active_at = NOW(), current_activity = 'Studying CS 305' WHERE id IN (5, 6, 7);
UPDATE users SET is_online = 0, last_active_at = DATE_SUB(NOW(), INTERVAL 30 MINUTE) WHERE id IN (8, 9);
UPDATE users SET is_online = 1, last_active_at = NOW(), current_activity = 'In AI Study Room' WHERE id IN (10);

-- ── Update user_profiles with progress data ────────────────────────────────
UPDATE user_profiles SET progress_percentage = 78.0, hours_spent = 18.6, bio = 'CS student passionate about AI and web development.' WHERE user_id = 5;
UPDATE user_profiles SET progress_percentage = 65.0, hours_spent = 14.2, bio = 'Studying Data Structures and Algorithms.' WHERE user_id = 6;
UPDATE user_profiles SET progress_percentage = 55.0, hours_spent = 10.5, bio = 'Learning algorithms and problem solving.' WHERE user_id = 7;
UPDATE user_profiles SET progress_percentage = 82.0, hours_spent = 22.1, bio = 'Advanced learner focusing on machine learning.' WHERE user_id = 8;
UPDATE user_profiles SET progress_percentage = 47.0, hours_spent = 8.3, bio  = 'Building mobile apps alongside my studies.' WHERE user_id = 9;
UPDATE user_profiles SET progress_percentage = 91.0, hours_spent = 28.4, bio = 'Top performer. Planning a research project on NLP.' WHERE user_id = 10;

-- ── Notification seeds for demo ────────────────────────────────────────────
INSERT INTO notifications (user_id, title, message, type, icon, is_read, created_at)
VALUES
  (5, 'Fatima replied',   'Fatima_Student replied to your post in AI & ML Hub',   'mention',  '💬', 0, DATE_SUB(NOW(), INTERVAL 2 HOUR)),
  (5, 'Quiz available',   'New quiz available: Neural Networks Basics',            'quiz',     '✅', 0, DATE_SUB(NOW(), INTERVAL 3 HOUR)),
  (5, 'Achievement',      'Achievement Unlocked: Consistent Learner!',             'achievement','🏆',0, DATE_SUB(NOW(), INTERVAL 1 DAY)),
  (5, 'Session reminder', 'Backpropagation Study Group starts in 30 minutes',      'reminder', '📅', 1, DATE_SUB(NOW(), INTERVAL 2 DAY)),
  (6, 'John replied',     'John_Doe replied to your question in #project-help',    'mention',  '💬', 0, DATE_SUB(NOW(), INTERVAL 1 HOUR)),
  (6, 'New session',      'CS 305 study session scheduled for tomorrow at 3 PM',   'session',  '📅', 0, DATE_SUB(NOW(), INTERVAL 4 HOUR)),
  (7, 'Resource added',   'New lecture notes uploaded to CS 201 Resources',        'resource', '📄', 0, DATE_SUB(NOW(), INTERVAL 5 HOUR))
ON DUPLICATE KEY UPDATE message = VALUES(message);


SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- END OF ECOLLAB DATABASE SETUP
-- ============================================================
