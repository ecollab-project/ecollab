<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once ROOT_PATH . '/database/config/db.php';
require_once ROOT_PATH . '/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH . '/services/DocumentAccessService.php';
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);
header('Content-Type: application/json; charset=utf-8');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new RuntimeException('Method not allowed.', 405);
    AuthMiddleware::verifyCsrf();
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) throw new RuntimeException('Invalid report.', 400);
    $id = (int)($input['id'] ?? 0);
    $reason = (string)($input['reason'] ?? '');
    $description = trim((string)($input['description'] ?? ''));
    if ($id < 1 || !in_array($reason, ['spam','harassment','inappropriate','phishing','other'], true)
        || mb_strlen($description) > 2000) throw new RuntimeException('Choose a reason and limit details to 2,000 characters.', 400);
    $db = Database::getInstance();
    $access = DocumentAccessService::get($db, $id, (int)$user['id']);
    $owner = (int)$access['document']['created_by'];
    if ($owner === (int)$user['id']) throw new RuntimeException('You cannot report your own document.', 400);
    $s = $db->prepare('INSERT INTO content_reports (reporter_id,reported_user_id,document_id,server_id,reason,description)
        VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
    $s->execute([(int)$user['id'], $owner, $id, (int)$access['workspace']['server_id'], $reason, $description ?: null]);
    echo json_encode(['success'=>true, 'report_id'=>(int)$db->lastInsertId()]);
} catch (Throwable $e) {
    $status = (int)$e->getCode();
    if ($status < 400 || $status > 599) { $status = 500; error_log('[document-report] '.$e->getMessage()); }
    http_response_code($status);
    echo json_encode(['success'=>false, 'error'=>$status === 500 ? 'Unable to submit the report.' : $e->getMessage()]);
}
