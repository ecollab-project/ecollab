-- ============================================================
-- Ecollab OAuth / SSO — Schema notes
-- The schema already contains sso_provider and sso_uid on the
-- users table, and the sso_tokens table.
-- This file just ensures the index exists and the DB is correct.
-- Run against ecollab_v2.
-- ============================================================

USE ecollab_v2;

-- Ensure the SSO index exists (safe if already present)
CREATE INDEX IF NOT EXISTS idx_users_sso
    ON users (sso_provider, sso_uid);

-- email_verified column already exists in schema.
-- No additional columns needed.
