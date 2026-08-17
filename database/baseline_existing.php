<?php
declare(strict_types=1);

/**
 * Baseline an existing Ecollab database whose core schema was created
 * before schema_migrations tracking was introduced.
 *
 * This does NOT modify application tables or delete data.
 * It only records 002_core_schema.sql as already applied when the
 * core tables that migration creates are already present.
 *
 * Usage:
 *   php database/baseline_existing.php
 *
 * Then run:
 *   php database/migrate.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/config/db.php';

$db = Database::getInstance();

$registry = __DIR__ . '/migrations/000_migration_registry.sql';
$db->exec(file_get_contents($registry));

$coreTables = [
    'institutions',
    'users',
    'user_profiles',
    'servers',
    'server_members',
    'channels',
    'messages',
];

$missing = [];
foreach ($coreTables as $table) {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
    );
    $stmt->execute([':table' => $table]);
    if ((int)$stmt->fetchColumn() === 0) {
        $missing[] = $table;
    }
}

if ($missing) {
    echo "Existing-database baseline was NOT applied.\n";
    echo "Missing core table(s): " . implode(', ', $missing) . "\n";
    echo "The database does not look like an already-created Ecollab core schema.\n";
    exit(1);
}

$migration = __DIR__ . '/migrations/002_core_schema.sql';
$filename = basename($migration);
$checksum = hash('sha256', file_get_contents($migration));

$db->prepare("
    INSERT INTO schema_migrations
        (filename, checksum, applied_at, duration_ms, success, error_msg)
    VALUES
        (:filename, :checksum, NOW(), 0, 1,
         'Baselined: existing core schema detected; migration was already applied before tracking existed.')
    ON DUPLICATE KEY UPDATE
        checksum = VALUES(checksum),
        applied_at = NOW(),
        duration_ms = 0,
        success = 1,
        error_msg = VALUES(error_msg)
")->execute([
    ':filename' => $filename,
    ':checksum' => $checksum,
]);

echo "✓ Existing Ecollab core schema detected.\n";
echo "✓ 002_core_schema.sql has been recorded as applied.\n";
echo "✓ No application tables or data were changed.\n\n";
echo "Next: php database/migrate.php\n";
