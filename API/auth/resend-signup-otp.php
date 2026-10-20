<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/csrf/csrf.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
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

$limiter = new RateLimiter();
$ip = RateLimiter::getIP();
$result = $limiter->attempt('signup_otp_resend', $ip, 3, 600);
if (!$result['allowed']) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'error' => 'Too many verification-code requests. Please wait a few minutes.',
        'retry_after' => $result['retry_after'],
    ]);
    exit;
}

$pendingUserId = (int)($_SESSION['pending_signup_user_id'] ?? 0);
$pendingExpires = (int)($_SESSION['pending_signup_expires'] ?? 0);

if ($pendingUserId <= 0 || $pendingExpires < time()) {
    unset($_SESSION['pending_signup_user_id'], $_SESSION['pending_signup_expires']);
    echo json_encode(['success' => false, 'error' => 'Email verification session expired. Please register again.']);
    exit;
}

try {
    $db = Database::getInstance();
    $stmt = $db->prepare("
        SELECT id, email, full_name, email_verified, status
        FROM users
        WHERE id = :id AND deleted_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([':id' => $pendingUserId]);
    $user = $stmt->fetch();

    if (!$user || (int)$user['email_verified'] === 1) {
        unset($_SESSION['pending_signup_user_id'], $_SESSION['pending_signup_expires']);
        echo json_encode(['success' => false, 'error' => 'Email verification is no longer required.']);
        exit;
    }

    $otpService = new OtpService();
    $otp = $otpService->generate($pendingUserId, 'verify_email');
    $delivery = $otpService->deliver(
        (string)$user['email'],
        (string)$user['full_name'],
        $otp,
        'verify_email'
    );

    // Refresh the server-side verification window on a successful resend.
    if ($delivery['success']) {
        $_SESSION['pending_signup_expires'] = time() + OTP_EXPIRY;
    }

    $response = [
        'success' => $delivery['success'],
        'message' => $delivery['success']
            ? 'A new verification code has been sent.'
            : ($delivery['error'] ?? 'Unable to send the verification code.'),
    ];

    if (APP_DEBUG && isset($delivery['otp_debug'])) {
        $response['otp_debug'] = $delivery['otp_debug'];
    }

    if (!$delivery['success']) {
        http_response_code(502);
    }

    echo json_encode($response);
} catch (Throwable $e) {
    error_log('[API/auth/resend-signup-otp] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to send the verification code.']);
}
