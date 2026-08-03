<?php

declare(strict_types=1);

if (!defined('SESSION_LIFETIME')) {
    require_once dirname(__DIR__, 2) . '/config.php';
}

/**
 * AuthMiddleware — UNIFIED session-based auth gate for auth + chat modules.
 *
 * Session keys written by AuthService::writeSession() and read here:
 *   $_SESSION['user_id']             int
 *   $_SESSION['username']            string
 *   $_SESSION['email']               string
 *   $_SESSION['full_name']           string
 *   $_SESSION['role']                string  admin|super_admin|moderator|facilitator|student
 *   $_SESSION['avatar_gradient']     string  e.g. "#FF2D75,#9F3BFF"   ← auth canonical key
 *   $_SESSION['avatar_color_gradient'] string alias — written here for chat compatibility
 *   $_SESSION['logged_in_at']        int     Unix timestamp
 *   $_SESSION['csrf_token']          string  used by chat CSRF checks
 */
class AuthMiddleware
{

    // ── Unified user array shape returned by all requireAuth() calls ──────────
    // Both auth APIs and chat APIs receive this same structure.
    private static function buildUserArray(): array
    {
        $gradient = $_SESSION['avatar_gradient'] ?? $_SESSION['avatar_color_gradient'] ?? '#a855f7,#ec4899';
        return [
            'id'                   => (int)$_SESSION['user_id'],
            'username'             => $_SESSION['username']             ?? '',
            'email'                => $_SESSION['email']                ?? '',
            'full_name'            => $_SESSION['full_name']            ?? '',
            'role'                 => $_SESSION['role']                 ?? 'student',
            'avatar_gradient'      => $gradient,        // auth canonical
            'avatar_color_gradient' => $gradient,        // chat canonical (alias)
        ];
    }

    /**
     * Require an authenticated session.
     * $apiMode=true → return JSON 401 instead of redirecting.
     */
    public static function requireAuth(bool $apiMode = false): array
    {
        self::startSession();

        if (empty($_SESSION['user_id'])) {
            if ($apiMode) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode([
                    'success'  => false,
                    'error'    => 'Unauthenticated.',
                    'redirect' => BASE_URL . '/modules/auth/login.php',
                ]);
                exit;
            }
            $next = urlencode($_SERVER['REQUEST_URI'] ?? '/');
            header('Location: ' . BASE_URL . '/modules/auth/login.php?next=' . $next);
            exit;
        }

        return self::buildUserArray();
    }

    /**
     * Redirect already-authed users away from guest pages (login, signup).
     */
    public static function redirectIfAuthed(): void
    {
        self::startSession();
        if (!empty($_SESSION['user_id'])) {
            self::redirectToDashboard($_SESSION['role'] ?? 'student');
        }
    }

    /**
     * Send user to their role-appropriate dashboard.
     */
    public static function redirectToDashboard(string $role): never
    {
        $map = [
            'admin'       => BASE_URL . '/modules/admin/dashboard.php',
            'super_admin' => BASE_URL . '/modules/admin/dashboard.php',
            'moderator'   => BASE_URL . '/modules/admin/dashboard.php',
            'facilitator' => BASE_URL . '/modules/chat/chat.php',
            'student'     => BASE_URL . '/modules/chat/chat.php',
        ];
        header('Location: ' . ($map[$role] ?? BASE_URL . '/modules/student/dashboard.php'));
        exit;
    }

    /**
     * Destroy session on logout.
     */
    public static function destroySession(): void
    {
        self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 3600,
                $p['path'],
                $p['domain'],
                $p['secure'],
                $p['httponly']
            );
        }
        session_destroy();
    }

    /**
     * Start session with hardened cookie settings (idempotent).
     */
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => SESSION_LIFETIME,
                'path'     => '/',
                'secure'   => SESSION_SECURE,
                'httponly' => true,
                'samesite' => SESSION_SAMESITE,
            ]);
            session_start();
        }
    }

    /**
     * Return (or create) the CSRF token for the current session.
     * Compatible with both the auth CSRF class and chat's inline token checks.
     */
    public static function csrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Verify CSRF token from request header or POST body.
     * Used by chat API endpoints.
     */
    public static function verifyCsrf(): void
    {
        self::startSession();
        $submitted = $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? $_POST['_csrf_token']
            ?? $_POST['csrf_token']
            ?? '';
        $stored = $_SESSION['csrf_token'] ?? '';

        if (!hash_equals($stored, $submitted)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'CSRF token mismatch.']);
            exit;
        }
    }
}
