<?php

declare(strict_types=1);

/**
 * API/auth/oauth-callback.php
 *
 * Receives the redirect from Google / Microsoft after the user authorizes.
 * Exchanges the code for tokens, upserts the user, writes the session,
 * then redirects to the appropriate dashboard.
 *
 * Both providers send:  ?code=...&state=...
 * On denial they send: ?error=access_denied&state=...
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/services/OAuthService.php';

AuthMiddleware::startSession();

$provider = strtolower(trim($_GET['provider'] ?? ''));
$code     = trim($_GET['code']  ?? '');
$state    = trim($_GET['state'] ?? '');
$error    = trim($_GET['error'] ?? '');

// ── User denied the SSO consent screen ───────────────────────────────────────
if ($error !== '') {
    $msg = $error === 'access_denied'
        ? 'You cancelled the ' . ucfirst($provider) . ' sign-in.'
        : 'SSO error: ' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8');
    header('Location: ' . BASE_URL . '/modules/auth/login.php?sso_error=' . urlencode($msg));
    exit;
}

// ── Basic validation ──────────────────────────────────────────────────────────
if (!in_array($provider, ['google', 'microsoft'], true) || $code === '') {
    header('Location: ' . BASE_URL . '/modules/auth/login.php?sso_error=' . urlencode(
        'Invalid callback parameters. Please try signing in again.'
    ));
    exit;
}

// ── Process callback ──────────────────────────────────────────────────────────
try {
    $svc    = new OAuthService();
    $result = $svc->handleCallback($provider, $code, $state);
} catch (\Exception $e) {
    $result = ['success' => false, 'error' => 'An unexpected error occurred. Please try again.'];
}

if (!$result['success']) {
    header('Location: ' . BASE_URL . '/modules/auth/login.php?sso_error=' . urlencode($result['error']));
    exit;
}

// ── Success ───────────────────────────────────────────────────────────────────
header('Location: ' . $result['redirect']);
exit;
