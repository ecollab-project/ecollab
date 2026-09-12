<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once ROOT_PATH . '/database/config/db.php';
require_once ROOT_PATH . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json; charset=utf-8');
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

AuthMiddleware::verifyCsrf();

try {
    $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $serverId = filter_var($payload['server_id'] ?? 0, FILTER_VALIDATE_INT);
    if (!$serverId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'server_id is required']);
        exit;
    }

    $db = Database::getInstance();
    $db->beginTransaction();

    $serverStmt = $db->prepare("SELECT id,name,type,status FROM servers WHERE id=:sid LIMIT 1 FOR UPDATE");
    $serverStmt->execute([':sid' => $serverId]);
    $server = $serverStmt->fetch(PDO::FETCH_ASSOC);

    if (!$server || $server['status'] !== 'active') {
        $db->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Server not found or inactive']);
        exit;
    }
    if ($server['type'] !== 'public') {
        $db->rollBack();
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'This server is not publicly joinable']);
        exit;
    }

    $memberStmt = $db->prepare('SELECT server_role FROM server_members WHERE server_id=:sid AND user_id=:uid LIMIT 1');
    $memberStmt->execute([':sid' => $serverId, ':uid' => $user['id']]);
    $existingRole = $memberStmt->fetchColumn();

    if (!$existingRole) {
        $insert = $db->prepare("INSERT INTO server_members (server_id,user_id,server_role) VALUES (:sid,:uid,'member')");
        $insert->execute([':sid' => $serverId, ':uid' => $user['id']]);
        $db->prepare('UPDATE servers SET member_count=(SELECT COUNT(*) FROM server_members WHERE server_id=:sid) WHERE id=:sid2')
            ->execute([':sid' => $serverId, ':sid2' => $serverId]);

        // Joining a server is a dashboard/chat-visible event.
        $notif = $db->prepare("INSERT INTO notifications (recipient_id,actor_id,type,title,body,link_url,icon,is_read)
            VALUES (:uid,:uid2,'server_join','Joined server',:body,:link,'🟢',1)");
        $notif->execute([
            ':uid' => $user['id'],
            ':uid2' => $user['id'],
            ':body' => 'You joined ' . $server['name'] . '.',
            ':link' => '/modules/chat/chat.php?server_id=' . $serverId,
        ]);
    }

    $db->commit();
    echo json_encode([
        'success' => true,
        'joined' => !$existingRole,
        'server' => ['id' => (int)$server['id'], 'name' => $server['name']],
        'chat_url' => BASE_URL . '/modules/chat/chat.php?server_id=' . $serverId,
    ]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('[dashboard/join-server] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to join server']);
}
