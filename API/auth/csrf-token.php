<?php
declare(strict_types=1);


require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/security/csrf/csrf.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();

// Return (or regenerate) a CSRF token — used by SPA or AJAX fetches
echo json_encode([
    'success' => true,
    'token'   => CSRF::token(),
]);
