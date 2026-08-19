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

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$convId = (int)($body['conversation_id'] ?? 0);
$text   = trim($body['body'] ?? '');

if (!$convId || $text === '' || mb_strlen($text) > 4000) {
    http_response_code(400);
    echo json_encode(['error' => 'conversation_id and non-empty body (max 4000 chars) required']);
    exit;
}

try {
    $db = Database::getInstance();

    // Verify this user is part of the conversation.
    $check = $db->prepare("
        SELECT id, user_a, user_b FROM dm_conversations
        WHERE id = :cid AND (user_a = :me OR user_b = :me2)
        LIMIT 1
    ");
    $check->execute([':cid' => $convId, ':me' => $me['id'], ':me2' => $me['id']]);
    $conv = $check->fetch(PDO::FETCH_ASSOC);

    if (!$conv) {
        http_response_code(403);
        echo json_encode(['error' => 'Conversation not found or access denied']);
        exit;
    }

    // Insert message.
    $ins = $db->prepare("
        INSERT INTO dm_messages (conversation_id, sender_id, body)
        VALUES (:cid, :uid, :body)
    ");
    $ins->execute([':cid' => $convId, ':uid' => $me['id'], ':body' => $text]);
    $msgId = (int)$db->lastInsertId();

    // Update conversation last_message.
    $db->prepare("
        UPDATE dm_conversations
        SET last_message = :body, last_msg_at = NOW()
        WHERE id = :cid
    ")->execute([
        ':body' => mb_substr($text, 0, 120),
        ':cid'  => $convId,
    ]);

    // Mark sender as read.
    $db->prepare("
        INSERT INTO dm_reads (user_id, conversation_id, last_read_at)
        VALUES (:uid, :cid, NOW())
        ON DUPLICATE KEY UPDATE last_read_at = NOW()
    ")->execute([':uid' => $me['id'], ':cid' => $convId]);

    // Create a notification using the canonical notifications schema.
    $recipientId = ($conv['user_a'] == $me['id'])
        ? (int)$conv['user_b']
        : (int)$conv['user_a'];

    $db->prepare("
        INSERT INTO notifications
            (recipient_id, actor_id, type, title, body, link_url, is_read)
        VALUES
            (:recipient_id, :actor_id, 'message', :title, :body, :link_url, 0)
    ")->execute([
        ':recipient_id' => $recipientId,
        ':actor_id'     => $me['id'],
        ':title'        => ($me['full_name'] ?: $me['username']) . ' sent you a message',
        ':body'         => mb_substr($text, 0, 120),
        ':link_url'     => '/ecollab/?conversation_id=' . $convId,
    ]);

    echo json_encode([
        'success'      => true,
        'message_id'   => $msgId,
        'sender_id'    => $me['id'],
        'body'         => $text,
        'created_at'   => date('Y-m-d H:i:s'),
        'recipient_id' => $recipientId,
    ]);

} catch (Throwable $e) {
    error_log('[dm/send-message] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
