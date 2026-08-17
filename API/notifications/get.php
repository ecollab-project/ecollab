<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json; charset=utf-8');
AuthMiddleware::startSession();
$me = AuthMiddleware::requireAuth(true);

try {
    $db = Database::getInstance();

    // Notifications have changed slightly across older Ecollab database
    // versions. Read the actual table shape first so an older local database
    // cannot turn the notification bell into a HTTP 500.
    $tableStmt = $db->query("SHOW TABLES LIKE 'notifications'");
    $tableExists = (bool)$tableStmt->fetchColumn();
    $tableStmt->closeCursor();

    if (!$tableExists) {
        echo json_encode([
            'success'       => true,
            'notifications' => [],
            'unread_count'  => 0,
        ]);
        exit;
    }

    $columnsStmt = $db->query("SHOW COLUMNS FROM notifications");
    $columns = [];
    foreach ($columnsStmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[$column['Field']] = true;
    }
    $columnsStmt->closeCursor();

    // user_id is required for a useful notification row. If an old/incomplete
    // table has no user_id, fail soft rather than breaking the whole chat page.
    if (!isset($columns['user_id'])) {
        echo json_encode([
            'success'       => true,
            'notifications' => [],
            'unread_count'  => 0,
        ]);
        exit;
    }

    // Use SELECT * for compatibility with schema revisions, then normalize the
    // fields expected by the JavaScript notification UI.
    $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = :uid ORDER BY created_at DESC LIMIT 30");
    $stmt->execute([':uid' => $me['id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt->closeCursor();

    $notifs = [];
    $unreadCount = 0;

    foreach ($rows as $row) {
        $isRead = false;
        if (array_key_exists('is_read', $row)) {
            $isRead = (bool)$row['is_read'];
        } elseif (array_key_exists('read_at', $row)) {
            $isRead = !empty($row['read_at']);
        } elseif (array_key_exists('status', $row)) {
            $isRead = strtolower((string)$row['status']) === 'read';
        }

        if (!$isRead) {
            $unreadCount++;
        }

        $notifs[] = [
            'id'         => $row['id'] ?? null,
            'type'       => $row['type'] ?? 'system',
            'title'      => $row['title'] ?? ($row['subject'] ?? ''),
            'body'       => $row['body'] ?? ($row['message'] ?? ($row['content'] ?? '')),
            'ref_id'     => $row['ref_id'] ?? ($row['reference_id'] ?? null),
            'is_read'    => $isRead ? 1 : 0,
            'created_at' => $row['created_at'] ?? ($row['sent_at'] ?? null),
        ];
    }

    echo json_encode([
        'success'       => true,
        'notifications' => $notifs,
        'unread_count'  => $unreadCount,
    ]);

} catch (Throwable $e) {
    error_log('[notifications/get] ' . $e->getMessage());
    // Notifications are supplementary UI. Never allow this endpoint to take
    // down the chat page when a local database is on an older schema.
    http_response_code(200);
    echo json_encode([
        'success'       => true,
        'notifications' => [],
        'unread_count'  => 0,
    ]);
}
