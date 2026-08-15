<?php
declare(strict_types=1);
/**
 * API/collab/collab-extra.php
 * Extra collaboration tools REST API.
 * Tools: flashcards | mindmap | review | summary | goals | resources
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/security/AuditLogger.php';
require_once dirname(__DIR__, 2) . '/security/SecurityHeaders.php';
require_once dirname(__DIR__, 2) . '/security/csrf/csrf.php';
require_once dirname(__DIR__, 2) . '/security/rate-limit/RateLimiter.php';

header('Content-Type: application/json');
SecurityHeaders::send(isApi: true);
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);

$db     = Database::getInstance();
$tool   = $_GET['tool']   ?? '';
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

$body = [];
if (in_array($method, ['POST','PUT','PATCH','DELETE'], true)) {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    if ($method !== 'DELETE') CSRF::verify();
}

$uid      = (int)$user['id'];
$username = $user['username'];

// Channel membership guard
$channelId = (int)($body['channel_id'] ?? $_GET['channel_id'] ?? 0);
if (!$channelId) json_fail('channel_id required', 400);

$stmt = $db->prepare("SELECT channel_id FROM channel_members WHERE channel_id=:c AND user_id=:u LIMIT 1");
$stmt->execute([':c' => $channelId, ':u' => $uid]);
if (!$stmt->fetch()) json_fail('Not a member of this channel', 403);

function json_ok(array $data = []): never  { echo json_encode(['ok' => true] + $data); exit; }
function json_fail(string $m, int $c = 400): never {
    http_response_code($c); echo json_encode(['ok' => false, 'error' => $m]); exit;
}
function ws_broadcast(PDO $db, int $cid, array $payload): void {
    try {
        $db->prepare("INSERT INTO ws_relay (channel_id, payload, created_at) VALUES(:c,:p,NOW())")
           ->execute([':c' => $cid, ':p' => json_encode($payload)]);
    } catch (\Throwable) {}
}

match ($tool) {
    'flashcards' => extra_flashcards($db, $uid, $username, $channelId, $action, $body),
    'mindmap'    => extra_mindmap($db, $uid, $username, $channelId, $action, $body),
    'review'     => extra_review($db, $uid, $username, $channelId, $action, $body),
    'summary'    => extra_summary($db, $uid, $username, $channelId, $action, $body),
    'goals'      => extra_goals($db, $uid, $username, $channelId, $action, $body),
    'resources'  => extra_resources($db, $uid, $username, $channelId, $action, $body),
    default      => json_fail("Unknown tool: $tool", 400),
};

// ════════════════════════════════════════════════════════════
// FLASHCARDS
// ════════════════════════════════════════════════════════════
function extra_flashcards(PDO $db, int $uid, string $uname, int $cid, string $action, array $body): never {
    match ($action) {
        'list_decks' => (function() use ($db, $cid) {
            $s = $db->prepare("SELECT d.*,u.username creator,(SELECT COUNT(*) FROM collab_flashcards WHERE deck_id=d.id) card_count
                FROM collab_decks d JOIN users u ON u.id=d.created_by
                WHERE d.channel_id=:c ORDER BY d.updated_at DESC");
            $s->execute([':c'=>$cid]);
            json_ok(['decks'=>$s->fetchAll()]);
        })(),
        'get_deck' => (function() use ($db, $uid, $cid, $body) {
            $did = (int)($body['deck_id']??$_GET['deck_id']??0);
            if (!$did) json_fail('deck_id required');
            $d = $db->prepare("SELECT * FROM collab_decks WHERE id=:id AND channel_id=:c LIMIT 1");
            $d->execute([':id'=>$did,':c'=>$cid]);
            $deck = $d->fetch(); if (!$deck) json_fail('Deck not found',404);
            $c = $db->prepare("SELECT fc.*,
                (SELECT rating FROM collab_flashcard_reviews WHERE card_id=fc.id AND user_id=:uid ORDER BY reviewed_at DESC LIMIT 1) last_rating
                FROM collab_flashcards fc WHERE fc.deck_id=:did ORDER BY fc.position");
            $c->execute([':did'=>$did,':uid'=>$uid]);
            $deck['cards'] = $c->fetchAll();
            json_ok(['deck'=>$deck]);
        })(),
        'create_deck' => (function() use ($db, $uid, $uname, $cid, $body) {
            $title = mb_substr(trim($body['title']??''),0,200);
            if (!$title) json_fail('title required');
            $db->prepare("INSERT INTO collab_decks (channel_id,title,description,created_by) VALUES(:c,:t,:d,:u)")
               ->execute([':c'=>$cid,':t'=>$title,':d'=>mb_substr($body['description']??'',0,500),':u'=>$uid]);
            $did = (int)$db->lastInsertId();
            // Bulk-insert cards if supplied
            if (!empty($body['cards']) && is_array($body['cards'])) {
                $cs = $db->prepare("INSERT INTO collab_flashcards (deck_id,front,back,hint,position,created_by) VALUES(:d,:f,:b,:h,:p,:u)");
                foreach (array_slice($body['cards'],0,200) as $i=>$card) {
                    $cs->execute([':d'=>$did,':f'=>mb_substr($card['front']??'',0,1000),
                        ':b'=>mb_substr($card['back']??'',0,1000),':h'=>mb_substr($card['hint']??'',0,500),
                        ':p'=>$i,':u'=>$uid]);
                }
            }
            ws_broadcast($db,$cid,['type'=>'collab_flashcards_updated','channel_id'=>$cid,'actor'=>$uname]);
            json_ok(['deck_id'=>$did]);
        })(),
        'add_card' => (function() use ($db, $uid, $uname, $cid, $body) {
            $did = (int)($body['deck_id']??0);
            $front = mb_substr(trim($body['front']??''),0,1000);
            $back  = mb_substr(trim($body['back']??''),0,1000);
            if (!$did||!$front||!$back) json_fail('deck_id, front, back required');
            $db->prepare("INSERT INTO collab_flashcards (deck_id,front,back,hint,position,created_by) VALUES(:d,:f,:b,:h,(SELECT COALESCE(MAX(position)+1,0) FROM collab_flashcards t2 WHERE t2.deck_id=:d2),:u)")
               ->execute([':d'=>$did,':f'=>$front,':b'=>$back,':h'=>mb_substr($body['hint']??'',0,500),':d2'=>$did,':u'=>$uid]);
            ws_broadcast($db,$cid,['type'=>'collab_flashcards_updated','channel_id'=>$cid,'deck_id'=>$did,'actor'=>$uname]);
            json_ok(['card_id'=>(int)$db->lastInsertId()]);
        })(),
        'rate_card' => (function() use ($db, $uid, $body) {
            $cid2   = (int)($body['card_id']??0);
            $rating = max(1,min(3,(int)($body['rating']??3)));
            if (!$cid2) json_fail('card_id required');
            $db->prepare("INSERT INTO collab_flashcard_reviews (card_id,user_id,rating) VALUES(:c,:u,:r)")
               ->execute([':c'=>$cid2,':u'=>$uid,':r'=>$rating]);
            json_ok();
        })(),
        'delete_deck' => (function() use ($db, $uid, $cid, $body) {
            $did = (int)($body['deck_id']??0); if (!$did) json_fail('deck_id required');
            $d = $db->prepare("SELECT created_by FROM collab_decks WHERE id=:id AND channel_id=:c LIMIT 1");
            $d->execute([':id'=>$did,':c'=>$cid]); $deck=$d->fetch();
            if (!$deck) json_fail('Not found',404);
            if ((int)$deck['created_by']!==$uid) json_fail('Only creator can delete',403);
            $db->prepare("DELETE FROM collab_flashcard_reviews WHERE card_id IN (SELECT id FROM collab_flashcards WHERE deck_id=:did)")->execute([':did'=>$did]);
            $db->prepare("DELETE FROM collab_flashcards WHERE deck_id=:did")->execute([':did'=>$did]);
            $db->prepare("DELETE FROM collab_decks WHERE id=:did")->execute([':did'=>$did]);
            json_ok();
        })(),
        default => json_fail("Unknown flashcards action: $action"),
    };
}

// ════════════════════════════════════════════════════════════
// MIND MAP
// ════════════════════════════════════════════════════════════
function extra_mindmap(PDO $db, int $uid, string $uname, int $cid, string $action, array $body): never {
    $ensureMap = function() use ($db,$uid,$cid): array {
        $s=$db->prepare("SELECT * FROM collab_mindmaps WHERE channel_id=:c LIMIT 1");
        $s->execute([':c'=>$cid]); $m=$s->fetch();
        if (!$m) {
            $initGraph = json_encode(['nodes'=>[['id'=>'root','label'=>'Central Idea','x'=>0,'y'=>0,'color'=>'#a855f7','root'=>true]],'edges'=>[]]);
            $db->prepare("INSERT INTO collab_mindmaps (channel_id,title,graph_json,created_by,updated_by) VALUES(:c,'Mind Map',:g,:u,:u)")
               ->execute([':c'=>$cid,':g'=>$initGraph,':u'=>$uid]);
            $s->execute([':c'=>$cid]); $m=$s->fetch();
        }
        return $m;
    };
    match ($action) {
        'get' => (function() use ($ensureMap) { json_ok(['map'=>$ensureMap()]); })(),
        'save' => (function() use ($db,$uid,$uname,$cid,$body,$ensureMap) {
            $graph   = $body['graph_json']??null;
            $version = (int)($body['version']??0);
            $title   = mb_substr(trim($body['title']??'Mind Map'),0,200);
            if (!$graph) json_fail('graph_json required');
            // Validate JSON is parseable
            $decoded = json_decode($graph,true);
            if (!is_array($decoded)||!isset($decoded['nodes'])) json_fail('Invalid graph_json');
            // Node/edge count safety caps
            if (count($decoded['nodes']??[])>500) json_fail('Max 500 nodes');
            if (count($decoded['edges']??[])>1000) json_fail('Max 1000 edges');
            $m = $ensureMap();
            if ($version>0 && (int)$m['version']>$version) json_fail('Version conflict',409);
            $db->prepare("UPDATE collab_mindmaps SET title=:t,graph_json=:g,version=version+1,updated_by=:u WHERE channel_id=:c")
               ->execute([':t'=>$title,':g'=>$graph,':u'=>$uid,':c'=>$cid]);
            ws_broadcast($db,$cid,['type'=>'collab_mindmap_updated','channel_id'=>$cid,'actor'=>$uname,'title'=>$title]);
            json_ok(['version'=>(int)$m['version']+1]);
        })(),
        default => json_fail("Unknown mindmap action: $action"),
    };
}

// ════════════════════════════════════════════════════════════
// PEER REVIEW
// ════════════════════════════════════════════════════════════
function extra_review(PDO $db, int $uid, string $uname, int $cid, string $action, array $body): never {
    match ($action) {
        'list' => (function() use ($db,$cid) {
            $s = $db->prepare("SELECT r.*,u.username author FROM collab_review_requests r JOIN users u ON u.id=r.author_id
                WHERE r.channel_id=:c ORDER BY r.created_at DESC");
            $s->execute([':c'=>$cid]);
            json_ok(['requests'=>$s->fetchAll()]);
        })(),
        'get' => (function() use ($db,$cid,$body) {
            $rid=(int)($body['request_id']??$_GET['request_id']??0); if (!$rid) json_fail('request_id required');
            $r=$db->prepare("SELECT r.*,u.username author FROM collab_review_requests r JOIN users u ON u.id=r.author_id WHERE r.id=:id AND r.channel_id=:c LIMIT 1");
            $r->execute([':id'=>$rid,':c'=>$cid]); $req=$r->fetch(); if (!$req) json_fail('Not found',404);
            $f=$db->prepare("SELECT f.*,u.username reviewer FROM collab_review_feedback f JOIN users u ON u.id=f.reviewer_id WHERE f.request_id=:id ORDER BY f.created_at");
            $f->execute([':id'=>$rid]); $req['feedback']=$f->fetchAll();
            json_ok(['request'=>$req]);
        })(),
        'create' => (function() use ($db,$uid,$uname,$cid,$body) {
            $title   = mb_substr(trim($body['title']??''),0,200); if (!$title) json_fail('title required');
            $content = mb_substr($body['content']??'',0,10000);
            $fileUrl = mb_substr(trim($body['file_url']??''),0,500);
            $db->prepare("INSERT INTO collab_review_requests (channel_id,author_id,title,content,file_url) VALUES(:c,:u,:t,:ct,:f)")
               ->execute([':c'=>$cid,':u'=>$uid,':t'=>$title,':ct'=>$content,':f'=>$fileUrl?:null]);
            $rid=(int)$db->lastInsertId();
            ws_broadcast($db,$cid,['type'=>'collab_review_created','channel_id'=>$cid,'request_id'=>$rid,'title'=>$title,'actor'=>$uname]);
            json_ok(['request_id'=>$rid]);
        })(),
        'add_feedback' => (function() use ($db,$uid,$uname,$cid,$body) {
            $rid     = (int)($body['request_id']??0); if (!$rid) json_fail('request_id required');
            $comment = mb_substr(trim($body['comment']??''),0,3000); if (!$comment) json_fail('comment required');
            $rating  = $body['rating']??null;
            if ($rating!==null) $rating=max(1,min(5,(int)$rating));
            // Cannot review your own submission
            $r=$db->prepare("SELECT author_id FROM collab_review_requests WHERE id=:id AND channel_id=:c LIMIT 1");
            $r->execute([':id'=>$rid,':c'=>$cid]); $req=$r->fetch();
            if (!$req) json_fail('Not found',404);
            if ((int)$req['author_id']===$uid) json_fail('Cannot review your own submission',403);
            $db->prepare("INSERT INTO collab_review_feedback (request_id,reviewer_id,comment,rating) VALUES(:rid,:u,:c,:r)")
               ->execute([':rid'=>$rid,':u'=>$uid,':c'=>$comment,':r'=>$rating]);
            ws_broadcast($db,$cid,['type'=>'collab_review_feedback','channel_id'=>$cid,'request_id'=>$rid,'actor'=>$uname]);
            json_ok(['feedback_id'=>(int)$db->lastInsertId()]);
        })(),
        'close' => (function() use ($db,$uid,$cid,$body) {
            $rid=(int)($body['request_id']??0); if (!$rid) json_fail('request_id required');
            $r=$db->prepare("SELECT author_id FROM collab_review_requests WHERE id=:id AND channel_id=:c LIMIT 1");
            $r->execute([':id'=>$rid,':c'=>$cid]); $req=$r->fetch();
            if (!$req) json_fail('Not found',404);
            if ((int)$req['author_id']!==$uid) json_fail('Only author can close',403);
            $db->prepare("UPDATE collab_review_requests SET state='closed' WHERE id=:id")->execute([':id'=>$rid]);
            json_ok();
        })(),
        default => json_fail("Unknown review action: $action"),
    };
}

// ════════════════════════════════════════════════════════════
// CHAT SUMMARY  (AI-powered via Anthropic claude-haiku)
// ════════════════════════════════════════════════════════════
function extra_summary(PDO $db, int $uid, string $uname, int $cid, string $action, array $body): never {
    match ($action) {
        'list' => (function() use ($db,$cid) {
            $s=$db->prepare("SELECT s.*,u.username generator FROM collab_summaries s JOIN users u ON u.id=s.generated_by WHERE s.channel_id=:c ORDER BY s.generated_at DESC LIMIT 20");
            $s->execute([':c'=>$cid]);
            json_ok(['summaries'=>$s->fetchAll()]);
        })(),
        'generate' => (function() use ($db,$uid,$uname,$cid,$body) {
            // Rate-limit: 3 summaries per channel per hour
            $limiter = new \RateLimiter();
            $rl = $limiter->attempt('chat_summary', "channel_$cid", 3, 3600);
            if (!$rl['allowed']) json_fail('Summary limit reached (3/hour). Try again later.',429);

            // Fetch last N messages for summarising
            $limit  = min(200, max(20, (int)($body['message_count']??100)));
            $msgs   = $db->prepare("SELECT m.id,m.content,u.username FROM messages m
                JOIN users u ON u.id=m.sender_id
                WHERE m.channel_id=:c AND m.deleted_at IS NULL
                ORDER BY m.id DESC LIMIT :lim");
            $msgs->bindValue(':c', $cid, PDO::PARAM_INT);
            $msgs->bindValue(':lim', $limit, PDO::PARAM_INT);
            $msgs->execute();
            $rows = array_reverse($msgs->fetchAll());

            if (count($rows)<3) json_fail('Not enough messages to summarise (need at least 3)');

            $firstId = (int)$rows[0]['id'];
            $lastId  = (int)end($rows)['id'];

            // Build transcript for the AI
            $transcript = implode("\n", array_map(
                fn($r) => "[{$r['username']}]: {$r['content']}",
                $rows
            ));
            $transcript = mb_substr($transcript, 0, 8000); // token cap

            // Call Anthropic API
            $apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : (getenv('ANTHROPIC_API_KEY') ?: '');
            if (!$apiKey || $apiKey === 'your_anthropic_api_key_here') {
                json_fail('AI API key not configured',503);
            }

            $payload = json_encode([
                'model'      => 'claude-haiku-4-5-20251001',
                'max_tokens' => 600,
                'system'     => 'You are a helpful study assistant. Summarise the following chat conversation in 3-6 concise bullet points. Focus on key topics discussed, decisions made, and any action items. Be factual and brief.',
                'messages'   => [['role'=>'user','content'=>"Chat transcript:\n\n$transcript"]],
            ]);

            $ch = curl_init('https://api.anthropic.com/v1/messages');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    "x-api-key: $apiKey",
                    'anthropic-version: 2023-06-01',
                ],
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $resp   = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($status !== 200) json_fail('AI service unavailable',503);
            $ai = json_decode($resp, true);
            $summaryText = $ai['content'][0]['text'] ?? '';
            if (!$summaryText) json_fail('AI returned empty response',503);

            $db->prepare("INSERT INTO collab_summaries (channel_id,summary,from_msg_id,to_msg_id,message_count,generated_by)
                VALUES(:c,:s,:f,:t,:mc,:u)")
               ->execute([':c'=>$cid,':s'=>$summaryText,':f'=>$firstId,':t'=>$lastId,
                          ':mc'=>count($rows),':u'=>$uid]);
            $sid=(int)$db->lastInsertId();
            ws_broadcast($db,$cid,['type'=>'collab_summary_ready','channel_id'=>$cid,'summary_id'=>$sid,'actor'=>$uname]);
            json_ok(['summary_id'=>$sid,'summary'=>$summaryText,'message_count'=>count($rows)]);
        })(),
        default => json_fail("Unknown summary action: $action"),
    };
}

// ════════════════════════════════════════════════════════════
// STUDY GOALS
// ════════════════════════════════════════════════════════════
function extra_goals(PDO $db, int $uid, string $uname, int $cid, string $action, array $body): never {
    match ($action) {
        'list' => (function() use ($db,$uid,$cid) {
            $s=$db->prepare("SELECT g.*,u.username owner,
                (SELECT COUNT(*) FROM collab_goal_milestones WHERE goal_id=g.id) total_milestones,
                (SELECT COUNT(*) FROM collab_goal_milestones WHERE goal_id=g.id AND done=1) done_milestones,
                (SELECT emoji FROM collab_goal_reactions WHERE goal_id=g.id AND user_id=:uid LIMIT 1) my_reaction,
                (SELECT COUNT(*) FROM collab_goal_reactions WHERE goal_id=g.id) reaction_count
                FROM collab_goals g JOIN users u ON u.id=g.user_id
                WHERE g.channel_id=:c AND g.status!='abandoned'
                ORDER BY g.scope DESC,g.status ASC,g.created_at DESC");
            $s->execute([':c'=>$cid,':uid'=>$uid]);
            $goals=$s->fetchAll();
            // Attach milestones
            foreach ($goals as &$goal) {
                $ms=$db->prepare("SELECT * FROM collab_goal_milestones WHERE goal_id=:id ORDER BY position");
                $ms->execute([':id'=>$goal['id']]);
                $goal['milestones']=$ms->fetchAll();
            }
            json_ok(['goals'=>$goals]);
        })(),
        'create' => (function() use ($db,$uid,$uname,$cid,$body) {
            $title=mb_substr(trim($body['title']??''),0,300); if (!$title) json_fail('title required');
            $scope=in_array($body['scope']??'group',['personal','group'])?$body['scope']:'group';
            $db->prepare("INSERT INTO collab_goals (channel_id,user_id,title,description,scope,target_date) VALUES(:c,:u,:t,:d,:s,:td)")
               ->execute([':c'=>$cid,':u'=>$uid,':t'=>$title,':d'=>mb_substr($body['description']??'',0,1000),
                          ':s'=>$scope,':td'=>$body['target_date']??null]);
            $gid=(int)$db->lastInsertId();
            // Insert milestones
            if (!empty($body['milestones'])&&is_array($body['milestones'])) {
                $ms=$db->prepare("INSERT INTO collab_goal_milestones (goal_id,label,position) VALUES(:g,:l,:p)");
                foreach (array_slice($body['milestones'],0,20) as $i=>$m) {
                    $ms->execute([':g'=>$gid,':l'=>mb_substr($m['label']??$m,0,300),':p'=>$i]);
                }
            }
            if ($scope==='group')
                ws_broadcast($db,$cid,['type'=>'collab_goal_created','channel_id'=>$cid,'goal_id'=>$gid,'title'=>$title,'actor'=>$uname]);
            json_ok(['goal_id'=>$gid]);
        })(),
        'update_progress' => (function() use ($db,$uid,$uname,$cid,$body) {
            $gid=(int)($body['goal_id']??0); if (!$gid) json_fail('goal_id required');
            $progress=max(0,min(100,(int)($body['progress']??0)));
            $status=$progress===100?'completed':'active';
            $g=$db->prepare("SELECT user_id,scope FROM collab_goals WHERE id=:id AND channel_id=:c LIMIT 1");
            $g->execute([':id'=>$gid,':c'=>$cid]); $goal=$g->fetch();
            if (!$goal) json_fail('Goal not found',404);
            if ((int)$goal['user_id']!==$uid) json_fail('Only owner can update progress',403);
            $db->prepare("UPDATE collab_goals SET progress=:p,status=:s WHERE id=:id")
               ->execute([':p'=>$progress,':s'=>$status,':id'=>$gid]);
            ws_broadcast($db,$cid,['type'=>'collab_goal_updated','channel_id'=>$cid,'goal_id'=>$gid,'progress'=>$progress,'actor'=>$uname]);
            json_ok(['status'=>$status]);
        })(),
        'toggle_milestone' => (function() use ($db,$uid,$cid,$body) {
            $mid=(int)($body['milestone_id']??0); if (!$mid) json_fail('milestone_id required');
            // Verify ownership via join
            $m=$db->prepare("SELECT m.id,g.user_id FROM collab_goal_milestones m
                JOIN collab_goals g ON g.id=m.goal_id WHERE m.id=:mid AND g.channel_id=:c LIMIT 1");
            $m->execute([':mid'=>$mid,':c'=>$cid]); $ms=$m->fetch();
            if (!$ms) json_fail('Milestone not found',404);
            if ((int)$ms['user_id']!==$uid) json_fail('Only goal owner can toggle milestones',403);
            $db->prepare("UPDATE collab_goal_milestones SET done=1-done WHERE id=:id")->execute([':id'=>$mid]);
            json_ok();
        })(),
        'react' => (function() use ($db,$uid,$body) {
            $gid=(int)($body['goal_id']??0); if (!$gid) json_fail('goal_id required');
            $emoji=mb_substr(trim($body['emoji']??'👍'),0,10)?:'👍';
            $db->prepare("INSERT INTO collab_goal_reactions (goal_id,user_id,emoji) VALUES(:g,:u,:e)
                ON DUPLICATE KEY UPDATE emoji=:e2,reacted_at=NOW()")
               ->execute([':g'=>$gid,':u'=>$uid,':e'=>$emoji,':e2'=>$emoji]);
            json_ok();
        })(),
        'abandon' => (function() use ($db,$uid,$cid,$body) {
            $gid=(int)($body['goal_id']??0); if (!$gid) json_fail('goal_id required');
            $g=$db->prepare("SELECT user_id FROM collab_goals WHERE id=:id AND channel_id=:c LIMIT 1");
            $g->execute([':id'=>$gid,':c'=>$cid]); $goal=$g->fetch();
            if (!$goal) json_fail('Not found',404);
            if ((int)$goal['user_id']!==$uid) json_fail('Only owner can abandon',403);
            $db->prepare("UPDATE collab_goals SET status='abandoned' WHERE id=:id")->execute([':id'=>$gid]);
            json_ok();
        })(),
        default => json_fail("Unknown goals action: $action"),
    };
}

// ════════════════════════════════════════════════════════════
// RESOURCE LIBRARY
// ════════════════════════════════════════════════════════════
function extra_resources(PDO $db, int $uid, string $uname, int $cid, string $action, array $body): never {
    $VALID_TYPES = ['link','pdf','video','image','file','note','other'];
    match ($action) {
        'list' => (function() use ($db,$uid,$cid,$body) {
            $type   = in_array($body['type']??'',['link','pdf','video','image','file','note','other'])?$body['type']:null;
            $search = mb_substr(trim($body['search']??$_GET['search']??''),0,100);
            $where  = 'r.channel_id=:c';
            $params = [':c'=>$cid];
            if ($type)   { $where.=' AND r.type=:t';                $params[':t']=$type; }
            if ($search) { $where.=' AND (r.title LIKE :s OR r.tags LIKE :s2)';
                           $params[':s']="%$search%"; $params[':s2']="%$search%"; }
            $s=$db->prepare("SELECT r.*,u.username adder,
                (SELECT COUNT(*) FROM collab_resource_votes WHERE resource_id=r.id) vote_count,
                (SELECT 1 FROM collab_resource_votes WHERE resource_id=r.id AND user_id=:uid LIMIT 1) voted,
                (SELECT COUNT(*) FROM collab_resource_comments WHERE resource_id=r.id) comment_count
                FROM collab_resources r JOIN users u ON u.id=r.added_by
                WHERE $where ORDER BY vote_count DESC,r.created_at DESC LIMIT 100");
            $s->execute([':uid'=>$uid]+$params);
            json_ok(['resources'=>$s->fetchAll()]);
        })(),
        'add' => (function() use ($db,$uid,$uname,$cid,$body,$VALID_TYPES) {
            $title = mb_substr(trim($body['title']??''),0,300); if (!$title) json_fail('title required');
            $url   = mb_substr(trim($body['url']??''),0,2048);
            $type  = in_array($body['type']??'link',$VALID_TYPES)?$body['type']:'link';
            $tags  = mb_substr(trim($body['tags']??''),0,500);
            // Validate URL if provided
            if ($url && !filter_var($url,FILTER_VALIDATE_URL)) json_fail('Invalid URL');
            $db->prepare("INSERT INTO collab_resources (channel_id,title,url,description,type,tags,added_by) VALUES(:c,:t,:u,:d,:tp,:tg,:uid)")
               ->execute([':c'=>$cid,':t'=>$title,':u'=>$url?:null,':d'=>mb_substr($body['description']??'',0,1000),
                          ':tp'=>$type,':tg'=>$tags?:null,':uid'=>$uid]);
            $rid=(int)$db->lastInsertId();
            ws_broadcast($db,$cid,['type'=>'collab_resource_added','channel_id'=>$cid,'resource_id'=>$rid,'title'=>$title,'actor'=>$uname]);
            json_ok(['resource_id'=>$rid]);
        })(),
        'vote' => (function() use ($db,$uid,$body) {
            $rid=(int)($body['resource_id']??0); if (!$rid) json_fail('resource_id required');
            // Toggle vote
            $v=$db->prepare("SELECT 1 FROM collab_resource_votes WHERE resource_id=:r AND user_id=:u LIMIT 1");
            $v->execute([':r'=>$rid,':u'=>$uid]);
            if ($v->fetch()) {
                $db->prepare("DELETE FROM collab_resource_votes WHERE resource_id=:r AND user_id=:u")->execute([':r'=>$rid,':u'=>$uid]);
                $db->prepare("UPDATE collab_resources SET upvotes=GREATEST(0,upvotes-1) WHERE id=:r")->execute([':r'=>$rid]);
                json_ok(['voted'=>false]);
            } else {
                $db->prepare("INSERT INTO collab_resource_votes (resource_id,user_id) VALUES(:r,:u)")->execute([':r'=>$rid,':u'=>$uid]);
                $db->prepare("UPDATE collab_resources SET upvotes=upvotes+1 WHERE id=:r")->execute([':r'=>$rid]);
                json_ok(['voted'=>true]);
            }
        })(),
        'comment' => (function() use ($db,$uid,$uname,$cid,$body) {
            $rid     = (int)($body['resource_id']??0); if (!$rid) json_fail('resource_id required');
            $comment = mb_substr(trim($body['comment']??''),0,1000); if (!$comment) json_fail('comment required');
            $db->prepare("INSERT INTO collab_resource_comments (resource_id,user_id,comment) VALUES(:r,:u,:c)")
               ->execute([':r'=>$rid,':u'=>$uid,':c'=>$comment]);
            ws_broadcast($db,$cid,['type'=>'collab_resource_commented','channel_id'=>$cid,'resource_id'=>$rid,'actor'=>$uname]);
            json_ok(['comment_id'=>(int)$db->lastInsertId()]);
        })(),
        'get_comments' => (function() use ($db,$body) {
            $rid=(int)($body['resource_id']??$_GET['resource_id']??0); if (!$rid) json_fail('resource_id required');
            $s=$db->prepare("SELECT c.*,u.username FROM collab_resource_comments c JOIN users u ON u.id=c.user_id WHERE c.resource_id=:r ORDER BY c.created_at");
            $s->execute([':r'=>$rid]);
            json_ok(['comments'=>$s->fetchAll()]);
        })(),
        'delete' => (function() use ($db,$uid,$cid,$body) {
            $rid=(int)($body['resource_id']??0); if (!$rid) json_fail('resource_id required');
            $r=$db->prepare("SELECT added_by FROM collab_resources WHERE id=:r AND channel_id=:c LIMIT 1");
            $r->execute([':r'=>$rid,':c'=>$cid]); $res=$r->fetch();
            if (!$res) json_fail('Not found',404);
            if ((int)$res['added_by']!==$uid) json_fail('Only adder can delete',403);
            $db->prepare("DELETE FROM collab_resource_votes WHERE resource_id=:r")->execute([':r'=>$rid]);
            $db->prepare("DELETE FROM collab_resource_comments WHERE resource_id=:r")->execute([':r'=>$rid]);
            $db->prepare("DELETE FROM collab_resources WHERE id=:r")->execute([':r'=>$rid]);
            json_ok();
        })(),
        default => json_fail("Unknown resources action: $action"),
    };
}
