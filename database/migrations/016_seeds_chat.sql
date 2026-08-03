-- ============================================================
-- Ecollab Chat — Seed Data Extension
-- Run AFTER seeds.txt and schema-chat-addon.sql
-- Populates: servers, channels, channel_members, messages,
--            message_reactions, message_reads, whiteboards
-- ============================================================

-- (USE ecollab removed — migration runner uses the configured DB connection)
SET NAMES utf8mb4;
SET foreign_key_checks = 0;

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
-- SERVERS (workspaces / Discord-style guilds)
-- ══════════════════════════════════════════════════════════════
INSERT INTO servers
  (id, name, slug, icon_emoji, category, type, member_count, status, created_by)
VALUES
  (1, 'CS Department Hub',     'cs-hub',        '💻', 'academic',    'academic',   12, 'active', 1),
  (2, 'IT Project Team Alpha', 'it-alpha',      '🚀', 'project',     'project',    6,  'active', 2),
  (3, 'Study Lounge 2025',     'study-lounge',  '📚', 'study_group', 'study_group',8,  'active', 5)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ── Server members ──────────────────────────────────────────
INSERT INTO server_members (server_id, user_id, server_role, joined_at)
VALUES
  -- CS Hub
  (1, 1,  'owner',     NOW()),
  (1, 2,  'admin',     NOW()),
  (1, 3,  'moderator', NOW()),
  (1, 4,  'moderator', NOW()),
  (1, 5,  'member',    NOW()),
  (1, 6,  'member',    NOW()),
  (1, 7,  'member',    NOW()),
  (1, 8,  'member',    NOW()),
  (1, 9,  'member',    NOW()),
  (1, 10, 'member',    NOW()),
  -- IT Alpha
  (2, 2,  'owner',     NOW()),
  (2, 5,  'member',    NOW()),
  (2, 6,  'member',    NOW()),
  (2, 7,  'member',    NOW()),
  (2, 8,  'member',    NOW()),
  (2, 9,  'member',    NOW()),
  -- Study Lounge
  (3, 5,  'owner',     NOW()),
  (3, 6,  'member',    NOW()),
  (3, 7,  'member',    NOW()),
  (3, 8,  'member',    NOW()),
  (3, 9,  'member',    NOW()),
  (3, 10, 'member',    NOW())
ON DUPLICATE KEY UPDATE server_role = VALUES(server_role);

-- ══════════════════════════════════════════════════════════════
-- CHANNELS
-- ══════════════════════════════════════════════════════════════
INSERT INTO channels
  (id, server_id, name, slug, type, description, position, is_private, is_locked, created_by)
VALUES
  -- CS Hub text channels
  (1,  1, 'general',          'general',         'text',        'General discussion for everyone',                        1,  0, 0, 1),
  (2,  1, 'announcements',    'announcements',    'announcement','Official announcements from faculty',                    2,  0, 0, 1),
  (3,  1, 'resources',        'resources',        'text',        'Share study materials, links, and resources',            3,  0, 0, 1),
  (4,  1, 'project-help',     'project-help',     'text',        'Ask for help on projects and assignments',               4,  0, 0, 1),
  (5,  1, 'off-topic',        'off-topic',        'text',        'Casual conversation, memes, and fun',                    5,  0, 0, 1),
  (6,  1, 'algorithms',       'algorithms',       'text',        'Deep dives into algorithms and data structures',         6,  0, 0, 3),
  (7,  1, 'whiteboard-room',  'whiteboard-room',  'whiteboard',  'Collaborative drawing and problem solving',              7,  0, 0, 1),
  (8,  1, 'study-voice',      'study-voice',      'voice',       'Voice study sessions',                                   8,  0, 0, 1),
  (9,  1, 'project-voice',    'project-voice',    'voice',       'Project team discussions',                               9,  0, 0, 1),
  -- IT Alpha
  (10, 2, 'team-chat',        'team-chat',        'text',        'Main team discussion channel',                           1,  0, 0, 2),
  (11, 2, 'dev-updates',      'dev-updates',      'text',        'Development progress and updates',                       2,  0, 0, 2),
  (12, 2, 'design-feedback',  'design-feedback',  'text',        'UI/UX design reviews and feedback',                      3,  0, 0, 2),
  (13, 2, 'standup',          'standup',          'voice',       'Daily standup meetings',                                 4,  0, 0, 2),
  -- Study Lounge
  (14, 3, 'study-general',    'study-general',    'text',        'General study chat',                                     1,  0, 0, 5),
  (15, 3, 'exam-prep',        'exam-prep',        'text',        'Exam preparation and tips',                              2,  0, 0, 5),
  (16, 3, 'chill-zone',       'chill-zone',       'voice',       'Background music and chill studying',                    3,  0, 0, 5)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ══════════════════════════════════════════════════════════════
-- SAMPLE MESSAGES — General channel (id=1)
-- ══════════════════════════════════════════════════════════════
INSERT INTO messages
  (id, channel_id, sender_id, content, content_type, is_pinned, created_at, updated_at)
VALUES
  (1,  1, 1,  'Welcome to the CS Department Hub! 👋 This is our official space for discussions, resources, and collaboration.',
              'text', 1, DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_SUB(NOW(), INTERVAL 5 DAY)),
  (2,  1, 3,  'Hey everyone! I''m Adam Smith, your facilitator for Data Structures this semester. Feel free to ask questions here anytime.',
              'text', 0, DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_SUB(NOW(), INTERVAL 5 DAY)),
  (3,  1, 4,  'Hello students! Maria Santos here. Looking forward to a productive semester with all of you! 📚',
              'text', 0, DATE_SUB(NOW(), INTERVAL 4 DAY), DATE_SUB(NOW(), INTERVAL 4 DAY)),
  (4,  1, 6,  'Thanks for setting this up! Really helpful to have one place for everything.',
              'text', 0, DATE_SUB(NOW(), INTERVAL 4 DAY), DATE_SUB(NOW(), INTERVAL 4 DAY)),
  (5,  1, 5,  'Can''t wait for the study sessions! 🎉',
              'text', 0, DATE_SUB(NOW(), INTERVAL 4 DAY), DATE_SUB(NOW(), INTERVAL 4 DAY)),
  (6,  1, 7,  'Does anyone have notes from yesterday''s algorithms lecture?',
              'text', 0, DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY)),
  (7,  1, 8,  '@sara_kim I can share mine! I''ll upload them to the resources channel.',
              'text', 0, DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY)),
  (8,  1, 3,  'Great initiative! Also check the #resources channel — I uploaded the lecture slides this morning.',
              'text', 0, DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY)),
  (9,  1, 9,  'Has anyone started on the midterm project yet?',
              'text', 0, DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY)),
  (10, 1, 6,  'I started outlining mine. The requirements are a bit vague though — anyone else confused by section 3.2?',
              'text', 0, DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY)),
  (11, 1, 4,  'Good question! I''ll post a clarification in #announcements shortly.',
              'text', 0, DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY)),
  (12, 1, 5,  'Heads up — study voice channel is open if anyone wants to work together tonight! 🎧',
              'text', 0, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY)),
  (13, 1, 7,  'Joining in 10 minutes! 👍',
              'text', 0, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY)),
  (14, 1, 10, 'Same! See you there.',
              'text', 0, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY)),
  (15, 1, 3,  'Quick reminder: Assignment 2 is due this Friday at 11:59 PM. No extensions this time! 😅',
              'text', 1, DATE_SUB(NOW(), INTERVAL 12 HOUR), DATE_SUB(NOW(), INTERVAL 12 HOUR)),
  (16, 1, 6,  'Professor what happens if I submit at 12:01 AM? 😬',
              'text', 0, DATE_SUB(NOW(), INTERVAL 11 HOUR), DATE_SUB(NOW(), INTERVAL 11 HOUR)),
  (17, 1, 3,  '😂 Please don''t test that. Just submit early!',
              'text', 0, DATE_SUB(NOW(), INTERVAL 11 HOUR), DATE_SUB(NOW(), INTERVAL 11 HOUR)),
  (18, 1, 5,  'Good morning everyone! Starting another day of coding ☀️',
              'text', 0, DATE_SUB(NOW(), INTERVAL 2 HOUR), DATE_SUB(NOW(), INTERVAL 2 HOUR)),
  (19, 1, 8,  'Morning! Coffee ☕ and code — the perfect combo.',
              'text', 0, DATE_SUB(NOW(), INTERVAL 1 HOUR), DATE_SUB(NOW(), INTERVAL 1 HOUR)),
  (20, 1, 9,  'Working on my capstone proposal today. Wish me luck! 🍀',
              'text', 0, DATE_SUB(NOW(), INTERVAL 30 MINUTE), DATE_SUB(NOW(), INTERVAL 30 MINUTE))
ON DUPLICATE KEY UPDATE content = VALUES(content);

-- ── Resources channel messages ──────────────────────────────
INSERT INTO messages
  (id, channel_id, sender_id, content, content_type, created_at, updated_at)
VALUES
  (21, 3, 3,  'Week 5 Lecture Slides — Sorting Algorithms: [attached]', 'text', DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY)),
  (22, 3, 4,  'Here''s a great video on Binary Trees: https://youtu.be/example — highly recommend watching before Thursday!', 'text', DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY)),
  (23, 3, 8,  'I made a cheatsheet for Big-O notation. Feel free to use it!', 'text', DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY)),
  (24, 3, 6,  'MIT OpenCourseWare has free lectures on Algorithms: https://ocw.mit.edu/6.006', 'text', DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY))
ON DUPLICATE KEY UPDATE content = VALUES(content);

-- ── Project help channel messages ───────────────────────────
INSERT INTO messages
  (id, channel_id, sender_id, content, content_type, created_at, updated_at)
VALUES
  (25, 4, 7,  'I''m stuck on the graph traversal part of Assignment 2. My DFS keeps hitting a stack overflow for large graphs. Any ideas?', 'text', DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY)),
  (26, 4, 6,  'Try converting the recursive DFS to an iterative version using an explicit stack. Much safer for deep graphs!', 'text', DATE_SUB(NOW(), INTERVAL 23 HOUR), DATE_SUB(NOW(), INTERVAL 23 HOUR)),
  (27, 4, 3,  'Exactly what John said. Also check your base cases — infinite recursion is often caused by a missing termination condition.', 'text', DATE_SUB(NOW(), INTERVAL 22 HOUR), DATE_SUB(NOW(), INTERVAL 22 HOUR)),
  (28, 4, 7,  'That worked! Thank you both so much! 🙌', 'text', DATE_SUB(NOW(), INTERVAL 20 HOUR), DATE_SUB(NOW(), INTERVAL 20 HOUR))
ON DUPLICATE KEY UPDATE content = VALUES(content);

-- ── IT Alpha team-chat messages ─────────────────────────────
INSERT INTO messages
  (id, channel_id, sender_id, content, content_type, created_at, updated_at)
VALUES
  (29, 10, 2,  'Team standup in 5 minutes! 🚀', 'text', DATE_SUB(NOW(), INTERVAL 6 HOUR), DATE_SUB(NOW(), INTERVAL 6 HOUR)),
  (30, 10, 5,  'Ready! Just pushed my latest changes to the dev branch.', 'text', DATE_SUB(NOW(), INTERVAL 6 HOUR), DATE_SUB(NOW(), INTERVAL 6 HOUR)),
  (31, 10, 6,  'I''ll be there — finishing the auth module now.', 'text', DATE_SUB(NOW(), INTERVAL 6 HOUR), DATE_SUB(NOW(), INTERVAL 6 HOUR)),
  (32, 10, 2,  'Great progress everyone. Sprint review is Friday at 2 PM. Make sure your features are merged by Thursday EOD.', 'text', DATE_SUB(NOW(), INTERVAL 5 HOUR), DATE_SUB(NOW(), INTERVAL 5 HOUR))
ON DUPLICATE KEY UPDATE content = VALUES(content);

-- ══════════════════════════════════════════════════════════════
-- MESSAGE REACTIONS
-- ══════════════════════════════════════════════════════════════
INSERT INTO message_reactions (message_id, user_id, emoji) VALUES
  (1,  5,  '👍'), (1,  6,  '👍'), (1,  7,  '❤️'),  (1,  8,  '🎉'),
  (2,  5,  '👋'), (2,  6,  '👋'), (2,  7,  '👋'),
  (3,  5,  '👋'), (3,  9,  '❤️'),
  (8,  7,  '👍'), (8,  8,  '👍'), (8,  9,  '🙏'),
  (15, 5,  '😅'), (15, 6,  '😅'), (15, 7,  '😬'),
  (17, 5,  '😂'), (17, 6,  '😂'), (17, 8,  '😂'),
  (28, 3,  '👍'), (28, 6,  '🎉')
ON DUPLICATE KEY UPDATE emoji = VALUES(emoji);

-- ── Update cached reaction counts ──────────────────────────
UPDATE messages m
SET reaction_count = (
  SELECT COUNT(*) FROM message_reactions mr WHERE mr.message_id = m.id
);

-- ══════════════════════════════════════════════════════════════
-- MESSAGE READS (mark all seed messages as read by all members)
-- ══════════════════════════════════════════════════════════════
INSERT INTO message_reads (user_id, channel_id, last_read_at)
SELECT u.id, c.id, NOW()
FROM users u
CROSS JOIN channels c
JOIN server_members sm ON sm.server_id = c.server_id AND sm.user_id = u.id
ON DUPLICATE KEY UPDATE last_read_at = VALUES(last_read_at);

-- ══════════════════════════════════════════════════════════════
-- WHITEBOARD INITIAL STATE
-- ══════════════════════════════════════════════════════════════
INSERT INTO whiteboards (channel_id, state_json, created_by, created_at, updated_at)
VALUES
  (7, '{"objects":[],"background":"#0b0f1a","version":"1.0"}', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE updated_at = NOW();

-- ══════════════════════════════════════════════════════════════
-- CHANNEL MEMBER COUNTS
-- ══════════════════════════════════════════════════════════════
UPDATE channels c
SET member_count = (
  SELECT COUNT(DISTINCT sm.user_id)
  FROM server_members sm WHERE sm.server_id = c.server_id
);

SET foreign_key_checks = 1;
