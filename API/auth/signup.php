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

// Rate limit by IP
$limiter = new RateLimiter();
$ip      = RateLimiter::getIP();
$result  = $limiter->attempt('signup', $ip, RATE_LIMIT_SIGNUP, RATE_LIMIT_WINDOW);
if (!$result['allowed']) {
    http_response_code(429);
    echo json_encode([
        'success'     => false,
        'error'       => 'Too many registrations from your IP. Please wait ' . ceil($result['retry_after'] / 60) . ' minute(s).',
        'retry_after' => $result['retry_after'],
    ]);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON request.']);
    exit;
}

// Basic input sanitization. Keep the complete onboarding payload so the
// service can persist Focus Areas, Interests, Availability and Hobbies.
$data = [
    'full_name'      => strip_tags(trim((string)($body['full_name'] ?? ''))),
    'email'          => strtolower(trim((string)($body['email'] ?? ''))),
    'password'       => (string)($body['password'] ?? ''),
    'course'         => strip_tags(trim((string)($body['course'] ?? ''))),
    'year_level'     => (int)($body['year_level'] ?? 0),
    'study_style'    => strip_tags(trim((string)($body['study_style'] ?? ''))),
    'primary_goal'   => strip_tags(trim((string)($body['primary_goal'] ?? ''))),
    'interests'      => array_map('strip_tags', (array)($body['interests'] ?? [])),
    'collab_style'   => array_map('strip_tags', (array)($body['collab_style'] ?? [])),
    'goals'          => array_map('strip_tags', (array)($body['goals'] ?? [])),
    'availability'   => array_map('strip_tags', (array)($body['availability'] ?? [])),
    'hobbies'        => is_array($body['hobbies'] ?? null) ? $body['hobbies'] : [],
    'terms_agreed'   => !empty($body['terms_agreed']),
];

// Signup verification is intentionally enforced at the API boundary. The
// OTP is bound to the current session and the exact email that requested it,
// so the client cannot bypass verification by omitting the OTP field.
$otp = preg_replace('/\D/', '', (string)($body['otp'] ?? ''));
$pendingOtpHash = (string)($_SESSION['pending_otp'] ?? '');
$pendingOtpEmail = strtolower(trim((string)($_SESSION['pending_otp_email'] ?? '')));
$pendingOtpExpiry = (int)($_SESSION['pending_otp_expiry'] ?? 0);

if ($otp === '' || !preg_match('/^\d{6}$/', $otp)
    || $pendingOtpHash === ''
    || $pendingOtpEmail === ''
    || !hash_equals($pendingOtpEmail, $data['email'])
    || $pendingOtpExpiry < time()
    || !password_verify($otp, $pendingOtpHash)
) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error'   => 'Please verify your email with the current 6-digit code before creating your account.',
        'field'   => 'otp',
    ]);
    exit;
}

try {
    $service = new AuthService();
    $outcome = $service->register($data);

    if ($outcome['success']) {
        // Persist the successful verification and invalidate the session OTP.
        $db = Database::getInstance();
        $db->prepare('UPDATE users SET email_verified=1 WHERE id=:id')
            ->execute([':id' => (int)$outcome['user_id']]);
        unset($_SESSION['pending_otp'], $_SESSION['pending_otp_email'], $_SESSION['pending_otp_expiry']);

        CSRF::regenerate();
        echo json_encode([
            'success'  => true,
            'redirect' => BASE_URL . '/modules/onboarding/server-discovery.php',
            'username' => $outcome['username'],
        ]);
    } else {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'error'   => $outcome['error'],
            'field'   => $outcome['field'] ?? null,
        ]);
    }
} catch (Throwable $e) {
    error_log('[API/auth/signup] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Registration failed. Please try again.']);
}
