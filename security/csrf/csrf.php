<?php
declare(strict_types=1);

/**
 * csrf.php — Synchronizer-token CSRF protection
 * Uses the same session key as AuthMiddleware so both systems stay in sync.
 */
class CSRF {
    // MUST match AuthMiddleware::csrfToken() key
    private const TOKEN_KEY = 'csrf_token';
    private const TOKEN_LEN = 32;

    /**
     * Generate (or return existing) CSRF token for the current session.
     */
    public static function token(): string {
        self::startSession();
        if (empty($_SESSION[self::TOKEN_KEY])) {
            $_SESSION[self::TOKEN_KEY] = bin2hex(random_bytes(self::TOKEN_LEN));
        }
        return $_SESSION[self::TOKEN_KEY];
    }

    /**
     * Render a hidden CSRF input field.
     */
    public static function field(): string {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Validate the token from the request.
     * Checks X-CSRF-Token header first, then POST body keys.
     * Throws on failure.
     */
    public static function verify(): void {
        self::startSession();
        $submitted = $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? $_POST['csrf_token']
            ?? $_POST['_csrf_token']
            ?? '';
        $stored = $_SESSION[self::TOKEN_KEY] ?? '';

        if ($stored === '' || !hash_equals($stored, $submitted)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invalid security token. Please refresh the page and try again.']);
            exit;
        }
    }

    /**
     * Regenerate the token (call after successful login to prevent fixation).
     */
    public static function regenerate(): void {
        self::startSession();
        $_SESSION[self::TOKEN_KEY] = bin2hex(random_bytes(self::TOKEN_LEN));
    }

    /**
     * Use AuthMiddleware's session starter if available (preserves cookie params),
     * otherwise fall back to a plain session_start().
     */
    private static function startSession(): void {
        if (session_status() !== PHP_SESSION_NONE) return;
        if (class_exists('AuthMiddleware', false)) {
            AuthMiddleware::startSession();
        } else {
            session_start();
        }
    }
}
