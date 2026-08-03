<?php
declare(strict_types=1);


require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/services/AuthService.php';

header('Content-Type: application/json');

try {
    $service = new AuthService();
    echo json_encode($service->validateSession());
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'authenticated' => false]);
}
