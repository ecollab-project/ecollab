<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);

try {
    $db          = Database::getInstance();
    $userId      = (int)$user['id'];
    $after       = filter_input(INPUT_GET, 'after', FILTER_VALIDATE_INT) ?: 0;
    $myUsername  = $user['username']  ?? '';
    $myFullName  = $user['full_name'] ?? '';

    // Fetch ALL messages mentioning this user across ALL channels
    // No server_members filter — @mentions work even from channels you can see
    // is_deleted = 0 to exclude soft-deleted messages
    $sql = "
        SELECT
            m.id,
            m.content,
            m.created_at,
            m.channel_id,
            u.username      AS sender_username,
            u.full_name     AS sender_fullname,
            u.avatar_color_gradient,
            c.name          AS channel_name
        FROM messages m
        JOIN users    u ON u.id        = m.sender_id
        LEFT JOIN channels c ON c.id  = m.channel_id
        WHERE m.deleted_at IS NULL
          AND m.sender_id  != :uid
          AND m.id          > :after
          AND (
                m.content LIKE :mu
             OR m.content LIKE :mf
          )
        ORDER BY m.id DESC
        LIMIT 50
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':uid'   => $userId,
        ':after' => $after,
        ':mu'    => '%@' . $myUsername . '%',
        ':mf'    => '%@' . $myFullName . '%',
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $mentions = array_map(fn($r) => [
        'id'        => (int)$r['id'],
        'author'    => $r['sender_fullname'] ?: $r['sender_username'],
        'text'      => $r['content'],
        'channel'   => $r['channel_name'] ?? 'Direct Message',
        'channelId' => (int)$r['channel_id'],
        'time'      => date('h:i A', strtotime($r['created_at'])),
        'letter'    => strtoupper(substr($r['sender_fullname'] ?: $r['sender_username'], 0, 1)),
        'read'      => false,
    ], $rows);

    echo json_encode(['mentions' => $mentions, 'count' => count($mentions)]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'mentions' => []]);
}
