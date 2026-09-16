<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once ROOT_PATH . '/database/config/db.php';
require_once ROOT_PATH . '/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH . '/services/CoworkspaceService.php';

AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);
header('Content-Type: application/json; charset=utf-8');

function failDelete(string $message, int $status = 400): never {
    http_response_code($status);
    echo json_encode(['success'=>false,'error'=>$message]);
    exit;
}

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') failDelete('POST required.',405);
    AuthMiddleware::verifyCsrf();
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($body['document_id'] ?? 0);
    $wid = (int)($body['workspace_id'] ?? 0);
    if ($id < 1 || $wid < 1) failDelete('Document and Coworkspace are required.');

    $db = Database::getInstance();
    $uid = (int)$user['id'];
    $workspace = CoworkspaceService::get($db,$wid,$uid);
    $role = (string)($workspace['member_role'] ?? '');
    if (!in_array($role,['host','editor'],true)) failDelete('Only Coworkspace hosts and editors can delete documents.',403);

    $stmt = $db->prepare('SELECT id,storage_path FROM collab_documents WHERE id=:id AND workspace_id=:wid LIMIT 1');
    $stmt->execute([':id'=>$id,':wid'=>$wid]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$doc) failDelete('Document not found.',404);

    $db->beginTransaction();
    $db->prepare('DELETE FROM collab_document_presence WHERE document_id=?')->execute([$id]);
    $db->prepare('DELETE FROM collab_documents WHERE id=? AND workspace_id=?')->execute([$id,$wid]);
    $db->commit();

    $path = (string)($doc['storage_path'] ?? '');
    if ($path !== '') {
        $root = realpath(ROOT_PATH);
        $candidate = realpath(ROOT_PATH . '/' . ltrim($path,'/\\'));
        if ($root && $candidate && str_starts_with($candidate,$root . DIRECTORY_SEPARATOR) && is_file($candidate)) @unlink($candidate);
    }
    echo json_encode(['success'=>true,'deleted_id'=>$id]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
    error_log('[collaboration/delete-document] '.$e->getMessage());
    failDelete('Unable to delete the document.',500);
}
