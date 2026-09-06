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

function serverCoworkspaceFail(string $message, int $status = 400): never
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function serverCoworkspaceInput(): array
{
    $input = json_decode(file_get_contents('php://input'), true);
    return is_array($input) ? $input : $_POST;
}

function requireServerMember(PDO $db, int $serverId, int $userId): array
{
    $stmt = $db->prepare(
        'SELECT s.id, s.name, s.type, s.status, sm.server_role
         FROM servers s
         INNER JOIN server_members sm ON sm.server_id = s.id AND sm.user_id = :uid
         WHERE s.id = :sid AND s.status = "active"
         LIMIT 1'
    );
    $stmt->execute([':uid' => $userId, ':sid' => $serverId]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$server) serverCoworkspaceFail('You are not a member of this server.', 403);
    return $server;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$input = serverCoworkspaceInput();
$serverId = (int)($_GET['server_id'] ?? $input['server_id'] ?? 0);
$channelId = (int)($_GET['channel_id'] ?? $input['channel_id'] ?? 0);
$workspaceId = (int)($_GET['workspace_id'] ?? $input['workspace_id'] ?? 0);

try {
    if ($serverId < 1 && $channelId > 0) {
        $stmt = $db->prepare(
            'SELECT c.server_id
             FROM channels c
             INNER JOIN server_members sm ON sm.server_id = c.server_id AND sm.user_id = :uid
             WHERE c.id = :cid
             LIMIT 1'
        );
        $stmt->execute([':uid' => $uid, ':cid' => $channelId]);
        $serverId = (int)($stmt->fetchColumn() ?: 0);
    }

    if ($serverId < 1) serverCoworkspaceFail('A server is required.');
    $server = requireServerMember($db, $serverId, $uid);

    if ($method === 'GET') {
        if ($workspaceId > 0) {
            $stmt = $db->prepare(
                'SELECT w.id,w.server_id,w.channel_id,w.name,w.visibility,w.host_id,
                        w.allow_create_documents,w.allow_edit_documents,w.allow_whiteboard,w.allow_member_invites,
                        COALESCE(m.role,IF(w.host_id=:uid_host,"host",NULL)) AS member_role,
                        (SELECT COUNT(*) FROM collab_workspace_members x WHERE x.workspace_id=w.id) AS member_count,
                        (SELECT COUNT(*) FROM collab_workspace_presence p WHERE p.workspace_id=w.id AND p.last_seen_at >= (CURRENT_TIMESTAMP - INTERVAL 45 SECOND)) AS active_count
                 FROM collab_workspaces w
                 LEFT JOIN collab_workspace_members m ON m.workspace_id=w.id AND m.user_id=:uid_member
                 WHERE w.id=:wid AND w.server_id=:sid AND w.archived=0
                 LIMIT 1'
            );
            $stmt->execute([':uid_host'=>$uid,':uid_member'=>$uid,':wid'=>$workspaceId,':sid'=>$serverId]);
            $workspace = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$workspace) serverCoworkspaceFail('Coworkspace not found in this server.',404);
            echo json_encode(['success'=>true,'server'=>$server,'workspace'=>$workspace]);
            exit;
        }

        $stmt = $db->prepare(
            'SELECT w.id,w.server_id,w.channel_id,w.name,w.visibility,w.host_id,
                    w.allow_create_documents,w.allow_edit_documents,w.allow_whiteboard,w.allow_member_invites,
                    COALESCE(m.role,IF(w.host_id=:uid_host,"host",NULL)) AS member_role,
                    (SELECT COUNT(*) FROM collab_workspace_members x WHERE x.workspace_id=w.id) AS member_count,
                    (SELECT COUNT(*) FROM collab_workspace_presence p WHERE p.workspace_id=w.id AND p.last_seen_at >= (CURRENT_TIMESTAMP - INTERVAL 45 SECOND)) AS active_count
             FROM collab_workspaces w
             LEFT JOIN collab_workspace_members m ON m.workspace_id=w.id AND m.user_id=:uid_member
             WHERE w.server_id=:sid AND w.archived=0
             ORDER BY w.updated_at DESC'
        );
        $stmt->execute([':uid_host'=>$uid,':uid_member'=>$uid,':sid'=>$serverId]);
        echo json_encode(['success'=>true,'server'=>$server,'workspaces'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($method !== 'POST') serverCoworkspaceFail('Method not allowed.',405);
    AuthMiddleware::verifyCsrf();
    $action = (string)($input['action'] ?? 'create');

    if ($action === 'create') {
        $name = trim((string)($input['name'] ?? ''));
        $visibility = strtolower((string)($input['visibility'] ?? 'public'));
        $originChannel = (int)($input['channel_id'] ?? 0);
        if ($name === '') serverCoworkspaceFail('Coworkspace name is required.');
        if (!in_array($visibility,['public','private'],true)) serverCoworkspaceFail('Visibility must be public or private.');
        if ($originChannel < 1) {
            $stmt = $db->prepare('SELECT id FROM channels WHERE server_id=:sid ORDER BY position,id LIMIT 1');
            $stmt->execute([':sid'=>$serverId]);
            $originChannel = (int)($stmt->fetchColumn() ?: 0);
        } else {
            $stmt = $db->prepare('SELECT 1 FROM channels WHERE id=:cid AND server_id=:sid LIMIT 1');
            $stmt->execute([':cid'=>$originChannel,':sid'=>$serverId]);
            if (!$stmt->fetchColumn()) serverCoworkspaceFail('The origin channel does not belong to this server.');
        }
        if ($originChannel < 1) serverCoworkspaceFail('This server has no channel available for the Coworkspace.');
        $name = trim(substr(preg_replace('/[\x00-\x1F\x7F]/','',$name) ?: 'Coworkspace',0,200));

        $db->beginTransaction();
        $stmt = $db->prepare(
            'INSERT INTO collab_workspaces
             (channel_id,server_id,name,visibility,host_id,allow_create_documents,allow_edit_documents,allow_whiteboard,allow_member_invites)
             VALUES (:cid,:sid,:name,:visibility,:uid,:create_docs,:edit_docs,:whiteboard,:invites)'
        );
        $stmt->execute([
            ':cid'=>$originChannel,':sid'=>$serverId,':name'=>$name,':visibility'=>$visibility,':uid'=>$uid,
            ':create_docs'=>!empty($input['allow_create_documents'])?1:0,
            ':edit_docs'=>!empty($input['allow_edit_documents'])?1:0,
            ':whiteboard'=>!empty($input['allow_whiteboard'])?1:0,
            ':invites'=>!empty($input['allow_member_invites'])?1:0,
        ]);
        $id=(int)$db->lastInsertId();
        $member=$db->prepare('INSERT INTO collab_workspace_members (workspace_id,user_id,role) VALUES (:wid,:uid,"host")');
        $member->execute([':wid'=>$id,':uid'=>$uid]);
        $db->commit();
        echo json_encode(['success'=>true,'workspace'=>['id'=>$id,'server_id'=>$serverId,'channel_id'=>$originChannel,'name'=>$name,'visibility'=>$visibility,'role'=>'host']]);
        exit;
    }

    if ($workspaceId < 1) serverCoworkspaceFail('A Coworkspace is required.');
    $workspace = CoworkspaceService::get($db,$workspaceId,$uid,false);
    if ((int)$workspace['server_id'] !== $serverId) serverCoworkspaceFail('Coworkspace does not belong to this server.',403);

    if ($action === 'join') {
        if (!CoworkspaceService::canJoin($db,$workspace,$uid)) {
            serverCoworkspaceFail('This is a private Coworkspace. An invitation or approved access request is required.',403);
        }
        $stmt=$db->prepare('INSERT INTO collab_workspace_members (workspace_id,user_id,role) VALUES (:wid,:uid,"member") ON DUPLICATE KEY UPDATE role=role');
        $stmt->execute([':wid'=>$workspaceId,':uid'=>$uid]);
        echo json_encode(['success'=>true,'role'=>'member']);
        exit;
    }

    if ($action === 'request_access') {
        if ((int)$workspace['host_id'] === $uid || (string)$workspace['visibility'] === 'public') serverCoworkspaceFail('Access requests are only needed for private Coworkspaces.');
        $stmt=$db->prepare('INSERT INTO collab_workspace_access_requests (workspace_id,user_id,status) VALUES (:wid,:uid,"pending") ON DUPLICATE KEY UPDATE status=IF(status="denied","pending",status),updated_at=CURRENT_TIMESTAMP');
        $stmt->execute([':wid'=>$workspaceId,':uid'=>$uid]);
        echo json_encode(['success'=>true,'status'=>'pending']);
        exit;
    }

    serverCoworkspaceFail('Unknown Coworkspace action.');
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    $status=(int)$e->getCode();
    serverCoworkspaceFail($e->getMessage(),($status>=400&&$status<600)?$status:500);
}
