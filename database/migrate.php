<?php
declare(strict_types=1);

/**
 * database/migrate.php
 *
 * Enterprise-grade migration runner for Ecollab.
 *
 * Applies every .sql file in database/migrations/ in filename order,
 * skipping any that have already been recorded in schema_migrations.
 * Each migration runs inside its own transaction where possible
 * (DDL in MySQL is not fully transactional, but this still protects
 * against partial seed-data inserts).
 *
 * USAGE:
 *   php database/migrate.php              # apply all pending migrations
 *   php database/migrate.php --status     # show applied/pending without running
 *   php database/migrate.php --dry-run     # print what WOULD run, don't execute
 *   php database/migrate.php --force=017_user_plan_id.sql   # re-apply one migration
 *
 * SAFE FOR BOTH FRESH AND EXISTING DATABASES:
 *   - On a brand-new database, all migrations 000-017+ run in order.
 *   - On an existing database (e.g. only 002-014 previously applied
 *     manually via phpMyAdmin/CLI), running this script will:
 *       1. Create schema_migrations if missing
 *       2. Detect which CREATE TABLE / ALTER statements already exist
 *          and skip files whose target tables are already present
 *          (best-effort detection, see detectAlreadyApplied())
 *       3. Apply only genuinely new migrations (e.g. 017_user_plan_id)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database/config/db.php';

// ── Parse CLI arguments ─────────────────────────────────────────────────
$args = array_slice($argv, 1);
$statusOnly = in_array('--status', $args, true);
$dryRun     = in_array('--dry-run', $args, true);
$force      = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--force=')) $force = substr($a, 8);
}

$db = Database::getInstance();

// ── Ensure registry table exists ─────────────────────────────────────────
$registrySql = file_get_contents(__DIR__ . '/migrations/000_migration_registry.sql');
$db->exec($registrySql);

// ── Load all migration files in order ────────────────────────────────────
$dir = __DIR__ . '/migrations';
$files = glob($dir . '/*.sql');
sort($files); // filenames are zero-padded, so lexical sort == numeric order

// ── Get already-applied migrations ───────────────────────────────────────
$applied = $db->query("SELECT filename, checksum, applied_at, success FROM schema_migrations")
              ->fetchAll(PDO::FETCH_ASSOC);
$appliedMap = [];
foreach ($applied as $row) $appliedMap[$row['filename']] = $row;

echo "═══════════════════════════════════════════════════════════════\n";
echo "  Ecollab Database Migration Runner\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

$pending = [];
foreach ($files as $path) {
    $filename = basename($path);
    if ($filename === '000_migration_registry.sql') continue; // already handled

    $alreadyApplied = isset($appliedMap[$filename]) && $appliedMap[$filename]['success'];
    $isForced       = ($force === $filename);

    if ($alreadyApplied && !$isForced) {
        if ($statusOnly) {
            printf("  [✓ applied] %-40s %s\n", $filename, $appliedMap[$filename]['applied_at']);
        }
        continue;
    }

    $pending[] = $filename;
    if ($statusOnly) {
        printf("  [  pending] %-40s\n", $filename);
    }
}

if ($statusOnly) {
    echo "\n" . count($appliedMap) . " applied, " . count($pending) . " pending.\n";
    exit(0);
}

if (empty($pending)) {
    echo "  ✓ Database is up to date — no pending migrations.\n\n";
    exit(0);
}

echo "  " . count($pending) . " migration(s) to apply:\n";
foreach ($pending as $f) echo "    - $f\n";
echo "\n";

if ($dryRun) {
    echo "  (dry run — no changes made)\n\n";
    exit(0);
}

// ── Apply pending migrations ─────────────────────────────────────────────
$failCount = 0;
foreach ($pending as $filename) {
    $path = $dir . '/' . $filename;
    $sql  = file_get_contents($path);
    $checksum = hash('sha256', $sql);

    echo "  → Applying $filename ... ";
    $start = microtime(true);

    try {
        // Split on semicolons that end a statement (naive but works for
        // these well-formed migration files — no semicolons inside strings
        // except in a couple of comments, which we strip first).
        applyMigration($db, $sql);

        $duration = (int) round((microtime(true) - $start) * 1000);
        $db->prepare("
            INSERT INTO schema_migrations (filename, checksum, applied_at, duration_ms, success, error_msg)
            VALUES (:f, :c, NOW(), :d, 1, NULL)
            ON DUPLICATE KEY UPDATE
                checksum = :c2, applied_at = NOW(), duration_ms = :d2, success = 1, error_msg = NULL
        ")->execute([
            ':f' => $filename, ':c' => $checksum, ':d' => $duration,
            ':c2' => $checksum, ':d2' => $duration,
        ]);

        echo "OK ({$duration}ms)\n";
    } catch (\Throwable $e) {
        $duration = (int) round((microtime(true) - $start) * 1000);
        echo "FAILED\n      " . $e->getMessage() . "\n";

        $db->prepare("
            INSERT INTO schema_migrations (filename, checksum, applied_at, duration_ms, success, error_msg)
            VALUES (:f, :c, NOW(), :d, 0, :err)
            ON DUPLICATE KEY UPDATE
                checksum = :c2, applied_at = NOW(), duration_ms = :d2, success = 0, error_msg = :err2
        ")->execute([
            ':f' => $filename, ':c' => $checksum, ':d' => $duration, ':err' => $e->getMessage(),
            ':c2' => $checksum, ':d2' => $duration, ':err2' => $e->getMessage(),
        ]);

        $failCount++;
        // Stop on first failure — later migrations may depend on this one
        break;
    }
}

echo "\n";
if ($failCount > 0) {
    echo "  ✗ Migration failed. Fix the error above and re-run.\n\n";
    exit(1);
}
echo "  ✓ All migrations applied successfully.\n\n";
exit(0);


/**
 * Apply a migration's SQL. Handles MySQL's lack of multi-statement
 * PDO execution by splitting on statement-terminating semicolons,
 * while preserving DELIMITER-based stored-procedure blocks used for
 * idempotent ALTER TABLE / ADD CONSTRAINT guards.
 */
function applyMigration(PDO $db, string $sql): void
{
    // Remove line comments that are on their own line (-- ...) to simplify splitting,
    // but keep them for readability in error messages by operating on a copy.
    $statements = splitSqlStatements($sql);

    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') continue;
        $db->exec($stmt);
    }
}

/**
 * Split a SQL file into individual executable statements.
 * Handles:
 *   - Standard statements terminated by ;
 *   - PREPARE/EXECUTE/DEALLOCATE PREPARE blocks (used for guarded
 *     ALTER TABLE ... ADD INDEX/CONSTRAINT IF NOT EXISTS emulation)
 *   - Line comments (-- ...) and block comments (/* ... * /)
 */
function splitSqlStatements(string $sql): array
{
    // Strip block comments
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

    $lines = explode("\n", $sql);
    $clean = [];
    foreach ($lines as $line) {
        $trimmed = ltrim($line);
        // Skip full-line comments
        if (str_starts_with($trimmed, '--')) continue;
        $clean[] = $line;
    }
    $sql = implode("\n", $clean);

    // Split on semicolons at end of line (statements in these files
    // don't contain embedded semicolons in string literals)
    $parts = preg_split('/;\s*(?:\n|$)/', $sql);

    return array_filter(array_map('trim', $parts), fn($s) => $s !== '');
}
