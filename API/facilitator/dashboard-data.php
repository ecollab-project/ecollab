<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once ROOT_PATH . '/database/config/db.php';
require_once ROOT_PATH . '/security/csrf/csrf.php';
require_once ROOT_PATH . '/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH . '/security/middleware/RoleMiddleware.php';
require_once ROOT_PATH . '/services/UserService.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);
RoleMiddleware::requireRole(['facilitator','admin','super_admin','moderator'], true);

try {
    $action  = $_GET['action'] ?? 'all';
    $service = new UserService();

    /**
     * Returns true if $userId can manage (moderate/edit settings for)
     * the given channel: either their server_role for that channel's
     * server is owner/admin/moderator, OR they created the channel
     * themselves. Mirrors the canManage() check already used in
     * API/chat/channel-access-request.php, applied here so
     * resolve_report / update_channel_settings / create_announcement
     * can't be used on channels the facilitator doesn't actually manage.
     *
     * Returns null if the channel doesn't exist.
     */
    $canManageChannel = function (PDO $db, int $channelId, int $userId): ?bool {
        $ch = $db->prepare("SELECT server_id, created_by FROM channels WHERE id = :id LIMIT 1");
        $ch->execute([':id' => $channelId]);
        $channel = $ch->fetch();
        if (!$channel) return null;

        if ((int)$channel['created_by'] === $userId) return true;

        $sm = $db->prepare("SELECT server_role FROM server_members WHERE server_id = :sid AND user_id = :uid LIMIT 1");
        $sm->execute([':sid' => $channel['server_id'], ':uid' => $userId]);
        return in_array($sm->fetchColumn(), ['owner', 'admin', 'moderator'], true);
    };

    switch ($action) {
        case 'kick_member':
            CSRF::verify();
            $body      = json_decode(file_get_contents('php://input'), true) ?? [];
            $targetId  = (int)($body['user_id']   ?? 0);
            $serverId  = (int)($body['server_id'] ?? 0);
            if (!$targetId || !$serverId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'user_id and server_id required']);
                break;
            }
            // Verify current user is admin/mod of this server
            $db   = Database::getInstance();
            $chk  = $db->prepare("SELECT server_role FROM server_members WHERE server_id=:sid AND user_id=:uid");
            $chk->execute([':sid' => $serverId, ':uid' => $user['id']]);
            $role = $chk->fetchColumn();
            if (!in_array($role, ['owner','admin','moderator'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
                break;
            }
            $db->prepare("DELETE FROM server_members WHERE server_id=:sid AND user_id=:uid")
               ->execute([':sid' => $serverId, ':uid' => $targetId]);
            echo json_encode(['success' => true]);
            break;

        case 'create_announcement':
            CSRF::verify();
            $body      = json_decode(file_get_contents('php://input'), true) ?? [];
            $channelId = (int)($body['channel_id'] ?? 0);
            $content   = trim(strip_tags($body['content'] ?? ''));
            if (!$channelId || $content === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'channel_id and content required']);
                break;
            }
            $db = Database::getInstance();
            $canManage = $canManageChannel($db, $channelId, (int)$user['id']);
            if ($canManage === null) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Channel not found']);
                break;
            }
            if (!$canManage && !in_array($user['role'], ['admin','super_admin','moderator'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'You do not manage this channel']);
                break;
            }
            $ins = $db->prepare("
                INSERT INTO messages (channel_id,sender_id,content,content_type,is_pinned,created_at,updated_at)
                VALUES (:cid,:uid,:c,'text',1,NOW(),NOW())
            ");
            $ins->execute([':cid' => $channelId, ':uid' => $user['id'], ':c' => $content]);
            echo json_encode(['success' => true, 'message_id' => (int)$db->lastInsertId()]);
            break;

        case 'resolve_report':
            CSRF::verify();
            $body      = json_decode(file_get_contents('php://input'), true) ?? [];
            $messageId = (int)($body['message_id'] ?? 0);
            $action2   = $body['resolution'] ?? 'dismissed'; // 'removed' | 'dismissed'
            if (!$messageId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'message_id required']);
                break;
            }
            $db = Database::getInstance();
            $msgStmt = $db->prepare("SELECT channel_id FROM messages WHERE id = :id LIMIT 1");
            $msgStmt->execute([':id' => $messageId]);
            $channelId = $msgStmt->fetchColumn();
            if ($channelId === false) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Message not found']);
                break;
            }
            // DMs/study-room messages have no channel_id — only admins/mods
            // can resolve reports outside a channel a facilitator manages.
            if ($channelId === null) {
                if (!in_array($user['role'], ['admin','super_admin','moderator'], true)) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
                    break;
                }
            } else {
                $canManage = $canManageChannel($db, (int)$channelId, (int)$user['id']);
                if (!$canManage && !in_array($user['role'], ['admin','super_admin','moderator'], true)) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'error' => 'You do not manage this channel']);
                    break;
                }
            }
            if ($action2 === 'removed') {
                $db->prepare("UPDATE messages SET is_deleted=1,deleted_at=NOW() WHERE id=:id")
                   ->execute([':id' => $messageId]);
            }
            echo json_encode(['success' => true]);
            break;

        case 'update_channel_settings':
            CSRF::verify();
            $body      = json_decode(file_get_contents('php://input'), true) ?? [];
            $channelId = (int)($body['channel_id'] ?? 0);
            $name      = trim(strip_tags($body['name']        ?? ''));
            $desc      = trim(strip_tags($body['description'] ?? ''));
            if (!$channelId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'channel_id required']);
                break;
            }
            $db = Database::getInstance();
            $canManage = $canManageChannel($db, $channelId, (int)$user['id']);
            if ($canManage === null) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Channel not found']);
                break;
            }
            if (!$canManage && !in_array($user['role'], ['admin','super_admin','moderator'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'You do not manage this channel']);
                break;
            }
            $db->prepare("UPDATE channels SET name=:n,description=:d,updated_at=NOW() WHERE id=:id")
               ->execute([':n' => $name, ':d' => $desc, ':id' => $channelId]);
            echo json_encode(['success' => true]);
            break;

        case 'activity':
            $data = $service->getFacilitatorDashboardData($user['id']);
            echo json_encode([
                'success'  => true,
                'activity' => $data['activity'],
                'stats'    => $data['stats'],
            ]);
            break;

        default:
            $data = $service->getFacilitatorDashboardData($user['id']);
            echo json_encode(['success' => true, 'data' => $data]);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => APP_DEBUG ? $e->getMessage() : 'Server error']);
}
