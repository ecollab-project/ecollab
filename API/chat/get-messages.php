<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/services/MessageService.php';
require_once dirname(__DIR__, 2) . '/services/ChannelService.php';
require_once dirname(__DIR__, 2) . '/services/ChatReadService.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);
// These endpoints write no session data; release the per-account lock before DB work.
session_write_close();
header('Cache-Control: no-store, private');

try {
    $channelId = filter_input(INPUT_GET, 'channel_id', FILTER_VALIDATE_INT);
    if (!$channelId) {
        http_response_code(400);
        echo json_encode(['error' => 'channel_id is required']);
        exit;
    }

    $before     = filter_input(INPUT_GET, 'before', FILTER_VALIDATE_INT) ?: null;
    $after      = filter_input(INPUT_GET, 'after', FILTER_VALIDATE_INT);
    $after      = $after !== false && $after !== null && $after >= 0 ? $after : null;
    if ($after !== null) $before = null;
    $requestedLimit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT);
    $limit = MessageService::normalizeLimit($requestedLimit === false || $requestedLimit === null ? 50 : $requestedLimit);
    $pinnedOnly = filter_input(INPUT_GET, 'pinned', FILTER_VALIDATE_INT) === 1;

    $db = Database::getInstance();
    $service = new MessageService();

    if ($pinnedOnly) {
        $messages = $service->getPinnedMessages((int)$channelId, $user['id']);
        echo json_encode(['success' => true, 'messages' => $messages]);
        exit;
    }

    $messages = $service->getMessages((int)$channelId, $user['id'], $before, $limit, $after);
    $reads=new ChatReadService($db);
    $boundary=$before===null&&$after===null?$reads->snapshot('channel',(int)$channelId,(int)$user['id']):[];

    // Mark as read
    $cs = new ChannelService();
    if(($_GET['read']??'1')!=='0'&&$before===null){$reads->mark('channel',(int)$channelId,(int)$user['id'],$messages,$after);$cs->markRead((int)$channelId, $user['id']);}

    echo json_encode([
        'success'  => true,
        'messages' => $messages,
        'first_unread_id' => $boundary['first_unread_id']??null,
        'has_more' => count($messages) >= $limit,
    ]);
} catch (Throwable $e) {
    $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['error' => $code < 500 ? $e->getMessage() : 'Server error']);
}

