<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/services/MessageService.php';

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
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $messageId = filter_var($body['message_id'] ?? 0, FILTER_VALIDATE_INT);

    if (!$messageId) {
        http_response_code(400);
        echo json_encode(['error' => 'message_id is required']);
        exit;
    }

    $service = new MessageService();
    $message = $service->pinMessage((int)$messageId, (int)$user['id'], $user['role']);

    echo json_encode(['success' => true, 'message' => $message]);
} catch (Throwable $e) {
    $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['error' => $code < 500 ? $e->getMessage() : 'Server error']);
}
