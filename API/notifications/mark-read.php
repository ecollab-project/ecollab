<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$me = AuthMiddleware::requireAuth(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$ids  = $body['ids'] ?? null;

try {
    $db = Database::getInstance();

    // Canonical notifications schema uses recipient_id. The fallback keeps
    // older local databases functional until migration 024 is applied.
    $columnsStmt = $db->query("SHOW COLUMNS FROM notifications");
    $columns = [];
    foreach ($columnsStmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[$column['Field']] = true;
    }
    $columnsStmt->closeCursor();

    $recipientColumn = isset($columns['recipient_id']) ? 'recipient_id' : 'user_id';

    if ($ids === null) {
        $db->prepare(
            "UPDATE notifications
             SET is_read = 1, read_at = COALESCE(read_at, NOW())
             WHERE {$recipientColumn} = :uid AND is_read = 0"
        )->execute([':uid' => $me['id']]);
    } elseif (is_array($ids) && !empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare(
            "UPDATE notifications
             SET is_read = 1, read_at = COALESCE(read_at, NOW())
             WHERE {$recipientColumn} = ? AND id IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$me['id']], array_map('intval', $ids)));
    }

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    error_log('[notifications/mark-read] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
