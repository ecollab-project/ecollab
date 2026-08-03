<?php
declare(strict_types=1);


require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/security/csrf/csrf.php';
require_once dirname(__DIR__, 2) . '/security/rate-limit/RateLimiter.php';
require_once dirname(__DIR__, 2) . '/services/AuthService.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

CSRF::verify();

$limiter = new RateLimiter();
$ip      = RateLimiter::getIP();
$result  = $limiter->attempt('forgot', $ip, RATE_LIMIT_FORGOT, RATE_LIMIT_WINDOW);
if (!$result['allowed']) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'error'   => 'Too many requests. Please wait ' . ceil($result['retry_after'] / 60) . ' minute(s).',
    ]);
    exit;
}

$body  = json_decode(file_get_contents('php://input'), true) ?? [];
$email = strtolower(trim((string)($body['email'] ?? '')));

try {
    $service = new AuthService();
    $outcome = $service->forgotPassword($email);
    echo json_encode($outcome);
} catch (Throwable $e) {
    error_log('[API/auth/forgot-password] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'An error occurred. Please try again.']);
}
