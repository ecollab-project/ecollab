<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json; charset=utf-8');
AuthMiddleware::startSession();
$me = AuthMiddleware::requireAuth(true);
$db = Database::getInstance();

function publicServerJson(array $data, int $status=200): never {
    http_response_code($status);
    echo json_encode(['success'=>$status<400,...$data], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $action = (string)($_GET['action'] ?? '');

    if ($action === 'list' && $method === 'GET') {
        $stmt = $db->prepare("
            SELECT s.id,s.name,s.slug,s.icon_emoji,s.icon_url,s.category,s.member_count
            FROM servers s
            LEFT JOIN server_members sm ON sm.server_id=s.id AND sm.user_id=:uid
            WHERE s.status='active' AND s.type='public' AND sm.id IS NULL
            ORDER BY s.member_count DESC,s.created_at DESC
            LIMIT 20
        ");
        $stmt->execute([':uid'=>(int)$me['id']]);
        publicServerJson(['servers'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'join' && $method === 'POST') {
        AuthMiddleware::verifyCsrf();
        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $serverId = (int)($body['server_id'] ?? 0);
        if (!$serverId) publicServerJson(['error'=>'server_id is required'],400);

        $stmt=$db->prepare("SELECT id,name,type,status FROM servers WHERE id=? LIMIT 1");
        $stmt->execute([$serverId]);
        $server=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$server || $server['status']!=='active') publicServerJson(['error'=>'Server not found'],404);
        if ($server['type']!=='public') publicServerJson(['error'=>'This server is private and requires an invite or direct access'],403);

        $member=$db->prepare("SELECT id FROM server_members WHERE server_id=? AND user_id=? LIMIT 1");
        $member->execute([$serverId,(int)$me['id']]);
        if ($member->fetchColumn()) publicServerJson(['server_id'=>$serverId,'name'=>$server['name'],'already_member'=>true]);

        $db->beginTransaction();
        try {
            $db->prepare("INSERT INTO server_members(server_id,user_id,server_role,joined_at) VALUES(?,?,'member',NOW())")
               ->execute([$serverId,(int)$me['id']]);
            $db->prepare("UPDATE servers SET member_count=(SELECT COUNT(*) FROM server_members WHERE server_id=?) WHERE id=?")
               ->execute([$serverId,$serverId]);
            $db->commit();
        } catch(Throwable $e) {
            if($db->inTransaction())$db->rollBack();
            throw $e;
        }
        publicServerJson(['server_id'=>$serverId,'name'=>$server['name'],'already_member'=>false]);
    }

    publicServerJson(['error'=>'Unknown action'],404);
} catch(Throwable $e) {
    error_log('[server/public] '.$e->getMessage());
    publicServerJson(['error'=>defined('APP_DEBUG')&&APP_DEBUG?$e->getMessage():'Public server service unavailable'],500);
}
