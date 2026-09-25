<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once ROOT_PATH . '/database/config/db.php';
require_once ROOT_PATH . '/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH . '/services/CoworkspaceService.php';

AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);
header('Content-Type: application/json; charset=utf-8');
$db = Database::getInstance();
$uid = (int)$user['id'];

function memberJsonFail(string $message, int $status = 400): never
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') memberJsonFail('Method not allowed.', 405);
AuthMiddleware::verifyCsrf();

$workspaceId = (int)($input['workspace_id'] ?? 0);
$action = (string)($input['action'] ?? 'list');
if ($workspaceId < 1) memberJsonFail('A Coworkspace is required.');

try {
    // Coworkspace authorization is server-wide. The service verifies both
    // server membership and Coworkspace access before any member operation.
    $workspace = CoworkspaceService::get($db, $workspaceId, $uid);
    $serverId = (int)$workspace['server_id'];

    if ($action === 'list') {
        $stmt = $db->prepare(
            'SELECT u.id, u.username, u.full_name, m.role, m.joined_at
             FROM collab_workspace_members m
             INNER JOIN users u ON u.id = m.user_id
             INNER JOIN server_members sm ON sm.user_id = m.user_id AND sm.server_id = :sid
             WHERE m.workspace_id = :wid
             ORDER BY FIELD(m.role,"host","editor","member","viewer"), u.full_name, u.username'
        );
        $stmt->execute([':sid' => $serverId, ':wid' => $workspaceId]);
        echo json_encode(['success' => true, 'members' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'invite') {
        $isHost = (int)$workspace['host_id'] === $uid;
        $role = (string)($workspace['member_role'] ?? '');
        if (!$isHost && !((int)$workspace['allow_member_invites'] === 1 && in_array($role, ['editor'], true))) {
            memberJsonFail('You do not have permission to invite members.', 403);
        }
        $targetId = (int)($input['user_id'] ?? 0);
        $targetRole = (string)($input['role'] ?? 'member');
        if ($targetId < 1 || !in_array($targetRole, ['editor','member','viewer'], true)) memberJsonFail('Invalid member or role.');

        $serverMember = $db->prepare(
            'SELECT 1
             FROM server_members sm
             INNER JOIN servers s ON s.id = sm.server_id
             WHERE sm.server_id = :sid AND sm.user_id = :uid AND s.status = "active"
             LIMIT 1'
        );
        $serverMember->execute([':sid' => $serverId, ':uid' => $targetId]);
        if (!$serverMember->fetchColumn()) memberJsonFail('The invited user must belong to this server.', 403);

        $stmt = $db->prepare(
            'INSERT INTO collab_workspace_members (workspace_id,user_id,role) VALUES (:wid,:uid,:role)
             ON DUPLICATE KEY UPDATE role = VALUES(role)'
        );
        $stmt->execute([':wid' => $workspaceId, ':uid' => $targetId, ':role' => $targetRole]);
        $request = $db->prepare('UPDATE collab_workspace_access_requests SET status="approved", updated_at=CURRENT_TIMESTAMP WHERE workspace_id=:wid AND user_id=:uid');
        $request->execute([':wid' => $workspaceId, ':uid' => $targetId]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'remove') {
        CoworkspaceService::requireHost($db, $workspaceId, $uid);
        $targetId = (int)($input['user_id'] ?? 0);
        if ($targetId < 1 || $targetId === $uid) memberJsonFail('Invalid member.');
        $stmt = $db->prepare('DELETE FROM collab_workspace_members WHERE workspace_id=:wid AND user_id=:uid AND role <> "host"');
        $stmt->execute([':wid' => $workspaceId, ':uid' => $targetId]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'set_role') {
        CoworkspaceService::requireHost($db, $workspaceId, $uid);
        $targetId = (int)($input['user_id'] ?? 0);
        $role = (string)($input['role'] ?? 'member');
        if ($targetId < 1 || !in_array($role, ['editor','member','viewer'], true)) memberJsonFail('Invalid member role.');
        $stmt = $db->prepare('UPDATE collab_workspace_members SET role=:role WHERE workspace_id=:wid AND user_id=:uid AND role <> "host"');
        $stmt->execute([':role' => $role, ':wid' => $workspaceId, ':uid' => $targetId]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'requests') {
        CoworkspaceService::requireHost($db, $workspaceId, $uid);
        $stmt = $db->prepare(
            'SELECT r.id, r.user_id, u.username, u.full_name, r.status, r.created_at
             FROM collab_workspace_access_requests r
             INNER JOIN users u ON u.id=r.user_id
             INNER JOIN server_members sm ON sm.user_id=r.user_id AND sm.server_id=:sid
             WHERE r.workspace_id=:wid AND r.status="pending"
             ORDER BY r.created_at ASC'
        );
        $stmt->execute([':sid' => $serverId, ':wid' => $workspaceId]);
        echo json_encode(['success' => true, 'requests' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'approve_request' || $action === 'deny_request') {
        CoworkspaceService::requireHost($db, $workspaceId, $uid);
        $targetId = (int)($input['user_id'] ?? 0);
        if ($targetId < 1) memberJsonFail('A user is required.');

        if ($action === 'approve_request') {
            $serverMember = $db->prepare(
                'SELECT 1
                 FROM server_members sm
                 INNER JOIN servers s ON s.id = sm.server_id
                 WHERE sm.server_id=:sid AND sm.user_id=:uid AND s.status="active"
                 LIMIT 1'
            );
            $serverMember->execute([':sid' => $serverId, ':uid' => $targetId]);
            if (!$serverMember->fetchColumn()) memberJsonFail('The requester is no longer a server member.', 403);

            $db->beginTransaction();
            $member = $db->prepare('INSERT INTO collab_workspace_members (workspace_id,user_id,role) VALUES (:wid,:uid,"member") ON DUPLICATE KEY UPDATE role=role');
            $member->execute([':wid' => $workspaceId, ':uid' => $targetId]);
            $req = $db->prepare('UPDATE collab_workspace_access_requests SET status="approved", updated_at=CURRENT_TIMESTAMP WHERE workspace_id=:wid AND user_id=:uid');
            $req->execute([':wid' => $workspaceId, ':uid' => $targetId]);
            $db->commit();
        } else {
            $req = $db->prepare('UPDATE collab_workspace_access_requests SET status="denied", updated_at=CURRENT_TIMESTAMP WHERE workspace_id=:wid AND user_id=:uid');
            $req->execute([':wid' => $workspaceId, ':uid' => $targetId]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    memberJsonFail('Unknown member action.');
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    $status = (int)$e->getCode();
    memberJsonFail($e->getMessage(), ($status >= 400 && $status < 600) ? $status : 500);
}
