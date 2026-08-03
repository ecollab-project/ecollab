<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/services/MessageService.php';
require_once dirname(__DIR__, 2) . '/services/ChannelService.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);

try {
    $channelId = filter_input(INPUT_GET, 'channel_id', FILTER_VALIDATE_INT);
    if (!$channelId) {
        http_response_code(400);
        echo json_encode(['error' => 'channel_id is required']);
        exit;
    }

    $before   = filter_input(INPUT_GET, 'before', FILTER_VALIDATE_INT) ?: null;
    $limit    = filter_input(INPUT_GET, 'limit',  FILTER_VALIDATE_INT) ?: 50;
    $pinnedOnly = filter_input(INPUT_GET, 'pinned', FILTER_VALIDATE_INT) === 1;

    $service  = new MessageService();

    if ($pinnedOnly) {
        // Return only pinned messages for this channel (used by the pin-icon modal)
        $messages = $service->getPinnedMessages((int)$channelId, $user['id']);
        echo json_encode(['success' => true, 'messages' => $messages]);
        exit;
    }

    $messages = $service->getMessages((int)$channelId, $user['id'], $before, $limit);

    // Mark as read
    $cs = new ChannelService();
    $cs->markRead((int)$channelId, $user['id']);

    echo json_encode([
        'success'  => true,
        'messages' => $messages,
        'has_more' => count($messages) >= $limit,
    ]);
} catch (Throwable $e) {
    $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['error' => $e->getMessage()]);
}
