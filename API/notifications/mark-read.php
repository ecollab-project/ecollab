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
$ids  = $body['ids'] ?? null; // null = mark all; array = mark specific

try {
    $db = Database::getInstance();

    if ($ids === null) {
        // Mark all
        $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = :uid AND is_read = 0")
           ->execute([':uid' => $me['id']]);
    } elseif (is_array($ids) && !empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND id IN ({$placeholders})");
        $stmt->execute(array_merge([$me['id']], array_map('intval', $ids)));
    }

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    error_log('[notifications/mark-read] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
