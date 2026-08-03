<?php
declare(strict_types=1);

/**
 * security/AccountLockout.php
 *
 * Progressive account lockout + IP-based brute-force protection.
 *
 * Policy (configurable via constants / .env):
 *   Attempts 1-3  → no delay
 *   Attempts 4-5  → 30-second lockout
 *   Attempts 6-8  → 5-minute lockout
 *   Attempts 9-11 → 30-minute lockout
 *   Attempts 12+  → 24-hour lockout + admin alert
 *
 * Separate tracking per (identifier) and per (ip_address) so:
 *   - Credential stuffing is blocked at the IP level
 *   - Targeted account attacks are blocked at the account level
 */

require_once __DIR__ . '/AuditLogger.php';

class AccountLockout
{
    // ── Lockout thresholds [min_attempts => lockout_seconds] ──────────────
    private const THRESHOLDS = [
        12 => 86400,  // 24 h
        9  => 1800,   // 30 min
        6  => 300,    // 5 min
        4  => 30,     // 30 sec
    ];

    // IP-level: more attempts allowed per window before IP ban
    private const IP_WINDOW_SECONDS  = 600;   // 10-min rolling window
    private const IP_MAX_ATTEMPTS    = 20;    // across any accounts
    private const IP_BAN_DURATION    = 3600;  // 1-hour temporary ban

    private PDO $db;
    private bool $enabled = true;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->ensureTables();
    }

    // ─────────────────────────────────────────────────────────────────────
    // PUBLIC API
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Check if an identifier (email/username) or the request IP is blocked.
     * Returns ['blocked' => false] if safe, or error details if blocked.
     */
    public function check(string $identifier, ?string $ip = null): array
    {
        if (!$this->enabled) {
            // Degraded mode: no security tables — allow through.
            // CSRF, password hashing, and rate limiting still apply.
            return ['blocked' => false];
        }

        $ip = $ip ?? $this->getIp();

        // 1. IP block list (permanent/temporary)
        if ($this->isIpBlocked($ip)) {
            AuditLogger::violation(AuditLogger::IP_BLOCKED, ['ip' => $ip], AuditLogger::RISK_HIGH);
            return [
                'blocked'     => true,
                'reason'      => 'ip_blocked',
                'message'     => 'Access from your network has been restricted.',
                'retry_after' => 0,
            ];
        }

        // 2. Account-level lockout
        $lockout = $this->getAccountLockout($identifier);
        if ($lockout && $lockout['locked_until'] && strtotime($lockout['locked_until']) > time()) {
            $retryAfter = strtotime($lockout['locked_until']) - time();
            AuditLogger::log(AuditLogger::LOGIN_LOCKED,
                ['identifier' => hash('sha256', $identifier), 'retry_after' => $retryAfter],
                'blocked', AuditLogger::RISK_MEDIUM);
            return [
                'blocked'     => true,
                'reason'      => 'account_locked',
                'message'     => 'Account temporarily locked. Try again in ' . $this->humanTime($retryAfter) . '.',
                'retry_after' => $retryAfter,
                'failed_count'=> (int)$lockout['failed_count'],
            ];
        }

        // 3. IP-level rate check (cross-account stuffing detection)
        $ipCount = $this->getIpAttemptCount($ip);
        if ($ipCount >= self::IP_MAX_ATTEMPTS) {
            // Auto-add temp IP block
            $this->blockIp($ip, 'Automated: exceeded login attempt threshold', self::IP_BAN_DURATION);
            AuditLogger::violation(AuditLogger::IP_BLOCKED,
                ['ip' => $ip, 'attempts' => $ipCount], AuditLogger::RISK_CRIT);
            return [
                'blocked'     => true,
                'reason'      => 'ip_rate_limit',
                'message'     => 'Too many requests from this network. Please try again later.',
                'retry_after' => self::IP_BAN_DURATION,
            ];
        }

        return ['blocked' => false];
    }

    /**
     * Record a failed login attempt. Applies lockout if threshold crossed.
     * Returns the updated lockout state.
     */
    public function recordFailure(string $identifier, ?string $ip = null): array
    {
        if (!$this->enabled) {
            return ['failed_count' => 0, 'locked' => false, 'locked_until' => null,
                    'lockout_secs' => 0, 'message' => 'Invalid credentials.'];
        }

        $ip = $ip ?? $this->getIp();

        // Record IP-level attempt
        $this->recordIpAttempt($ip);

        // Record failed login analytics
        $this->recordFailedAnalytic($identifier, $ip);

        // Upsert account lockout row
        $existing = $this->getAccountLockout($identifier);
        $count    = $existing ? (int)$existing['failed_count'] + 1 : 1;

        $lockoutSeconds = $this->getLockoutDuration($count);
        $lockedUntil    = $lockoutSeconds > 0
            ? date('Y-m-d H:i:s', time() + $lockoutSeconds)
            : null;

        $this->db->prepare("
            INSERT INTO account_lockouts
                (identifier, ip_address, failed_count, locked_until, lock_reason, last_attempt)
            VALUES (:id, :ip, :cnt, :lu, :reason, NOW())
            ON DUPLICATE KEY UPDATE
                failed_count  = :cnt2,
                ip_address    = :ip2,
                locked_until  = :lu2,
                lock_reason   = :reason2,
                last_attempt  = NOW()
        ")->execute([
            ':id'      => mb_substr($identifier, 0, 255),
            ':ip'      => $ip,
            ':cnt'     => $count,
            ':lu'      => $lockedUntil,
            ':reason'  => $lockoutSeconds > 0 ? "Failed login $count times" : null,
            ':cnt2'    => $count,
            ':ip2'     => $ip,
            ':lu2'     => $lockedUntil,
            ':reason2' => $lockoutSeconds > 0 ? "Failed login $count times" : null,
        ]);

        return [
            'failed_count'  => $count,
            'locked'        => $lockedUntil !== null,
            'locked_until'  => $lockedUntil,
            'lockout_secs'  => $lockoutSeconds,
            'message'       => $this->buildFailureMessage($count, $lockoutSeconds),
        ];
    }

    /**
     * Clear lockout after a successful login.
     */
    public function recordSuccess(string $identifier): void
    {
        if (!$this->enabled) return;

        $this->db->prepare("
            DELETE FROM account_lockouts WHERE identifier = :id
        ")->execute([':id' => mb_substr($identifier, 0, 255)]);
    }

    /**
     * Manually block an IP address.
     */
    public function blockIp(string $ip, string $reason = '', int $durationSeconds = 0): void
    {
        if (!$this->enabled) return;

        $expiresAt = $durationSeconds > 0
            ? date('Y-m-d H:i:s', time() + $durationSeconds)
            : null; // permanent

        $this->db->prepare("
            INSERT INTO ip_blocks (ip_address, reason, expires_at)
            VALUES (:ip, :reason, :exp)
            ON DUPLICATE KEY UPDATE
                reason     = VALUES(reason),
                expires_at = VALUES(expires_at)
        ")->execute([':ip' => $ip, ':reason' => mb_substr($reason, 0, 200), ':exp' => $expiresAt]);
    }

    /**
     * Unblock an IP address.
     */
    public function unblockIp(string $ip): void
    {
        if (!$this->enabled) return;

        $this->db->prepare("DELETE FROM ip_blocks WHERE ip_address = :ip")
                 ->execute([':ip' => $ip]);
    }

    /**
     * Get lockout status for display (e.g. in login form).
     */
    public function getStatus(string $identifier): array
    {
        if (!$this->enabled) return ['locked' => false, 'failed_count' => 0];

        $lockout = $this->getAccountLockout($identifier);
        if (!$lockout) return ['locked' => false, 'failed_count' => 0];

        $locked = $lockout['locked_until'] && strtotime($lockout['locked_until']) > time();
        return [
            'locked'       => $locked,
            'failed_count' => (int)$lockout['failed_count'],
            'locked_until' => $lockout['locked_until'],
            'retry_after'  => $locked ? strtotime($lockout['locked_until']) - time() : 0,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────

    private function isIpBlocked(string $ip): bool
    {
        $stmt = $this->db->prepare("
            SELECT 1 FROM ip_blocks
            WHERE ip_address = :ip
              AND (expires_at IS NULL OR expires_at > NOW())
            LIMIT 1
        ");
        $stmt->execute([':ip' => $ip]);
        return (bool)$stmt->fetchColumn();
    }

    private function getAccountLockout(string $identifier): array|false
    {
        $stmt = $this->db->prepare("
            SELECT * FROM account_lockouts WHERE identifier = :id LIMIT 1
        ");
        $stmt->execute([':id' => mb_substr($identifier, 0, 255)]);
        return $stmt->fetch();
    }

    private function getIpAttemptCount(string $ip): int
    {
        $window = date('Y-m-d H:i:s', time() - self::IP_WINDOW_SECONDS);
        $stmt   = $this->db->prepare("
            SELECT COUNT(*) FROM failed_login_analytics
            WHERE ip_address = :ip AND attempted_at >= :window
        ");
        $stmt->execute([':ip' => $ip, ':window' => $window]);
        return (int)$stmt->fetchColumn();
    }

    private function recordIpAttempt(string $ip): void
    {
        $this->db->prepare("
            INSERT INTO failed_login_analytics (ip_address, identifier, attempted_at)
            VALUES (:ip, 'ip_level', NOW())
        ")->execute([':ip' => $ip]);

        // Prune old IP records (> 1 hour) to keep table small
        $this->db->prepare("
            DELETE FROM failed_login_analytics
            WHERE ip_address = :ip AND attempted_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ")->execute([':ip' => $ip]);
    }

    private function recordFailedAnalytic(string $identifier, string $ip): void
    {
        $this->db->prepare("
            INSERT INTO failed_login_analytics (ip_address, identifier, user_agent, attempted_at)
            VALUES (:ip, :id, :ua, NOW())
        ")->execute([
            ':ip' => $ip,
            ':id' => hash('sha256', $identifier), // never store plaintext
            ':ua' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300),
        ]);
    }

    private function getLockoutDuration(int $failedCount): int
    {
        foreach (self::THRESHOLDS as $minAttempts => $seconds) {
            if ($failedCount >= $minAttempts) return $seconds;
        }
        return 0;
    }

    private function buildFailureMessage(int $count, int $lockoutSeconds): string
    {
        if ($lockoutSeconds > 0) {
            return "Account locked for {$this->humanTime($lockoutSeconds)} after $count failed attempts.";
        }
        $nextThreshold = null;
        foreach (array_reverse(self::THRESHOLDS, true) as $min => $secs) {
            if ($count < $min) { $nextThreshold = $min; break; }
        }
        if ($nextThreshold) {
            $remaining = $nextThreshold - $count;
            return "Invalid credentials. $remaining more failed attempt(s) will lock your account.";
        }
        return 'Invalid credentials.';
    }

    private function humanTime(int $seconds): string
    {
        if ($seconds >= 3600)  return round($seconds / 3600) . ' hour(s)';
        if ($seconds >= 60)    return round($seconds / 60)   . ' minute(s)';
        return "$seconds second(s)";
    }

    private function getIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = trim(explode(',', $_SERVER[$k])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return '0.0.0.0';
    }

    private function ensureTables(): void
    {
        // Tables are created by migration 014_security.sql.
        // On databases that haven't applied this migration yet (old version
        // running alongside new code), gracefully disable lockout tracking
        // rather than fatal-erroring on every login attempt.
        try {
            $this->db->query("SELECT 1 FROM account_lockouts LIMIT 0");
            $this->db->query("SELECT 1 FROM ip_blocks LIMIT 0");
            $this->db->query("SELECT 1 FROM failed_login_analytics LIMIT 0");
        } catch (\Throwable) {
            $this->enabled = false;
            error_log('[Ecollab] AccountLockout: security tables missing — '
                . 'lockout protection DISABLED until database/migrate.php is run '
                . '(migration 014_security.sql)');
        }
    }

    /**
     * Whether lockout tracking is active (security tables present).
     * APIs/services can use this to show a degraded-mode notice.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
