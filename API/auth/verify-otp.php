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

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$otp  = preg_replace('/\D/', '', (string)($body['otp'] ?? ''));

// Pending signup/login verification is always bound to the server-side
// session. The client cannot select another account or verification action.
$pendingSignupUserId = (int)($_SESSION['pending_signup_user_id'] ?? 0);
$pendingSignupExpires = (int)($_SESSION['pending_signup_expires'] ?? 0);
$pendingLoginUserId = (int)($_SESSION['pending_login_user_id'] ?? 0);
$pendingLoginExpires = (int)($_SESSION['pending_login_expires'] ?? 0);

if ($pendingSignupUserId > 0 && $pendingSignupExpires >= time()) {
    $userId = $pendingSignupUserId;
    $action = 'verify_email';
} elseif ($pendingLoginUserId > 0 && $pendingLoginExpires >= time()) {
    $userId = $pendingLoginUserId;
    $action = '2fa';
} else {
    $userId = (int)($body['user_id'] ?? 0);
    $action = 'reset_password';
}

if ($userId <= 0 || strlen($otp) !== OTP_LENGTH) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
    exit;
}

try {
    $service = new AuthService();
    $outcome = $service->verifyOtp($userId, $otp, $action);

    if ($outcome['success']) {
        $limiter->clear('otp_verify', $ip);
    }

    echo json_encode($outcome);
} catch (Throwable $e) {
    error_log('[API/auth/verify-otp] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Verification failed. Please try again.']);
}
