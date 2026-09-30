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
    $tableStmt = $db->query("SHOW TABLES LIKE 'notifications'");
    $tableExists = (bool)$tableStmt->fetchColumn();
    $tableStmt->closeCursor();

    if (!$tableExists) {
        echo json_encode(['success'=>true,'notifications'=>[],'unread_count'=>0]);
        exit;
    }

    // recipient_id is the canonical Ecollab notification recipient column.
    // Migration 031 converts legacy user_id schemas before this endpoint relies
    // on the column.
    $columnsStmt = $db->query("SHOW COLUMNS FROM notifications");
    $columns = [];
    foreach ($columnsStmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[$column['Field']] = true;
    }
    $columnsStmt->closeCursor();

    if (!isset($columns['recipient_id'])) {
        echo json_encode(['success'=>true,'notifications'=>[],'unread_count'=>0]);
        exit;
    }

    $stmt = $db->prepare("SELECT * FROM notifications WHERE recipient_id = :uid ORDER BY created_at DESC LIMIT 30");
    $stmt->execute([':uid' => $me['id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt->closeCursor();

    $notifs = [];
    $unreadCount = 0;
    foreach ($rows as $row) {
        $isRead = array_key_exists('is_read', $row)
            ? (bool)$row['is_read']
            : (!empty($row['read_at'] ?? null));
        if (!$isRead) $unreadCount++;
        $notifs[] = [
            'id'         => $row['id'] ?? null,
            'type'       => $row['type'] ?? 'system',
            'title'      => $row['title'] ?? '',
            'body'       => $row['body'] ?? '',
            'ref_id'     => $row['ref_id'] ?? null,
            'link_url'   => $row['link_url'] ?? null,
            'icon'       => $row['icon'] ?? '🔔',
            'is_read'    => $isRead ? 1 : 0,
            'created_at' => $row['created_at'] ?? null,
        ];
    }

    echo json_encode(['success'=>true,'notifications'=>$notifs,'unread_count'=>$unreadCount]);
} catch (Throwable $e) {
    error_log('[notifications/get] '.$e->getMessage());
    http_response_code(200);
    echo json_encode(['success'=>true,'notifications'=>[],'unread_count'=>0]);
}
