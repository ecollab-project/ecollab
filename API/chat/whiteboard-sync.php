<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/services/WhiteboardService.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);

AuthMiddleware::verifyCsrf();

try {
    $method = $_SERVER['REQUEST_METHOD'];

    $channelId = filter_input(INPUT_GET, 'channel_id', FILTER_VALIDATE_INT)
              ?: filter_var(json_decode(file_get_contents('php://input'), true)['channel_id'] ?? 0, FILTER_VALIDATE_INT);

    if (!$channelId) {
        http_response_code(400);
        echo json_encode(['error' => 'channel_id is required']);
        exit;
    }

    $service = new WhiteboardService();

    if ($method === 'GET') {
        $state = $service->getState((int)$channelId, $user['id']);
        echo json_encode(['success' => true, 'whiteboard' => $state]);
    } elseif ($method === 'POST') {
        $body      = json_decode(file_get_contents('php://input'), true) ?? [];
        $stateJson = $body['state_json'] ?? '';
        if ($stateJson === '') {
            http_response_code(400);
            echo json_encode(['error' => 'state_json is required']);
            exit;
        }
        $result = $service->syncState((int)$channelId, $user['id'], $stateJson);
        echo json_encode(['success' => true, 'whiteboard' => $result]);
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Throwable $e) {
    $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['error' => $e->getMessage()]);
}
