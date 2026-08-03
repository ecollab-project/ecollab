<?php
declare(strict_types=1);


require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'authenticated' => false]);
    exit;
}

// Refresh last_seen_at
try {
    $db = Database::getInstance();
    $db->prepare("UPDATE users SET last_seen_at = NOW() WHERE id = :id")
       ->execute([':id' => $_SESSION['user_id']]);
    $_SESSION['logged_in_at'] = time(); // Slide session

    echo json_encode([
        'success'       => true,
        'authenticated' => true,
        'user_id'       => $_SESSION['user_id'],
        'expires_in'    => SESSION_LIFETIME,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not refresh session.']);
}
