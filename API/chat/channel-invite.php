<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json; charset=utf-8');
AuthMiddleware::startSession();
$me = AuthMiddleware::requireAuth(true);
$db = Database::getInstance();

function channelInviteJson(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode(['success' => $status < 400, ...$data], JSON_UNESCAPED_UNICODE);
    exit;
}
function channelInfo(PDO $db, int $channelId): ?array {
    $s = $db->prepare('SELECT id,server_id,name,slug,is_private,created_by FROM channels WHERE id=? LIMIT 1');
    $s->execute([$channelId]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}
function channelRole(PDO $db, int $serverId, int $userId): ?string {
    $s = $db->prepare('SELECT server_role FROM server_members WHERE server_id=? AND user_id=? LIMIT 1');
    $s->execute([$serverId,$userId]);
    return $s->fetchColumn() ?: null;
}
function canChannelInvite(PDO $db, array $channel, int $userId): bool {
    $role = channelRole($db,(int)$channel['server_id'],$userId);
    return in_array($role,['owner','admin','moderator'],true) || (int)$channel['created_by']===$userId;
}
function channelInviteUrl(string $token): string {
    return rtrim((string)BASE_URL,'/') . '/modules/chat/chat.php?channel_invite=' . rawurlencode($token);
}

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $action = (string)($_GET['action'] ?? '');
    $body = $method === 'POST' ? (json_decode(file_get_contents('php://input'),true) ?: $_POST) : $_GET;
    if ($method === 'POST') AuthMiddleware::verifyCsrf();

    if ($action === 'create') {
        $channelId=(int)($body['channel_id'] ?? 0);
        $ch=channelInfo($db,$channelId);
        if (!$ch) channelInviteJson(['error'=>'Channel not found'],404);
        if (!canChannelInvite($db,$ch,(int)$me['id'])) channelInviteJson(['error'=>'Insufficient permissions'],403);
        $maxUses=max(0,min(100000,(int)($body['max_uses'] ?? 0)));
        $expiresHours=max(0,min(8760,(int)($body['expires_hours'] ?? 0)));
        $expiresAt=$expiresHours>0?date('Y-m-d H:i:s',time()+$expiresHours*3600):null;
        $token=rtrim(strtr(base64_encode(random_bytes(24)),'+/','-_'),'=');
        $hash=hash('sha256',$token);
        $stmt=$db->prepare('INSERT INTO channel_invites (channel_id,created_by,token_hash,max_uses,expires_at) VALUES (?,?,?,?,?)');
        $stmt->execute([$channelId,$me['id'],$hash,$maxUses,$expiresAt]);
        channelInviteJson(['invite'=>['id'=>(int)$db->lastInsertId(),'channel_id'=>$channelId,'max_uses'=>$maxUses,'expires_at'=>$expiresAt,'invite_url'=>channelInviteUrl($token)]]);
    }

    if ($action === 'list') {
        $channelId=(int)($_GET['channel_id'] ?? 0);
        $ch=channelInfo($db,$channelId);
        if (!$ch) channelInviteJson(['error'=>'Channel not found'],404);
        if (!canChannelInvite($db,$ch,(int)$me['id'])) channelInviteJson(['error'=>'Insufficient permissions'],403);
        $s=$db->prepare('SELECT id,channel_id,max_uses,use_count,expires_at,revoked_at,created_at FROM channel_invites WHERE channel_id=? ORDER BY created_at DESC LIMIT 50');
        $s->execute([$channelId]);
        channelInviteJson(['invites'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'revoke') {
        $inviteId=(int)($body['invite_id'] ?? 0);
        $s=$db->prepare('SELECT channel_id FROM channel_invites WHERE id=? LIMIT 1');
        $s->execute([$inviteId]);
        $channelId=(int)($s->fetchColumn() ?: 0);
        $ch=channelInfo($db,$channelId);
        if (!$ch || !canChannelInvite($db,$ch,(int)$me['id'])) channelInviteJson(['error'=>'Insufficient permissions'],403);
        $db->prepare('UPDATE channel_invites SET revoked_at=NOW() WHERE id=?')->execute([$inviteId]);
        channelInviteJson(['message'=>'Channel invite revoked']);
    }

    if ($action === 'join') {
        $raw=trim((string)($body['invite_code'] ?? $body['token'] ?? ''));
        if ($raw==='') channelInviteJson(['error'=>'Invite code required'],400);
        if (filter_var($raw,FILTER_VALIDATE_URL)) {
            $parts=parse_url($raw); parse_str((string)($parts['query'] ?? ''),$query); $raw=(string)($query['channel_invite'] ?? '');
        }
        $hash=hash('sha256',$raw);
        $db->beginTransaction();
        try {
            $s=$db->prepare("SELECT ci.*,c.name AS channel_name,c.server_id,c.is_private,s.name AS server_name FROM channel_invites ci JOIN channels c ON c.id=ci.channel_id JOIN servers s ON s.id=c.server_id WHERE ci.token_hash=? AND ci.revoked_at IS NULL AND (ci.expires_at IS NULL OR ci.expires_at>NOW()) AND (ci.max_uses=0 OR ci.use_count<ci.max_uses) LIMIT 1 FOR UPDATE");
            $s->execute([$hash]);
            $invite=$s->fetch(PDO::FETCH_ASSOC);
            if (!$invite) { $db->rollBack(); channelInviteJson(['error'=>'Invalid or expired channel invite'],404); }

            $m=$db->prepare('SELECT id FROM server_members WHERE server_id=? AND user_id=? LIMIT 1');
            $m->execute([(int)$invite['server_id'],(int)$me['id']]);
            $alreadyServer=(bool)$m->fetchColumn();
            if (!$alreadyServer) {
                $db->prepare("INSERT INTO server_members (server_id,user_id,server_role,joined_at) VALUES (?,?,'member',NOW())")->execute([(int)$invite['server_id'],(int)$me['id']]);
                $db->prepare('UPDATE servers SET member_count=(SELECT COUNT(*) FROM server_members WHERE server_id=?) WHERE id=?')->execute([(int)$invite['server_id'],(int)$invite['server_id']]);
            }
            $cm=$db->prepare('SELECT 1 FROM channel_members WHERE channel_id=? AND user_id=? LIMIT 1');
            $cm->execute([(int)$invite['channel_id'],(int)$me['id']]);
            $alreadyChannel=(bool)$cm->fetchColumn();
            if ((int)$invite['is_private']===1 && !$alreadyChannel) {
                $db->prepare('INSERT IGNORE INTO channel_members (channel_id,user_id) VALUES (?,?)')->execute([(int)$invite['channel_id'],(int)$me['id']]);
            }
            $db->prepare('UPDATE channel_invites SET use_count=use_count+1 WHERE id=?')->execute([(int)$invite['id']]);
            $db->commit();
            channelInviteJson(['server_id'=>(int)$invite['server_id'],'channel_id'=>(int)$invite['channel_id'],'channel_name'=>$invite['channel_name'],'server_name'=>$invite['server_name'],'already_member'=>$alreadyChannel]);
        } catch(Throwable $e) {
            if($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    channelInviteJson(['error'=>'Unknown action'],404);
} catch(Throwable $e) {
    error_log('[chat/channel-invite] '.$e->getMessage());
    channelInviteJson(['error'=>defined('APP_DEBUG')&&APP_DEBUG?$e->getMessage():'Channel invite service unavailable'],500);
}
