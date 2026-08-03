<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/services/ChannelService.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);

try {
    $serverId = filter_input(INPUT_GET, 'server_id', FILTER_VALIDATE_INT);
    if (!$serverId) {
        http_response_code(400);
        echo json_encode(['error' => 'server_id is required']);
        exit;
    }

    $service  = new ChannelService();
    $channels = $service->getChannelsForUser((int)$serverId, $user['id']);
    $servers  = $service->getServersForUser($user['id']);

    echo json_encode([
        'success'  => true,
        'channels' => $channels,
        'servers'  => $servers,
    ]);
} catch (Throwable $e) {
    $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['error' => $e->getMessage()]);
}
