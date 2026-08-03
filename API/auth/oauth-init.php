<?php

declare(strict_types=1);

/**
 * API/auth/oauth-init.php
 *
 * Entry point for SSO. Called by the login page buttons.
 * Builds the provider auth URL and redirects the browser to it.
 *
 * Usage: GET /API/auth/oauth-init.php?provider=google
 *        GET /API/auth/oauth-init.php?provider=microsoft
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/services/OAuthService.php';

AuthMiddleware::startSession();

// Already logged in? Send them straight to chat.
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/modules/chat/chat.php');
    exit;
}

$provider = strtolower(trim($_GET['provider'] ?? ''));

if (!in_array($provider, ['google', 'microsoft'], true)) {
    http_response_code(400);
    echo 'Invalid provider. Use ?provider=google or ?provider=microsoft';
    exit;
}

// Check credentials are configured
$configured = match ($provider) {
    'google'    => GOOGLE_CLIENT_ID    !== '' && GOOGLE_CLIENT_SECRET    !== '',
    'microsoft' => MICROSOFT_CLIENT_ID !== '' && MICROSOFT_CLIENT_SECRET !== '',
};

if (!$configured) {
    // Redirect back to login with a friendly error
    header('Location: ' . BASE_URL . '/modules/auth/login.php?sso_error=' . urlencode(
        ucfirst($provider) . ' SSO is not configured yet. Please contact the administrator.'
    ));
    exit;
}

try {
    $svc = new OAuthService();
    $url = $svc->getAuthUrl($provider);
    header('Location: ' . $url);
    exit;
} catch (\Exception $e) {
    header('Location: ' . BASE_URL . '/modules/auth/login.php?sso_error=' . urlencode(
        'Could not connect to ' . ucfirst($provider) . '. Please try again.'
    ));
    exit;
}
