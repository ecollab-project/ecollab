<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$me = AuthMiddleware::requireAuth(true);

try {
    $db = Database::getInstance();

    // Fetch notifications first and fully consume the result before issuing
    // the unread-count query. This keeps the endpoint safe with both buffered
    // and unbuffered PDO/MySQL configurations.
    $stmt = $db->prepare("
        SELECT id, type, title, body, ref_id, is_read, created_at
        FROM notifications
        WHERE user_id = :uid
        ORDER BY created_at DESC
        LIMIT 30
    ");
    $stmt->execute([':uid' => $me['id']]);
    $notifs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt->closeCursor();

    $ucStmt = $db->prepare("
        SELECT COUNT(*)
        FROM notifications
        WHERE user_id = :uid AND is_read = 0
    ");
    $ucStmt->execute([':uid' => $me['id']]);
    $unreadCount = (int)$ucStmt->fetchColumn();
    $ucStmt->closeCursor();

    echo json_encode([
        'success'       => true,
        'notifications' => $notifs,
        'unread_count'  => $unreadCount,
    ]);

} catch (Throwable $e) {
    error_log('[notifications/get] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
