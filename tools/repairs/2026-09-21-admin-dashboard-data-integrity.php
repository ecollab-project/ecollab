<?php
declare(strict_types=1);

/**
 * Safe, idempotent repair for fabricated/incorrect admin-dashboard fallbacks.
 *
 * Run from repository root:
 *   php tools/repairs/2026-09-21-admin-dashboard-data-integrity.php
 *
 * The script:
 *   1. Reads the current dashboard.php actually checked out on the VPS.
 *   2. Creates a timestamped backup only after all target patterns are found.
 *   3. Replaces fabricated fallbacks with live-data/empty-state handling.
 *   4. Refuses to write when the expected target is absent or ambiguous.
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

$original = $source;

/**
 * Replace exact known fabricated blocks. We support both indentation variants
 * seen in the repository so formatting differences do not prevent the repair.
 */
$studyRoomPatterns = [
    <<<'HTML'
<?php if (empty($dashData['study_rooms'])): ?>
            <div class="room-item"><span class="room-hash">#</span><span class="room-name">toastDEV#zWw9Rm</span><span class="room-count">12/25</span><button class="btn-join" onclick="joinRoom('toastDEV#zWw9Rm')">Join</button></div>
            <div class="room-item"><span class="room-hash">#</span><span class="room-name">Data-Structures-Discuss</span><span class="room-count">15/30</span><button class="btn-join" onclick="joinRoom('Data-Structures-Discuss')">Join</button></div>
            <div class="room-item"><span class="room-hash">#</span><span class="room-name">AI Study Group</span><span class="room-count">10/20</span><button class="btn-join" onclick="joinRoom('AI Study Group')">Join</button></div>
<?php endif; ?>
HTML,
    <<<'HTML'
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
];

$studyRoomReplacement = <<<'HTML'
<?php if (empty($dashData['study_rooms'])): ?>
            <div class="dashboard-empty-state">No active study rooms.</div>
<?php endif; ?>
HTML;

$studyRoomReplaced = false;
foreach ($studyRoomPatterns as $pattern) {
    $count = substr_count($source, $pattern);
    if ($count > 0) {
        if ($count !== 1) {
            fwrite(STDERR, "ERROR: ambiguous fabricated study-room fallback; found {$count} matches.\n");
            exit(1);
        }
        $source = str_replace($pattern, $studyRoomReplacement, $source);
        $studyRoomReplaced = true;
        break;
    }
}

/* Recent-users false empty row: support both the original and indented forms. */
$recentUserEmpty = '<tr><td colspan="6" class="dashboard-empty-state">No recent users found.</td></tr>';
$recentCount = substr_count($source, $recentUserEmpty);

if ($recentCount === 1) {
    $source = str_replace(
        $recentUserEmpty,
        <<<'PHP'
<?php if (empty($dashData['recent_users'])): ?>
<tr><td colspan="6" class="dashboard-empty-state">No recent users found.</td></tr>
<?php endif; ?>
PHP,
        $source
    );
} elseif ($recentCount > 1) {
    /*
     * One row belongs to the recent-users dashboard table; if multiple matches
     * exist, fail rather than guessing.
     */
    fwrite(STDERR, "ERROR: ambiguous recent-users empty state; found {$recentCount} matches.\n");
    exit(1);
}

/* System-log false empty state: only target the exact dashboard message. */
$systemLogEmpty = '<div class="dashboard-empty-state">No system log entries available.</div>';
$systemLogCount = substr_count($source, $systemLogEmpty);

if ($systemLogCount === 1) {
    $source = str_replace(
        $systemLogEmpty,
        <<<'PHP'
<?php if (empty($dashData['system_logs'])): ?>
<div class="dashboard-empty-state">No system log entries available.</div>
<?php endif; ?>
PHP,
        $source
    );
} elseif ($systemLogCount > 1) {
    fwrite(STDERR, "ERROR: ambiguous system-log empty state; found {$systemLogCount} matches.\n");
    exit(1);
}

/* AI accuracy should remain unavailable when backend returns NULL. */
$oldAccuracy = <<<'PHP'
number_format((float)($stats['ai_accuracy'] ?? 0),1) ?>%
PHP;

$oldAccuracyWithSpace = <<<'PHP'
number_format((float)($stats['ai_accuracy'] ?? 0), 1) ?>%
PHP;

$newAccuracy = <<<'PHP'
(($stats['ai_accuracy'] ?? null) === null
    ? 'N/A'
    : number_format((float)$stats['ai_accuracy'], 1) . '%') ?>
PHP;

/*
 * The dashboard expression lives inside a short echo tag. Match the complete
 * expression including the closing PHP delimiter to avoid changing unrelated
 * number_format calls.
 */
$accuracyReplacements = [
    $oldAccuracy => $newAccuracy,
    $oldAccuracyWithSpace => $newAccuracy,
];

$accuracyReplaced = false;
foreach ($accuracyReplacements as $old => $new) {
    $count = substr_count($source, $old);
    if ($count > 0) {
        if ($count !== 1) {
            fwrite(STDERR, "ERROR: ambiguous AI accuracy expression; found {$count} matches.\n");
            exit(1);
        }
        $source = str_replace($old, $new, $source);
        $accuracyReplaced = true;
        break;
    }
}

/*
 * Replace the description only when the exact dashboard phrase exists by
 * itself. Do not modify other informational text.
 */
$oldDescription = 'From recorded system data';
$newDescription = <<<'PHP'
<?= (($stats['ai_accuracy'] ?? null) === null)
    ? 'No evaluated matching results recorded yet'
    : 'From recorded system data' ?>
PHP;

$descriptionCount = substr_count($source, $oldDescription);
if ($descriptionCount === 1) {
    $source = str_replace($oldDescription, $newDescription, $source);
} elseif ($descriptionCount > 1) {
    fwrite(STDERR, "ERROR: ambiguous AI accuracy description; found {$descriptionCount} matches.\n");
    exit(1);
}

if ($source === $original) {
    fwrite(STDOUT, "No dashboard changes were necessary; expected fabricated fallbacks were not present.\n");
    exit(0);
}

/* Backup only immediately before writing the verified replacement. */
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
fwrite(STDOUT, "Study-room fallback repaired: " . ($studyRoomReplaced ? 'yes' : 'no') . "\n");
fwrite(STDOUT, "AI accuracy expression repaired: " . ($accuracyReplaced ? 'yes' : 'no') . "\n");
fwrite(STDOUT, "Backup: {$backup}\n");
