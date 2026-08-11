<?php
declare(strict_types=1);

if (!defined('APP_NAME')) require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';

/**
 * RateLimiter — Database-backed sliding window rate limiter.
 * Uses the `rate_limit_log` table (created below if absent via ensureTable()).
 */
class RateLimiter {

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->ensureTable();
    }

    /**
     * Check and record an attempt for a given action + identifier.
     *
     * @param  string $action   e.g. 'login', 'signup', 'forgot_password'
     * @param  string $identity IP or email (anything unique)
     * @param  int    $maxAttempts
     * @param  int    $windowSeconds
     * @return array  ['allowed' => bool, 'attempts' => int, 'retry_after' => int]
     */
    public function attempt(string $action, string $identity, int $maxAttempts = 10, int $windowSeconds = 900): array {
        $key = $action . ':' . hash('sha256', $identity);

        // Window boundary is computed by MySQL itself (NOW() - INTERVAL ... SECOND),
        // not by PHP's date()/time() -- comparing a PHP-computed timestamp against a
        // MySQL-generated `created_at` column is unsafe unless PHP's date.timezone
        // and MySQL's own timezone are guaranteed identical. $windowSeconds is
        // interpolated directly (with an explicit (int) cast) rather than bound as
        // a placeholder inside INTERVAL, since a bound parameter in that specific
        // syntactic position can be sent to MySQL as a string and not reliably
        // coerced to a numeric interval by every PDO/MySQL driver configuration --
        // interpolating a cast int is safe here as this value is always an internal
        // default/constant, never derived from user or request input.
        $windowSeconds = (int)$windowSeconds;

        // Count recent attempts
        $count = $this->db->prepare("
            SELECT COUNT(*) FROM rate_limit_log
            WHERE lookup_key = :key AND created_at >= (NOW() - INTERVAL $windowSeconds SECOND)
        ");
        $count->execute([':key' => $key]);
        $attempts = (int)$count->fetchColumn();

        if ($attempts >= $maxAttempts) {
            // Find oldest attempt in window to calculate retry_after
            $oldest = $this->db->prepare("
                SELECT MIN(created_at) FROM rate_limit_log
                WHERE lookup_key = :key AND created_at >= (NOW() - INTERVAL $windowSeconds SECOND)
            ");
            $oldest->execute([':key' => $key]);
            $oldestTs   = strtotime($oldest->fetchColumn() ?: 'now');
            $retryAfter = max(0, ($oldestTs + $windowSeconds) - time());
            return ['allowed' => false, 'attempts' => $attempts, 'retry_after' => $retryAfter];
        }

        // Record attempt
        $ins = $this->db->prepare("
            INSERT INTO rate_limit_log (lookup_key, created_at) VALUES (:key, NOW())
        ");
        $ins->execute([':key' => $key]);

        // Purge old entries for this key
        $del = $this->db->prepare("
            DELETE FROM rate_limit_log WHERE lookup_key = :key AND created_at < (NOW() - INTERVAL $windowSeconds SECOND)
        ");
        $del->execute([':key' => $key]);

        return ['allowed' => true, 'attempts' => $attempts + 1, 'retry_after' => 0];
    }

    /**
     * Clear all attempts for a given action + identity (e.g. after successful login).
     */
    public function clear(string $action, string $identity): void {
        $key = $action . ':' . hash('sha256', $identity);
        $this->db->prepare("DELETE FROM rate_limit_log WHERE lookup_key = :key")
            ->execute([':key' => $key]);
    }

    /**
     * Check remaining attempts without recording one.
     */
    public function remaining(string $action, string $identity, int $maxAttempts, int $windowSeconds): int {
        $key = $action . ':' . hash('sha256', $identity);
        $windowSeconds = (int)$windowSeconds;
        $count = $this->db->prepare("
            SELECT COUNT(*) FROM rate_limit_log
            WHERE lookup_key = :key AND created_at >= (NOW() - INTERVAL $windowSeconds SECOND)
        ");
        $count->execute([':key' => $key]);
        return max(0, $maxAttempts - (int)$count->fetchColumn());
    }

    /**
     * Get the real IP (considers reverse-proxy headers).
     */
    public static function getIP(): string {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = explode(',', $_SERVER[$key])[0];
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return '0.0.0.0';
    }

    /**
     * Ensure the backing table exists.
     */
    private function ensureTable(): void {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS rate_limit_log (
                id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                lookup_key VARCHAR(80)     NOT NULL,
                created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_lookup_key (lookup_key),
                KEY idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}
