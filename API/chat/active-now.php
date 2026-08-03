<?php
declare(strict_types=1);
/**
 * active-now.php — Returns real-time online users for a server.
 * Also accepts ?action=heartbeat&server_id=X to keep user online.
 * Also accepts ?action=voice_status&server_id=X to get voice-channel occupants.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store');

AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);

$db       = Database::getInstance();
$userId   = (int)$user['id'];
$serverId = (int)($_GET['server_id'] ?? $_POST['server_id'] ?? 0);
$action   = $_GET['action'] ?? $_POST['action'] ?? 'get';

// ── Heartbeat: keep user online ───────────────────────────────────────────
if ($action === 'heartbeat') {
    $db->prepare("UPDATE users SET is_online=1, last_active_at=NOW() WHERE id=:id")
       ->execute([':id' => $userId]);
    echo json_encode(['success' => true]);
    exit;
}

// ── Join voice (track which voice channel user is in) ─────────────────────
if ($action === 'join_voice') {
    $channelId = (int)($_POST['channel_id'] ?? 0);
    if ($channelId) {
        $db->prepare("UPDATE users SET voice_channel_id=:cid WHERE id=:id")
           ->execute([':cid' => $channelId, ':id' => $userId]);
    }
    echo json_encode(['success' => true]);
    exit;
}

// ── Leave voice ───────────────────────────────────────────────────────────
if ($action === 'leave_voice') {
    $db->prepare("UPDATE users SET voice_channel_id=NULL WHERE id=:id")
       ->execute([':id' => $userId]);
    echo json_encode(['success' => true]);
    exit;
}

// ── Get active now list ───────────────────────────────────────────────────
if ($serverId <= 0) {
    echo json_encode(['success' => false, 'users' => [], 'error' => 'Missing server_id']);
    exit;
}

// Verify user is a member of this server
$check = $db->prepare("SELECT 1 FROM server_members WHERE server_id=:s AND user_id=:u LIMIT 1");
$check->execute([':s' => $serverId, ':u' => $userId]);
if (!$check->fetch()) {
    echo json_encode(['success' => false, 'users' => [], 'error' => 'Not a member']);
    exit;
}

// Mark self as online (heartbeat)
$db->prepare("UPDATE users SET is_online=1, last_active_at=NOW() WHERE id=:id")
   ->execute([':id' => $userId]);

// Fetch online users (active in last 2 minutes counts as online)
// Also get users who are in a voice channel
$stmt = $db->prepare("
    SELECT
        u.id,
        u.username,
        u.full_name,
        u.role,
        u.avatar_color_gradient AS grad,
        u.is_online,
        u.last_active_at,
        u.voice_channel_id,
        c.name AS voice_channel_name,
        CASE
            WHEN u.voice_channel_id IS NOT NULL THEN 'voice'
            WHEN u.is_online = 1 AND u.last_active_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE) THEN 'study'
            WHEN u.is_online = 1 OR u.last_active_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE) THEN 'idle'
            ELSE 'offline'
        END AS status
    FROM users u
    JOIN server_members sm ON sm.user_id = u.id AND sm.server_id = :sid
    LEFT JOIN channels c ON c.id = u.voice_channel_id
    WHERE (u.is_online = 1 OR u.last_active_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE))
    ORDER BY
        FIELD(CASE
            WHEN u.voice_channel_id IS NOT NULL THEN 'voice'
            WHEN u.is_online = 1 AND u.last_active_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE) THEN 'study'
            ELSE 'idle'
        END, 'voice','study','idle'),
        u.full_name ASC
    LIMIT 100
");
$stmt->execute([':sid' => $serverId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$users = [];
foreach ($rows as $r) {
    $name = trim($r['full_name'] ?: $r['username']);
    $role = '';
    if ($r['status'] === 'voice') {
        $role = 'In Voice' . ($r['voice_channel_name'] ? ' • ' . $r['voice_channel_name'] : '');
    } elseif ($r['status'] === 'study') {
        $role = $r['role'] === 'facilitator' ? 'Facilitator · Active' : 'Studying';
    } else {
        $ago = '';
        if ($r['last_active_at']) {
            $diff = time() - strtotime($r['last_active_at']);
            if ($diff < 60) $ago = 'Just now';
            elseif ($diff < 3600) $ago = round($diff/60) . 'm ago';
            else $ago = round($diff/3600) . 'h ago';
        }
        $role = 'Idle' . ($ago ? ' · Last seen ' . $ago : '');
    }
    $users[] = [
        'id'              => (int)$r['id'],
        'name'            => $name,
        'username'        => $r['username'],
        'role'            => $role,
        'userRole'        => $r['role'],
        'grad'            => $r['grad'] ?: '#3b82f6,#6366f1',
        'status'          => $r['status'],
        'online'          => (bool)$r['is_online'],
        'voice_channel'   => $r['voice_channel_id'] ? (int)$r['voice_channel_id'] : null,
        'is_me'           => (int)$r['id'] === $userId,
    ];
}

echo json_encode([
    'success' => true,
    'users'   => $users,
    'count'   => count($users),
    'ts'      => time(),
]);
