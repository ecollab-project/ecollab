-- ============================================================
-- 000_migration_registry.sql
-- ============================================================
-- Tracks which migrations have been applied to this database.
-- This is the FIRST migration ever run, and is itself idempotent.
--
-- Every other migration file in this directory is numbered
-- 001, 002, 003... and applied in order by migrate.php.
-- Re-running an already-applied migration is a no-op (the
-- runner checks this table before executing each file).
-- ============================================================

CREATE TABLE IF NOT EXISTS schema_migrations (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    filename     VARCHAR(150) NOT NULL,
    checksum     VARCHAR(64)  NOT NULL,   -- SHA-256 of file content at time of apply
    applied_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    duration_ms  INT UNSIGNED NOT NULL DEFAULT 0,
    success      TINYINT(1)   NOT NULL DEFAULT 1,
    error_msg    TEXT         NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_filename (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
