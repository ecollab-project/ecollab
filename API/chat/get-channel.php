<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/services/ChannelService.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);
// These endpoints write no session data; release the per-account lock before DB work.
session_write_close();
header('Cache-Control: no-store, private');

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

    // Resolve access before exposing member/activity data.
    $platformRole = (string)($user['role'] ?? 'student');
    $isSysAdmin = in_array($platformRole, ['admin','super_admin'], true);
    $serverRole = null;
    if (!$isSysAdmin) {
        $roleStmt = $db->prepare("SELECT server_role FROM server_members WHERE server_id = :sid AND user_id = :uid LIMIT 1");
        $roleStmt->execute([':sid' => $channel['server_id'], ':uid' => $user['id']]);
        $serverRole = $roleStmt->fetchColumn();
    }
    $canManage = $isSysAdmin
        || in_array($serverRole, ['owner', 'admin'], true)
        || (int)($channel['owner_id'] ?? $channel['created_by'] ?? 0) === (int)$user['id'];

    $hasAccess = true;
    if ($channel['is_private']) {
        $accStmt = $db->prepare("SELECT 1 FROM channel_members WHERE channel_id = :cid AND user_id = :uid LIMIT 1");
        $accStmt->execute([':cid' => $channelId, ':uid' => $user['id']]);
        $hasAccess = $isSysAdmin || (bool)$accStmt->fetchColumn() || $canManage;
    }

    $members = $hasAccess ? $service->getOnlineMembers((int)$channel['server_id'], (int)$user['id']) : [];
    if ($hasAccess) {
        $service->markRead((int)$channelId, $user['id']);
    }

    // Access state was resolved before loading channel content.

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
    echo json_encode(['error' => $code < 500 ? $e->getMessage() : 'Server error']);
}

