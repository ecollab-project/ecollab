-- ============================================================
-- ECOLLAB – Production MySQL Database Schema
-- Platform: AI-Powered Peer Learning & Collaboration
-- Institution: Fatima University (Computing Department)
-- Generated from frontend UI analysis of all 7 HTML files
-- Engine: InnoDB | Charset: utf8mb4
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

CREATE DATABASE IF NOT EXISTS ecollab_v2
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE ecollab_v2;


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
    REFERENCES institutions(id) ON DELETE SET NULL ON UPDATE CASCADE,
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
  host_id         BIGINT UNSIGNED NOT NULL,
  name            VARCHAR(80)     NOT NULL,
  join_code       VARCHAR(12)     NULL,               -- used in "join room by code"
  description     TEXT            NULL,
  subject         VARCHAR(80)     NULL,               -- e.g. "CS 305 - Neural Networks"
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
  user_id     BIGINT UNSIGNED  NOT NULL,
  role        ENUM('host','speaker','listener') NOT NULL DEFAULT 'listener',
  is_muted    TINYINT(1)       NOT NULL DEFAULT 0,
  is_video_on TINYINT(1)       NOT NULL DEFAULT 0,
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

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- END OF SCHEMA
-- Tables: 31 primary + junction tables
-- ============================================================