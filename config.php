<?php

declare(strict_types=1);

/**
 * config.php — Unified global configuration for Ecollab (auth + chat).
 * Single source of truth for all constants used across both modules.
 */

// ── Load .env ────────────────────────────────────────────────────────────────
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $val] = array_map('trim', explode('=', $line, 2));
        $val = trim($val, "\"'");
        // An already-set real environment variable takes precedence over .env.
        if (!array_key_exists($key, $_ENV) && getenv($key) === false) {
            $_ENV[$key] = $val;
            putenv("$key=$val");
        }
    }
}

function env(string $key, mixed $default = null): mixed
{
    return $_ENV[$key] ?? getenv($key) ?: $default;
}

function guessAppUrl(): string
{
    if (empty($_SERVER['HTTP_HOST'])) {
        return 'https://ecollab.io';
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'];
    $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
    $rootDir = realpath(__DIR__) ?: '';

    if ($docRoot !== '' && $rootDir !== '' && str_starts_with($rootDir, $docRoot)) {
        $relative = trim(str_replace('\\', '/', substr($rootDir, strlen($docRoot))), '/');
        return $scheme . '://' . $host . ($relative ? '/' . $relative : '');
    }

    return $scheme . '://' . $host;
}

/**
 * Resolve the application URL for the current runtime.
 *
 * On XAMPP/local Apache, the actual folder is authoritative. This prevents a
 * stale APP_URL from an older checkout (for example /ecollab_sample5/ecollab)
 * from breaking every CSS/JS asset and every authentication redirect.
 * Production hosts continue to use the configured APP_URL.
 */
function resolveAppUrl(): string
{
    $configured = trim((string)env('APP_URL', ''));

    if (!empty($_SERVER['HTTP_HOST'])) {
        $host = strtolower((string)$_SERVER['HTTP_HOST']);
        $host = preg_replace('/:\d+$/', '', $host) ?: $host;

        $isLocalHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.local');

        if ($isLocalHost) {
            return rtrim(guessAppUrl(), '/');
        }
    }

    return rtrim($configured !== '' ? $configured : guessAppUrl(), '/');
}

function isLocalRuntime(): bool
{
    if (empty($_SERVER['HTTP_HOST'])) {
        return false;
    }

    $host = strtolower((string)$_SERVER['HTTP_HOST']);
    $host = preg_replace('/:\d+$/', '', $host) ?: $host;

    return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
        || str_ends_with($host, '.local');
}

// ── Application ─────────────────────────────────────────────────────────────
define('APP_NAME',    env('APP_NAME',    'Ecollab'));
define('APP_ENV',     env('APP_ENV',     'production'));
define('APP_URL',     resolveAppUrl());
define('APP_DEBUG',   env('APP_DEBUG',   'false') === 'true');
define('APP_KEY',     env('APP_KEY',     ''));
define('ROOT_PATH',   __DIR__);
define('BASE_URL',    rtrim(APP_URL, '/'));

// ── Database ──────────────────────────────────────────────────────────────────
define('DB_HOST',    env('DB_HOST',    '127.0.0.1'));
define('DB_PORT',    env('DB_PORT',    '3306'));
define('DB_NAME',    env('DB_NAME',    'ecollab'));
define('DB_USER',    env('DB_USER',    'root'));
define('DB_PASS',    env('DB_PASS',    ''));
define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));

// ── Session ───────────────────────────────────────────────────────────────────
define('SESSION_LIFETIME', (int)env('SESSION_LIFETIME', 3600));
define('SESSION_SECURE',   env('SESSION_SECURE',   'false') === 'true');
define('SESSION_SAMESITE', env('SESSION_SAMESITE', 'Lax'));

// ── Security ──────────────────────────────────────────────────────────────────
define('BCRYPT_COST',        (int)env('BCRYPT_COST',        12));
define('CSRF_TOKEN_LENGTH',  (int)env('CSRF_TOKEN_LENGTH',  32));

// ── Rate Limiting ─────────────────────────────────────────────────────────────
define('RATE_LIMIT_LOGIN',   (int)env('RATE_LIMIT_LOGIN',   10));
define('RATE_LIMIT_SIGNUP',  (int)env('RATE_LIMIT_SIGNUP',  5));
define('RATE_LIMIT_FORGOT',  (int)env('RATE_LIMIT_FORGOT',  5));
define('RATE_LIMIT_WINDOW',  (int)env('RATE_LIMIT_WINDOW',  900));

// ── OTP ───────────────────────────────────────────────────────────────────────
define('OTP_EXPIRY', (int)env('OTP_EXPIRY', 600));
define('OTP_LENGTH', (int)env('OTP_LENGTH', 6));

// ── Mail ──────────────────────────────────────────────────────────────────────
define('MAIL_HOST',      env('MAIL_HOST',      'localhost'));
define('MAIL_PORT',      (int)env('MAIL_PORT', 587));
define('MAIL_USER',      env('MAIL_USER',      ''));
define('MAIL_PASS',      env('MAIL_PASS',      ''));
define('MAIL_FROM',      env('MAIL_FROM',      'noreply@ecollab.io'));
define('MAIL_FROM_NAME', env('MAIL_FROM_NAME', 'Ecollab'));

// ── WebSocket ─────────────────────────────────────────────────────────────────
define('WS_HOST', env('WS_HOST', '0.0.0.0'));
define('WS_PORT', (int)env('WS_PORT', 8080));
define('WS_URL',  env('WS_URL',  'ws://localhost:8080'));

// ── File uploads ──────────────────────────────────────────────────────────────
define('UPLOAD_MAX_BYTES', (int)env('UPLOAD_MAX_BYTES', 20 * 1024 * 1024));
define('UPLOAD_DIR',       ROOT_PATH . '/uploads/');

// ── Institution ───────────────────────────────────────────────────────────────
define('DEFAULT_INSTITUTION_DOMAIN', env('DEFAULT_INSTITUTION_DOMAIN', 'fatima.edu.ph'));

// ── Error reporting ───────────────────────────────────────────────────────────
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', ROOT_PATH . '/logs/php-errors.log');
}

// ── OAuth / SSO ───────────────────────────────────────────────────────────────
// Localhost always follows the active XAMPP path. This avoids stale callback
// URLs copied from previous local project folders.
$googleRedirectUri = isLocalRuntime()
    ? APP_URL . '/API/auth/oauth-callback.php?provider=google'
    : env('GOOGLE_REDIRECT_URI', APP_URL . '/API/auth/oauth-callback.php?provider=google');

$microsoftRedirectUri = isLocalRuntime()
    ? APP_URL . '/API/auth/oauth-callback.php?provider=microsoft'
    : env('MICROSOFT_REDIRECT_URI', APP_URL . '/API/auth/oauth-callback.php?provider=microsoft');

define('GOOGLE_CLIENT_ID',      env('GOOGLE_CLIENT_ID',      ''));
define('GOOGLE_CLIENT_SECRET',  env('GOOGLE_CLIENT_SECRET',  ''));
define('GOOGLE_REDIRECT_URI',   $googleRedirectUri);

define('MICROSOFT_CLIENT_ID',     env('MICROSOFT_CLIENT_ID',     ''));
define('MICROSOFT_CLIENT_SECRET', env('MICROSOFT_CLIENT_SECRET', ''));
define('MICROSOFT_TENANT_ID',     env('MICROSOFT_TENANT_ID',     'common'));
define('MICROSOFT_REDIRECT_URI',  $microsoftRedirectUri);
