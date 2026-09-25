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
$fullName = strip_tags(trim((string)($body['full_name'] ?? '')));

$limiter = new RateLimiter();
$ip = RateLimiter::getIP();
$result = $limiter->attempt('signup_email_otp', $ip, 5, 600);
if (!$result['allowed']) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'error' => 'Too many verification-code requests. Please wait before requesting another code.',
        'retry_after' => $result['retry_after'],
    ]);
    exit;
}

try {
    $service = new AuthService();
    $outcome = $service->sendPreSignupEmailOtp($email, $fullName);

    if (!$outcome['success']) {
        http_response_code(422);
        echo json_encode($outcome);
        exit;
    }

    echo json_encode([
        'success' => true,
        'otp_required' => true,
        'mail_sent' => true,
        'email' => $outcome['email'],
        'otp_debug' => $outcome['otp_debug'] ?? null,
    ]);
} catch (Throwable $e) {
    error_log('[API/auth/send-signup-email-otp] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to send the verification code. Please try again.']);
}
