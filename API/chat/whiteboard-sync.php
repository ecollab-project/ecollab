<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/services/WhiteboardService.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);

try {
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method !== 'GET') {
        AuthMiddleware::verifyCsrf();
    }

    $channelId = filter_input(INPUT_GET, 'channel_id', FILTER_VALIDATE_INT)
        ?: filter_var(json_decode(file_get_contents('php://input'), true)['channel_id'] ?? 0, FILTER_VALIDATE_INT);

    if (!$channelId) {
        http_response_code(400);
        echo json_encode(['error' => 'channel_id is required']);
        exit;
    }

    $service = new WhiteboardService();

    $action = $_GET['action'] ?? 'state';

    if ($method === 'GET' && $action === 'versions') {
        echo json_encode(['success' => true, 'versions' => $service->listVersions((int)$channelId, (int)$user['id'])]);
    } elseif ($method === 'GET' && $action === 'download') {
        $version = $service->getVersion((int)$channelId, (int)$user['id'], (int)($_GET['version_id'] ?? 0));
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="whiteboard-version-' . (int)$version['version_no'] . '.json"');
        echo $version['state_json'];
    } elseif ($method === 'GET') {
        $state = $service->getState((int)$channelId, $user['id']);
        echo json_encode(['success' => true, 'whiteboard' => $state]);
    } elseif ($method === 'POST') {
        $body      = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = $body['action'] ?? 'sync';
        if ($action === 'lock') {
            $result = $service->setLocked((int)$channelId, (int)$user['id'], (bool)($body['locked'] ?? false));
            echo json_encode(['success' => true, 'whiteboard' => $result]);
            exit;
        }
        if ($action === 'save_version') {
            $result = $service->saveVersion((int)$channelId, (int)$user['id'], (string)($body['title'] ?? ''), (string)($body['state_json'] ?? ''));
            echo json_encode(['success' => true, 'version' => $result]);
            exit;
        }
        if ($action === 'restore_version') {
            $result = $service->restoreVersion((int)$channelId, (int)$user['id'], (int)($body['version_id'] ?? 0));
            echo json_encode(['success' => true, 'whiteboard' => $result]);
            exit;
        }
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
    echo json_encode(['error' => $code < 500 ? $e->getMessage() : 'Server error']);
}
