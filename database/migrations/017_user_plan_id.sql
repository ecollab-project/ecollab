-- ============================================================
-- 001_user_plan_id.sql
-- ============================================================
-- FIXES A LATENT BUG: services/AuthService.php::login() selects
-- `u.plan_id` and writes $_SESSION['plan_id'], but no schema file
-- ever defined this column. On a database without this migration,
-- EVERY login attempt fatals with "Unknown column 'u.plan_id'".
--
-- This migration is idempotent (safe on old AND new databases):
--   - ADD COLUMN IF NOT EXISTS guards against re-application
--   - CREATE TABLE IF NOT EXISTS guards the lookup table
--   - The FK is added only if it doesn't already exist (checked
--     via a guarded procedure, since MySQL has no
--     "ADD CONSTRAINT IF NOT EXISTS")
-- ============================================================

-- ── Subscription plan catalogue ─────────────────────────────
CREATE TABLE IF NOT EXISTS subscription_plans (
    id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        VARCHAR(30)  NOT NULL,           -- 'free','student_plus','institution'
    name        VARCHAR(60)  NOT NULL,
    description VARCHAR(255),
    token_grant INT UNSIGNED NOT NULL DEFAULT 0, -- monthly token allowance
    price_cents INT UNSIGNED NOT NULL DEFAULT 0,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO subscription_plans (id, code, name, description, token_grant, price_cents) VALUES
    (1, 'free',          'Free',          'Basic access for all students', 100,  0),
    (2, 'student_plus',  'Student Plus',  'Extra AI tokens and priority support', 1000, 499),
    (3, 'institution',   'Institution',   'Unlimited usage for university accounts', 100000, 0);

-- ── Add plan_id to users (idempotent) ───────────────────────
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS plan_id SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER tokens_balance;

-- ── Add index for plan-based queries ────────────────────────
-- (MySQL 8 lacks "ADD INDEX IF NOT EXISTS", so we use a guarded
--  procedure to avoid "Duplicate key name" on re-run)
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE table_schema = DATABASE()
      AND table_name   = 'users'
      AND index_name   = 'idx_plan_id'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE users ADD INDEX idx_plan_id (plan_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── Add FK constraint (guarded — MySQL has no IF NOT EXISTS for FKs) ──
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE table_schema      = DATABASE()
      AND table_name        = 'users'
      AND constraint_name   = 'fk_user_plan'
      AND constraint_type   = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE users ADD CONSTRAINT fk_user_plan FOREIGN KEY (plan_id) REFERENCES subscription_plans(id) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── Backfill: any existing users with plan_id=0 or NULL get 'free' ──
UPDATE users SET plan_id = 1 WHERE plan_id IS NULL OR plan_id = 0;
