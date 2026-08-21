<?php

declare(strict_types=1);


require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/security/csrf/csrf.php';
require_once dirname(__DIR__, 2) . '/security/rate-limit/RateLimiter.php';
require_once dirname(__DIR__, 2) . '/security/AuditLogger.php';
require_once dirname(__DIR__, 2) . '/security/SecurityHeaders.php';
require_once dirname(__DIR__, 2) . '/services/AuthService.php';

header('Content-Type: application/json');
SecurityHeaders::send(isApi: true);
AuthMiddleware::startSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

CSRF::verify();

$body = json_decode(file_get_contents('php://input'), true) ?? [];

$identifier = trim((string)($body['identifier'] ?? ''));
$password   = (string)($body['password']   ?? '');
$remember   = !empty($body['remember']);

// Rate limit by IP + identifier
$limiter = new RateLimiter();
$ip      = RateLimiter::getIP();
$result  = $limiter->attempt('login', $ip, RATE_LIMIT_LOGIN, RATE_LIMIT_WINDOW);
if (!$result['allowed']) {
    http_response_code(429);
    echo json_encode([
        'success'     => false,
        'error'       => 'Too many login attempts. Please try again in ' . ceil($result['retry_after'] / 60) . ' minute(s).',
        'retry_after' => $result['retry_after'],
    ]);
    exit;
}

try {
    $service = new AuthService();
    $outcome = $service->login($identifier, $password, $remember);

    if ($outcome['success']) {
        $limiter->clear('login', $ip);
        CSRF::regenerate();

        $role     = $outcome['role'];
        // Determine redirect based on role
        $redirect = match (true) {
            in_array($role, ['admin', 'super_admin', 'moderator']) => BASE_URL . '/modules/admin/dashboard.php',
            $role === 'facilitator' => BASE_URL . '/modules/facilitator/dashboard.php',
            default => BASE_URL . '/modules/chat/chat.php',
        };
        // Allow ?next= override (e.g. from AuthMiddleware redirect)
        $next = trim($_GET['next'] ?? '');
        if ($next !== '' && str_starts_with($next, '/') && !str_contains($next, '..')) {
            $redirect = BASE_URL . $next;
        }

        echo json_encode(['success' => true, 'redirect' => $redirect, 'role' => $role]);
    } else {
        http_response_code(401);
        // Do not reveal whether the supplied identifier belongs to an account.
        // Preserve lockout/disabled/SSO messages because they do not distinguish
        // an existing account from a nonexistent one during normal login failure.
        $error = (string)($outcome['error'] ?? '');
        if (str_starts_with($error, 'No account found with that email or Student ID.')
            || str_starts_with($error, 'Incorrect password.')) {
            $error = 'Invalid credentials. Please check your email/Student ID and password.';
        }
        echo json_encode(['success' => false, 'error' => $error]);
    }
} catch (Throwable $e) {
    error_log('[API/auth/login] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'An unexpected error occurred. Please try again.']);
}
