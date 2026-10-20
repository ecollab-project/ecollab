<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/security/csrf/csrf.php';
require_once dirname(__DIR__, 2) . '/security/rate-limit/RateLimiter.php';
require_once dirname(__DIR__, 2) . '/services/AuthService.php';

header('Content-Type: application/json; charset=utf-8');
AuthMiddleware::startSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

CSRF::verify();

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$email = strtolower(trim((string)($body['email'] ?? '')));
$otp = preg_replace('/\D/', '', (string)($body['otp'] ?? ''));

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($otp) !== OTP_LENGTH) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid verification request.']);
    exit;
}

$limiter = new RateLimiter();
$ip = RateLimiter::getIP();
$result = $limiter->attempt('signup_email_otp_verify', $ip, 10, 300);
if (!$result['allowed']) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many verification attempts. Please wait a moment.']);
    exit;
}

try {
    $service = new AuthService();
    $outcome = $service->verifyPreSignupEmailOtp($email, $otp);

    if ($outcome['success']) {
        $limiter->clear('signup_email_otp_verify', $ip);
    }

    if (!$outcome['success']) {
        http_response_code(422);
    }

    echo json_encode($outcome);
} catch (Throwable $e) {
    error_log('[API/auth/verify-signup-email-otp] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Verification failed. Please try again.']);
}
