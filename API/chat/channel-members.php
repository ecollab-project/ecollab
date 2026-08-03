<?php
declare(strict_types=1);
/**
 * channel-members.php
 * Handles private channel access management.
 *
 * GET  ?channel_id=X                    → list members + server members (for owner/admin)
 * POST action=add    channel_id, user_id → grant access
 * POST action=remove channel_id, user_id → revoke access
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$me = AuthMiddleware::requireAuth(true);

$db = Database::getInstance();

// ── GET: list all server members with their access status ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $channelId = (int)($_GET['channel_id'] ?? 0);
    if (!$channelId) {
        http_response_code(400);
        echo json_encode(['error' => 'channel_id required']);
        exit;
    }

    // Get channel info
    $chStmt = $db->prepare("SELECT id, server_id, name, is_private, created_by FROM channels WHERE id = :cid LIMIT 1");
    $chStmt->execute([':cid' => $channelId]);
    $channel = $chStmt->fetch(PDO::FETCH_ASSOC);
    if (!$channel) {
        http_response_code(404);
        echo json_encode(['error' => 'Channel not found']);
        exit;
    }

    $serverId = (int)$channel['server_id'];

    // Verify requester is channel creator or server owner/admin
    $roleStmt = $db->prepare("SELECT server_role FROM server_members WHERE server_id = :sid AND user_id = :uid LIMIT 1");
    $roleStmt->execute([':sid' => $serverId, ':uid' => $me['id']]);
    $myRole = $roleStmt->fetchColumn();
    $canManage = in_array($myRole, ['owner', 'admin', 'moderator'], true) || (int)$channel['created_by'] === (int)$me['id'];
    if (!$canManage) {
        http_response_code(403);
        echo json_encode(['error' => 'Insufficient permissions']);
        exit;
    }

    // Return all server members with access status
    $stmt = $db->prepare("
        SELECT
            u.id,
            u.username,
            u.full_name,
            u.role,
            u.avatar_color_gradient AS grad,
            u.is_online,
            sm.server_role,
            sm.nickname,
            CASE WHEN cm.user_id IS NOT NULL THEN 1 ELSE 0 END AS has_access
        FROM users u
        JOIN server_members sm ON sm.user_id = u.id AND sm.server_id = :sid
        LEFT JOIN channel_members cm ON cm.channel_id = :cid AND cm.user_id = u.id
        WHERE u.id <> :me AND u.deleted_at IS NULL
        ORDER BY has_access DESC, u.full_name ASC
    ");
    $stmt->execute([':sid' => $serverId, ':cid' => $channelId, ':me' => $me['id']]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'channel' => $channel, 'members' => $members]);
    exit;
}

// ── POST: add or remove ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    AuthMiddleware::verifyCsrf();
    $body      = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $action    = $body['action'] ?? '';
    $channelId = (int)($body['channel_id'] ?? 0);
    $targetId  = (int)($body['user_id'] ?? 0);

    if (!$channelId || !$targetId || !in_array($action, ['add', 'remove'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'action, channel_id, and user_id required']);
        exit;
    }

    $chStmt = $db->prepare("SELECT id, server_id, is_private, created_by FROM channels WHERE id = :cid LIMIT 1");
    $chStmt->execute([':cid' => $channelId]);
    $channel = $chStmt->fetch(PDO::FETCH_ASSOC);
    if (!$channel) {
        http_response_code(404);
        echo json_encode(['error' => 'Channel not found']);
        exit;
    }

    $serverId = (int)$channel['server_id'];
    $roleStmt = $db->prepare("SELECT server_role FROM server_members WHERE server_id = :sid AND user_id = :uid LIMIT 1");
    $roleStmt->execute([':sid' => $serverId, ':uid' => $me['id']]);
    $myRole = $roleStmt->fetchColumn();
    $canManage = in_array($myRole, ['owner', 'admin', 'moderator'], true) || (int)$channel['created_by'] === (int)$me['id'];
    if (!$canManage) {
        http_response_code(403);
        echo json_encode(['error' => 'Insufficient permissions']);
        exit;
    }

    if ($action === 'add') {
        $ins = $db->prepare("INSERT IGNORE INTO channel_members (channel_id, user_id) VALUES (:cid, :uid)");
        $ins->execute([':cid' => $channelId, ':uid' => $targetId]);
        echo json_encode(['success' => true, 'message' => 'User granted access']);
    } else {
        $del = $db->prepare("DELETE FROM channel_members WHERE channel_id = :cid AND user_id = :uid");
        $del->execute([':cid' => $channelId, ':uid' => $targetId]);
        echo json_encode(['success' => true, 'message' => 'User access revoked']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
