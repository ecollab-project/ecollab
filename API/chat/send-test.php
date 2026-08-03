<?php
/**
 * TEMPORARY — DELETE AFTER USE
 * Simulates send-message.php step by step to find exact failure point.
 * POST to this URL with JSON: {"channel_id": 1, "content": "test"}
 */
declare(strict_types=1);

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        echo json_encode(['step' => 'FATAL', 'error' => $err['message'], 'file' => basename($err['file']), 'line' => $err['line']]);
    }
});

$steps = [];

// Step 1: config
try {
    require_once dirname(__DIR__, 2) . '/config.php';
    $steps[] = 'config: OK';
} catch (Throwable $e) { die(json_encode(['step' => 'config', 'error' => $e->getMessage()])); }

// Step 2: db
try {
    require_once dirname(__DIR__, 2) . '/database/config/db.php';
    $steps[] = 'db_include: OK';
} catch (Throwable $e) { die(json_encode(['step' => 'db_include', 'error' => $e->getMessage()])); }

// Step 3: AuthMiddleware
try {
    require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
    $steps[] = 'auth_include: OK';
} catch (Throwable $e) { die(json_encode(['step' => 'auth_include', 'error' => $e->getMessage()])); }

// Step 4: MessageService
try {
    require_once dirname(__DIR__, 2) . '/services/MessageService.php';
    $steps[] = 'service_include: OK';
} catch (Throwable $e) { die(json_encode(['step' => 'service_include', 'error' => $e->getMessage()])); }

// Step 5: header + session
header('Content-Type: application/json');
try {
    AuthMiddleware::startSession();
    $steps[] = 'session: OK, id=' . session_id();
} catch (Throwable $e) { die(json_encode(['steps' => $steps, 'step' => 'session', 'error' => $e->getMessage()])); }

// Step 6: requireAuth
try {
    $user = AuthMiddleware::requireAuth(true);
    $steps[] = 'auth: OK, user_id=' . $user['id'] . ' role=' . $user['role'];
} catch (Throwable $e) { die(json_encode(['steps' => $steps, 'step' => 'requireAuth', 'error' => $e->getMessage()])); }

// Step 7: CSRF
try {
    $submitted = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? 'MISSING';
    $stored    = $_SESSION['csrf_token'] ?? 'NOT_IN_SESSION';
    $match     = hash_equals($stored, $submitted);
    $steps[]   = 'csrf: submitted=' . substr($submitted,0,8) . '... stored=' . substr($stored,0,8) . '... match=' . ($match?'YES':'NO');
    if (!$match) {
        die(json_encode(['steps' => $steps, 'step' => 'csrf', 'error' => 'CSRF MISMATCH — this is your 403, not 500']));
    }
} catch (Throwable $e) { die(json_encode(['steps' => $steps, 'step' => 'csrf', 'error' => $e->getMessage()])); }

// Step 8: parse body
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$channelId = (int)($body['channel_id'] ?? 0);
$steps[] = 'body: channel_id=' . $channelId . ' content=' . substr($body['content'] ?? '', 0, 20);

if (!$channelId) {
    die(json_encode(['steps' => $steps, 'error' => 'Send a POST with JSON {channel_id: N, content: "test"}']));
}

// Step 9: DB connection
try {
    $db = Database::getInstance();
    $steps[] = 'db_connect: OK';
} catch (Throwable $e) { die(json_encode(['steps' => $steps, 'step' => 'db_connect', 'error' => $e->getMessage()])); }

// Step 10: access check
try {
    $roleStmt = $db->prepare("SELECT role FROM users WHERE id = :uid LIMIT 1");
    $roleStmt->execute([':uid' => $user['id']]);
    $senderRole = $roleStmt->fetchColumn() ?: 'student';
    $steps[] = 'role_check: role=' . $senderRole;

    $isPrivileged = in_array($senderRole, ['admin','super_admin','moderator'], true);
    if (!$isPrivileged) {
        $access = $db->prepare("SELECT 1 FROM channels c JOIN server_members sm ON sm.server_id = c.server_id AND sm.user_id = :uid WHERE c.id = :cid AND c.is_locked = 0 LIMIT 1");
        $access->execute([':uid' => $user['id'], ':cid' => $channelId]);
        $hasAccess = (bool)$access->fetch();
        $steps[] = 'access_check: ' . ($hasAccess ? 'GRANTED' : 'DENIED — user not in server_members for this channel');
        if (!$hasAccess) die(json_encode(['steps' => $steps, 'error' => 'Access denied']));
    } else {
        $steps[] = 'access_check: PRIVILEGED user, skipped';
    }
} catch (Throwable $e) { die(json_encode(['steps' => $steps, 'step' => 'access_check', 'error' => $e->getMessage()])); }

// Step 11: INSERT
try {
    $content = trim($body['content'] ?? '');
    $stmt = $db->prepare("INSERT INTO messages (channel_id, sender_id, content, content_type, parent_id, created_at, updated_at) VALUES (:cid, :uid, :content, :type, :parent, NOW(), NOW())");
    $stmt->execute([':cid' => $channelId, ':uid' => $user['id'], ':content' => $content, ':type' => 'text', ':parent' => null]);
    $msgId = (int)$db->lastInsertId();
    $steps[] = 'insert: OK, message_id=' . $msgId;
} catch (Throwable $e) { die(json_encode(['steps' => $steps, 'step' => 'insert', 'error' => $e->getMessage()])); }

// Step 12: getMessageById
try {
    $sel = $db->prepare("SELECT m.*, u.username, u.full_name, u.avatar_url, u.avatar_color_gradient, u.role, u.is_verified FROM messages m JOIN users u ON u.id = m.sender_id WHERE m.id = :id");
    $sel->execute([':id' => $msgId]);
    $msg = $sel->fetch();
    $steps[] = 'fetch_msg: ' . ($msg ? 'OK' : 'NOT FOUND — this would throw RuntimeException 500');
} catch (Throwable $e) { die(json_encode(['steps' => $steps, 'step' => 'fetch_msg', 'error' => $e->getMessage()])); }

echo json_encode(['steps' => $steps, 'result' => 'ALL STEPS PASSED', 'msg_id' => $msgId ?? null]);
