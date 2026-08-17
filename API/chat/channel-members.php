<?php
declare(strict_types=1);
/**
 * channel-members.php
 * Private-channel member/access management.
 *
 * GET  ?channel_id=X                    -> list server members + access state
 * POST action=add    channel_id,user_id  -> grant access (server member only)
 * POST action=remove channel_id,user_id -> revoke access
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json; charset=utf-8');
AuthMiddleware::startSession();
$me = AuthMiddleware::requireAuth(true);
$db = Database::getInstance();

function cmJson(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode(['success' => $status < 400, ...$data], JSON_UNESCAPED_UNICODE);
    exit;
}
function cmChannel(PDO $db, int $id): ?array {
    $s=$db->prepare('SELECT id,server_id,name,is_private,created_by FROM channels WHERE id=? LIMIT 1');
    $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}
function cmRole(PDO $db,int $serverId,int $userId): ?string {
    $s=$db->prepare('SELECT server_role FROM server_members WHERE server_id=? AND user_id=? LIMIT 1');
    $s->execute([$serverId,$userId]); return $s->fetchColumn() ?: null;
}
function cmCanManage(PDO $db,array $channel,int $userId): bool {
    return in_array(cmRole($db,(int)$channel['server_id'],$userId),['owner','admin','moderator'],true) || (int)$channel['created_by']===$userId;
}

try {
    $method=strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method==='GET') {
        $channelId=(int)($_GET['channel_id'] ?? 0);
        if(!$channelId) cmJson(['error'=>'channel_id required'],400);
        $channel=cmChannel($db,$channelId);
        if(!$channel) cmJson(['error'=>'Channel not found'],404);
        if(!cmCanManage($db,$channel,(int)$me['id'])) cmJson(['error'=>'Insufficient permissions'],403);
        $s=$db->prepare("SELECT u.id,u.username,u.full_name,u.role,u.avatar_color_gradient AS grad,u.is_online,sm.server_role,sm.nickname,CASE WHEN cm.user_id IS NOT NULL THEN 1 ELSE 0 END AS has_access FROM users u JOIN server_members sm ON sm.user_id=u.id AND sm.server_id=? LEFT JOIN channel_members cm ON cm.channel_id=? AND cm.user_id=u.id WHERE u.id<>? AND u.deleted_at IS NULL ORDER BY has_access DESC,u.full_name,u.username");
        $s->execute([(int)$channel['server_id'],$channelId,(int)$me['id']]);
        cmJson(['channel'=>$channel,'members'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if($method!=='POST') cmJson(['error'=>'Method not allowed'],405);
    AuthMiddleware::verifyCsrf();
    $body=json_decode(file_get_contents('php://input'),true) ?: $_POST;
    $action=(string)($body['action'] ?? '');
    $channelId=(int)($body['channel_id'] ?? 0);
    $targetId=(int)($body['user_id'] ?? 0);
    if(!$channelId || !$targetId || !in_array($action,['add','remove'],true)) cmJson(['error'=>'action, channel_id, and user_id required'],400);
    $channel=cmChannel($db,$channelId);
    if(!$channel) cmJson(['error'=>'Channel not found'],404);
    if(!cmCanManage($db,$channel,(int)$me['id'])) cmJson(['error'=>'Insufficient permissions'],403);

    $serverMember=$db->prepare('SELECT 1 FROM server_members WHERE server_id=? AND user_id=? LIMIT 1');
    $serverMember->execute([(int)$channel['server_id'],$targetId]);
    if(!$serverMember->fetchColumn()) cmJson(['error'=>'User must be a member of this server first'],409);

    if($action==='add') {
        $db->prepare('INSERT IGNORE INTO channel_members(channel_id,user_id) VALUES(?,?)')->execute([$channelId,$targetId]);
        cmJson(['message'=>'Channel access granted']);
    }
    $db->prepare('DELETE FROM channel_members WHERE channel_id=? AND user_id=?')->execute([$channelId,$targetId]);
    cmJson(['message'=>'Channel access revoked']);
} catch(Throwable $e) {
    error_log('[chat/channel-members] '.$e->getMessage());
    cmJson(['error'=>defined('APP_DEBUG')&&APP_DEBUG?$e->getMessage():'Channel member service unavailable'],500);
}
