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

    // Get all DM conversations for this user, with partner info + unread count
    $stmt = $db->prepare("
        SELECT
            dc.id           AS conversation_id,
            dc.last_message,
            dc.last_msg_at,
            u.id            AS partner_id,
            u.username      AS partner_username,
            u.full_name     AS partner_name,
            u.avatar_color_gradient AS partner_gradient,
            (
                SELECT COUNT(*) FROM dm_messages dm
                WHERE dm.conversation_id = dc.id
                  AND dm.sender_id <> :me2
                  AND dm.is_deleted = 0
                  AND dm.created_at > COALESCE(
                      (SELECT dr.last_read_at FROM dm_reads dr
                       WHERE dr.user_id = :me3 AND dr.conversation_id = dc.id),
                      '1970-01-01'
                  )
            ) AS unread_count
        FROM dm_conversations dc
        JOIN users u ON u.id = IF(dc.user_a = :me4, dc.user_b, dc.user_a)
        WHERE (dc.user_a = :me5 OR dc.user_b = :me6)
          AND u.deleted_at IS NULL
        ORDER BY COALESCE(dc.last_msg_at, dc.created_at) DESC
        LIMIT 50
    ");
    $stmt->execute([
        ':me2' => $me['id'],
        ':me3' => $me['id'],
        ':me4' => $me['id'],
        ':me5' => $me['id'],
        ':me6' => $me['id'],
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'conversations' => $rows]);

} catch (Throwable $e) {
    error_log('[dm/get-conversations] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
