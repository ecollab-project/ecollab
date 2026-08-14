<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/services/ChannelService.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);

try {
    $channelId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$channelId) {
        http_response_code(400);
        echo json_encode(['error' => 'Channel id is required']);
        exit;
    }

    $db = Database::getInstance();

    $service = new ChannelService();
    $channel = $service->getChannel((int)$channelId, $user['id']);
    if (!$channel) {
        http_response_code(404);
        echo json_encode(['error' => 'Channel not found or access denied']);
        exit;
    }

    $members = $service->getOnlineMembers((int)$channel['server_id']);
    $service->markRead((int)$channelId, $user['id']);

    // Auto-create access requests table if needed
    $db->exec("
        CREATE TABLE IF NOT EXISTS channel_access_requests (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            channel_id   INT UNSIGNED    NOT NULL,
            user_id      BIGINT UNSIGNED NOT NULL,
            status       ENUM('pending','accepted','declined') NOT NULL DEFAULT 'pending',
            requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at  DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_channel_user (channel_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Determine if user can manage the channel and if they have access
    $roleStmt = $db->prepare("SELECT server_role FROM server_members WHERE server_id = :sid AND user_id = :uid LIMIT 1");
    $roleStmt->execute([':sid' => $channel['server_id'], ':uid' => $user['id']]);
    $serverRole = $roleStmt->fetchColumn();
    $canManage = in_array($serverRole, ['owner', 'admin', 'moderator'])
        || (int)($channel['created_by'] ?? 0) === (int)$user['id'];

    $hasAccess = true;
    if ($channel['is_private']) {
        $accStmt = $db->prepare("SELECT 1 FROM channel_members WHERE channel_id = :cid AND user_id = :uid LIMIT 1");
        $accStmt->execute([':cid' => $channelId, ':uid' => $user['id']]);
        $hasAccess = (bool)$accStmt->fetchColumn() || $canManage;
    }

    // Check for pending request status
    $requestStatus = null;
    if ($channel['is_private'] && !$hasAccess) {
        $reqStmt = $db->prepare("SELECT status FROM channel_access_requests WHERE channel_id = :cid AND user_id = :uid LIMIT 1");
        $reqStmt->execute([':cid' => $channelId, ':uid' => $user['id']]);
        $requestStatus = $reqStmt->fetchColumn() ?: null;
    }

    echo json_encode([
        'success'        => true,
        'channel'        => $channel,
        'members'        => $members,
        'can_manage'     => $canManage,
        'has_access'     => $hasAccess,
        'request_status' => $requestStatus,
    ]);
} catch (Throwable $e) {
    $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['error' => $e->getMessage()]);
}
