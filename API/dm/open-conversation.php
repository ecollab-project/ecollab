<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
header('Content-Type: application/json');
AuthMiddleware::startSession();
$me = AuthMiddleware::requireAuth(true);
$partnerId = (int)($_GET['partner_id'] ?? 0);

if (!$partnerId || $partnerId === (int)$me['id']) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid partner_id']);
    exit;
}

try {
    $db = Database::getInstance();

    $partnerStmt = $db->prepare(
        'SELECT id, username, full_name, avatar_color_gradient
         FROM users
         WHERE id = :id AND deleted_at IS NULL
         LIMIT 1'
    );
    $partnerStmt->execute([':id' => $partnerId]);
    $partner = $partnerStmt->fetch(PDO::FETCH_ASSOC);
    $partnerStmt->closeCursor();

    if (!$partner) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        exit;
    }

    $friend = $db->prepare(
        "SELECT status
         FROM friendships
         WHERE ((requester_id = :me AND addressee_id = :them)
            OR (requester_id = :them2 AND addressee_id = :me2))
         LIMIT 1"
    );
    $friend->execute([
        ':me'   => $me['id'],
        ':them' => $partnerId,
        ':them2' => $partnerId,
        ':me2'  => $me['id'],
    ]);
    $friendStatus = $friend->fetchColumn();
    $friend->closeCursor();

    // user_settings uses allow_dm (not direct_messages).
    // A missing settings row means the platform default is to allow DMs.
    $pref = $db->prepare(
        'SELECT allow_dm FROM user_settings WHERE user_id = :id LIMIT 1'
    );
    $pref->execute([':id' => $partnerId]);
    $allow = $pref->fetchColumn();
    $pref->closeCursor();

    if ($friendStatus !== 'accepted' && $allow !== false && (int)$allow === 0) {
        http_response_code(403);
        echo json_encode(['error' => 'This user is not accepting direct messages']);
        exit;
    }

    $a = min((int)$me['id'], $partnerId);
    $b = max((int)$me['id'], $partnerId);

    $upsert = $db->prepare(
        'INSERT IGNORE INTO dm_conversations (user_a, user_b) VALUES (:a, :b)'
    );
    $upsert->execute([':a' => $a, ':b' => $b]);
    $upsert->closeCursor();

    $sel = $db->prepare(
        'SELECT id FROM dm_conversations WHERE user_a = :a AND user_b = :b LIMIT 1'
    );
    $sel->execute([':a' => $a, ':b' => $b]);
    $convId = (int)$sel->fetchColumn();
    $sel->closeCursor();

    if (!$convId) {
        throw new RuntimeException('Unable to create direct-message conversation');
    }

    $readStmt = $db->prepare(
        'INSERT INTO dm_reads (user_id, conversation_id, last_read_at)
         VALUES (:uid, :cid, NOW())
         ON DUPLICATE KEY UPDATE last_read_at = NOW()'
    );
    $readStmt->execute([':uid' => $me['id'], ':cid' => $convId]);
    $readStmt->closeCursor();

    $msgs = $db->prepare(
        'SELECT dm.id, dm.sender_id, dm.body, dm.created_at,
                u.username AS sender_username,
                u.full_name AS sender_name,
                u.avatar_color_gradient AS sender_gradient
         FROM dm_messages dm
         JOIN users u ON u.id = dm.sender_id
         WHERE dm.conversation_id = :cid AND dm.is_deleted = 0
         ORDER BY dm.created_at DESC
         LIMIT 50'
    );
    $msgs->execute([':cid' => $convId]);
    $messages = array_reverse($msgs->fetchAll(PDO::FETCH_ASSOC));
    $msgs->closeCursor();

    echo json_encode([
        'success' => true,
        'conversation_id' => $convId,
        'partner' => $partner,
        'friend_status' => $friendStatus ?: 'none',
        'messages' => $messages,
    ]);
} catch (Throwable $e) {
    error_log('[dm/open-conversation] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
