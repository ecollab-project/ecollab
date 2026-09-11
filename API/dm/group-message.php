<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$me = AuthMiddleware::requireAuth(true);
$uid = (int)$me['id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $db = Database::getInstance();

    function requireGroupMembership(PDO $db, int $groupId, int $uid): void
    {
        $mem = $db->prepare('SELECT 1 FROM dm_group_members WHERE group_id = :gid AND user_id = :uid');
        $mem->execute([':gid' => $groupId, ':uid' => $uid]);
        if (!$mem->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'You are not a member of this group']);
            exit;
        }
    }

    if ($method === 'GET') {
        $groupId = (int)($_GET['group_id'] ?? 0);
        if (!$groupId) { http_response_code(400); echo json_encode(['error' => 'group_id required']); exit; }
        requireGroupMembership($db, $groupId, $uid);

        $g = $db->prepare('SELECT id, name FROM dm_groups WHERE id = :gid LIMIT 1');
        $g->execute([':gid' => $groupId]);
        $group = $g->fetch(PDO::FETCH_ASSOC);
        if (!$group) { http_response_code(404); echo json_encode(['error' => 'Group not found']); exit; }

        $memStmt = $db->prepare("
            SELECT u.id, u.username, u.full_name, u.avatar_color_gradient
            FROM dm_group_members gm JOIN users u ON u.id = gm.user_id
            WHERE gm.group_id = :gid
        ");
        $memStmt->execute([':gid' => $groupId]);
        $members = $memStmt->fetchAll(PDO::FETCH_ASSOC);

        $readStmt = $db->prepare(
            'INSERT INTO dm_reads (user_id, group_id, last_read_at) VALUES (:uid, :gid, NOW())
             ON DUPLICATE KEY UPDATE last_read_at = NOW()'
        );
        $readStmt->execute([':uid' => $uid, ':gid' => $groupId]);

        $msgs = $db->prepare(
            'SELECT dm.id, dm.sender_id, dm.body, dm.created_at,
                    u.username AS sender_username, u.full_name AS sender_name,
                    u.avatar_color_gradient AS sender_gradient
             FROM dm_messages dm
             JOIN users u ON u.id = dm.sender_id
             WHERE dm.group_id = :gid AND dm.is_deleted = 0
             ORDER BY dm.created_at DESC
             LIMIT 50'
        );
        $msgs->execute([':gid' => $groupId]);
        $messages = array_reverse($msgs->fetchAll(PDO::FETCH_ASSOC));

        echo json_encode([
            'success' => true,
            'group' => $group,
            'members' => $members,
            'messages' => $messages,
        ]);
        exit;
    }

    if ($method === 'POST') {
        AuthMiddleware::verifyCsrf();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $groupId = (int)($body['group_id'] ?? 0);
        $text = trim((string)($body['body'] ?? ''));
        if (!$groupId || $text === '') { http_response_code(400); echo json_encode(['error' => 'group_id and body required']); exit; }
        if (mb_strlen($text) > 4000) { http_response_code(400); echo json_encode(['error' => 'Message too long']); exit; }
        requireGroupMembership($db, $groupId, $uid);

        $db->prepare('INSERT INTO dm_messages (group_id, sender_id, body) VALUES (:gid, :uid, :body)')
            ->execute([':gid' => $groupId, ':uid' => $uid, ':body' => $text]);
        $msgId = (int)$db->lastInsertId();

        $db->prepare('UPDATE dm_groups SET last_message = :msg, last_msg_at = NOW() WHERE id = :gid')
            ->execute([':msg' => mb_substr($text, 0, 200), ':gid' => $groupId]);

        echo json_encode(['success' => true, 'message_id' => $msgId]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
} catch (Throwable $e) {
    error_log('[dm/group-message] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : 'Server error']);
}
