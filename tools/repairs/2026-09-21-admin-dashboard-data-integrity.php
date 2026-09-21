<?php
declare(strict_types=1);

/**
 * Safe, idempotent repair for the admin dashboard's fabricated/incorrect UI fallbacks.
 * Run from the repository root:
 *   php tools/repairs/2026-09-21-admin-dashboard-data-integrity.php
 *
 * The script creates a timestamped backup and verifies every expected pattern
 * before changing anything. A failed verification leaves the dashboard untouched.
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
        'new' => <<<'PHP'
<?php if (empty($dashData['recent_users'])): ?>
<tr><td colspan="6" class="dashboard-empty-state">No recent users found.</td></tr>
<?php endif; ?>
PHP,
    ],
    [
        'name' => 'avoid false system-log empty state',
        'old' => '<div class="dashboard-empty-state">No system log entries available.</div>',
        'new' => <<<'PHP'
<?php if (empty($dashData['system_logs'])): ?>
<div class="dashboard-empty-state">No system log entries available.</div>
<?php endif; ?>
PHP,
    ],
];

foreach ($replacements as $replacement) {
    $count = substr_count($source, $replacement['old']);

    if ($count !== 1) {
        fwrite(
            STDERR,
            "ERROR: expected exactly one occurrence for {$replacement['name']}; found {$count}.\n"
        );
        exit(1);
    }

    $source = str_replace($replacement['old'], $replacement['new'], $source);
}

/*
 * Replace the false 0% fallback with an explicit unavailable state.
 * This is deliberately done as a literal string replacement rather than
 * regex/code evaluation so the repair remains predictable.
 */
$oldAccuracy = <<<'PHP'
number_format((float)($stats['ai_accuracy'] ?? 0), 1) . '%'
PHP;

$newAccuracy = <<<'PHP'
(($stats['ai_accuracy'] ?? null) === null
    ? 'Not available'
    : number_format((float)$stats['ai_accuracy'], 1) . '%')
PHP;

$count = substr_count($source, $oldAccuracy);

if ($count !== 1) {
    fwrite(
        STDERR,
        "ERROR: expected exactly one AI accuracy expression; found {$count}.\n"
    );
    exit(1);
}

$source = str_replace($oldAccuracy, $newAccuracy, $source, $count);

/* Replace the accompanying claim when no evaluated accuracy exists. */
$oldAccuracyDescription = 'From recorded system data';
$newAccuracyDescription = <<<'PHP'
<?= (($stats['ai_accuracy'] ?? null) === null)
    ? 'No evaluated matching results recorded yet'
    : 'From recorded system data' ?>
PHP;

$descriptionCount = substr_count($source, $oldAccuracyDescription);

if ($descriptionCount !== 1) {
    fwrite(
        STDERR,
        "ERROR: expected exactly one AI accuracy description; found {$descriptionCount}.\n"
    );
    exit(1);
}

$source = str_replace($oldAccuracyDescription, $newAccuracyDescription, $source, $descriptionCount);

$backup = $file . '.before-data-integrity-' . date('Ymd_His') . '.bak';

if (!copy($file, $backup)) {
    fwrite(STDERR, "ERROR: unable to create backup: {$backup}\n");
    exit(1);
}

if (file_put_contents($file, $source) === false) {
    fwrite(STDERR, "ERROR: unable to write repaired dashboard.\n");
    fwrite(STDERR, "Backup retained at: {$backup}\n");
    exit(1);
}

fwrite(STDOUT, "Dashboard repair applied successfully.\n");
fwrite(STDOUT, "Backup: {$backup}\n");
