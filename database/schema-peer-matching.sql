-- ============================================================
-- Ecollab Tag-Based Peer Matching Schema
-- Extends existing: interest_tags, user_interests, user_hobbies,
-- user_profiles, peer_matches
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ── Study style preference (extended, standalone if user_profiles missing) ──
CREATE TABLE IF NOT EXISTS pm_user_study_prefs (
    user_id          BIGINT UNSIGNED NOT NULL,
    -- Study style
    study_style      ENUM('solo','group','mixed')       NOT NULL DEFAULT 'mixed',
    -- Preferred session length
    session_length   ENUM('short','medium','long')      NOT NULL DEFAULT 'medium',  -- <1h, 1-2h, 2h+
    -- Preferred time of day
    time_preference  ENUM('morning','afternoon','evening','night','flexible') NOT NULL DEFAULT 'flexible',
    -- Learning modality
    learning_mode    ENUM('visual','auditory','reading','kinesthetic','mixed') NOT NULL DEFAULT 'mixed',
    -- Pace preference
    pace             ENUM('slow','moderate','fast','adaptive') NOT NULL DEFAULT 'moderate',
    -- Communication style
    comm_style       ENUM('frequent','occasional','minimal') NOT NULL DEFAULT 'occasional',
    -- Goal focus
    primary_goal     ENUM('pass_exams','build_projects','find_study_partners',
                          'improve_skills','network_collaborate','research') NOT NULL DEFAULT 'improve_skills',
    -- Availability (bitmask: Mon=1 Tue=2 Wed=4 Thu=8 Fri=16 Sat=32 Sun=64)
    availability_days TINYINT UNSIGNED NOT NULL DEFAULT 127,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Subject tags (courses / disciplines) ────────────────────────────────────
CREATE TABLE IF NOT EXISTS pm_subjects (
    id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(100)      NOT NULL,
    slug        VARCHAR(100)      NOT NULL,
    category    VARCHAR(60),                  -- e.g. STEM, Humanities, Business
    color       VARCHAR(30)       NOT NULL DEFAULT '#a855f7',
    icon        VARCHAR(10)       NOT NULL DEFAULT '📚',
    PRIMARY KEY (id),
    UNIQUE KEY uk_slug (slug),
    KEY idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── User ↔ subject linking (with proficiency level) ─────────────────────────
CREATE TABLE IF NOT EXISTS pm_user_subjects (
    user_id     BIGINT UNSIGNED   NOT NULL,
    subject_id  SMALLINT UNSIGNED NOT NULL,
    -- role: studying = needs help, tutoring = can help others, both = either
    role        ENUM('studying','tutoring','both') NOT NULL DEFAULT 'studying',
    proficiency ENUM('beginner','intermediate','advanced','expert') NOT NULL DEFAULT 'intermediate',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, subject_id),
    KEY idx_subject (subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Hobby tags ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS pm_hobby_tags (
    id       SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name     VARCHAR(80)       NOT NULL,
    slug     VARCHAR(80)       NOT NULL,
    category VARCHAR(40),                  -- e.g. Music, Sports, Gaming, Art
    icon     VARCHAR(10)       NOT NULL DEFAULT '🎯',
    PRIMARY KEY (id),
    UNIQUE KEY uk_slug (slug),
    KEY idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── User ↔ hobby linking ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS pm_user_hobbies (
    user_id  BIGINT UNSIGNED   NOT NULL,
    hobby_id SMALLINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, hobby_id),
    KEY idx_hobby (hobby_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Interest tags (reuses existing interest_tags table if present) ────────────
-- We store a denormalized copy in pm_interest_tags so this module is self-contained
CREATE TABLE IF NOT EXISTS pm_interest_tags (
    id       SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name     VARCHAR(80)       NOT NULL,
    slug     VARCHAR(80)       NOT NULL,
    category VARCHAR(40),
    icon     VARCHAR(10)       NOT NULL DEFAULT '💡',
    PRIMARY KEY (id),
    UNIQUE KEY uk_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pm_user_interests (
    user_id     BIGINT UNSIGNED   NOT NULL,
    interest_id SMALLINT UNSIGNED NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, interest_id),
    KEY idx_interest (interest_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Precomputed compatibility scores (cached, refreshed on profile change) ───
CREATE TABLE IF NOT EXISTS pm_compatibility (
    user_a_id       BIGINT UNSIGNED NOT NULL,
    user_b_id       BIGINT UNSIGNED NOT NULL,  -- always user_a < user_b
    score_total     DECIMAL(5,2)    NOT NULL DEFAULT 0,
    score_subjects  DECIMAL(5,2)    NOT NULL DEFAULT 0,
    score_interests DECIMAL(5,2)    NOT NULL DEFAULT 0,
    score_hobbies   DECIMAL(5,2)    NOT NULL DEFAULT 0,
    score_style     DECIMAL(5,2)    NOT NULL DEFAULT 0,
    shared_subjects TINYINT UNSIGNED NOT NULL DEFAULT 0,
    shared_interests TINYINT UNSIGNED NOT NULL DEFAULT 0,
    shared_hobbies  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    -- Summary tags shown on match card (JSON array of strings)
    match_tags      VARCHAR(500),
    computed_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_a_id, user_b_id),
    KEY idx_user_b (user_b_id),
    KEY idx_score  (score_total DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Tag-based match request (extends friendships) ─────────────────────────────
CREATE TABLE IF NOT EXISTS pm_match_requests (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    requester_id BIGINT UNSIGNED NOT NULL,
    addressee_id BIGINT UNSIGNED NOT NULL,
    score        DECIMAL(5,2)    NOT NULL DEFAULT 0,
    note         VARCHAR(300),              -- optional personal note
    status       ENUM('pending','accepted','declined','expired') NOT NULL DEFAULT 'pending',
    matched_via  VARCHAR(80),               -- 'subject:Math', 'hobby:Gaming', 'style:group'
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responded_at DATETIME,
    PRIMARY KEY (id),
    UNIQUE KEY uk_pair (requester_id, addressee_id),
    KEY idx_addressee (addressee_id),
    KEY idx_status (status),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Match feedback (after studying together) ──────────────────────────────────
CREATE TABLE IF NOT EXISTS pm_match_feedback (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    match_id     BIGINT UNSIGNED NOT NULL,
    reviewer_id  BIGINT UNSIGNED NOT NULL,
    rating       TINYINT UNSIGNED NOT NULL,  -- 1-5
    comment      VARCHAR(500),
    tags         VARCHAR(200),              -- 'helpful,punctual,motivating'
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_match_reviewer (match_id, reviewer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Seed data: subjects ────────────────────────────────────────────────────────
INSERT IGNORE INTO pm_subjects (name, slug, category, color, icon) VALUES
-- STEM
('Mathematics',          'mathematics',       'STEM',       '#3b82f6', '🔢'),
('Calculus',             'calculus',          'STEM',       '#3b82f6', '∫'),
('Statistics',           'statistics',        'STEM',       '#3b82f6', '📊'),
('Physics',              'physics',           'STEM',       '#06b6d4', '⚛️'),
('Chemistry',            'chemistry',         'STEM',       '#22c55e', '🧪'),
('Biology',              'biology',           'STEM',       '#84cc16', '🧬'),
('Computer Science',     'computer-science',  'STEM',       '#a855f7', '💻'),
('Data Structures',      'data-structures',   'STEM',       '#a855f7', '🌲'),
('Algorithms',           'algorithms',        'STEM',       '#a855f7', '⚙️'),
('Machine Learning',     'machine-learning',  'STEM',       '#ec4899', '🤖'),
('Web Development',      'web-development',   'STEM',       '#f97316', '🌐'),
('Database Systems',     'database-systems',  'STEM',       '#f59e0b', '🗄️'),
('Networking',           'networking',        'STEM',       '#06b6d4', '📡'),
('Cybersecurity',        'cybersecurity',     'STEM',       '#ef4444', '🔐'),
('Electrical Engineering','electrical-engineering','STEM', '#f59e0b', '⚡'),
-- Business
('Accounting',           'accounting',        'Business',   '#f59e0b', '📒'),
('Economics',            'economics',         'Business',   '#22c55e', '📈'),
('Management',           'management',        'Business',   '#3b82f6', '🏢'),
('Marketing',            'marketing',         'Business',   '#ec4899', '📣'),
('Finance',              'finance',           'Business',   '#22c55e', '💰'),
-- Humanities
('Philosophy',           'philosophy',        'Humanities', '#8b5cf6', '🤔'),
('History',              'history',           'Humanities', '#92400e', '📜'),
('Literature',           'literature',        'Humanities', '#f87171', '📖'),
('Psychology',           'psychology',        'Humanities', '#a78bfa', '🧠'),
('Sociology',            'sociology',         'Humanities', '#fb923c', '👥'),
('Political Science',    'political-science', 'Humanities', '#60a5fa', '🗳️'),
-- Other
('Medicine / Nursing',   'medicine-nursing',  'Health',     '#ef4444', '🏥'),
('Architecture',         'architecture',      'Design',     '#78716c', '🏛️'),
('Graphic Design',       'graphic-design',    'Design',     '#f472b6', '🎨'),
('Law',                  'law',               'Humanities', '#b45309', '⚖️');

-- ── Seed data: hobby tags ─────────────────────────────────────────────────────
INSERT IGNORE INTO pm_hobby_tags (name, slug, category, icon) VALUES
('Gaming',            'gaming',           'Entertainment','🎮'),
('Music',             'music',            'Arts',         '🎵'),
('Drawing / Art',     'drawing-art',      'Arts',         '🎨'),
('Photography',       'photography',      'Arts',         '📷'),
('Reading',           'reading',          'Intellectual', '📚'),
('Writing',           'writing',          'Intellectual', '✍️'),
('Coding / Dev',      'coding-dev',       'Tech',         '💻'),
('3D Modeling',       '3d-modeling',      'Tech',         '🖥️'),
('Video Editing',     'video-editing',    'Tech',         '🎬'),
('Fitness / Gym',     'fitness-gym',      'Sports',       '💪'),
('Martial Arts',      'martial-arts',     'Sports',       '🥋'),
('Basketball',        'basketball',       'Sports',       '🏀'),
('Soccer',            'soccer',           'Sports',       '⚽'),
('Swimming',          'swimming',         'Sports',       '🏊'),
('Running',           'running',          'Sports',       '🏃'),
('Cooking',           'cooking',          'Lifestyle',    '🍳'),
('Anime / Manga',     'anime-manga',      'Entertainment','🎌'),
('Board Games',       'board-games',      'Entertainment','♟️'),
('Travel',            'travel',           'Lifestyle',    '✈️'),
('Volunteering',      'volunteering',     'Social',       '🤝'),
('Chess',             'chess',            'Intellectual', '♟️'),
('Language Learning', 'language-learning','Intellectual', '🌍'),
('Robotics',          'robotics',         'Tech',         '🤖'),
('Research',          'research',         'Intellectual', '🔬');

-- ── Seed data: interest tags ──────────────────────────────────────────────────
INSERT IGNORE INTO pm_interest_tags (name, slug, category, icon) VALUES
('Artificial Intelligence', 'ai',            'Technology',  '🤖'),
('Open Source',         'open-source',       'Technology',  '🔓'),
('Entrepreneurship',    'entrepreneurship',  'Business',    '🚀'),
('Research & Science',  'research-science',  'Academic',    '🔬'),
('Environmental Issues','environmental',     'Social',      '🌱'),
('Mental Health',       'mental-health',     'Social',      '💚'),
('Space Exploration',   'space',             'Science',     '🚀'),
('Blockchain / Web3',   'blockchain',        'Technology',  '🔗'),
('Product Design',      'product-design',    'Design',      '🎨'),
('Education Reform',    'education-reform',  'Social',      '📚'),
('Film / Cinema',       'film-cinema',       'Arts',        '🎬'),
('Philosophy of Mind',  'philosophy-mind',   'Philosophy',  '🧠'),
('Linguistics',         'linguistics',       'Academic',    '🗣️'),
('Astrophysics',        'astrophysics',      'Science',     '⭐'),
('Human Rights',        'human-rights',      'Social',      '✊'),
('Financial Literacy',  'financial-literacy','Business',    '💸'),
('Public Speaking',     'public-speaking',   'Skills',      '🎤'),
('Leadership',          'leadership',        'Skills',      '👑'),
('Data Science',        'data-science',      'Technology',  '📊'),
('Cybersecurity',       'cybersecurity-int', 'Technology',  '🔐');

SET FOREIGN_KEY_CHECKS = 1;
