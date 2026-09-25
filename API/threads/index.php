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

function platformRole(PDO $db, int $userId): string {
    $s = $db->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
    $s->execute([$userId]);
    return (string)($s->fetchColumn() ?: 'student');
}

function isSysAdmin(PDO $db, int $userId): bool {
    return in_array(platformRole($db, $userId), ['admin', 'super_admin'], true);
}

function serverRole(PDO $db, int $serverId, int $userId): ?string {
    if (isSysAdmin($db, $userId)) return 'sysadmin';
    $s = $db->prepare('SELECT server_role FROM server_members WHERE server_id = ? AND user_id = ? LIMIT 1');
    $s->execute([$serverId, $userId]);
    $role = $s->fetchColumn();
    return $role !== false ? (string)$role : null;
}

function isServerMember(PDO $db, int $serverId, int $userId): bool {
    if (isSysAdmin($db, $userId)) {
        $s = $db->prepare("SELECT 1 FROM servers WHERE id = ? AND status = 'active' LIMIT 1");
        $s->execute([$serverId]);
        return (bool)$s->fetchColumn();
    }
    return serverRole($db, $serverId, $userId) !== null;
}

function channelRow(PDO $db, int $channelId): ?array {
    $s = $db->prepare('SELECT id, server_id, name, is_private, created_by FROM channels WHERE id = ? LIMIT 1');
    $s->execute([$channelId]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

function canAccessChannel(PDO $db, array $channel, int $userId): bool {
    if (isSysAdmin($db, $userId)) return true;
    $sid = (int)$channel['server_id'];
    $role = serverRole($db, $sid, $userId);
    if ($role === null) return false;
    if ((int)$channel['is_private'] !== 1) return true;
    if ((int)$channel['created_by'] === $userId || in_array($role, ['owner', 'admin'], true)) return true;
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


function attachmentRows(PDO $db, int $threadId): array {
    $s=$db->prepare('SELECT id, thread_id, reply_id, file_url, file_name, mime_type, file_size, created_by, created_at FROM thread_attachments WHERE thread_id=? ORDER BY id ASC');
    $s->execute([$threadId]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

function validThreadAttachments(array $items): array {
    $out=[];
    foreach ($items as $item) {
        $path=trim((string)($item['path'] ?? ''));
        $url=trim((string)($item['url'] ?? ''));
        if ($path !== '' && preg_match('#^/uploads/threads/[A-Za-z0-9._/-]+$#',$path)) {
            $url=rtrim(BASE_URL,'/').$path;
        } elseif ($url !== '' && preg_match('#^'.preg_quote(rtrim(BASE_URL,'/'),'#').'/uploads/threads/[A-Za-z0-9._/-]+$#',$url)) {
            $path=parse_url($url,PHP_URL_PATH) ?: '';
        } else {
            continue;
        }
        $mime=(string)($item['mime_type'] ?? '');
        if (!in_array($mime,['image/jpeg','image/png','image/gif','image/webp'],true)) continue;
        $out[]=['url'=>$url,'file_name'=>mb_substr(trim((string)($item['file_name'] ?? 'image')),0,255),'mime_type'=>$mime,'file_size'=>max(0,(int)($item['file_size'] ?? 0))];
    }
    return $out;
}

function saveThreadAttachments(PDO $db, int $threadId, ?int $replyId, array $items, int $uid): void {
    foreach (validThreadAttachments($items) as $a) {
        $s=$db->prepare('INSERT INTO thread_attachments(thread_id,reply_id,file_url,file_name,mime_type,file_size,created_by) VALUES(?,?,?,?,?,?,?)');
        $s->execute([$threadId,$replyId,$a['url'],$a['file_name'],$a['mime_type'],$a['file_size'],$uid]);
    }
}
function threadBaseSelect(): string {
    return "
        SELECT
            t.id, t.title, t.body, t.scope, t.server_id, t.channel_id,
            t.created_by, t.is_locked, t.is_pinned, t.is_bookmarked, t.created_at, t.updated_at,
            u.username AS author_username, u.full_name AS author_name,
            u.avatar_url AS author_avatar_url,
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
            if ($thread) $thread['is_owner'] = ((int)$thread['created_by'] === (int)$me['id']) ? 1 : 0;
            if (!$thread || !canSeeThread($db, $thread, (int)$me['id'])) threadJson(['error' => 'Thread not found'], 404);

            $r = $db->prepare("SELECT r.id, r.thread_id, r.parent_reply_id, r.created_by, r.body, r.created_at, u.username AS author_username, u.full_name AS author_name, u.avatar_url AS author_avatar_url, COALESCE(u.avatar_color_gradient,'#a855f7,#ec4899') AS author_gradient, COALESCE((SELECT SUM(v.vote) FROM thread_reply_votes v WHERE v.reply_id=r.id),0) AS score, COALESCE((SELECT vote FROM thread_reply_votes mv WHERE mv.reply_id=r.id AND mv.user_id=? LIMIT 1),0) AS my_vote FROM thread_replies r JOIN users u ON u.id=r.created_by WHERE r.thread_id=? AND r.is_deleted=0 ORDER BY r.created_at ASC");
            $r->execute([(int)$me['id'], $id]);

            threadJson(['thread' => $thread, 'replies' => $r->fetchAll(PDO::FETCH_ASSOC), 'attachments' => attachmentRows($db, $id)]);
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
        $threads=$s->fetchAll(PDO::FETCH_ASSOC);
        foreach($threads as &$thread){
            $thread['is_owner'] = ((int)$thread['created_by'] === (int)$me['id']) ? 1 : 0;
            $thread['attachments']=attachmentRows($db,(int)$thread['id']);
        }unset($thread);
        threadJson(['threads'=>$threads, 'scope'=>$scope, 'server_id'=>$serverId, 'channel_id'=>$channelId, 'current_user_id'=>(int)$me['id']]);
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
        if ($content === '' && empty($body['attachments'])) threadJson(['error' => 'Add text, an image, or both'], 400);
        [$allowed, $reason] = canPostToScope($db, $scope, $serverId, $channelId, $uid);
        if (!$allowed) threadJson(['error' => $reason], 403);
        if ($scope === 'public') { $serverId = null; $channelId = null; }
        if ($scope === 'server') $channelId = null;

        $s = $db->prepare('INSERT INTO threads(title,body,scope,server_id,channel_id,created_by) VALUES(?,?,?,?,?,?)');
        $s->execute([$title, $content, $scope, $serverId ?: null, $channelId ?: null, $uid]);
        $threadId=(int)$db->lastInsertId();
        saveThreadAttachments($db,$threadId,null,is_array($body['attachments'] ?? null)?$body['attachments']:[],$uid);
        threadJson(['thread_id'=>$threadId,'message'=>'Thread created'], 201);
    }

    if ($action === 'reply') {
        $threadId = (int)($body['thread_id'] ?? 0);
        $content = trim((string)($body['body'] ?? ''));
        $parentId = (int)($body['parent_reply_id'] ?? 0);
        if (!$threadId || ($content === '' && empty($body['attachments']))) threadJson(['error' => 'Add text, an image, or both'], 400);
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
        $replyId=(int)$db->lastInsertId();
        saveThreadAttachments($db,$threadId,$replyId,is_array($body['attachments'] ?? null)?$body['attachments']:[],$uid);
        $db->prepare('UPDATE threads SET updated_at=NOW() WHERE id=?')->execute([$threadId]);
        threadJson(['reply_id'=>$replyId,'message'=>'Reply posted'], 201);
    }

    if ($action === 'bookmark') {
        $id=(int)($body['id'] ?? 0); if(!$id) threadJson(['error'=>'Thread id is required'],400);
        $s=$db->prepare('SELECT * FROM threads WHERE id=? AND is_deleted=0 LIMIT 1');$s->execute([$id]);$thread=$s->fetch(PDO::FETCH_ASSOC);
        if(!$thread || !canSeeThread($db,$thread,$uid)) threadJson(['error'=>'Thread not found'],404);
        $next=((int)($thread['is_bookmarked'] ?? 0)===1)?0:1;
        $db->prepare('UPDATE threads SET is_bookmarked=? WHERE id=?')->execute([$next,$id]);
        threadJson(['bookmarked'=>$next]);
    }

    if ($action === 'edit') {
        $id=(int)($body['id'] ?? 0);$title=trim((string)($body['title'] ?? ''));$content=trim((string)($body['body'] ?? ''));
        $s=$db->prepare('SELECT * FROM threads WHERE id=? AND is_deleted=0 LIMIT 1');$s->execute([$id]);$thread=$s->fetch(PDO::FETCH_ASSOC);
        if(!$thread) threadJson(['error'=>'Thread not found'],404);
        if((int)$thread['created_by']!==$uid) threadJson(['error'=>'Only the post owner can edit this post'],403);
        if($title==='' || mb_strlen($title)>180 || $content==='') threadJson(['error'=>'Title and body are required'],400);
        $db->prepare('UPDATE threads SET title=?,body=?,updated_at=NOW() WHERE id=?')->execute([$title,$content,$id]);
        threadJson(['message'=>'Post updated']);
    }

    if ($action === 'delete') {
        $id=(int)($body['id'] ?? 0);$s=$db->prepare('SELECT created_by FROM threads WHERE id=? AND is_deleted=0 LIMIT 1');$s->execute([$id]);$owner=(int)$s->fetchColumn();
        if(!$owner) threadJson(['error'=>'Thread not found'],404);
        if($owner!==$uid) threadJson(['error'=>'Only the post owner can delete this post'],403);
        $db->prepare('UPDATE threads SET is_deleted=1,updated_at=NOW() WHERE id=?')->execute([$id]);
        threadJson(['message'=>'Post deleted']);
    }

    if ($action === 'report') {
        $id=(int)($body['id'] ?? 0);$reason=trim((string)($body['reason'] ?? 'other'));
        $s=$db->prepare('SELECT created_by FROM threads WHERE id=? AND is_deleted=0 LIMIT 1');$s->execute([$id]);$target=(int)$s->fetchColumn();
        if(!$target) threadJson(['error'=>'Thread not found'],404);
        if($target===$uid) threadJson(['error'=>'You cannot report your own post'],422);
        try {
            $db->prepare("INSERT INTO thread_reports(thread_id,reporter_id,reported_user_id,reason,status) VALUES(?,?,?,?, 'pending')")->execute([$id,$uid,$target,mb_substr($reason,0,255)]);
        } catch (PDOException $e) {
            // A retry must preserve the original report, including its moderation status.
            $errorInfo = $e->errorInfo ?? [];
            if (($errorInfo[0] ?? '') === '23000'
                && (int)($errorInfo[1] ?? 0) === 1062
                && preg_match("/for key '(?:thread_reports\.)?uq_thread_reporter'/", (string)($errorInfo[2] ?? '')) === 1) {
                threadJson(['message'=>'Post reported', 'already_reported'=>true]);
            }
            throw $e;
        }
        threadJson(['message'=>'Post reported']);
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
    threadJson(['error' => 'Thread service unavailable'], 500);
}
