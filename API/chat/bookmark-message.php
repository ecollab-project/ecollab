<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);
$uid = (int)$user['id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}
AuthMiddleware::verifyCsrf();

try {
    $db = Database::getInstance();
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $messageId = (int)($body['message_id'] ?? 0);
    if (!$messageId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'message_id required']);
        exit;
    }

    // Only allow bookmarking messages in a channel the user actually has access to.
    $access = $db->prepare("
        SELECT 1 FROM messages m
        JOIN channels c ON c.id = m.channel_id
        LEFT JOIN server_members sm ON sm.server_id = c.server_id AND sm.user_id = :uid
        LEFT JOIN channel_members cm ON cm.channel_id = c.id AND cm.user_id = :uid2
        WHERE m.id = :mid AND m.is_deleted = 0
          AND (c.is_private = 0 OR cm.user_id IS NOT NULL OR sm.server_role IN ('owner','admin','moderator'))
          AND sm.user_id IS NOT NULL
        LIMIT 1
    ");
    $access->execute([':uid' => $uid, ':uid2' => $uid, ':mid' => $messageId]);
    if (!$access->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Message not found']);
        exit;
    }

    $existing = $db->prepare('SELECT 1 FROM message_bookmarks WHERE user_id = :uid AND message_id = :mid');
    $existing->execute([':uid' => $uid, ':mid' => $messageId]);

    if ($existing->fetchColumn()) {
        $db->prepare('DELETE FROM message_bookmarks WHERE user_id = :uid AND message_id = :mid')
            ->execute([':uid' => $uid, ':mid' => $messageId]);
        echo json_encode(['success' => true, 'bookmarked' => false]);
    } else {
        $db->prepare('INSERT INTO message_bookmarks (user_id, message_id) VALUES (:uid, :mid)')
            ->execute([':uid' => $uid, ':mid' => $messageId]);
        echo json_encode(['success' => true, 'bookmarked' => true]);
    }
} catch (Throwable $e) {
    error_log('[bookmark-message] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : 'Server error']);
}
