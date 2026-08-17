<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json; charset=utf-8');
AuthMiddleware::startSession();
$me = AuthMiddleware::requireAuth(true);
$db = Database::getInstance();

function threadJson(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode(['success' => $status < 400, ...$data], JSON_UNESCAPED_UNICODE);
    exit;
}

function serverRole(PDO $db, int $serverId, int $userId): ?string {
    $s = $db->prepare('SELECT server_role FROM server_members WHERE server_id = ? AND user_id = ? LIMIT 1');
    $s->execute([$serverId, $userId]);
    $role = $s->fetchColumn();
    return $role !== false ? (string)$role : null;
}

function isServerMember(PDO $db, int $serverId, int $userId): bool {
    return serverRole($db, $serverId, $userId) !== null;
}

function channelRow(PDO $db, int $channelId): ?array {
    $s = $db->prepare('SELECT id, server_id, name, is_private, created_by FROM channels WHERE id = ? LIMIT 1');
    $s->execute([$channelId]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

function canAccessChannel(PDO $db, array $channel, int $userId): bool {
    $sid = (int)$channel['server_id'];
    $role = serverRole($db, $sid, $userId);
    if ($role === null) return false;
    if ((int)$channel['is_private'] !== 1) return true;
    if ((int)$channel['created_by'] === $userId || in_array($role, ['owner', 'admin', 'moderator'], true)) return true;
    $s = $db->prepare('SELECT 1 FROM channel_members WHERE channel_id = ? AND user_id = ? LIMIT 1');
    $s->execute([(int)$channel['id'], $userId]);
    return (bool)$s->fetchColumn();
}

function canSeeThread(PDO $db, array $thread, int $userId): bool {
    $scope = $thread['scope'];
    if ($scope === 'public') return true;
    if ($scope === 'server') return isServerMember($db, (int)$thread['server_id'], $userId);
    $channel = channelRow($db, (int)$thread['channel_id']);
    return $channel ? canAccessChannel($db, $channel, $userId) : false;
}

function canPostToScope(PDO $db, string $scope, int $serverId, int $channelId, int $userId): array {
    if (!in_array($scope, ['public', 'server', 'channel'], true)) return [false, 'Invalid thread scope'];
    if ($scope === 'public') return [true, null];
    if ($scope === 'server') {
        if (!$serverId || !isServerMember($db, $serverId, $userId)) return [false, 'You must be a member of the server'];
        return [true, null];
    }
    if (!$channelId) return [false, 'channel_id is required for a channel thread'];
    $channel = channelRow($db, $channelId);
    if (!$channel) return [false, 'Channel not found'];
    if (!canAccessChannel($db, $channel, $userId)) return [false, 'You do not have access to this channel'];
    return [true, null];
}

function threadBaseSelect(): string {
    return "
        SELECT
            t.id, t.title, t.body, t.scope, t.server_id, t.channel_id,
            t.created_by, t.is_locked, t.is_pinned, t.created_at, t.updated_at,
            u.username AS author_username, u.full_name AS author_name,
            COALESCE(u.avatar_color_gradient, '#a855f7,#ec4899') AS author_gradient,
            COALESCE((SELECT SUM(v.vote) FROM thread_votes v WHERE v.thread_id = t.id), 0) AS score,
            COALESCE((SELECT COUNT(*) FROM thread_replies r WHERE r.thread_id = t.id AND r.is_deleted = 0), 0) AS reply_count,
            COALESCE((SELECT vote FROM thread_votes mv WHERE mv.thread_id = t.id AND mv.user_id = :vote_user LIMIT 1), 0) AS my_vote,
            s.name AS server_name,
            c.name AS channel_name
        FROM threads t
        JOIN users u ON u.id = t.created_by
        LEFT JOIN servers s ON s.id = t.server_id
        LEFT JOIN channels c ON c.id = t.channel_id
    ";
}

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($method === 'GET') {
        $action = (string)($_GET['action'] ?? 'list');

        if ($action === 'get') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) threadJson(['error' => 'Thread id is required'], 400);

            $sql = threadBaseSelect() . ' WHERE t.id = :id AND t.is_deleted = 0 LIMIT 1';
            $s = $db->prepare($sql);
            $s->execute([':id' => $id, ':vote_user' => $me['id']]);
            $thread = $s->fetch(PDO::FETCH_ASSOC);
            if (!$thread || !canSeeThread($db, $thread, (int)$me['id'])) threadJson(['error' => 'Thread not found'], 404);

            $r = $db->prepare("SELECT r.id, r.thread_id, r.parent_reply_id, r.created_by, r.body, r.created_at, u.username AS author_username, u.full_name AS author_name, COALESCE(u.avatar_color_gradient,'#a855f7,#ec4899') AS author_gradient, COALESCE((SELECT SUM(v.vote) FROM thread_reply_votes v WHERE v.reply_id=r.id),0) AS score, COALESCE((SELECT vote FROM thread_reply_votes mv WHERE mv.reply_id=r.id AND mv.user_id=:uid LIMIT 1),0) AS my_vote FROM thread_replies r JOIN users u ON u.id=r.created_by WHERE r.thread_id=:tid AND r.is_deleted=0 ORDER BY r.created_at ASC");
            $r->execute([':uid' => $me['id'], ':tid' => $id]);

            threadJson(['thread' => $thread, 'replies' => $r->fetchAll(PDO::FETCH_ASSOC)]);
        }

        $scope = (string)($_GET['scope'] ?? 'all');
        $serverId = (int)($_GET['server_id'] ?? 0);
        $channelId = (int)($_GET['channel_id'] ?? 0);
        $limit = max(1, min(50, (int)($_GET['limit'] ?? 30)));

        $conditions = ['t.is_deleted = 0'];
        $params = [':vote_user' => $me['id']];

        if ($scope === 'public') {
            $conditions[] = "t.scope = 'public'";
        } elseif ($scope === 'server') {
            if (!$serverId || !isServerMember($db, $serverId, (int)$me['id'])) threadJson(['error' => 'Server membership required'], 403);
            $conditions[] = "t.scope = 'server' AND t.server_id = :server_id";
            $params[':server_id'] = $serverId;
        } elseif ($scope === 'channel') {
            if (!$channelId) threadJson(['error' => 'channel_id is required'], 400);
            $channel = channelRow($db, $channelId);
            if (!$channel || !canAccessChannel($db, $channel, (int)$me['id'])) threadJson(['error' => 'Channel access required'], 403);
            $conditions[] = "t.scope = 'channel' AND t.channel_id = :channel_id";
            $params[':channel_id'] = $channelId;
        } else {
            // "all" shows public threads plus the current server and current channel.
            $parts = ["t.scope = 'public'"];
            if ($serverId && isServerMember($db, $serverId, (int)$me['id'])) {
                $parts[] = "(t.scope = 'server' AND t.server_id = :all_server_id)";
                $params[':all_server_id'] = $serverId;
            }
            if ($channelId) {
                $channel = channelRow($db, $channelId);
                if ($channel && canAccessChannel($db, $channel, (int)$me['id'])) {
                    $parts[] = "(t.scope = 'channel' AND t.channel_id = :all_channel_id)";
                    $params[':all_channel_id'] = $channelId;
                }
            }
            $conditions[] = '(' . implode(' OR ', $parts) . ')';
        }

        $sql = threadBaseSelect() . ' WHERE ' . implode(' AND ', $conditions) . ' ORDER BY t.is_pinned DESC, t.created_at DESC LIMIT ' . $limit;
        $s = $db->prepare($sql);
        $s->execute($params);
        threadJson(['threads' => $s->fetchAll(PDO::FETCH_ASSOC), 'scope' => $scope, 'server_id' => $serverId, 'channel_id' => $channelId]);
    }

    if ($method !== 'POST') threadJson(['error' => 'Method not allowed'], 405);
    AuthMiddleware::verifyCsrf();
    $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $action = (string)($body['action'] ?? '');
    $uid = (int)$me['id'];

    if ($action === 'create') {
        $title = trim((string)($body['title'] ?? ''));
        $content = trim((string)($body['body'] ?? ''));
        $scope = (string)($body['scope'] ?? 'public');
        $serverId = (int)($body['server_id'] ?? 0);
        $channelId = (int)($body['channel_id'] ?? 0);
        if ($title === '' || mb_strlen($title) > 180) threadJson(['error' => 'Title is required and must be 180 characters or less'], 400);
        if ($content === '') threadJson(['error' => 'Thread body is required'], 400);
        [$allowed, $reason] = canPostToScope($db, $scope, $serverId, $channelId, $uid);
        if (!$allowed) threadJson(['error' => $reason], 403);
        if ($scope === 'public') { $serverId = null; $channelId = null; }
        if ($scope === 'server') $channelId = null;

        $s = $db->prepare('INSERT INTO threads(title,body,scope,server_id,channel_id,created_by) VALUES(?,?,?,?,?,?)');
        $s->execute([$title, $content, $scope, $serverId ?: null, $channelId ?: null, $uid]);
        threadJson(['thread_id' => (int)$db->lastInsertId(), 'message' => 'Thread created'], 201);
    }

    if ($action === 'reply') {
        $threadId = (int)($body['thread_id'] ?? 0);
        $content = trim((string)($body['body'] ?? ''));
        $parentId = (int)($body['parent_reply_id'] ?? 0);
        if (!$threadId || $content === '') threadJson(['error' => 'thread_id and body are required'], 400);
        $s = $db->prepare('SELECT * FROM threads WHERE id=? AND is_deleted=0 LIMIT 1');
        $s->execute([$threadId]);
        $thread = $s->fetch(PDO::FETCH_ASSOC);
        if (!$thread || !canSeeThread($db, $thread, $uid)) threadJson(['error' => 'Thread not found'], 404);
        if ((int)$thread['is_locked'] === 1) threadJson(['error' => 'This thread is locked'], 409);
        if ($parentId) {
            $p = $db->prepare('SELECT 1 FROM thread_replies WHERE id=? AND thread_id=? AND is_deleted=0 LIMIT 1');
            $p->execute([$parentId, $threadId]);
            if (!$p->fetchColumn()) threadJson(['error' => 'Parent reply not found'], 404);
        }
        $s = $db->prepare('INSERT INTO thread_replies(thread_id,parent_reply_id,created_by,body) VALUES(?,?,?,?)');
        $s->execute([$threadId, $parentId ?: null, $uid, $content]);
        $db->prepare('UPDATE threads SET updated_at=NOW() WHERE id=?')->execute([$threadId]);
        threadJson(['reply_id' => (int)$db->lastInsertId(), 'message' => 'Reply posted'], 201);
    }

    if ($action === 'vote') {
        $target = (string)($body['target'] ?? 'thread');
        $id = (int)($body['id'] ?? 0);
        $vote = (int)($body['vote'] ?? 0);
        if (!$id || !in_array($vote, [-1, 0, 1], true) || !in_array($target, ['thread','reply'], true)) threadJson(['error' => 'Invalid vote'], 400);

        if ($target === 'thread') {
            $s = $db->prepare('SELECT * FROM threads WHERE id=? AND is_deleted=0 LIMIT 1'); $s->execute([$id]); $thread = $s->fetch(PDO::FETCH_ASSOC);
            if (!$thread || !canSeeThread($db, $thread, $uid)) threadJson(['error' => 'Thread not found'], 404);
            if ($vote === 0) $db->prepare('DELETE FROM thread_votes WHERE thread_id=? AND user_id=?')->execute([$id,$uid]);
            else $db->prepare('INSERT INTO thread_votes(thread_id,user_id,vote) VALUES(?,?,?) ON DUPLICATE KEY UPDATE vote=VALUES(vote)')->execute([$id,$uid,$vote]);
            $q = $db->prepare('SELECT COALESCE(SUM(vote),0) score, COALESCE((SELECT vote FROM thread_votes WHERE thread_id=? AND user_id=?),0) my_vote FROM thread_votes WHERE thread_id=?'); $q->execute([$id,$uid,$id]);
        } else {
            $s = $db->prepare('SELECT t.* FROM thread_replies r JOIN threads t ON t.id=r.thread_id WHERE r.id=? AND r.is_deleted=0 AND t.is_deleted=0 LIMIT 1'); $s->execute([$id]); $thread = $s->fetch(PDO::FETCH_ASSOC);
            if (!$thread || !canSeeThread($db, $thread, $uid)) threadJson(['error' => 'Reply not found'], 404);
            if ($vote === 0) $db->prepare('DELETE FROM thread_reply_votes WHERE reply_id=? AND user_id=?')->execute([$id,$uid]);
            else $db->prepare('INSERT INTO thread_reply_votes(reply_id,user_id,vote) VALUES(?,?,?) ON DUPLICATE KEY UPDATE vote=VALUES(vote)')->execute([$id,$uid,$vote]);
            $q = $db->prepare('SELECT COALESCE(SUM(vote),0) score, COALESCE((SELECT vote FROM thread_reply_votes WHERE reply_id=? AND user_id=?),0) my_vote FROM thread_reply_votes WHERE reply_id=?'); $q->execute([$id,$uid,$id]);
        }
        threadJson($q->fetch(PDO::FETCH_ASSOC) ?: ['score'=>0,'my_vote'=>0]);
    }

    threadJson(['error' => 'Unknown action'], 400);
} catch (Throwable $e) {
    error_log('[threads] ' . $e->getMessage());
    threadJson(['error' => defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'Thread service unavailable'], 500);
}
