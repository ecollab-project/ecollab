<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once ROOT_PATH . '/database/config/db.php';
require_once ROOT_PATH . '/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH . '/services/DocumentAccessService.php';
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    $db = Database::getInstance();
    $a = DocumentAccessService::get($db, (int)($_GET['id'] ?? 0), (int)$user['id']);
    $d = $a['document'];
    if (isset($_GET['version']) && (int)$_GET['version'] !== (int)$d['version']) {
        // Historical content may contain data no longer present in the shared current file.
        if (!$a['owner']) throw new RuntimeException('Only owners can download history.', 403);
        $s = $db->prepare('SELECT storage_path FROM collab_document_versions WHERE document_id=? AND version=?');
        $s->execute([$d['id'], (int)$_GET['version']]);
        $path = $s->fetchColumn();
        if (!$path) throw new RuntimeException('Version not found.', 404);
        $d['storage_path'] = $path;
    }
    $path = DocumentAccessService::path($d);
    session_write_close();
    header('Content-Type: application/octet-stream');
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($d['file_name']));
    header('Content-Length: ' . filesize($path));
    readfile($path);
} catch (Throwable $e) {
    http_response_code(in_array($e->getCode(), [403,404], true) ? $e->getCode() : 500);
    echo 'Document unavailable.';
}
