<?php
declare(strict_types=1);

/**
 * security/SecurityHeaders.php
 *
 * Sends hardened HTTP security headers on every response and
 * provides a centralised input sanitisation / validation service.
 *
 * Usage (in every API entry point and page, after session start):
 *   SecurityHeaders::send();            // emit all headers
 *   $nonce = SecurityHeaders::nonce();  // get the per-request CSP nonce
 *
 *   // Sanitise user input:
 *   $clean = InputSanitiser::string($_POST['title']);
 *   $email = InputSanitiser::email($_POST['email']);
 *   $url   = InputSanitiser::url($_POST['url']);
 *   $int   = InputSanitiser::int($_GET['page'], 1, 100);
 */

class SecurityHeaders
{
    private static ?string $nonce = null;

    /**
     * Emit all recommended security headers.
     * Call once per request before any output.
     */
    public static function send(bool $isApi = false): void
    {
        $nonce = self::nonce();
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;

        // ── Content-Security-Policy ─────────────────────────────────────
        $cspParts = [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' https://cdnjs.cloudflare.com",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com",
            "img-src 'self' data: blob: https:",
            "connect-src 'self' " . self::wsOrigin(),
            "media-src 'self' blob:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "upgrade-insecure-requests",
        ];
        header("Content-Security-Policy: " . implode('; ', $cspParts));

        // ── HSTS (only over HTTPS) ──────────────────────────────────────
        if ($isHttps) {
            header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
        }

        // ── Clickjacking ────────────────────────────────────────────────
        header("X-Frame-Options: DENY");
        header("X-Content-Type-Options: nosniff");

        // ── Referrer policy ─────────────────────────────────────────────
        header("Referrer-Policy: strict-origin-when-cross-origin");

        // ── Permissions policy ──────────────────────────────────────────
        header("Permissions-Policy: camera=(), microphone=(self), geolocation=(), payment=(), usb=()");

        // ── Cross-Origin policies ───────────────────────────────────────
        header("Cross-Origin-Opener-Policy: same-origin");
        header("Cross-Origin-Embedder-Policy: require-corp");
        header("Cross-Origin-Resource-Policy: same-origin");

        // ── Cache control for sensitive API responses ───────────────────
        if ($isApi) {
            header("Cache-Control: no-store, no-cache, must-revalidate, private");
            header("Pragma: no-cache");
            header("Expires: 0");
        }

        // ── Remove server/PHP fingerprints ──────────────────────────────
        header_remove("X-Powered-By");
        header_remove("Server");
    }

    /**
     * Return (and lazily generate) the per-request CSP nonce.
     */
    public static function nonce(): string
    {
        if (self::$nonce === null) {
            self::$nonce = base64_encode(random_bytes(16));
        }
        return self::$nonce;
    }

    /**
     * Helper: build the WebSocket origin for the CSP connect-src directive.
     */
    private static function wsOrigin(): string
    {
        $host = defined('WS_HOST') ? WS_HOST : 'localhost';
        $port = defined('WS_PORT') ? WS_PORT : 8080;
        $proto = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')) ? 'wss' : 'ws';
        return "$proto://$host:$port";
    }
}

// ═════════════════════════════════════════════════════════════════════════
// INPUT SANITISER
// ═════════════════════════════════════════════════════════════════════════

class InputSanitiser
{
    // Characters that must never appear in any user input field
    private const ALWAYS_STRIP = ["\x00", "\x01", "\x02", "\x03", "\x04",
                                   "\x05", "\x06", "\x07", "\x08", "\x0b",
                                   "\x0c", "\x0e", "\x0f", "\x10", "\x11",
                                   "\x12", "\x13", "\x14", "\x15", "\x16",
                                   "\x17", "\x18", "\x19", "\x1a", "\x1b",
                                   "\x1c", "\x1d", "\x1e", "\x1f"];

    /**
     * Sanitise a generic string input.
     * Strips control characters, normalises whitespace, caps length.
     * Does NOT strip HTML — use htmlspecialchars() at output time.
     */
    public static function string(
        mixed  $value,
        int    $maxLen  = 500,
        bool   $trim    = true
    ): string {
        $s = (string)($value ?? '');
        // Strip null bytes and ASCII control characters
        $s = str_replace(self::ALWAYS_STRIP, '', $s);
        // Normalise unicode newlines
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        if ($trim) $s = trim($s);
        return mb_substr($s, 0, $maxLen);
    }

    /**
     * Sanitise and validate an email address.
     * Returns the cleaned email or null if invalid.
     */
    public static function email(mixed $value): ?string
    {
        $s = self::string($value, 254, true);
        $s = strtolower($s);
        return filter_var($s, FILTER_VALIDATE_EMAIL) !== false ? $s : null;
    }

    /**
     * Sanitise and validate a URL.
     * Only allows http/https schemes. Returns null if invalid.
     */
    public static function url(mixed $value, int $maxLen = 2048): ?string
    {
        $s = self::string($value, $maxLen, true);
        if (!filter_var($s, FILTER_VALIDATE_URL)) return null;
        // Only allow safe schemes
        $scheme = strtolower(parse_url($s, PHP_URL_SCHEME) ?? '');
        return in_array($scheme, ['http', 'https'], true) ? $s : null;
    }

    /**
     * Sanitise an integer with optional min/max bounds.
     */
    public static function int(mixed $value, ?int $min = null, ?int $max = null): int
    {
        $i = (int)$value;
        if ($min !== null) $i = max($min, $i);
        if ($max !== null) $i = min($max, $i);
        return $i;
    }

    /**
     * Sanitise a float with optional bounds.
     */
    public static function float(mixed $value, ?float $min = null, ?float $max = null): float
    {
        $f = (float)$value;
        if ($min !== null) $f = max($min, $f);
        if ($max !== null) $f = min($max, $f);
        return $f;
    }

    /**
     * Validate that a value is one of an allowed set.
     * Returns the value if valid, or $default otherwise.
     */
    public static function enum(mixed $value, array $allowed, mixed $default = null): mixed
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    /**
     * Sanitise a username: alphanumeric + underscore/hyphen/dot, no spaces.
     */
    public static function username(mixed $value, int $maxLen = 50): string
    {
        $s = self::string($value, $maxLen, true);
        return preg_replace('/[^a-zA-Z0-9_\-.]/', '', $s);
    }

    /**
     * Sanitise a plain-text field for display (HTML-encode for output).
     */
    public static function text(mixed $value, int $maxLen = 1000): string
    {
        return htmlspecialchars(self::string($value, $maxLen), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Sanitise a multiline text block (preserves newlines, strips dangerous chars).
     */
    public static function textarea(mixed $value, int $maxLen = 10000): string
    {
        $s = self::string($value, $maxLen, false);
        // Strip any embedded HTML tags
        return strip_tags($s);
    }

    /**
     * Validate and sanitise a date string (Y-m-d).
     * Returns null if invalid.
     */
    public static function date(mixed $value): ?string
    {
        $s = self::string($value, 10, true);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
        $d = \DateTime::createFromFormat('Y-m-d', $s);
        return ($d && $d->format('Y-m-d') === $s) ? $s : null;
    }

    /**
     * Validate and sanitise a datetime string (Y-m-d H:i:s or Y-m-dTH:i).
     */
    public static function datetime(mixed $value): ?string
    {
        $s = trim((string)($value ?? ''));
        $s = str_replace('T', ' ', $s);
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $fmt) {
            $d = \DateTime::createFromFormat($fmt, $s);
            if ($d) return $d->format('Y-m-d H:i:s');
        }
        return null;
    }

    /**
     * Sanitise JSON input: decode, validate structure, re-encode.
     * Returns null if the JSON is invalid or exceeds size limit.
     */
    public static function json(mixed $value, int $maxBytes = 65536): ?string
    {
        $s = (string)($value ?? '');
        if (strlen($s) > $maxBytes) return null;
        $decoded = json_decode($s, true);
        if (json_last_error() !== JSON_ERROR_NONE) return null;
        return json_encode($decoded, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Detect and log potential XSS/injection attempts.
     * Returns true if the input looks malicious.
     */
    public static function isMalicious(string $input): bool
    {
        $patterns = [
            '/<\s*script/i',                     // XSS script tag
            '/javascript\s*:/i',                  // javascript: URI
            '/on\w+\s*=/i',                       // event handler attributes
            '/union\s+select/i',                  // SQL UNION injection
            '/;\s*(?:drop|delete|truncate)\s+/i', // SQL destructive ops
            '/\.\.\//i',                           // path traversal
            '/\x00/',                              // null byte injection
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input)) return true;
        }
        return false;
    }

    /**
     * Sanitise all string values in a request body array recursively.
     */
    public static function sanitiseBody(array $body, int $maxLen = 2000): array
    {
        $clean = [];
        foreach ($body as $k => $v) {
            $key = self::string((string)$k, 100);
            if (is_array($v)) {
                $clean[$key] = self::sanitiseBody($v, $maxLen);
            } else {
                $clean[$key] = self::string($v, $maxLen);
            }
        }
        return $clean;
    }
}
