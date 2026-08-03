-- ============================================================
-- ECOLLAB – Seed Data
-- Realistic sample data matching the frontend UI
-- ============================================================

-- (USE ecollab_v2 removed — migration runner uses the configured DB connection)

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1. SUBSCRIPTION PLANS
-- ============================================================


-- ============================================================
-- 2. INSTITUTIONS
-- ============================================================
INSERT INTO institutions (id, name, domain, sso_provider, is_active)
VALUES
  (1, 'Our Lady of Fatima University', 'fatima.edu.ph', 'microsoft', 1),
  (2, 'Ecollab Demo Institution',      'demo.ecollab.io', 'google', 1);


-- ============================================================
-- 3. ACADEMIC PROGRAMS
-- ============================================================
INSERT INTO academic_programs (id, institution_id, code, name, abbreviation)
VALUES
  (1, 1, 'BSCS',  'Bachelor of Science in Computer Science',     'BS CompSci'),
  (2, 1, 'BSIT',  'Bachelor of Science in Information Technology', 'BS IT'),
  (3, 1, 'BSIS',  'Bachelor of Science in Information Systems',  'BS IS'),
  (4, 1, 'BSCOE', 'Bachelor of Science in Computer Engineering', 'BS CompEng');


-- ============================================================
-- 4. USERS
-- Roles: admin, facilitator, moderator, student (x many)
-- Passwords are bcrypt hashes of "Password123!"
-- ============================================================
INSERT INTO users
  (id, institution_id, username, email, student_id,
   password_hash, full_name, avatar_color_gradient, role, status,
   email_verified, is_online, tokens_balance, is_verified)
VALUES
  -- Admin
  (1, 1, 3, 'super_admin',     'admin@fatima.edu.ph',         'ADMIN-001',
   '$2y$12$/c2ioXP8IYPhxSyEOL61p.5cpTWpgxDr6x/yDFj2wXYyhNnQiQblu', 'System Administrator',
   '#ff4fd8,#7c5cff', 'admin', 'active', 1, 1, 9999, 1),

  -- Moderator
  (2, 1, 3, 'mod_carlos',      'carlos.reyes@fatima.edu.ph',  'MOD-001',
   '$2y$12$/c2ioXP8IYPhxSyEOL61p.5cpTWpgxDr6x/yDFj2wXYyhNnQiQblu',   'Carlos Reyes',
   '#00d4ff,#3b82f6', 'moderator', 'active', 1, 1, 500, 1),

  -- Facilitators
  (3, 1, 3, 'adam_smith',      'adam.smith@fatima.edu.ph',    'FAC-001',
   '$2y$12$/c2ioXP8IYPhxSyEOL61p.5cpTWpgxDr6x/yDFj2wXYyhNnQiQblu',  'Adam Smith',
   '#ef4444,#dc2626', 'facilitator', 'active', 1, 1, 300, 1),
  (4, 1, 3, 'prof_santos',     'maria.santos@fatima.edu.ph',  'FAC-002',
   '$2y$12$/c2ioXP8IYPhxSyEOL61p.5cpTWpgxDr6x/yDFj2wXYyhNnQiQblu',  'Maria Santos',
   '#7c5cff,#ff4fd8', 'facilitator', 'active', 1, 0, 300, 1),

  -- Students
  (5, 1, 'fatima_student',  'fatima.student@fatima.edu.ph','2025-001',
   '$2y$12$/c2ioXP8IYPhxSyEOL61p.5cpTWpgxDr6x/yDFj2wXYyhNnQiQblu',  'Fatima Santos',
   '#ff4fd8,#7c5cff', 'student', 'active', 1, 1, 80, 0),
  (6, 1, 2, 'john_doe',        'john.doe@fatima.edu.ph',      '2025-002',
   '$2y$12$/c2ioXP8IYPhxSyEOL61p.5cpTWpgxDr6x/yDFj2wXYyhNnQiQblu',  'John Doe',
   '#3b82f6,#00d4ff', 'student', 'active', 1, 1, 150, 0),
  (7, 1, 'sara_kim',        'sara.kim@fatima.edu.ph',      '2025-003',
   '$2y$12$/c2ioXP8IYPhxSyEOL61p.5cpTWpgxDr6x/yDFj2wXYyhNnQiQblu',  'Sara Kim',
   '#22c55e,#16a34a', 'student', 'active', 1, 0, 60, 0),
  (8, 1, 'mike_lee',        'mike.lee@fatima.edu.ph',      '2025-004',
   '$2y$12$/c2ioXP8IYPhxSyEOL61p.5cpTWpgxDr6x/yDFj2wXYyhNnQiQblu',  'Mike Lee',
   '#f59e0b,#ef4444', 'student', 'active', 1, 1, 40, 0),
  (9, 1, 2, 'david_wilson',    'david.wilson@fatima.edu.ph',  '2025-005',
   '$2y$12$/c2ioXP8IYPhxSyEOL61p.5cpTWpgxDr6x/yDFj2wXYyhNnQiQblu',  'David Wilson',
   '#7c5cff,#ff4fd8', 'student', 'active', 1, 0, 200, 0),
  (10,1, 'leyla_ahmed',     'leyla.ahmed@fatima.edu.ph',   '2025-006',
   '$2y$12$/c2ioXP8IYPhxSyEOL61p.5cpTWpgxDr6x/yDFj2wXYyhNnQiQblu',  'Leyla Ahmed',
   '#e91e8c,#7c3aed', 'student', 'offline', 1, 0, 20, 0);


-- ============================================================
-- 5. USER PROFILES
-- ============================================================
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


-- ============================================================
-- 6. INTEREST TAGS
-- ============================================================
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


-- ============================================================
-- 7. SERVERS
-- ============================================================
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


-- ============================================================
-- 8. CHANNELS
-- ============================================================
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


-- ============================================================
-- 9. STUDY ROOMS
-- ============================================================
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


-- ============================================================
-- 10. MESSAGES
-- ============================================================
INSERT INTO messages (id, channel_id, sender_id, content, content_type) VALUES
  (1, 1, 3,  'Welcome to CS 305! Please check the announcements for the syllabus.', 'text'),
  (2, 1, 5,  'Thank you, Prof! Looking forward to the CNNs chapter.', 'text'),
  (3, 1, 6,  'Can someone share the Chapter 5 notes?', 'text'),
  (4, 1, 7,  'I uploaded them in the resources section!', 'text'),
  (5, 2, 3,  'Midterm exam will be on May 28. Coverage: Chapters 1-6.', 'announcement'),
  (6, 5, 5,  'Quick question – when to use BST vs Heap?', 'text'),
  (7, 5, 6,  'BST for ordered data, Heap for priority queues!', 'text'),
  (8, 8, 4,  'Lab 3 submission deadline is this Friday.', 'text');


-- ============================================================
-- 11. DIRECT MESSAGES
-- ============================================================
INSERT INTO direct_messages (sender_id, recipient_id, content, is_read) VALUES
  (6, 5, 'Hey! Are you joining the study room tonight?', 1),
  (5, 6, 'Yes! Starting at 8PM. Join ROOM-CS305 😊', 1),
  (6, 5, 'Perfect, see you there!', 0),
  (3, 5, 'Hi, great work on your last quiz Fatima!', 0);


-- ============================================================
-- 12. SUBJECT CLASSES
-- ============================================================
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


-- ============================================================
-- 13. STUDY SESSIONS
-- ============================================================
INSERT INTO study_sessions
  (user_id, room_id, class_id, subject_label, started_at, ended_at, duration_mins)
VALUES
  (6, 1, 1, 'CS 305 Chapter 5 CNNs',    DATE_SUB(NOW(), INTERVAL 2 HOUR),  NOW(), 120),
  (5, 2, 2, 'CS 201 Trees & Graphs',    DATE_SUB(NOW(), INTERVAL 90 MINUTE), NOW(), 90),
  (6, NULL,2, 'CS 201 Solo Review',     DATE_SUB(NOW(), INTERVAL 1 DAY),   DATE_SUB(NOW(), INTERVAL 20 HOUR), 240),
  (8, 3, NULL,'Unity 6 Collab',         DATE_SUB(NOW(), INTERVAL 3 HOUR),  DATE_SUB(NOW(), INTERVAL 1 HOUR), 120),
  (7, NULL,3, 'CS 210 Normalization',   DATE_SUB(NOW(), INTERVAL 2 DAY),   DATE_SUB(NOW(), INTERVAL 44 HOUR), 90);


-- ============================================================
-- 14. NOTES
-- ============================================================
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


-- ============================================================
-- 15. UPLOADED FILES
-- ============================================================
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


-- ============================================================
-- 16. AI SESSIONS & MESSAGES
-- ============================================================
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


-- ============================================================
-- 17. NOTIFICATIONS
-- ============================================================
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


-- ============================================================
-- 18. PEER MATCHES (AI)
-- ============================================================
INSERT INTO peer_matches
  (user_a_id, user_b_id, match_score, match_reason, status)
VALUES
  (5, 6,  94.5, 'Shared interests in AI and Data Science. Both prefer group study. Same program year.', 'accepted'),
  (5, 7,  78.2, 'Similar courses enrolled. Complementary study schedules.', 'pending'),
  (6, 8,  88.0, 'Both prefer group collaboration and share DevOps/Cloud interests.', 'accepted'),
  (7, 10, 71.5, 'Both are in lower years and studying similar beginner topics.', 'pending');


-- ============================================================
-- 19. CALENDAR EVENTS
-- ============================================================
INSERT INTO calendar_events
  (user_id, class_id, room_id, title, event_type, starts_at, ends_at, color_hex)
VALUES
  (6, 1, 1, 'Neural Networks Study Room',  'study_session', DATE_ADD(NOW(), INTERVAL 2 HOUR), DATE_ADD(NOW(), INTERVAL 4 HOUR), '#e91e8c'),
  (6, 1, NULL,'CS 305 Midterm Exam',       'exam',          DATE_ADD(NOW(), INTERVAL 9 DAY),  DATE_ADD(NOW(), INTERVAL 9 DAY),  '#ef4444'),
  (5, 2, 2, 'DSA Review Session',          'study_session', DATE_ADD(NOW(), INTERVAL 1 DAY),  DATE_ADD(NOW(), INTERVAL 1 DAY),  '#7c3aed'),
  (7, 3, NULL,'CS 210 Lab 3 Deadline',     'deadline',      DATE_ADD(NOW(), INTERVAL 5 DAY),  NULL, '#f59e0b'),
  (5, NULL,NULL,'Competitive Coding Contest','personal',     DATE_ADD(NOW(), INTERVAL 7 DAY),  DATE_ADD(NOW(), INTERVAL 7 DAY),  '#06b6d4');


-- ============================================================
-- 20. WHITEBOARD SESSIONS
-- ============================================================
INSERT INTO whiteboard_sessions (room_id, class_id, created_by, title, status)
VALUES
  (1, 1, 6, 'CNN Architecture Diagram',    'active'),
  (2, 2, 5, 'BST Deletion Walkthrough',    'saved'),
  (NULL,3, 7, 'ER Diagram – Library DB',  'saved');


-- ============================================================
-- 21. ROLES & PERMISSIONS
-- ============================================================
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


-- ============================================================
-- 22. MODERATION ACTIONS
-- ============================================================
INSERT INTO moderation_actions
  (moderator_id, target_user_id, server_id, action_type, reason, is_active)
VALUES
  (2, 9, 4, 'warn',  'Spamming links in #general channel', 1),
  (1, 10, NULL,'mute','Repeated off-topic messages in class channel', 1);


-- ============================================================
-- 23. CONTENT REPORTS
-- ============================================================
INSERT INTO content_reports
  (reporter_id, reported_user_id, server_id, reason, description, status)
VALUES
  (6, 9,  4, 'spam',        'User repeatedly posting promotional links in #general', 'pending'),
  (5, NULL,1, 'inappropriate','Flagged message with possible phishing content',       'reviewing');


-- ============================================================
-- 24. ACTIVITY LOGS
-- ============================================================
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


-- ============================================================
-- 25. FEEDBACK
-- ============================================================
INSERT INTO feedback (user_id, type, title, description, rating, status) VALUES
  (6, 'feature_request', 'Add LaTeX support in notes editor',
   'It would be great to write math equations using LaTeX syntax in notes.', NULL, 'open'),
  (5, 'bug',             'AI chat sometimes freezes on mobile',
   'When using the AI assistant on mobile, the chat input gets stuck after 3 messages.', NULL, 'reviewing'),
  (7, 'praise',          'Love the whiteboard feature!',
   'The whiteboard tool is incredibly helpful for collaborative studying. Great work!', 5, 'resolved');


-- ============================================================
-- 26. USER SETTINGS
-- ============================================================
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


-- ============================================================
-- 27. PLATFORM ANALYTICS (Daily snapshot)
-- ============================================================
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


-- ============================================================
-- 28. FACILITATOR STATS
-- ============================================================
INSERT INTO facilitator_stats
  (facilitator_id, class_id, total_students, active_students,
   avg_score, very_active_count, inactive_count, drop_rate, avg_study_hours)
VALUES
  (3, 1, 42, 38, 82.5, 18, 4, 2.4, 6.8),
  (3, 2, 38, 35, 78.0, 14, 3, 1.8, 5.2),
  (4, 3, 29, 26, 85.1, 11, 3, 3.1, 4.9),
  (4, 4, 25, 22, 79.8,  9, 3, 2.0, 5.5);


-- ============================================================
-- 29. SUBSCRIPTION RECORDS
-- ============================================================


-- ============================================================
-- 30. TOKEN TRANSACTIONS
-- ============================================================
INSERT INTO token_transactions (user_id, type, amount, balance_after, reason) VALUES
  (6, 'earned',  50, 200, 'Study streak reward – 7 days'),
  (6, 'spent',  -10, 190, 'AI query – Neural Networks summary'),
  (6, 'earned', 100, 290, 'Weekly study goal achieved'),
  (5, 'earned',  30,  80, 'First study room session'),
  (8, 'earned', 200, 200, 'Top contributor of the week');


-- ============================================================
-- 31. SSO TOKENS
-- ============================================================
INSERT INTO sso_tokens (user_id, provider, provider_uid)
VALUES
  (6, 'microsoft', 'ms_uid_john_doe_fatima'),
  (5, 'google',    'google_uid_fatima_santos');


-- ============================================================
-- 32. SYSTEM SETTINGS
-- ============================================================
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


SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- END OF SEED DATA
-- ============================================================