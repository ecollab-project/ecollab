<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json; charset=utf-8');
AuthMiddleware::startSession();
$me = AuthMiddleware::requireAuth(true);
$db = Database::getInstance();

function inviteJson(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode(['success' => $status < 400, ...$data], JSON_UNESCAPED_UNICODE);
    exit;
}
function serverRole(PDO $db, int $serverId, int $userId): ?string {
    $s = $db->prepare('SELECT server_role FROM server_members WHERE server_id = ? AND user_id = ? LIMIT 1');
    $s->execute([$serverId, $userId]);
    return $s->fetchColumn() ?: null;
}
function canManageServerInvite(?string $role): bool {
    return in_array($role, ['owner', 'admin', 'moderator'], true);
}
function inviteUrl(string $token): string {
    return rtrim((string)BASE_URL, '/') . '/modules/chat/chat.php?invite=' . rawurlencode($token);
}

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $action = (string)($_GET['action'] ?? '');
    $body = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: $_POST) : $_GET;
    if ($method === 'POST') AuthMiddleware::verifyCsrf();

    if ($action === 'create') {
        $serverId = (int)($body['server_id'] ?? 0);
        // Any server member may create an invite; only moderators/admins/owners
        // may list or revoke existing invites.
        if (!$serverId || !serverRole($db, $serverId, (int)$me['id'])) inviteJson(['error' => 'Server membership required'], 403);
        $maxUses = max(0, min(100000, (int)($body['max_uses'] ?? 0)));
        $expiresHours = max(0, min(8760, (int)($body['expires_hours'] ?? 0)));
        $expiresAt = $expiresHours > 0 ? date('Y-m-d H:i:s', time() + $expiresHours * 3600) : null;
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $hash = hash('sha256', $token);
        $stmt = $db->prepare('INSERT INTO server_invites (server_id, created_by, token_hash, max_uses, expires_at) VALUES (?,?,?,?,?)');
        $stmt->execute([$serverId, $me['id'], $hash, $maxUses, $expiresAt]);
        inviteJson(['invite' => ['id' => (int)$db->lastInsertId(), 'server_id' => $serverId, 'max_uses' => $maxUses, 'expires_at' => $expiresAt, 'invite_url' => inviteUrl($token)]]);
    }

    if ($action === 'list') {
        $serverId = (int)($_GET['server_id'] ?? 0);
        if (!$serverId || !canManageServerInvite(serverRole($db, $serverId, (int)$me['id']))) inviteJson(['error' => 'Insufficient permissions'], 403);
        $stmt = $db->prepare('SELECT id,server_id,max_uses,use_count,expires_at,revoked_at,created_at FROM server_invites WHERE server_id=? ORDER BY created_at DESC LIMIT 50');
        $stmt->execute([$serverId]);
        inviteJson(['invites' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'revoke') {
        $inviteId = (int)($body['invite_id'] ?? 0);
        $stmt = $db->prepare('SELECT server_id FROM server_invites WHERE id=? LIMIT 1');
        $stmt->execute([$inviteId]);
        $serverId = (int)($stmt->fetchColumn() ?: 0);
        if (!$serverId || !canManageServerInvite(serverRole($db, $serverId, (int)$me['id']))) inviteJson(['error' => 'Insufficient permissions'], 403);
        $db->prepare('UPDATE server_invites SET revoked_at=NOW() WHERE id=?')->execute([$inviteId]);
        inviteJson(['message' => 'Invite revoked']);
    }

    if ($action === 'join') {
        $raw = trim((string)($body['invite_code'] ?? $body['token'] ?? ''));
        if ($raw === '') inviteJson(['error' => 'Invite code required'], 400);
        if (filter_var($raw, FILTER_VALIDATE_URL)) { $parts=parse_url($raw); parse_str((string)($parts['query'] ?? ''),$query); $raw=(string)($query['invite'] ?? ''); }
        $hash=hash('sha256',$raw);
        $db->beginTransaction();
        try {
            $stmt=$db->prepare("SELECT si.*,s.name,s.slug FROM server_invites si JOIN servers s ON s.id=si.server_id WHERE si.token_hash=? AND si.revoked_at IS NULL AND (si.expires_at IS NULL OR si.expires_at>NOW()) AND (si.max_uses=0 OR si.use_count<si.max_uses) LIMIT 1 FOR UPDATE");
            $stmt->execute([$hash]); $invite=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!$invite){$db->rollBack();inviteJson(['error'=>'Invalid or expired invite link'],404);}
            $member=$db->prepare('SELECT id FROM server_members WHERE server_id=? AND user_id=? LIMIT 1');
            $member->execute([(int)$invite['server_id'],(int)$me['id']]); $already=(bool)$member->fetchColumn();
            if(!$already){
                $db->prepare("INSERT INTO server_members(server_id,user_id,server_role,joined_at) VALUES(?,?,'member',NOW())")->execute([(int)$invite['server_id'],(int)$me['id']]);
                $db->prepare('UPDATE servers SET member_count=(SELECT COUNT(*) FROM server_members WHERE server_id=?) WHERE id=?')->execute([(int)$invite['server_id'],(int)$invite['server_id']]);
            }
            $db->prepare('UPDATE server_invites SET use_count=use_count+1 WHERE id=?')->execute([(int)$invite['id']]);
            $db->commit(); inviteJson(['server_id'=>(int)$invite['server_id'],'name'=>$invite['name'],'already_member'=>$already]);
        } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    inviteJson(['error'=>'Unknown action'],404);
} catch(Throwable $e){error_log('[server/invite] '.$e->getMessage());inviteJson(['error'=>defined('APP_DEBUG')&&APP_DEBUG?$e->getMessage():'Server invite service unavailable'],500);}
