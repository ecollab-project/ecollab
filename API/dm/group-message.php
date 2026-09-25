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
            SELECT u.id, u.username, u.full_name, u.avatar_url, u.avatar_color_gradient
            FROM dm_group_members gm JOIN users u ON u.id = gm.user_id
            WHERE gm.group_id = :gid
        ");
        $memStmt->execute([':gid' => $groupId]);
        $members = $memStmt->fetchAll(PDO::FETCH_ASSOC);

        // dm_reads is keyed by (user_id, conversation_id), and conversation_id
        // is NOT NULL in the current schema. Group conversations therefore cannot
        // be stored in dm_reads without corrupting/overwriting direct-message read
        // state. Group read tracking will use a dedicated table in a later schema
        // change; opening a group must not fail just because no DM read row exists.

        $msgs = $db->prepare(
            'SELECT dm.id, dm.sender_id, dm.body, dm.attachment_path, dm.attachment_name, dm.attachment_size, dm.attachment_mime, dm.created_at,
                    u.username AS sender_username, u.full_name AS sender_name,
                    u.avatar_url AS sender_avatar_url,
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
        $attachmentPath = trim((string)($body['attachment_path'] ?? ''));
        $attachmentName = trim((string)($body['attachment_name'] ?? ''));
        $attachmentSize = max(0, (int)($body['attachment_size'] ?? 0));
        $attachmentMime = trim((string)($body['attachment_mime'] ?? ''));
        if (!$groupId || ($text === '' && $attachmentPath === '')) { http_response_code(400); echo json_encode(['error' => 'group_id and a message or attachment are required']); exit; }
        if (mb_strlen($text) > 4000) { http_response_code(400); echo json_encode(['error' => 'Message too long']); exit; }
        requireGroupMembership($db, $groupId, $uid);

        $db->prepare('INSERT INTO dm_messages (group_id, sender_id, body, attachment_path, attachment_name, attachment_size, attachment_mime) VALUES (:gid, :uid, :body, :apath, :aname, :asize, :amime)')
            ->execute([':gid' => $groupId, ':uid' => $uid, ':body' => $text, ':apath' => $attachmentPath ?: null, ':aname' => $attachmentName ?: null, ':asize' => $attachmentSize ?: null, ':amime' => $attachmentMime ?: null]);
        $msgId = (int)$db->lastInsertId();

        $db->prepare('UPDATE dm_groups SET last_message = :msg, last_msg_at = NOW() WHERE id = :gid')
            ->execute([':msg' => mb_substr($text !== '' ? $text : ('📎 ' . ($attachmentName ?: 'Attachment')), 0, 200), ':gid' => $groupId]);

        echo json_encode(['success' => true, 'message_id' => $msgId, 'attachment_path' => $attachmentPath, 'attachment_name' => $attachmentName, 'attachment_size' => $attachmentSize, 'attachment_mime' => $attachmentMime]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
} catch (Throwable $e) {
    error_log('[dm/group-message] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : 'Server error']);
}
