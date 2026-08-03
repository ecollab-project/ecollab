<?php
declare(strict_types=1);


require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/csrf/csrf.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
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

// Rate limit OTP attempts per IP
$limiter = new RateLimiter();
$ip      = RateLimiter::getIP();
$result  = $limiter->attempt('otp_verify', $ip, 10, 300); // 10 attempts per 5 min
if (!$result['allowed']) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many attempts. Please wait a moment.']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$userId = (int)($body['user_id'] ?? 0);
$otp    = preg_replace('/\D/', '', (string)($body['otp'] ?? ''));

if ($userId <= 0 || strlen($otp) !== OTP_LENGTH) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
    exit;
}

try {
    $service = new AuthService();
    $outcome = $service->verifyOtp($userId, $otp);

    if ($outcome['success']) {
        $limiter->clear('otp_verify', $ip);
    }

    echo json_encode($outcome);
} catch (Throwable $e) {
    error_log('[API/auth/verify-otp] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Verification failed. Please try again.']);
}
