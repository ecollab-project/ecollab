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

function presenceFail(string $message, int $status = 400): never
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

try {
    $workspaceId = (int)($_GET['workspace_id'] ?? $_POST['workspace_id'] ?? 0);
    if ($workspaceId < 1) presenceFail('A Coworkspace is required.');

    $workspace = CoworkspaceService::get($db, $workspaceId, $uid);
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        $stmt = $db->prepare(
            'SELECT p.user_id, u.username, u.full_name, m.role, p.last_seen_at
             FROM collab_workspace_presence p
             INNER JOIN collab_workspace_members m ON m.workspace_id=p.workspace_id AND m.user_id=p.user_id
             INNER JOIN users u ON u.id=p.user_id
             WHERE p.workspace_id=:wid AND p.last_seen_at >= (CURRENT_TIMESTAMP - INTERVAL 45 SECOND)
             ORDER BY p.last_seen_at DESC, u.full_name, u.username'
        );
        $stmt->execute([':wid' => $workspaceId]);
        echo json_encode(['success' => true, 'active' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    AuthMiddleware::verifyCsrf();
    $role = (string)($workspace['member_role'] ?? '');
    if ($role === '') presenceFail('You do not have access to this Coworkspace.', 403);

    $stmt = $db->prepare(
        'INSERT INTO collab_workspace_presence (workspace_id,user_id,last_seen_at)
         VALUES (:wid,:uid,CURRENT_TIMESTAMP)
         ON DUPLICATE KEY UPDATE last_seen_at=CURRENT_TIMESTAMP'
    );
    $stmt->execute([':wid' => $workspaceId, ':uid' => $uid]);
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    $status = (int)$e->getCode();
    presenceFail($e->getMessage(), ($status >= 400 && $status < 600) ? $status : 500);
}
