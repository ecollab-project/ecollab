<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json; charset=utf-8');
AuthMiddleware::startSession();
$me = AuthMiddleware::requireAuth(true);
$uid = (int)$me['id'];
$q = trim((string)($_GET['q'] ?? ''));
$serverId = (int)($_GET['server_id'] ?? 0);

try {
    $db = Database::getInstance();

    // DM/group suggestions are based on shared-server membership, not friendship.
    // If a current server is supplied, prefer members of that server. Otherwise,
    // show users who share at least one server with the requester.
    $params = [':uid' => $uid];
    $whereServer = '';
    if ($serverId > 0) {
        $access = $db->prepare('SELECT 1 FROM server_members WHERE server_id=:sid AND user_id=:uid LIMIT 1');
        $access->execute([':sid'=>$serverId, ':uid'=>$uid]);
        if (!$access->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['success'=>false,'error'=>'You are not a member of this server']);
            exit;
        }
        $whereServer = ' AND sm_other.server_id = :sid ';
        $params[':sid'] = $serverId;
    }

    $sql = "
        SELECT DISTINCT u.id, u.username, u.full_name, u.avatar_url, u.avatar_color_gradient,
               u.is_online, COALESCE(u.is_system,0) AS is_system
        FROM server_members sm_me
        JOIN server_members sm_other ON sm_other.server_id = sm_me.server_id
        JOIN users u ON u.id = sm_other.user_id
        WHERE sm_me.user_id = :uid
          AND u.id <> :uid2
          AND u.deleted_at IS NULL
          AND u.status NOT IN ('banned','suspended','deactivated')
          AND COALESCE(u.is_system,0) = 0
          {$whereServer}
    ";
    $params[':uid2'] = $uid;

    if ($q !== '') {
        $sql .= " AND (u.username LIKE :q OR u.full_name LIKE :q2)";
        $params[':q'] = '%' . $q . '%';
        $params[':q2'] = '%' . $q . '%';
    }

    $sql .= ' ORDER BY u.is_online DESC, u.full_name ASC LIMIT 100';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success'=>true,'users'=>$users,'server_id'=>$serverId ?: null]);
} catch (Throwable $e) {
    error_log('[dm/server-users] '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>(defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : 'Server error']);
}
