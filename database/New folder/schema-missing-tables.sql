-- ============================================================
-- Ecollab – Missing Tables Required by ChannelService
-- Run against ecollab_v2 AFTER the main schema.
-- ============================================================

USE ecollab_v2;

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


-- ============================================================
-- EXTRA: user_hobbies  (required by signup step 5 / AuthService)
-- ============================================================
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


-- ============================================================
-- EXTRA: missing interest_tags seed rows for new slugs
-- Run after schema + seeds.
-- ============================================================
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
