<?php
declare(strict_types=1);

/**
 * Safe, idempotent repair for the admin dashboard's fabricated/incorrect UI fallbacks.
 * Run from the repository root:
 *   php tools/repairs/2026-09-21-admin-dashboard-data-integrity.php
 *
 * The script creates a timestamped backup, verifies every expected pattern before
 * changing anything, and refuses to write if the source layout differs.
 */

$root = dirname(__DIR__, 2);
$file = $root . '/modules/admin/dashboard.php';

if (!is_file($file)) {
    fwrite(STDERR, "ERROR: dashboard file not found: {$file}\n");
    exit(1);
}

$source = file_get_contents($file);
if ($source === false) {
    fwrite(STDERR, "ERROR: unable to read dashboard file.\n");
    exit(1);
}

$backup = $file . '.before-data-integrity-' . date('Ymd_His') . '.bak';
if (!copy($file, $backup)) {
    fwrite(STDERR, "ERROR: unable to create backup: {$backup}\n");
    exit(1);
}

$replacements = [
    [
        'name' => 'remove fabricated study-room fallback',
        'old' => <<<'HTML'
<?php if (empty($dashData['study_rooms'])): ?>
  <div class="room-row">
    <div class="room-info">
      <div class="room-name">toastDEV#zWw9Rm</div>
      <div class="room-meta">12/25</div>
    </div>
  </div>
  <div class="room-row">
    <div class="room-info">
      <div class="room-name">Data-Structures-Discuss</div>
      <div class="room-meta">15/30</div>
    </div>
  </div>
  <div class="room-row">
    <div class="room-info">
      <div class="room-name">AI Study Group</div>
      <div class="room-meta">10/20</div>
    </div>
  </div>
<?php endif; ?>
HTML,
        'new' => <<<'HTML'
<?php if (empty($dashData['study_rooms'])): ?>
  <div class="dashboard-empty-state">No active study rooms.</div>
<?php endif; ?>
HTML,
    ],
    [
        'name' => 'avoid false recent-users empty state',
        'old' => '<tr><td colspan="6" class="dashboard-empty-state">No recent users found.</td></tr>',
        'new' => '<?php if (empty($dashData[\'recent_users\'])): ?><tr><td colspan="6" class="dashboard-empty-state">No recent users found.</td></tr><?php endif; ?>',
    ],
    [
        'name' => 'avoid false system-log empty state',
        'old' => '<div class="dashboard-empty-state">No system log entries available.</div>',
        'new' => '<?php if (empty($dashData[\'system_logs\'])): ?><div class="dashboard-empty-state">No system log entries available.</div><?php endif; ?>',
    ],
];

foreach ($replacements as $replacement) {
    if (!str_contains($source, $replacement['old'])) {
        fwrite(STDERR, "ERROR: expected pattern missing: {$replacement['name']}\n");
        fwrite(STDERR, "Backup retained at: {$backup}\n");
        exit(1);
    }
    $source = str_replace($replacement['old'], $replacement['new'], $source, $count);
    if ($count !== 1) {
        fwrite(STDERR, "ERROR: expected exactly one replacement for {$replacement['name']}, got {$count}.\n");
        fwrite(STDERR, "Backup retained at: {$backup}\n");
        exit(1);
    }
}

$source = preg_replace_callback(
    '/number_format\(\(float\)\(\$stats\[\'ai_accuracy\'\]\s*\?\?\s*0\),\s*1\)\s*\.\s*\'%\'/',
    static fn (): string => "(($stats['ai_accuracy'] ?? null) === null ? 'Not available' : number_format((float)\$stats['ai_accuracy'], 1) . '%')",
    $source,
    -1,
    $accuracyCount
);

if ($accuracyCount !== 1) {
    fwrite(STDERR, "ERROR: AI accuracy expression was not found exactly once.\n");
    fwrite(STDERR, "Backup retained at: {$backup}\n");
    exit(1);
}

if (file_put_contents($file, $source) === false) {
    fwrite(STDERR, "ERROR: unable to write repaired dashboard.\n");
    fwrite(STDERR, "Backup retained at: {$backup}\n");
    exit(1);
}

fwrite(STDOUT, "Dashboard repair applied successfully.\n");
fwrite(STDOUT, "Backup: {$backup}\n");
