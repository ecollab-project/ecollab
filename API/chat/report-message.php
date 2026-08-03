<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

AuthMiddleware::verifyCsrf();

try {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $messageId  = filter_var($body['message_id'] ?? 0, FILTER_VALIDATE_INT);
    $reason     = $body['reason'] ?? 'other';
    $description = trim($body['description'] ?? '');

    $validReasons = ['spam', 'harassment', 'inappropriate', 'phishing', 'other'];
    if (!$messageId) {
        http_response_code(400);
        echo json_encode(['error' => 'message_id is required']);
        exit;
    }
    if (!in_array($reason, $validReasons, true)) {
        $reason = 'other';
    }

    $db = Database::getInstance();

    // Get the message + reported user
    $msgStmt = $db->prepare("SELECT sender_id, channel_id FROM messages WHERE id = :id AND is_deleted = 0 LIMIT 1");
    $msgStmt->execute([':id' => $messageId]);
    $msg = $msgStmt->fetch();

    if (!$msg) {
        http_response_code(404);
        echo json_encode(['error' => 'Message not found']);
        exit;
    }

    // Prevent reporting own messages
    if ((int)$msg['sender_id'] === (int)$user['id']) {
        http_response_code(400);
        echo json_encode(['error' => 'You cannot report your own message']);
        exit;
    }

    // Prevent duplicate reports from same user
    $dupStmt = $db->prepare("SELECT id FROM content_reports WHERE reporter_id = :rid AND message_id = :mid LIMIT 1");
    $dupStmt->execute([':rid' => $user['id'], ':mid' => $messageId]);
    if ($dupStmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'You have already reported this message']);
        exit;
    }

    // Get server_id from channel
    $chanStmt = $db->prepare("SELECT server_id FROM channels WHERE id = :cid LIMIT 1");
    $chanStmt->execute([':cid' => $msg['channel_id']]);
    $chan = $chanStmt->fetch();

    $db->prepare("
        INSERT INTO content_reports (reporter_id, reported_user_id, message_id, server_id, reason, description, status, created_at)
        VALUES (:reporter, :reported, :mid, :sid, :reason, :desc, 'pending', NOW())
    ")->execute([
        ':reporter' => $user['id'],
        ':reported' => $msg['sender_id'],
        ':mid'      => $messageId,
        ':sid'      => $chan['server_id'] ?? null,
        ':reason'   => $reason,
        ':desc'     => $description ?: null,
    ]);

    echo json_encode(['success' => true, 'message' => 'Report submitted successfully']);

} catch (Throwable $e) {
    error_log('[report-message] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'Server error']);
}
