<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

require_once dirname(__DIR__, 2) . '/services/ChatMentions.php';
header('Content-Type: application/json');
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);

try {
    session_write_close();
    header('Cache-Control: no-store, private');
    $after = max(0, filter_input(INPUT_GET, 'after', FILTER_VALIDATE_INT) ?: 0);
    $rows = ChatMentions::inbox(Database::getInstance(), $user, $after, 50);
    $mentions = array_map(static fn(array $row): array => [
        'id' => (int)$row['id'],
        'author' => $row['author'],
        'text' => ChatMentions::plainText($row['content']),
        'channel' => $row['channel'],
        'channelId' => (int)$row['channel_id'],
        'time' => date('h:i A', strtotime($row['created_at'])),
        'letter' => mb_strtoupper(mb_substr($row['author'], 0, 1)),
        'read' => false,
    ], $rows);

    echo json_encode(['mentions' => $mentions, 'count' => count($mentions)]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'mentions' => []]);
}
