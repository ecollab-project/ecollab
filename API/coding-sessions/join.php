<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once ROOT_PATH . '/database/config/db.php';
require_once ROOT_PATH . '/security/middleware/AuthMiddleware.php';

AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);
header('Content-Type: application/json; charset=utf-8');

$db = Database::getInstance();
$uid = (int)$user['id'];
$sessionId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));

function codingJoinFail(string $message, int $status = 400): never
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

if ($sessionId < 1 || $token === '') codingJoinFail('A valid coding session and invite token are required.');
if (strlen($token) < 32 || strlen($token) > 128) codingJoinFail('Invalid invite token.', 403);

try {
    $stmt = $db->prepare(
        'SELECT id, owner_id, invite_expires_at, version
         FROM coding_sessions
         WHERE id = :id
           AND invite_token = :token
           AND (invite_expires_at IS NULL OR invite_expires_at > NOW())
         LIMIT 1'
    );
    $stmt->execute([':id' => $sessionId, ':token' => $token]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) codingJoinFail('Invalid or expired invite.', 403);

    if ((int)$session['owner_id'] === $uid) {
        $role = 'editor';
    } else {
        $stmt = $db->prepare(
            'INSERT INTO coding_session_participants
                (session_id, user_id, status, role, joined_at)
             VALUES (:sid, :uid, \'approved\', \'editor\', NOW())
             ON DUPLICATE KEY UPDATE
                status = \'approved\',
                joined_at = NOW()'
        );
        $stmt->execute([':sid' => $sessionId, ':uid' => $uid]);
        $roleStmt = $db->prepare('SELECT role FROM coding_session_participants WHERE session_id = :sid AND user_id = :uid LIMIT 1');
        $roleStmt->execute([':sid' => $sessionId, ':uid' => $uid]);
        $role = (string)($roleStmt->fetchColumn() ?: 'editor');
    }

    echo json_encode([
        'success' => true,
        'session_id' => $sessionId,
        'status' => 'approved',
        'role' => $role,
        'version' => (int)$session['version'],
        'message' => 'Coding session access approved. Open the WebSocket and send code_join.',
    ]);
} catch (Throwable $e) {
    error_log('[coding-session-join] ' . get_class($e) . ': ' . $e->getMessage());
    codingJoinFail('Unable to join the coding session.', 500);
}
