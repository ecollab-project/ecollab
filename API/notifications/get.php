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

    $stmt = $db->prepare("
        SELECT id, type, title, body, ref_id, is_read, created_at
        FROM notifications
        WHERE user_id = :uid
        ORDER BY created_at DESC
        LIMIT 30
    ");
    $stmt->execute([':uid' => $me['id']]);
    $notifs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $unreadCount = (int)$db->prepare("
        SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = 0
    ")->execute([':uid' => $me['id']]) ? 0 : 0; // will use subquery below

    $ucStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = 0");
    $ucStmt->execute([':uid' => $me['id']]);
    $unreadCount = (int)$ucStmt->fetchColumn();

    echo json_encode([
        'success'      => true,
        'notifications' => $notifs,
        'unread_count' => $unreadCount,
    ]);

} catch (Throwable $e) {
    error_log('[notifications/get] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
