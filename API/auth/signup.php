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

$body = json_decode(file_get_contents('php://input'), true) ?? [];

// Basic input sanitization
$data = [
    'full_name'    => strip_tags(trim((string)($body['full_name']    ?? ''))),
    'email'        => strtolower(trim((string)($body['email']        ?? ''))),
    'password'     => (string)($body['password']     ?? ''),
    'course'       => strip_tags(trim((string)($body['course']       ?? ''))),
    'year_level'   => (int)($body['year_level']      ?? 0),
    'study_style'  => strip_tags(trim((string)($body['study_style']  ?? ''))),
    'primary_goal' => strip_tags(trim((string)($body['primary_goal'] ?? ''))),
    'interests'    => array_map('strip_tags', (array)($body['interests'] ?? [])),
    'terms_agreed' => !empty($body['terms_agreed']),
];

try {
    $service = new AuthService();
    $outcome = $service->register($data);

    if ($outcome['success']) {
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
