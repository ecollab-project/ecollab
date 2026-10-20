<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/services/ChannelAccessService.php';
require_once dirname(__DIR__, 2) . '/services/LiveKitService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    AuthMiddleware::startSession();
    $user = AuthMiddleware::requireAuth(true);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    AuthMiddleware::verifyCsrf();
    // Authentication and CSRF are complete; release this account’s session lock before I/O.
    session_write_close();

    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new InvalidArgumentException('Invalid JSON body.');
    }

    $channelId = (int)($input['channel_id'] ?? 0);
    if ($channelId <= 0) {
        throw new InvalidArgumentException('A valid channel_id is required.');
    }

    $channel = (new ChannelAccessService(Database::getInstance()))->voice($channelId, (int)$user['id']);

    $livekit = new LiveKitService();
    $result = $livekit->issueRoomToken($user, $channelId, (string)$channel['name']);
    echo json_encode(['success' => true] + $result, JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[livekit-token] ' . $e->getMessage());
    $code = $e->getCode() === 403 ? 403 : 503;
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $code === 403 ? 'Voice channel access denied.' : 'Voice service is temporarily unavailable.']);
}


