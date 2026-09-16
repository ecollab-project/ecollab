<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once ROOT_PATH . '/database/config/db.php';
require_once ROOT_PATH . '/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH . '/services/CoworkspaceService.php';

AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);
header('Content-Type: application/json; charset=utf-8');
$db = Database::getInstance();
$uid = (int)$user['id'];

function wbJson(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function wbInput(): array
{
    $raw = json_decode(file_get_contents('php://input'), true);
    return is_array($raw) ? $raw : $_POST;
}

function wbWorkspace(PDO $db, int $uid, int $workspaceId): array
{
    if ($workspaceId < 1) wbJson(['success' => false, 'error' => 'Coworkspace is required.'], 400);
    try {
        return CoworkspaceService::get($db, $workspaceId, $uid);
    } catch (Throwable $e) {
        $code = (int)$e->getCode();
        wbJson(['success' => false, 'error' => $code >= 400 && $code < 600 ? $e->getMessage() : 'Coworkspace not found.'], $code >= 400 && $code < 600 ? $code : 404);
    }
}

function wbCanEdit(array $workspace, int $uid): bool
{
    $role = (string)($workspace['member_role'] ?? '');
    return (int)($workspace['allow_whiteboard'] ?? 0) === 1
        && in_array($role, ['host', 'editor', 'member'], true);
}

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $data = $method === 'GET' ? $_GET : wbInput();
    $workspaceId = (int)($data['workspace_id'] ?? 0);
    $workspace = wbWorkspace($db, $uid, $workspaceId);

    if ((int)($workspace['allow_whiteboard'] ?? 0) !== 1) {
        wbJson(['success' => false, 'error' => 'Whiteboard access is disabled for this Coworkspace.'], 403);
    }

    if ($method === 'GET') {
        $whiteboardId = (int)($data['whiteboard_id'] ?? 0);
        if ($whiteboardId > 0) {
            $stmt = $db->prepare(
                'SELECT id,workspace_id,title,description,state_json,created_by,updated_by,created_at,updated_at
                 FROM collab_whiteboards WHERE id=:id AND workspace_id=:wid LIMIT 1'
            );
            $stmt->execute([':id' => $whiteboardId, ':wid' => $workspaceId]);
            $board = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$board) wbJson(['success' => false, 'error' => 'Whiteboard not found.'], 404);
            $board['state'] = json_decode((string)$board['state_json'], true) ?: ['paths' => [], 'text' => []];
            unset($board['state_json']);
            wbJson(['success' => true, 'whiteboard' => $board]);
        }

        $stmt = $db->prepare(
            'SELECT w.id,w.workspace_id,w.title,w.description,w.created_by,w.updated_by,w.created_at,w.updated_at,
                    COALESCE(NULLIF(u.full_name,""),u.username,"Unknown") AS creator_name
             FROM collab_whiteboards w
             LEFT JOIN users u ON u.id=w.created_by
             WHERE w.workspace_id=:wid ORDER BY w.updated_at DESC,w.id DESC'
        );
        $stmt->execute([':wid' => $workspaceId]);
        wbJson(['success' => true, 'workspace_id' => $workspaceId, 'whiteboards' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($method !== 'POST' && $method !== 'DELETE') wbJson(['success' => false, 'error' => 'Method not allowed.'], 405);
    AuthMiddleware::verifyCsrf();

    $whiteboardId = (int)($data['whiteboard_id'] ?? 0);
    $action = strtolower(trim((string)($data['action'] ?? ($method === 'DELETE' ? 'delete' : 'save'))));
    $role = (string)($workspace['member_role'] ?? '');
    $isHost = $role === 'host' || (int)($workspace['host_id'] ?? 0) === $uid;

    if ($action === 'create') {
        if (!wbCanEdit($workspace, $uid)) wbJson(['success' => false, 'error' => 'You do not have permission to create whiteboards.'], 403);
        $title = trim((string)($data['title'] ?? 'Untitled Whiteboard'));
        $description = trim((string)($data['description'] ?? ''));
        $title = mb_substr($title !== '' ? $title : 'Untitled Whiteboard', 0, 200);
        $description = mb_substr($description, 0, 500);
        $initialState = json_encode(['paths' => [], 'text' => [], 'objects' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $stmt = $db->prepare(
            'INSERT INTO collab_whiteboards(workspace_id,title,description,state_json,created_by,updated_by)
             VALUES(:wid,:title,:description,:state,:uid,:uid2)'
        );
        $stmt->execute([':wid' => $workspaceId, ':title' => $title, ':description' => $description ?: null, ':state' => $initialState, ':uid' => $uid, ':uid2' => $uid]);
        $id = (int)$db->lastInsertId();
        wbJson(['success' => true, 'whiteboard' => ['id' => $id, 'workspace_id' => $workspaceId, 'title' => $title, 'description' => $description, 'created_by' => $uid]]);
    }

    if ($whiteboardId < 1) wbJson(['success' => false, 'error' => 'Whiteboard is required.'], 400);

    $check = $db->prepare('SELECT id,title FROM collab_whiteboards WHERE id=:id AND workspace_id=:wid LIMIT 1');
    $check->execute([':id' => $whiteboardId, ':wid' => $workspaceId]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);
    if (!$existing) wbJson(['success' => false, 'error' => 'Whiteboard not found.'], 404);

    if ($action === 'delete') {
        if (!$isHost) wbJson(['success' => false, 'error' => 'Only the Coworkspace host can delete a whiteboard.'], 403);
        $stmt = $db->prepare('DELETE FROM collab_whiteboards WHERE id=:id AND workspace_id=:wid');
        $stmt->execute([':id' => $whiteboardId, ':wid' => $workspaceId]);
        wbJson(['success' => true, 'deleted' => $whiteboardId]);
    }

    if ($action === 'rename') {
        if (!$isHost && !wbCanEdit($workspace, $uid)) wbJson(['success' => false, 'error' => 'You do not have permission to rename this whiteboard.'], 403);
        $title = mb_substr(trim((string)($data['title'] ?? '')), 0, 200);
        if ($title === '') wbJson(['success' => false, 'error' => 'A whiteboard title is required.'], 400);
        $description = mb_substr(trim((string)($data['description'] ?? '')), 0, 500);
        $stmt = $db->prepare('UPDATE collab_whiteboards SET title=:title,description=:description,updated_by=:uid WHERE id=:id AND workspace_id=:wid');
        $stmt->execute([':title' => $title, ':description' => $description ?: null, ':uid' => $uid, ':id' => $whiteboardId, ':wid' => $workspaceId]);
        wbJson(['success' => true, 'whiteboard' => ['id' => $whiteboardId, 'title' => $title, 'description' => $description]]);
    }

    if (!wbCanEdit($workspace, $uid)) wbJson(['success' => false, 'error' => 'You do not have permission to edit this whiteboard.'], 403);
    $state = $data['state'] ?? null;
    if (!is_array($state)) wbJson(['success' => false, 'error' => 'Invalid whiteboard state.'], 400);
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (strlen($json) > 10 * 1024 * 1024) wbJson(['success' => false, 'error' => 'Whiteboard state is too large.'], 413);
    $stmt = $db->prepare('UPDATE collab_whiteboards SET state_json=:state,updated_by=:uid,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND workspace_id=:wid');
    $stmt->execute([':state' => $json, ':uid' => $uid, ':id' => $whiteboardId, ':wid' => $workspaceId]);
    wbJson(['success' => true, 'whiteboard_id' => $whiteboardId, 'updated_at' => date('Y-m-d H:i:s')]);
} catch (Throwable $e) {
    error_log('[collaboration/whiteboards] ' . $e->getMessage());
    wbJson(['success' => false, 'error' => 'Unable to process whiteboard request.'], 500);
}
