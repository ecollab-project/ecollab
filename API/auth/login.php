<?php
declare(strict_types=1);

header('Content-Type: application/json');
$requestId = bin2hex(random_bytes(8));
$stage = 'bootstrap';
try {
    require_once dirname(__DIR__, 2) . '/config.php';
    require_once dirname(__DIR__, 2) . '/database/config/db.php';
    require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
    require_once dirname(__DIR__, 2) . '/security/csrf/csrf.php';
    require_once dirname(__DIR__, 2) . '/security/rate-limit/RateLimiter.php';
    require_once dirname(__DIR__, 2) . '/security/AuditLogger.php';
    require_once dirname(__DIR__, 2) . '/security/SecurityHeaders.php';

    SecurityHeaders::send(isApi: true);
    AuthMiddleware::startSession();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
        exit;
    }

    CSRF::verify();

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)
        || !is_string($body['identifier'] ?? null)
        || !is_string($body['password'] ?? null)
        || trim($body['identifier']) === '' || $body['password'] === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Email or Student ID and password are required.']);
        exit;
    }
    $identifier = trim($body['identifier']);
    $password = $body['password'];
    $remember = !empty($body['remember']);
    $stage = 'rate_limit';

    // Rate limit this specific login identity.
    // Do NOT use the IP alone here: users on the same school/home/public network
    // must not share the 3-attempt login lockout.
    $limiter  = new RateLimiter();
    $ip       = RateLimiter::getIP();
    $loginKey = hash('sha256', $ip . '|' . strtolower($identifier));
    $result   = $limiter->attempt('login', $loginKey, RATE_LIMIT_LOGIN, RATE_LIMIT_WINDOW);
    if (!$result['allowed']) {
        http_response_code(429);
        echo json_encode([
            'success'     => false,
            'error'       => 'Too many login attempts. Please try again in ' . ceil($result['retry_after'] / 60) . ' minute(s).',
            'retry_after' => $result['retry_after'],
        ]);
        exit;
    }

    $stage = 'authenticate';
    require_once dirname(__DIR__, 2) . '/services/AuthService.php';
    $service = new AuthService();
    $outcome = $service->login($identifier, $password, $remember, false);

    if ($outcome['success']) {
        // Password was accepted and the authenticated session is created immediately.
        // Signup email verification is handled once during registration; normal
        // password login does not repeat that verification step.
        if (!empty($outcome['otp_required'])) {
            $response = [
                'success'      => true,
                'otp_required' => true,
                'message'      => 'A verification code has been sent to your email.',
                'user_id'      => (int)$outcome['user']['id'],
            ];
            if (APP_DEBUG && isset($outcome['otp_debug'])) {
                $response['otp_debug'] = $outcome['otp_debug'];
            }
            echo json_encode($response);
            exit;
        }

        // Only clear the password-login limiter after the full authentication flow.
        $limiter->clear('login', $loginKey);
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
        echo json_encode(['success' => false, 'error' => $outcome['error']]);
    }
} catch (Throwable $e) {
    // Keep credentials, SQL text and connection details out of both responses
    // and routine incident logs. The stage, location and code identify the fault.
    error_log(sprintf('[API/auth/login] request_id=%s stage=%s type=%s code=%s file=%s line=%d',
        $requestId, $stage, get_class($e), (string)$e->getCode(),
        basename($e->getFile()), $e->getLine()));
    http_response_code($e instanceof PDOException ? 503 : 500);
    echo json_encode(['success' => false,
        'error' => 'Sign-in is temporarily unavailable. Reference: ' . $requestId,
        'request_id' => $requestId]);
}
