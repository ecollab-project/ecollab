<?php
declare(strict_types=1);
/**
 * API/collab/collab.php — Unified REST API for all collaboration tools.
 *
 * Routes:  GET/POST  ?tool=notes|tasks|code|timer|quiz|calendar  &action=<action>
 *
 * Every route:
 *   - Requires active session (requireAuth)
 *   - Verifies CSRF on all writes
 *   - Scopes data to a channel_id the user is a member of
 *   - Broadcasts WS event after successful mutation
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
if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    if ($method !== 'DELETE') CSRF::verify();
}

$uid      = (int)$user['id'];
$username = $user['username'];

// ── Channel membership guard ─────────────────────────────────────────────────
$channelId = (int)($body['channel_id'] ?? $_GET['channel_id'] ?? 0);
if (!$channelId) json_fail('channel_id required', 400);

$stmt = $db->prepare("
    SELECT cm.channel_id
    FROM channel_members cm
    WHERE cm.channel_id = :cid AND cm.user_id = :uid
    LIMIT 1
");
$stmt->execute([':cid' => $channelId, ':uid' => $uid]);
if (!$stmt->fetch()) json_fail('Not a member of this channel', 403);

// ── Helpers ──────────────────────────────────────────────────────────────────
function json_ok(array $data = []): never  { echo json_encode(['ok' => true] + $data); exit; }
function json_fail(string $msg, int $code = 400): never {
    http_response_code($code); echo json_encode(['ok' => false, 'error' => $msg]); exit;
}

/**
 * Fire a WebSocket broadcast by writing to a tiny relay table.
 * The WS server polls this table every 200 ms and pushes to subscribers.
 * (No direct TCP connection needed from PHP — avoids Ratchet dependency in HTTP context.)
 */
function ws_broadcast(PDO $db, int $channelId, array $payload): void {
    try {
        $db->prepare("
            INSERT INTO ws_relay (channel_id, payload, created_at)
            VALUES (:cid, :payload, NOW())
        ")->execute([':cid' => $channelId, ':payload' => json_encode($payload)]);
    } catch (\Throwable) { /* non-fatal */ }
}

// ── Router ───────────────────────────────────────────────────────────────────
match ($tool) {
    'notes'    => collab_notes($db, $uid, $username, $channelId, $action, $body, $method),
    'tasks'    => collab_tasks($db, $uid, $username, $channelId, $action, $body, $method),
    'code'     => collab_code($db, $uid, $username, $channelId, $action, $body, $method),
    'timer'    => collab_timer($db, $uid, $username, $channelId, $action, $body, $method),
    'quiz'     => collab_quiz($db, $uid, $username, $channelId, $action, $body, $method),
    'calendar' => collab_calendar($db, $uid, $username, $channelId, $action, $body, $method),
    default    => json_fail("Unknown tool: $tool", 400),
};

// ════════════════════════════════════════════════════════════════════════════
// SHARED NOTES — Operational Transform (Google Docs-grade live editing)
// ════════════════════════════════════════════════════════════════════════════
function collab_notes(PDO $db, int $uid, string $username, int $cid, string $action, array $body, string $method): never {

    // Auto-create or fetch the channel's note row
    $ensureNote = function() use ($db, $uid, $cid): array {
        $stmt = $db->prepare("SELECT * FROM collab_notes WHERE channel_id=:cid LIMIT 1");
        $stmt->execute([':cid' => $cid]);
        $note = $stmt->fetch();
        if (!$note) {
            $db->prepare("
                INSERT INTO collab_notes (channel_id, title, content, revision, version, created_by, updated_by)
                VALUES (:cid, 'Untitled Document', '', 0, 1, :uid, :uid)
            ")->execute([':cid' => $cid, ':uid' => $uid]);
            $stmt->execute([':cid' => $cid]);
            $note = $stmt->fetch();
        }
        return $note;
    };

    match ($action) {

        // ── GET full document for initial load ───────────────────────────────
        'note_load' => (function() use ($ensureNote) {
            $note = $ensureNote();
            json_ok([
                'note' => [
                    'id'       => (int)$note['id'],
                    'title'    => $note['title'],
                    'content'  => $note['content'],
                    'revision' => (int)($note['revision'] ?? 0),
                    'version'  => (int)($note['version']  ?? 1),
                ],
                'readonly' => false,
            ]);
        })(),

        // ── POST op — receive, transform, persist, broadcast ─────────────────
        'note_op' => (function() use ($db, $uid, $username, $cid, $body) {
            $noteId   = (int)($body['note_id']  ?? 0);
            $opJson   = $body['op']              ?? null;
            $clientRev = (int)($body['revision'] ?? 0);

            if (!$noteId || !$opJson) json_fail('note_id and op required');

            $clientOp = json_decode($opJson, true);
            if (!is_array($clientOp)) json_fail('op must be a JSON array');

            // Serialise all op processing for this note with a row-level lock
            $db->beginTransaction();
            try {
                $stmt = $db->prepare(
                    "SELECT id, content, revision FROM collab_notes WHERE id=:id AND channel_id=:cid FOR UPDATE"
                );
                $stmt->execute([':id' => $noteId, ':cid' => $cid]);
                $note = $stmt->fetch();
                if (!$note) { $db->rollBack(); json_fail('Note not found', 404); }

                $serverRev = (int)$note['revision'];

                // Fetch all ops committed since client's base revision
                if ($clientRev < $serverRev) {
                    $ops = $db->prepare(
                        "SELECT op_json FROM collab_note_ops
                         WHERE note_id=:nid AND revision > :rev
                         ORDER BY revision ASC"
                    );
                    $ops->execute([':nid' => $noteId, ':rev' => $clientRev]);
                    $pending = $ops->fetchAll(PDO::FETCH_COLUMN);

                    // Transform client op against each committed op
                    foreach ($pending as $pendingJson) {
                        $serverOp = json_decode($pendingJson, true);
                        [$clientOp, ] = ot_transform($clientOp, $serverOp, 'right');
                    }
                }

                // Apply transformed op to document content
                $newContent = ot_apply($note['content'], $clientOp);
                $newRev     = $serverRev + 1;

                // Persist
                $db->prepare(
                    "UPDATE collab_notes
                     SET content=:c, revision=:rev, version=version+1, updated_by=:uid, updated_at=NOW()
                     WHERE id=:id"
                )->execute([':c' => $newContent, ':rev' => $newRev, ':uid' => $uid, ':id' => $noteId]);

                // Log the transformed op
                $db->prepare(
                    "INSERT INTO collab_note_ops (note_id, user_id, username, op_json, revision, ts)
                     VALUES (:nid, :uid, :uname, :op, :rev, NOW())"
                )->execute([
                    ':nid'   => $noteId,
                    ':uid'   => $uid,
                    ':uname' => $username,
                    ':op'    => json_encode($clientOp),
                    ':rev'   => $newRev,
                ]);

                // Prune old ops beyond 500 (keep recent history only)
                $db->prepare(
                    "DELETE FROM collab_note_ops WHERE note_id=:nid
                     ORDER BY revision ASC LIMIT (
                         SELECT GREATEST(0, COUNT(*) - 500) FROM collab_note_ops AS t2 WHERE t2.note_id=:nid2
                     )"
                )->execute([':nid' => $noteId, ':nid2' => $noteId]);

                $db->commit();

                // Broadcast to channel peers via ws_relay
                ws_broadcast($db, $cid, [
                    'type'        => 'collab_note_op',
                    'channel_id'  => $cid,
                    'note_id'     => $noteId,
                    'op'          => json_encode($clientOp),
                    'revision'    => $newRev,
                    'user_id'     => $uid,
                    'username'    => $username,
                ]);

                json_ok(['revision' => $newRev, 'last_editor' => $username]);

            } catch (\Throwable $e) {
                $db->rollBack();
                // Signal client to perform a full resync
                http_response_code(409);
                echo json_encode(['ok' => false, 'error' => 'RESYNC', 'detail' => $e->getMessage()]);
                exit;
            }
        })(),

        // ── POST title-only update (no OT needed) ────────────────────────────
        'note_title' => (function() use ($db, $uid, $username, $cid, $body) {
            $noteId = (int)($body['note_id'] ?? 0);
            $title  = mb_substr(trim($body['title'] ?? ''), 0, 200) ?: 'Untitled Document';
            if (!$noteId) json_fail('note_id required');
            $db->prepare("UPDATE collab_notes SET title=:t, updated_by=:uid WHERE id=:id AND channel_id=:cid")
               ->execute([':t' => $title, ':uid' => $uid, ':id' => $noteId, ':cid' => $cid]);
            ws_broadcast($db, $cid, [
                'type'       => 'collab_note_op',
                'channel_id' => $cid,
                'note_id'    => $noteId,
                'title'      => $title,
                'user_id'    => $uid,
                'username'   => $username,
            ]);
            json_ok();
        })(),

        // ── Legacy full-save (fallback for clients without OT engine) ─────────
        'get' => (function() use ($ensureNote) {
            $note = $ensureNote();
            json_ok(['note' => $note]);
        })(),

        'save' => (function() use ($db, $uid, $username, $cid, $body, $ensureNote) {
            $title   = mb_substr(trim($body['title']   ?? 'Untitled Document'), 0, 200);
            $content = mb_substr($body['content'] ?? '', 0, 65535);
            $version = (int)($body['version'] ?? 0);

            $note = $ensureNote();
            if ($note && (int)$note['version'] > $version && $version > 0) {
                json_fail('Version conflict — reload the document', 409);
            }
            if ($note) {
                $db->prepare(
                    "UPDATE collab_notes SET title=:t, content=:c, version=version+1,
                     revision=revision+1, updated_by=:uid WHERE id=:id"
                )->execute([':t'=>$title,':c'=>$content,':uid'=>$uid,':id'=>$note['id']]);
                $noteId = (int)$note['id'];
                $newVer = (int)$note['version'] + 1;
            } else {
                $db->prepare(
                    "INSERT INTO collab_notes (channel_id,title,content,version,revision,created_by,updated_by)
                     VALUES(:cid,:t,:c,1,1,:uid,:uid)"
                )->execute([':cid'=>$cid,':t'=>$title,':c'=>$content,':uid'=>$uid]);
                $noteId = (int)$db->lastInsertId();
                $newVer = 1;
            }
            ws_broadcast($db, $cid, [
                'type'      => 'collab_note_updated',
                'channel_id'=> $cid,
                'note_id'   => $noteId,
                'title'     => $title,
                'content'   => $content,
                'version'   => $newVer,
                'editor'    => $username,
            ]);
            json_ok(['note_id' => $noteId, 'version' => $newVer]);
        })(),

        default => json_fail("Unknown notes action: $action"),
    };
}

// ────────────────────────────────────────────────────────────────────────────
// PHP OT helpers (mirror the JS engine for server-side transform)
// ────────────────────────────────────────────────────────────────────────────

/**
 * Apply an OT op (array of retain/insert/delete components) to a string.
 * @throws \RuntimeException on inconsistency
 */
function ot_apply(string $s, array $op): string {
    $idx = 0; $out = '';
    foreach ($op as $c) {
        if (isset($c['retain'])) {
            $n = (int)$c['retain'];
            if ($idx + $n > mb_strlen($s))
                throw new \RuntimeException("OT retain past end at $idx");
            $out .= mb_substr($s, $idx, $n);
            $idx += $n;
        } elseif (isset($c['insert'])) {
            $out .= (string)$c['insert'];
        } elseif (isset($c['delete'])) {
            $n = (int)$c['delete'];
            if ($idx + $n > mb_strlen($s))
                throw new \RuntimeException("OT delete past end at $idx");
            $idx += $n;
        }
    }
    $out .= mb_substr($s, $idx);
    return $out;
}

/**
 * Transform op_a against op_b. Returns [a_prime, b_prime].
 * side = 'left' means op_a wins ties (server wins).
 */
function ot_transform(array $a, array $b, string $side = 'left'): array {
    $a2 = []; $b2 = [];
    $ai = 0; $bi = 0;

    $aComp = $a[$ai] ?? null;
    $bComp = $b[$bi] ?? null;

    $pushA = function($c) use (&$a2) {
        $last = end($a2);
        if ($last && isset($last['retain']) && isset($c['retain'])) { $a2[array_key_last($a2)]['retain'] += $c['retain']; return; }
        if ($last && isset($last['insert']) && isset($c['insert'])) { $a2[array_key_last($a2)]['insert'] .= $c['insert']; return; }
        if ($last && isset($last['delete']) && isset($c['delete'])) { $a2[array_key_last($a2)]['delete'] += $c['delete']; return; }
        $a2[] = $c;
    };
    $pushB = function($c) use (&$b2) {
        $last = end($b2);
        if ($last && isset($last['retain']) && isset($c['retain'])) { $b2[array_key_last($b2)]['retain'] += $c['retain']; return; }
        if ($last && isset($last['insert']) && isset($c['insert'])) { $b2[array_key_last($b2)]['insert'] .= $c['insert']; return; }
        if ($last && isset($last['delete']) && isset($c['delete'])) { $b2[array_key_last($b2)]['delete'] += $c['delete']; return; }
        $b2[] = $c;
    };

    while ($aComp !== null || $bComp !== null) {

        if (isset($aComp['insert']) && isset($bComp['insert'])) {
            if ($side === 'left') { $pushA(['insert' => $aComp['insert']]); $pushB(['retain' => mb_strlen($aComp['insert'])]); $aComp = $a[++$ai] ?? null; }
            else                  { $pushA(['retain' => mb_strlen($bComp['insert'])]); $pushB(['insert' => $bComp['insert']]); $bComp = $b[++$bi] ?? null; }
            continue;
        }
        if (isset($aComp['insert'])) { $pushA(['insert' => $aComp['insert']]); $pushB(['retain' => mb_strlen($aComp['insert'])]); $aComp = $a[++$ai] ?? null; continue; }
        if (isset($bComp['insert'])) { $pushA(['retain' => mb_strlen($bComp['insert'])]); $pushB(['insert' => $bComp['insert']]); $bComp = $b[++$bi] ?? null; continue; }

        if ($aComp === null || $bComp === null) break;

        if (isset($aComp['retain']) && isset($bComp['retain'])) {
            $n = min($aComp['retain'], $bComp['retain']);
            $pushA(['retain'=>$n]); $pushB(['retain'=>$n]);
            $aComp['retain'] -= $n; $bComp['retain'] -= $n;
        } elseif (isset($aComp['delete']) && isset($bComp['delete'])) {
            $n = min($aComp['delete'], $bComp['delete']);
            $aComp['delete'] -= $n; $bComp['delete'] -= $n;
        } elseif (isset($aComp['delete']) && isset($bComp['retain'])) {
            $n = min($aComp['delete'], $bComp['retain']);
            $pushA(['delete'=>$n]);
            $aComp['delete'] -= $n; $bComp['retain'] -= $n;
        } elseif (isset($aComp['retain']) && isset($bComp['delete'])) {
            $n = min($aComp['retain'], $bComp['delete']);
            $pushB(['delete'=>$n]);
            $aComp['retain'] -= $n; $bComp['delete'] -= $n;
        }

        if (isset($aComp['retain']) && $aComp['retain'] === 0) $aComp = $a[++$ai] ?? null;
        elseif (isset($aComp['delete']) && $aComp['delete'] === 0) $aComp = $a[++$ai] ?? null;
        elseif (isset($aComp['insert']) && $aComp['insert'] === '') $aComp = $a[++$ai] ?? null;

        if (isset($bComp['retain']) && $bComp['retain'] === 0) $bComp = $b[++$bi] ?? null;
        elseif (isset($bComp['delete']) && $bComp['delete'] === 0) $bComp = $b[++$bi] ?? null;
        elseif (isset($bComp['insert']) && $bComp['insert'] === '') $bComp = $b[++$bi] ?? null;
    }

    return [$a2, $b2];
}


// ════════════════════════════════════════════════════════════════════════════
// TASK BOARD (Kanban)
// ════════════════════════════════════════════════════════════════════════════
function collab_tasks(PDO $db, int $uid, string $username, int $cid, string $action, array $body, string $method): never {

    match ($action) {

        'get_board' => (function() use ($db, $uid, $cid) {
            // Auto-create board + default columns on first access
            $stmt = $db->prepare("SELECT * FROM collab_boards WHERE channel_id=:cid LIMIT 1");
            $stmt->execute([':cid' => $cid]);
            $board = $stmt->fetch();
            if (!$board) {
                $db->prepare("INSERT INTO collab_boards (channel_id, name, created_by) VALUES(:cid,'Task Board',:uid)")
                   ->execute([':cid' => $cid, ':uid' => $uid]);
                $boardId = (int)$db->lastInsertId();
                $defaults = [
                    ['To Do',       '#64748b', 0],
                    ['In Progress', '#3b82f6', 1],
                    ['In Review',   '#f59e0b', 2],
                    ['Done',        '#22c55e', 3],
                ];
                $colStmt = $db->prepare("INSERT INTO collab_columns (board_id,title,color,position) VALUES(:bid,:t,:c,:p)");
                foreach ($defaults as [$t, $c, $p]) $colStmt->execute([':bid'=>$boardId,':t'=>$t,':c'=>$c,':p'=>$p]);
                $board = ['id' => $boardId, 'channel_id' => $cid, 'name' => 'Task Board'];
            }
            $boardId = (int)$board['id'];

            $cols = $db->prepare("SELECT * FROM collab_columns WHERE board_id=:bid ORDER BY position");
            $cols->execute([':bid' => $boardId]);
            $columns = $cols->fetchAll();

            $tasks = $db->prepare("
                SELECT t.*, u.username AS assignee_name
                FROM collab_tasks t
                LEFT JOIN users u ON u.id = t.assignee_id
                WHERE t.board_id=:bid ORDER BY t.column_id, t.position
            ");
            $tasks->execute([':bid' => $boardId]);
            $allTasks = $tasks->fetchAll();

            foreach ($columns as &$col) {
                $col['tasks'] = array_values(array_filter($allTasks, fn($t) => (int)$t['column_id'] === (int)$col['id']));
            }
            json_ok(['board' => $board, 'columns' => $columns]);
        })(),

        'add_task' => (function() use ($db, $uid, $username, $cid, $body) {
            $colId  = (int)($body['column_id'] ?? 0);
            $title  = mb_substr(trim($body['title'] ?? ''), 0, 300);
            if (!$colId || $title === '') json_fail('column_id and title required');

            $stmt = $db->prepare("SELECT board_id FROM collab_columns WHERE id=:cid LIMIT 1");
            $stmt->execute([':cid' => $colId]);
            $col = $stmt->fetch();
            if (!$col) json_fail('Column not found', 404);

            $db->prepare("INSERT INTO collab_tasks (column_id,board_id,title,description,priority,due_date,assignee_id,created_by)
                VALUES(:col,:bid,:title,:desc,:priority,:due,:assignee,:uid)")
               ->execute([
                    ':col' => $colId, ':bid' => $col['board_id'],
                    ':title' => $title,
                    ':desc'  => mb_substr($body['description'] ?? '', 0, 2000),
                    ':priority' => in_array($body['priority']??'', ['low','medium','high','urgent']) ? $body['priority'] : 'medium',
                    ':due'   => $body['due_date'] ?: null,
                    ':assignee' => $body['assignee_id'] ?: null,
                    ':uid'   => $uid,
               ]);
            $taskId = (int)$db->lastInsertId();

            ws_broadcast($db, $cid, ['type' => 'collab_task_added', 'channel_id' => $cid,
                'task_id' => $taskId, 'column_id' => $colId, 'title' => $title, 'actor' => $username]);
            json_ok(['task_id' => $taskId]);
        })(),

        'move_task' => (function() use ($db, $username, $cid, $body) {
            $taskId   = (int)($body['task_id']    ?? 0);
            $toCol    = (int)($body['to_column']  ?? 0);
            $position = (int)($body['position']   ?? 0);
            if (!$taskId || !$toCol) json_fail('task_id and to_column required');

            $db->prepare("UPDATE collab_tasks SET column_id=:col, position=:pos WHERE id=:tid")
               ->execute([':col' => $toCol, ':pos' => $position, ':tid' => $taskId]);

            ws_broadcast($db, $cid, ['type' => 'collab_task_moved', 'channel_id' => $cid,
                'task_id' => $taskId, 'to_column' => $toCol, 'position' => $position, 'actor' => $username]);
            json_ok();
        })(),

        'update_task' => (function() use ($db, $username, $cid, $body) {
            $taskId = (int)($body['task_id'] ?? 0);
            if (!$taskId) json_fail('task_id required');
            $allowed = ['title','description','priority','due_date','assignee_id','done'];
            $sets = []; $params = [':tid' => $taskId];
            foreach ($allowed as $f) {
                if (array_key_exists($f, $body)) {
                    $sets[] = "$f = :$f";
                    $params[":$f"] = $body[$f];
                }
            }
            if (!$sets) json_fail('Nothing to update');
            $db->prepare("UPDATE collab_tasks SET " . implode(', ', $sets) . " WHERE id=:tid")->execute($params);
            ws_broadcast($db, $cid, ['type' => 'collab_task_updated', 'channel_id' => $cid,
                'task_id' => $taskId, 'changes' => array_intersect_key($body, array_flip($allowed)), 'actor' => $username]);
            json_ok();
        })(),

        'delete_task' => (function() use ($db, $username, $cid, $body) {
            $taskId = (int)($body['task_id'] ?? 0);
            if (!$taskId) json_fail('task_id required');
            $db->prepare("DELETE FROM collab_tasks WHERE id=:tid")->execute([':tid' => $taskId]);
            ws_broadcast($db, $cid, ['type' => 'collab_task_deleted', 'channel_id' => $cid,
                'task_id' => $taskId, 'actor' => $username]);
            json_ok();
        })(),

        default => json_fail("Unknown tasks action: $action"),
    };
}

// ════════════════════════════════════════════════════════════════════════════
// CODE SANDBOX
// ════════════════════════════════════════════════════════════════════════════
function collab_code(PDO $db, int $uid, string $username, int $cid, string $action, array $body, string $method): never {
    $ALLOWED_LANGS = ['javascript','python','php','html','css','sql','bash','json','markdown','typescript'];

    match ($action) {

        'get' => (function() use ($db, $cid) {
            $stmt = $db->prepare("SELECT * FROM collab_snippets WHERE channel_id=:cid ORDER BY updated_at DESC LIMIT 1");
            $stmt->execute([':cid' => $cid]);
            $snippet = $stmt->fetch() ?: ['id' => null, 'title' => 'Untitled', 'language' => 'javascript', 'code' => '', 'version' => 0];
            json_ok(['snippet' => $snippet]);
        })(),

        'save' => (function() use ($db, $uid, $username, $cid, $body, $ALLOWED_LANGS) {
            $title   = mb_substr(trim($body['title'] ?? 'Untitled'), 0, 200);
            $lang    = in_array($body['language'] ?? '', $ALLOWED_LANGS) ? $body['language'] : 'javascript';
            $code    = mb_substr($body['code'] ?? '', 0, 65535);
            $version = (int)($body['version'] ?? 1);

            $stmt = $db->prepare("SELECT id, version FROM collab_snippets WHERE channel_id=:cid LIMIT 1");
            $stmt->execute([':cid' => $cid]);
            $existing = $stmt->fetch();

            if ($existing) {
                if ((int)$existing['version'] > $version) json_fail('Version conflict', 409);
                $db->prepare("UPDATE collab_snippets SET title=:t,language=:l,code=:c,version=version+1,updated_by=:uid WHERE id=:id")
                   ->execute([':t' => $title, ':l' => $lang, ':c' => $code, ':uid' => $uid, ':id' => $existing['id']]);
                $snipId = (int)$existing['id'];
                $newVer = $version + 1;
            } else {
                $db->prepare("INSERT INTO collab_snippets (channel_id,title,language,code,version,created_by,updated_by) VALUES(:cid,:t,:l,:c,1,:uid,:uid)")
                   ->execute([':cid' => $cid, ':t' => $title, ':l' => $lang, ':c' => $code, ':uid' => $uid]);
                $snipId = (int)$db->lastInsertId();
                $newVer = 1;
            }

            ws_broadcast($db, $cid, ['type' => 'collab_code_updated', 'channel_id' => $cid,
                'snippet_id' => $snipId, 'title' => $title, 'language' => $lang,
                'code' => $code, 'version' => $newVer, 'editor' => $username]);
            json_ok(['snippet_id' => $snipId, 'version' => $newVer]);
        })(),

        'run_history' => (function() use ($db, $cid) {
            $stmt = $db->prepare("
                SELECT r.*, u.username
                FROM collab_snippet_runs r
                JOIN users u ON u.id = r.user_id
                JOIN collab_snippets s ON s.id = r.snippet_id
                WHERE s.channel_id = :cid
                ORDER BY r.ran_at DESC LIMIT 20
            ");
            $stmt->execute([':cid' => $cid]);
            json_ok(['runs' => $stmt->fetchAll()]);
        })(),

        'log_run' => (function() use ($db, $uid, $username, $cid, $body) {
            $snipId = (int)($body['snippet_id'] ?? 0);
            if (!$snipId) json_fail('snippet_id required');
            $db->prepare("INSERT INTO collab_snippet_runs (snippet_id,user_id,output,error,duration_ms)
                VALUES(:sid,:uid,:out,:err,:dur)")
               ->execute([
                    ':sid' => $snipId, ':uid' => $uid,
                    ':out' => mb_substr($body['output'] ?? '', 0, 10000),
                    ':err' => mb_substr($body['error']  ?? '', 0, 2000),
                    ':dur' => (int)($body['duration_ms'] ?? 0),
               ]);
            ws_broadcast($db, $cid, ['type' => 'collab_code_run', 'channel_id' => $cid,
                'snippet_id' => $snipId, 'runner' => $username,
                'has_error' => !empty($body['error'])]);
            json_ok(['run_id' => (int)$db->lastInsertId()]);
        })(),

        default => json_fail("Unknown code action: $action"),
    };
}

// ════════════════════════════════════════════════════════════════════════════
// STUDY TIMER  (shared Pomodoro)
// ════════════════════════════════════════════════════════════════════════════
function collab_timer(PDO $db, int $uid, string $username, int $cid, string $action, array $body, string $method): never {

    // Auto-create timer row
    $ensure = $db->prepare("INSERT IGNORE INTO collab_timers (channel_id) VALUES(:cid)");
    $ensure->execute([':cid' => $cid]);

    $fetch = fn() => $db->query("SELECT * FROM collab_timers WHERE channel_id=$cid LIMIT 1")->fetch();

    match ($action) {

        'get' => (function() use ($fetch) {
            $t = $fetch();
            // Compute live elapsed if running
            if ($t['state'] === 'running' && $t['started_at']) {
                $t['elapsed_sec'] = (int)$t['elapsed_sec'] + (time() - strtotime($t['started_at']));
            }
            json_ok(['timer' => $t]);
        })(),

        'start' => (function() use ($db, $uid, $username, $cid, $body, $fetch) {
            $t = $fetch();
            if ($t['state'] === 'running') json_fail('Timer already running');
            $mode    = in_array($body['mode']??'', ['focus','short_break','long_break']) ? $body['mode'] : 'focus';
            $dur     = min(60, max(1, (int)($body['duration_min'] ?? ($mode === 'focus' ? 25 : ($mode === 'short_break' ? 5 : 15)))));
            $db->prepare("UPDATE collab_timers SET state='running', mode=:mode, duration_min=:dur,
                started_at=NOW(), elapsed_sec=0, started_by=:uid WHERE channel_id=:cid")
               ->execute([':mode'=>$mode,':dur'=>$dur,':uid'=>$uid,':cid'=>$cid]);
            ws_broadcast($db, $cid, ['type'=>'collab_timer_start','channel_id'=>$cid,
                'mode'=>$mode,'duration_min'=>$dur,'actor'=>$username]);
            json_ok(['timer' => $fetch()]);
        })(),

        'pause' => (function() use ($db, $username, $cid, $fetch) {
            $t = $fetch();
            if ($t['state'] !== 'running') json_fail('Timer not running');
            $elapsed = (int)$t['elapsed_sec'] + (time() - strtotime($t['started_at']));
            $db->prepare("UPDATE collab_timers SET state='paused', elapsed_sec=:el, paused_at=NOW() WHERE channel_id=:cid")
               ->execute([':el'=>$elapsed,':cid'=>$cid]);
            ws_broadcast($db, $cid, ['type'=>'collab_timer_pause','channel_id'=>$cid,'elapsed_sec'=>$elapsed,'actor'=>$username]);
            json_ok(['elapsed_sec'=>$elapsed]);
        })(),

        'resume' => (function() use ($db, $username, $cid, $fetch) {
            $t = $fetch();
            if ($t['state'] !== 'paused') json_fail('Timer not paused');
            $db->prepare("UPDATE collab_timers SET state='running', started_at=NOW() WHERE channel_id=:cid")
               ->execute([':cid'=>$cid]);
            ws_broadcast($db, $cid, ['type'=>'collab_timer_resume','channel_id'=>$cid,'elapsed_sec'=>(int)$t['elapsed_sec'],'actor'=>$username]);
            json_ok();
        })(),

        'reset' => (function() use ($db, $username, $cid) {
            $db->prepare("UPDATE collab_timers SET state='idle', elapsed_sec=0, started_at=NULL, paused_at=NULL WHERE channel_id=:cid")
               ->execute([':cid'=>$cid]);
            ws_broadcast($db, $cid, ['type'=>'collab_timer_reset','channel_id'=>$cid,'actor'=>$username]);
            json_ok();
        })(),

        'complete' => (function() use ($db, $uid, $username, $cid, $fetch) {
            $t = $fetch();
            $db->prepare("UPDATE collab_timers SET state='done', elapsed_sec=0, round=LEAST(round+1,total_rounds) WHERE channel_id=:cid")
               ->execute([':cid'=>$cid]);
            // Log completion for analytics
            $db->prepare("INSERT INTO collab_timer_log (channel_id,user_id,mode,duration_min,completed) VALUES(:cid,:uid,:mode,:dur,1)")
               ->execute([':cid'=>$cid,':uid'=>$uid,':mode'=>$t['mode'],':dur'=>$t['duration_min']]);
            ws_broadcast($db, $cid, ['type'=>'collab_timer_done','channel_id'=>$cid,'mode'=>$t['mode'],'actor'=>$username]);
            json_ok();
        })(),

        default => json_fail("Unknown timer action: $action"),
    };
}

// ════════════════════════════════════════════════════════════════════════════
// QUIZ BUILDER
// ════════════════════════════════════════════════════════════════════════════
function collab_quiz(PDO $db, int $uid, string $username, int $cid, string $action, array $body, string $method): never {

    match ($action) {

        'list' => (function() use ($db, $cid) {
            $stmt = $db->prepare("SELECT q.*, u.username AS creator FROM collab_quizzes q JOIN users u ON u.id=q.created_by WHERE q.channel_id=:cid ORDER BY q.created_at DESC");
            $stmt->execute([':cid'=>$cid]);
            json_ok(['quizzes'=>$stmt->fetchAll()]);
        })(),

        'get' => (function() use ($db, $cid, $body) {
            $qid = (int)($body['quiz_id'] ?? $_GET['quiz_id'] ?? 0);
            if (!$qid) json_fail('quiz_id required');
            $q = $db->prepare("SELECT * FROM collab_quizzes WHERE id=:qid AND channel_id=:cid LIMIT 1");
            $q->execute([':qid'=>$qid,':cid'=>$cid]);
            $quiz = $q->fetch();
            if (!$quiz) json_fail('Quiz not found', 404);
            $qs = $db->prepare("SELECT * FROM collab_quiz_questions WHERE quiz_id=:qid ORDER BY position");
            $qs->execute([':qid'=>$qid]);
            $quiz['questions'] = $qs->fetchAll();
            json_ok(['quiz'=>$quiz]);
        })(),

        'create' => (function() use ($db, $uid, $username, $cid, $body) {
            $title = mb_substr(trim($body['title']??''), 0, 200);
            if ($title === '') json_fail('title required');
            $db->prepare("INSERT INTO collab_quizzes (channel_id,title,description,time_limit,created_by) VALUES(:cid,:t,:desc,:lim,:uid)")
               ->execute([':cid'=>$cid,':t'=>$title,':desc'=>mb_substr($body['description']??'',0,1000),
                          ':lim'=>$body['time_limit']??null,':uid'=>$uid]);
            $qid = (int)$db->lastInsertId();

            // Insert questions if provided
            if (!empty($body['questions']) && is_array($body['questions'])) {
                $qs = $db->prepare("INSERT INTO collab_quiz_questions (quiz_id,question,type,options,correct,points,position) VALUES(:qid,:q,:type,:opts,:corr,:pts,:pos)");
                foreach (array_slice($body['questions'], 0, 50) as $i => $q) {
                    $qs->execute([':qid'=>$qid,':q'=>mb_substr($q['question']??'',0,1000),
                                  ':type'=>in_array($q['type']??'',['mcq','true_false','short_answer'])?$q['type']:'mcq',
                                  ':opts'=>isset($q['options'])?json_encode($q['options']):null,
                                  ':corr'=>mb_substr($q['correct']??'',0,500),
                                  ':pts'=>min(10, max(1,(int)($q['points']??1))), ':pos'=>$i]);
                }
            }
            ws_broadcast($db, $cid, ['type'=>'collab_quiz_created','channel_id'=>$cid,'quiz_id'=>$qid,'title'=>$title,'actor'=>$username]);
            json_ok(['quiz_id'=>$qid]);
        })(),

        'set_state' => (function() use ($db, $uid, $username, $cid, $body) {
            $qid   = (int)($body['quiz_id']??0);
            $state = in_array($body['state']??'', ['draft','live','closed']) ? $body['state'] : '';
            if (!$qid || !$state) json_fail('quiz_id and state required');
            // Only creator can change state
            $q = $db->prepare("SELECT created_by FROM collab_quizzes WHERE id=:qid AND channel_id=:cid LIMIT 1");
            $q->execute([':qid'=>$qid,':cid'=>$cid]);
            $quiz = $q->fetch();
            if (!$quiz) json_fail('Quiz not found',404);
            if ((int)$quiz['created_by'] !== $uid) json_fail('Only the creator can change quiz state',403);
            $ts = $state === 'live' ? ', started_at=NOW()' : ($state === 'closed' ? ', closed_at=NOW()' : '');
            $db->prepare("UPDATE collab_quizzes SET state=:state$ts WHERE id=:qid")->execute([':state'=>$state,':qid'=>$qid]);
            ws_broadcast($db, $cid, ['type'=>'collab_quiz_state','channel_id'=>$cid,'quiz_id'=>$qid,'state'=>$state,'actor'=>$username]);
            json_ok();
        })(),

        'submit' => (function() use ($db, $uid, $username, $cid, $body) {
            $qid     = (int)($body['quiz_id']??0);
            $answers = $body['answers'] ?? [];
            if (!$qid || !is_array($answers)) json_fail('quiz_id and answers required');

            $q = $db->prepare("SELECT * FROM collab_quizzes WHERE id=:qid AND channel_id=:cid AND state='live' LIMIT 1");
            $q->execute([':qid'=>$qid,':cid'=>$cid]);
            if (!$q->fetch()) json_fail('Quiz not live',400);

            $qs = $db->prepare("SELECT * FROM collab_quiz_questions WHERE quiz_id=:qid");
            $qs->execute([':qid'=>$qid]);
            $questions = $qs->fetchAll();

            $score = 0; $max = 0;
            foreach ($questions as $question) {
                $max += (int)$question['points'];
                $given = strtolower(trim((string)($answers[$question['id']] ?? '')));
                $correct = strtolower(trim($question['correct']));
                if ($given === $correct) $score += (int)$question['points'];
            }

            $db->prepare("INSERT INTO collab_quiz_attempts (quiz_id,user_id,answers,score,max_score)
                VALUES(:qid,:uid,:ans,:score,:max)
                ON DUPLICATE KEY UPDATE answers=:ans2, score=:score2, max_score=:max2, submitted_at=NOW()")
               ->execute([':qid'=>$qid,':uid'=>$uid,':ans'=>json_encode($answers),
                          ':score'=>$score,':max'=>$max,':ans2'=>json_encode($answers),
                          ':score2'=>$score,':max2'=>$max]);

            ws_broadcast($db, $cid, ['type'=>'collab_quiz_submission','channel_id'=>$cid,
                'quiz_id'=>$qid,'actor'=>$username,'score'=>$score,'max'=>$max]);
            json_ok(['score'=>$score,'max_score'=>$max]);
        })(),

        'results' => (function() use ($db, $body) {
            $qid = (int)($body['quiz_id']??$_GET['quiz_id']??0);
            if (!$qid) json_fail('quiz_id required');
            $stmt = $db->prepare("SELECT a.*, u.username, u.full_name FROM collab_quiz_attempts a JOIN users u ON u.id=a.user_id WHERE a.quiz_id=:qid ORDER BY a.score DESC");
            $stmt->execute([':qid'=>$qid]);
            json_ok(['results'=>$stmt->fetchAll()]);
        })(),

        default => json_fail("Unknown quiz action: $action"),
    };
}

// ════════════════════════════════════════════════════════════════════════════
// GROUP CALENDAR
// ════════════════════════════════════════════════════════════════════════════
function collab_calendar(PDO $db, int $uid, string $username, int $cid, string $action, array $body, string $method): never {

    match ($action) {

        'list' => (function() use ($db, $cid) {
            $from  = $_GET['from'] ?? date('Y-m-01');
            $to    = $_GET['to']   ?? date('Y-m-t');
            $stmt  = $db->prepare("
                SELECT e.*, u.username AS creator,
                       (SELECT COUNT(*) FROM collab_event_rsvps r WHERE r.event_id=e.id AND r.status='going') AS going_count
                FROM collab_events e
                JOIN users u ON u.id=e.created_by
                WHERE e.channel_id=:cid AND e.start_time BETWEEN :from AND :to
                ORDER BY e.start_time
            ");
            $stmt->execute([':cid'=>$cid,':from'=>$from.' 00:00:00',':to'=>$to.' 23:59:59']);
            json_ok(['events'=>$stmt->fetchAll()]);
        })(),

        'create' => (function() use ($db, $uid, $username, $cid, $body) {
            $title = mb_substr(trim($body['title']??''), 0, 200);
            if ($title === '') json_fail('title required');
            $types = ['study','deadline','meeting','exam','social','other'];
            $db->prepare("INSERT INTO collab_events (channel_id,title,description,type,color,start_time,end_time,all_day,recurring,created_by)
                VALUES(:cid,:title,:desc,:type,:color,:start,:end,:allday,:rec,:uid)")
               ->execute([
                    ':cid'=>$cid,':title'=>$title,
                    ':desc'=>mb_substr($body['description']??'',0,1000),
                    ':type'=>in_array($body['type']??'study',$types)?$body['type']:'study',
                    ':color'=>preg_match('/^#[0-9a-fA-F]{6}$/',$body['color']??'')?$body['color']:'#a855f7',
                    ':start'=>$body['start_time']??date('Y-m-d H:i:s'),
                    ':end'=>$body['end_time']??date('Y-m-d H:i:s', strtotime('+1 hour')),
                    ':allday'=>(int)($body['all_day']??0),
                    ':rec'=>in_array($body['recurring']??'none',['none','daily','weekly','monthly'])?$body['recurring']:'none',
                    ':uid'=>$uid,
               ]);
            $eventId = (int)$db->lastInsertId();
            // Creator RSVPs as "going" automatically
            $db->prepare("INSERT IGNORE INTO collab_event_rsvps (event_id,user_id,status) VALUES(:eid,:uid,'going')")
               ->execute([':eid'=>$eventId,':uid'=>$uid]);
            ws_broadcast($db, $cid, ['type'=>'collab_event_created','channel_id'=>$cid,
                'event_id'=>$eventId,'title'=>$title,'start_time'=>$body['start_time'],'actor'=>$username]);
            json_ok(['event_id'=>$eventId]);
        })(),

        'update' => (function() use ($db, $uid, $username, $cid, $body) {
            $eid = (int)($body['event_id']??0);
            if (!$eid) json_fail('event_id required');
            $e = $db->prepare("SELECT created_by FROM collab_events WHERE id=:eid AND channel_id=:cid LIMIT 1");
            $e->execute([':eid'=>$eid,':cid'=>$cid]);
            $event = $e->fetch();
            if (!$event) json_fail('Event not found',404);
            if ((int)$event['created_by'] !== $uid) json_fail('Only the creator can edit this event',403);
            $allowed = ['title','description','type','color','start_time','end_time','all_day','recurring'];
            $sets = []; $params = [':eid'=>$eid];
            foreach ($allowed as $f) { if (array_key_exists($f,$body)) { $sets[] = "$f=:$f"; $params[":$f"]=$body[$f]; } }
            if ($sets) $db->prepare("UPDATE collab_events SET ".implode(',',$sets)." WHERE id=:eid")->execute($params);
            ws_broadcast($db, $cid, ['type'=>'collab_event_updated','channel_id'=>$cid,'event_id'=>$eid,'actor'=>$username]);
            json_ok();
        })(),

        'delete' => (function() use ($db, $uid, $username, $cid, $body) {
            $eid = (int)($body['event_id']??0);
            if (!$eid) json_fail('event_id required');
            $e = $db->prepare("SELECT created_by FROM collab_events WHERE id=:eid AND channel_id=:cid LIMIT 1");
            $e->execute([':eid'=>$eid,':cid'=>$cid]);
            $event = $e->fetch();
            if (!$event) json_fail('Event not found',404);
            if ((int)$event['created_by'] !== $uid) json_fail('Only the creator can delete this event',403);
            $db->prepare("DELETE FROM collab_events WHERE id=:eid")->execute([':eid'=>$eid]);
            $db->prepare("DELETE FROM collab_event_rsvps WHERE event_id=:eid")->execute([':eid'=>$eid]);
            ws_broadcast($db, $cid, ['type'=>'collab_event_deleted','channel_id'=>$cid,'event_id'=>$eid,'actor'=>$username]);
            json_ok();
        })(),

        'rsvp' => (function() use ($db, $uid, $username, $cid, $body) {
            $eid    = (int)($body['event_id']??0);
            $status = in_array($body['status']??'', ['going','maybe','not_going']) ? $body['status'] : 'going';
            if (!$eid) json_fail('event_id required');
            $db->prepare("INSERT INTO collab_event_rsvps (event_id,user_id,status) VALUES(:eid,:uid,:s)
                ON DUPLICATE KEY UPDATE status=:s2, rsvped_at=NOW()")
               ->execute([':eid'=>$eid,':uid'=>$uid,':s'=>$status,':s2'=>$status]);
            ws_broadcast($db, $cid, ['type'=>'collab_event_rsvp','channel_id'=>$cid,
                'event_id'=>$eid,'status'=>$status,'actor'=>$username]);
            json_ok();
        })(),

        default => json_fail("Unknown calendar action: $action"),
    };
}
