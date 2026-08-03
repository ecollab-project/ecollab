<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$me = AuthMiddleware::requireAuth(true);

$partnerId = (int)($_GET['partner_id'] ?? 0);
if (!$partnerId || $partnerId === $me['id']) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid partner_id']);
    exit;
}

try {
    $db = Database::getInstance();

    // Enforce ordered pair
    $a = min($me['id'], $partnerId);
    $b = max($me['id'], $partnerId);

    // Upsert conversation
    $upsert = $db->prepare("
        INSERT IGNORE INTO dm_conversations (user_a, user_b) VALUES (:a, :b)
    ");
    $upsert->execute([':a' => $a, ':b' => $b]);

    $sel = $db->prepare("SELECT id FROM dm_conversations WHERE user_a = :a AND user_b = :b LIMIT 1");
    $sel->execute([':a' => $a, ':b' => $b]);
    $convId = (int)$sel->fetchColumn();

    // Mark as read up to now
    $db->prepare("
        INSERT INTO dm_reads (user_id, conversation_id, last_read_at)
        VALUES (:uid, :cid, NOW())
        ON DUPLICATE KEY UPDATE last_read_at = NOW()
    ")->execute([':uid' => $me['id'], ':cid' => $convId]);

    // Fetch partner info
    $partnerStmt = $db->prepare("SELECT id, username, full_name, avatar_color_gradient FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1");
    $partnerStmt->execute([':id' => $partnerId]);
    $partner = $partnerStmt->fetch(PDO::FETCH_ASSOC);

    if (!$partner) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        exit;
    }

    // Fetch last 50 messages
    $msgs = $db->prepare("
        SELECT dm.id, dm.sender_id, dm.body, dm.created_at,
               u.username AS sender_username, u.full_name AS sender_name,
               u.avatar_color_gradient AS sender_gradient
        FROM dm_messages dm
        JOIN users u ON u.id = dm.sender_id
        WHERE dm.conversation_id = :cid AND dm.is_deleted = 0
        ORDER BY dm.created_at DESC
        LIMIT 50
    ");
    $msgs->execute([':cid' => $convId]);
    $messages = array_reverse($msgs->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode([
        'success'         => true,
        'conversation_id' => $convId,
        'partner'         => $partner,
        'messages'        => $messages,
    ]);

} catch (Throwable $e) {
    error_log('[dm/open-conversation] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
