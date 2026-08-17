<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json; charset=utf-8');
AuthMiddleware::startSession();
$me = AuthMiddleware::requireAuth(true);
$db = Database::getInstance();

function memberJson(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode(['success' => $status < 400, ...$data], JSON_UNESCAPED_UNICODE);
    exit;
}
function memberRole(PDO $db, int $serverId, int $userId): ?string {
    $s = $db->prepare('SELECT server_role FROM server_members WHERE server_id = ? AND user_id = ? LIMIT 1');
    $s->execute([$serverId, $userId]);
    return $s->fetchColumn() ?: null;
}
function canManageMembers(?string $role): bool {
    return in_array($role, ['owner', 'admin', 'moderator'], true);
}
function canActOnRole(string $actor, string $target): bool {
    $rank = ['member' => 1, 'moderator' => 2, 'admin' => 3, 'owner' => 4];
    return ($rank[$actor] ?? 0) >= ($rank[$target] ?? 99);
}

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $action = (string)($_GET['action'] ?? '');
    $body = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: $_POST) : $_GET;
    if ($method === 'POST') AuthMiddleware::verifyCsrf();

    $serverId = (int)($body['server_id'] ?? $_GET['server_id'] ?? 0);
    $actorRole = memberRole($db, $serverId, (int)$me['id']);
    if (!$serverId || !$actorRole) memberJson(['error' => 'Server membership required'], 403);

    if ($action === 'info') {
        $stmt = $db->prepare("SELECT s.id,s.name,s.slug,s.icon_emoji,s.icon_url,s.category,s.type,s.member_count,sm.server_role FROM servers s JOIN server_members sm ON sm.server_id=s.id AND sm.user_id=? WHERE s.id=? LIMIT 1");
        $stmt->execute([$me['id'], $serverId]);
        $server = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$server) memberJson(['error' => 'Server not found'], 404);
        memberJson(['server' => $server, 'can_manage' => canManageMembers($actorRole)]);
    }

    if ($action === 'list') {
        $stmt = $db->prepare("SELECT u.id,u.username,u.full_name,u.role,u.avatar_color_gradient,u.is_online,sm.server_role,sm.nickname,sm.joined_at FROM server_members sm JOIN users u ON u.id=sm.user_id WHERE sm.server_id=? AND u.deleted_at IS NULL ORDER BY FIELD(sm.server_role,'owner','admin','moderator','member'),u.full_name,u.username");
        $stmt->execute([$serverId]);
        memberJson(['members' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'candidates') {
        if (!canManageMembers($actorRole)) memberJson(['error' => 'Insufficient permissions'], 403);
        $q = trim((string)($_GET['q'] ?? ''));
        $params = [$serverId];
        $where = "u.deleted_at IS NULL AND u.status NOT IN ('banned','suspended','deactivated') AND NOT EXISTS (SELECT 1 FROM server_members x WHERE x.server_id=? AND x.user_id=u.id)";
        if ($q !== '') { $where .= ' AND (u.username LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)'; $like = '%' . $q . '%'; $params[]=$like; $params[]=$like; $params[]=$like; }
        $stmt = $db->prepare("SELECT u.id,u.username,u.full_name,u.role,u.avatar_color_gradient,u.is_online FROM users u WHERE $where ORDER BY u.is_online DESC,u.full_name,u.username LIMIT 40");
        $stmt->execute($params);
        memberJson(['users' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'add') {
        if (!canManageMembers($actorRole)) memberJson(['error' => 'Insufficient permissions'], 403);
        $targetId = (int)($body['user_id'] ?? 0);
        if (!$targetId || $targetId === (int)$me['id']) memberJson(['error' => 'Valid user_id required'], 400);
        $target = $db->prepare("SELECT id FROM users WHERE id=? AND deleted_at IS NULL AND status NOT IN ('banned','suspended','deactivated') LIMIT 1");
        $target->execute([$targetId]);
        if (!$target->fetchColumn()) memberJson(['error' => 'User not found'], 404);
        $existing = $db->prepare('SELECT server_role FROM server_members WHERE server_id=? AND user_id=? LIMIT 1');
        $existing->execute([$serverId,$targetId]);
        if ($existing->fetchColumn()) memberJson(['message' => 'User is already a member']);
        $db->prepare("INSERT INTO server_members (server_id,user_id,server_role,joined_at) VALUES (?,?,'member',NOW())")->execute([$serverId,$targetId]);
        $db->prepare('UPDATE servers SET member_count=(SELECT COUNT(*) FROM server_members WHERE server_id=?) WHERE id=?')->execute([$serverId,$serverId]);
        memberJson(['message' => 'Member added']);
    }

    if ($action === 'remove') {
        if (!canManageMembers($actorRole)) memberJson(['error' => 'Insufficient permissions'], 403);
        $targetId = (int)($body['user_id'] ?? 0);
        if (!$targetId || $targetId === (int)$me['id']) memberJson(['error' => 'Cannot remove yourself here'], 400);
        $stmt = $db->prepare('SELECT server_role FROM server_members WHERE server_id=? AND user_id=? LIMIT 1');
        $stmt->execute([$serverId,$targetId]);
        $targetRole = $stmt->fetchColumn();
        if (!$targetRole) memberJson(['message' => 'User is not a member']);
        if (!canActOnRole($actorRole, (string)$targetRole)) memberJson(['error' => 'You cannot remove a member with that role'], 403);
        $db->prepare('DELETE FROM server_members WHERE server_id=? AND user_id=?')->execute([$serverId,$targetId]);
        $db->prepare('DELETE cm FROM channel_members cm JOIN channels c ON c.id=cm.channel_id WHERE c.server_id=? AND cm.user_id=?')->execute([$serverId,$targetId]);
        $db->prepare('UPDATE servers SET member_count=(SELECT COUNT(*) FROM server_members WHERE server_id=?) WHERE id=?')->execute([$serverId,$serverId]);
        memberJson(['message' => 'Member removed']);
    }

    memberJson(['error' => 'Unknown action'], 404);
} catch (Throwable $e) {
    error_log('[server/members] ' . $e->getMessage());
    memberJson(['error' => defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'Member management unavailable'], 500);
}
