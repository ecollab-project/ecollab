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

    // Canonical schema uses recipient_id. Keep a safe fallback for databases
    // that have not yet run the notification-schema migration.
    $recipientColumn = isset($columns['recipient_id']) ? 'recipient_id' : null;
    if ($recipientColumn === null && isset($columns['user_id'])) {
        $recipientColumn = 'user_id';
    }

    if ($recipientColumn === null) {
        echo json_encode([
            'success'       => true,
            'notifications' => [],
            'unread_count'  => 0,
        ]);
        exit;
    }

    $stmt = $db->prepare(
        "SELECT * FROM notifications
         WHERE {$recipientColumn} = :uid
         ORDER BY created_at DESC
         LIMIT 30"
    );
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
            'link_url'   => $row['link_url'] ?? null,
            'actor_id'   => $row['actor_id'] ?? null,
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
    http_response_code(200);
    echo json_encode([
        'success'       => true,
        'notifications' => [],
        'unread_count'  => 0,
    ]);
}
