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

function docLinkFail(string $message, int $status = 400): never
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') docLinkFail('Method not allowed.', 405);
AuthMiddleware::verifyCsrf();
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;
$workspaceId = (int)($input['workspace_id'] ?? 0);
$documentId = (int)($input['document_id'] ?? 0);
if ($workspaceId < 1 || $documentId < 1) docLinkFail('Workspace and document are required.');

try {
    $workspace = CoworkspaceService::get($db, $workspaceId, $uid);
    $role = (string)($workspace['member_role'] ?? '');
    if ((int)$workspace['allow_create_documents'] !== 1 && !in_array($role, ['host','editor'], true)) {
        docLinkFail('Document linking is disabled for this Coworkspace.', 403);
    }

    $stmt = $db->prepare(
        'UPDATE collab_documents d
         INNER JOIN channels c ON c.id = d.channel_id
         SET d.workspace_id = :wid
         WHERE d.id = :did
           AND c.server_id = :sid'
    );
    $stmt->execute([
        ':wid' => $workspaceId,
        ':did' => $documentId,
        ':sid' => (int)$workspace['server_id'],
    ]);
    if ($stmt->rowCount() < 1) docLinkFail('Document not found in this server.', 404);
    echo json_encode(['success' => true, 'workspace_id' => $workspaceId, 'document_id' => $documentId]);
} catch (Throwable $e) {
    $status = (int)$e->getCode();
    docLinkFail($e->getMessage(), ($status >= 400 && $status < 600) ? $status : 500);
}
