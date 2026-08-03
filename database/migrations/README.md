# Ecollab Database Migrations

This directory contains the **versioned, ordered migration sequence** for
the Ecollab database schema. It replaces the previous ad-hoc collection of
schema files (`database/New folder/*.sql`, loose `schema-*.sql` files) with
a single source of truth that can be applied safely to **fresh installs**
and **existing (old-version) databases** alike.

## Quick start

```bash
# From the project root:
php database/migrate.php           # apply all pending migrations
php database/migrate.php --status   # show what's applied / pending
php database/migrate.php --dry-run  # preview without making changes
```

The runner is idempotent — running it repeatedly is always safe. Already-
applied migrations are skipped automatically (tracked in the
`schema_migrations` table, created by `000_migration_registry.sql`).

## Free-for-all policy (migration 018)

This deployment runs as a **single free plan with every feature unlocked**
for every account. There is no Pro/Campus tier, no token-based feature
gating, and no `plan_id`-based access check anywhere in the codebase
(verified — `subscription_plans`/`users.plan_id` are stored but never read
to block a feature).

**The only access boundaries that remain are role-based**, enforced by
`security/middleware/RoleMiddleware.php`:

| Role | Sees |
|---|---|
| `student` | Student dashboard, can join servers/channels, use all collaboration tools, AI features, peer matching |
| `facilitator` | Facilitator dashboard (channel management for channels they own/moderate), plus everything a student can do |
| `admin` / `super_admin` | Admin dashboard (platform-wide stats, user management, moderation), plus everything above |

Within a server, `server_members.server_role` (`owner` / `admin` /
`moderator` / `member`) still governs what actions a member can take in
*that specific server* (e.g. only owners/admins/moderators can manage
private channels) — this is server-level moderation, not a paid-tier
restriction, and is unchanged.

The `token_transactions` / `ai_sessions.token_cost` / `system_settings`
(`tokens` category) tables remain for analytics and gamification (e.g.
"tokens earned" displays, study-streak rewards) but do not block any
feature if a user's `tokens_balance` reaches zero.

## Migration sequence

| File | Purpose | Idempotent? |
|---|---|---|
| `000_migration_registry.sql` | Creates `schema_migrations` tracking table | Yes (`CREATE TABLE IF NOT EXISTS`) |
| `002_core_schema.sql` | Core 62-table schema (users, channels, messages, servers, etc.) | First-run only |
| `004_missing_tables.sql` | `message_reads`, `channel_members`, `user_hobbies` | Yes (`IF NOT EXISTS`) |
| `005_oauth_columns.sql` | No-op — SSO columns already in 002 | Yes |
| `006_chat_addon.sql` | Chat add-on tables (whiteboards, etc.) | Yes (`IF NOT EXISTS`) |
| `007_voice_presence.sql` | Adds `users.voice_channel_id` + FK | Yes (guarded ALTER) |
| `008_dm_migration.sql` | Direct message tables | Yes (`IF NOT EXISTS`) |
| `009_channel_access_requests.sql` | Channel join-request table | Yes (`IF NOT EXISTS`) |
| `010_channel_seen.sql` | Read-receipt tracking | Yes (`IF NOT EXISTS`) |
| `011_collab_tools.sql` | Notes/Tasks/Code/Timer/Quiz/Calendar collab tools | Yes (`IF NOT EXISTS`) |
| `012_collab_extra.sql` | Flashcards/Mindmap/Review/Summary/Goals/Resources | Yes (`IF NOT EXISTS`) |
| `013_peer_matching.sql` | Tag-based peer matching system | Yes (`IF NOT EXISTS`) |
| `014_security.sql` | Audit log, account lockout, IP blocks, field encryption | Yes (`IF NOT EXISTS`) |
| `015_seeds_v2.sql` | Demo/seed data (first-run only) | First-run only |
| `016_seeds_chat.sql` | Demo chat seed data (first-run only) | First-run only |
| `017_user_plan_id.sql` | Adds `users.plan_id` + `subscription_plans` | Yes (guarded ALTER + `INSERT IGNORE`) |
| `018_free_for_all.sql` | Collapses all paid tiers into a single unlimited Free plan | Yes (idempotent UPDATE/DELETE) |

> **Note on numbering:** `001` and `003` are intentionally absent. `003`
> was a full duplicate of `002` (an old alternate schema dump) and has been
> removed entirely — including it would fatal with "table already exists"
> on a fresh install. Numbers are not required to be contiguous; the
> runner sorts whatever files are present.

## Why this exists — old and new versions running simultaneously

Before this migration system, the project had:

- 12+ separate `.sql` files with **no tracking** of which had been applied
- **Duplicate table definitions** between `ecollab.sql` and `schema-v2.sql`
  that would conflict if both were run
- **Hardcoded `USE ecollab_v2;` / `USE ecollab;`** statements that silently
  redirected DDL to a fixed database name, breaking any install using a
  different `DB_NAME`
- **`ADD CONSTRAINT IF NOT EXISTS`** syntax (007, 017 originally) — valid
  only on MariaDB, not MySQL 8, causing fatal syntax errors
- A latent bug where `services/AuthService.php::login()` selected
  `u.plan_id`, a column that **no schema file ever created** — every login
  would fatal with "Unknown column 'u.plan_id'"

All of these are fixed in this migration set. Additionally,
`security/SchemaVersion.php` provides **runtime capability detection** so
the application code itself adapts:

```php
// AuthService::login() builds its SELECT list dynamically:
$cols = SchemaVersion::selectColumns('users',
    required: ['u.id', 'u.username', /* ... */],
    optional: ['plan_id' => 'u.plan_id']  // only included if migration 017 has run
);
```

This means:
- A database that has applied migrations through `016` (no `plan_id`)
  continues to work — `$_SESSION['plan_id']` is simply `null`.
- A database that has applied through `017` gets the full subscription
  feature set active immediately, with zero code changes required.
- `security/AccountLockout.php` similarly detects whether `014_security.sql`
  has been applied; if not, lockout tracking is disabled gracefully
  (`isEnabled() === false`) rather than fataling on every login.

## Checking what's active

Admins can call `GET /API/system/health.php?level=full` (requires
admin/super_admin role) to see:

- Which migrations have been applied
- Which features are therefore active (collaboration tools, peer matching,
  security audit logging, field encryption, etc.)
- PHP extension availability (`sodium` for field encryption)
- Recent high-risk security events

`GET /API/system/health.php` (no `level` param) returns a minimal
`{"status":"ok"}` for uptime monitors, with no auth required.

## Adding new migrations

1. Create `database/migrations/0NN_description.sql` with the next number
2. Use `CREATE TABLE IF NOT EXISTS` / `ADD COLUMN IF NOT EXISTS` for safety
3. For constraints/indexes that need "if not exists" semantics on MySQL 8,
   use the guarded pattern from `017_user_plan_id.sql`:
   ```sql
   SET @exists := (SELECT COUNT(*) FROM information_schema.STATISTICS
                    WHERE table_schema = DATABASE() AND table_name = '...'
                      AND index_name = '...');
   SET @sql := IF(@exists = 0, 'ALTER TABLE ... ADD INDEX ...', 'SELECT 1');
   PREPARE stmt FROM @sql;
   EXECUTE stmt;
   DEALLOCATE PREPARE stmt;
   ```
4. Never use `USE <database>;` or `CREATE DATABASE` — the runner connects
   to the database configured in `.env` (`DB_NAME`)
5. Never use `DELIMITER` — the runner splits on `;` and doesn't support
   custom delimiters
6. Run `php database/migrate.php --dry-run` to verify, then
   `php database/migrate.php` to apply
7. Add capability-detection support in `security/SchemaVersion.php` if
   application code needs to branch on whether the new migration has run
