<?php
declare(strict_types=1);

/**
 * security/AuditLogger.php
 *
 * Append-only security audit log.  Every call writes one row to
 * security_audit_log.  Never throws — logging must never break the
 * application flow.
 *
 * Usage:
 *   AuditLogger::log(AuditLogger::LOGIN_SUCCESS, ['email' => $email]);
 *   AuditLogger::log(AuditLogger::AUTH_CSRF_FAIL, [], 'failure', 60);
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/database/config/db.php';

class AuditLogger
{
    // ── Event type constants ───────────────────────────────────────────────
    // Auth
    const LOGIN_SUCCESS       = 'auth.login.success';
    const LOGIN_FAILURE       = 'auth.login.failure';
    const LOGIN_LOCKED        = 'auth.login.locked';
    const LOGOUT              = 'auth.logout';
    const REGISTER            = 'auth.register';
    const PASSWORD_RESET      = 'auth.password_reset';
    const PASSWORD_CHANGE     = 'auth.password_change';
    const EMAIL_VERIFY        = 'auth.email_verify';
    const OAUTH_LOGIN         = 'auth.oauth_login';
    const SESSION_EXPIRE      = 'auth.session_expire';
    const SESSION_HIJACK      = 'auth.session_hijack';
    // Security violations
    const AUTH_CSRF_FAIL      = 'security.csrf_fail';
    const AUTH_RATE_LIMIT     = 'security.rate_limit';
    const IP_BLOCKED          = 'security.ip_blocked';
    const XSS_ATTEMPT         = 'security.xss_attempt';
    const SQLI_ATTEMPT        = 'security.sqli_attempt';
    const PATH_TRAVERSAL      = 'security.path_traversal';
    const UNAUTHORIZED_ACCESS = 'security.unauthorized';
    const PRIVILEGE_ESCALATION = 'security.privilege_escalation';
    // Data access
    const PII_ACCESS          = 'data.pii_access';
    const BULK_EXPORT         = 'data.bulk_export';
    const DATA_DELETE         = 'data.delete';
    // Admin actions
    const ROLE_CHANGE         = 'admin.role_change';
    const USER_BAN            = 'admin.user_ban';
    const USER_UNBAN          = 'admin.user_unban';
    const CONFIG_CHANGE       = 'admin.config_change';
    // API
    const API_KEY_USE         = 'api.key_use';
    const WS_AUTH_SUCCESS     = 'ws.auth_success';
    const WS_AUTH_FAILURE     = 'ws.auth_failure';

    // Risk score presets
    const RISK_LOW    = 10;
    const RISK_MEDIUM = 40;
    const RISK_HIGH   = 70;
    const RISK_CRIT   = 90;

    private static bool $enabled = true;

    /**
     * Write an audit event. Never throws.
     *
     * @param string $eventType  One of the class constants above
     * @param array  $detail     Extra context (will be sanitised and JSON-encoded)
     * @param string $status     'success' | 'failure' | 'blocked'
     * @param int    $riskScore  0–100
     */
    public static function log(
        string $eventType,
        array  $detail     = [],
        string $status     = 'success',
        int    $riskScore  = 0
    ): void {
        if (!self::$enabled) return;

        try {
            $db        = Database::getInstance();
            $userId    = $_SESSION['user_id'] ?? null;
            $sessionId = session_id() ?: null;
            $ip        = self::getIp();
            $ua        = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
            $resource  = mb_substr(($_SERVER['REQUEST_URI'] ?? ''), 0, 200);

            // Sanitise detail — strip sensitive keys
            $clean = self::sanitiseDetail($detail);

            $db->prepare("
                INSERT INTO security_audit_log
                    (user_id, session_id, event_type, event_status,
                     ip_address, user_agent, resource, detail, risk_score, created_at)
                VALUES
                    (:uid, :sid, :type, :status,
                     :ip, :ua, :res, :detail, :risk, NOW())
            ")->execute([
                ':uid'    => $userId,
                ':sid'    => $sessionId,
                ':type'   => $eventType,
                ':status' => in_array($status, ['success','failure','blocked']) ? $status : 'success',
                ':ip'     => $ip,
                ':ua'     => $ua,
                ':res'    => $resource,
                ':detail' => $clean ? json_encode($clean, JSON_UNESCAPED_UNICODE) : null,
                ':risk'   => min(100, max(0, $riskScore)),
            ]);
        } catch (\Throwable) {
            // Never let logging break the application
        }
    }

    /**
     * Quick helper for logging the current authenticated user's action.
     */
    public static function logUser(
        string $eventType,
        array  $detail    = [],
        string $status    = 'success',
        int    $riskScore = self::RISK_LOW
    ): void {
        self::log($eventType, $detail, $status, $riskScore);
    }

    /**
     * Log a security violation and optionally trigger an alert.
     */
    public static function violation(
        string $eventType,
        array  $detail    = [],
        int    $riskScore = self::RISK_HIGH
    ): void {
        self::log($eventType, $detail, 'failure', $riskScore);

        // If risk is critical, write to PHP error log for immediate visibility
        if ($riskScore >= self::RISK_CRIT) {
            error_log(sprintf(
                '[ECOLLAB SECURITY] %s | IP:%s | User:%s | Detail:%s',
                $eventType,
                self::getIp(),
                $_SESSION['user_id'] ?? 'anon',
                json_encode($detail)
            ));
        }
    }

    /**
     * Retrieve recent audit events for the admin dashboard.
     */
    public static function recent(int $limit = 50, ?string $eventType = null): array
    {
        try {
            $db    = Database::getInstance();
            $where = $eventType ? "WHERE event_type = :type" : "";
            $stmt  = $db->prepare("
                SELECT l.*, u.username
                FROM security_audit_log l
                LEFT JOIN users u ON u.id = l.user_id
                $where
                ORDER BY l.created_at DESC
                LIMIT :lim
            ");
            if ($eventType) $stmt->bindValue(':type', $eventType);
            $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Get high-risk events in the last N minutes.
     */
    public static function recentHighRisk(int $minutes = 60, int $minRisk = 60): array
    {
        try {
            $db   = Database::getInstance();
            $stmt = $db->prepare("
                SELECT * FROM security_audit_log
                WHERE risk_score >= :risk
                  AND created_at >= DATE_SUB(NOW(), INTERVAL :min MINUTE)
                ORDER BY risk_score DESC, created_at DESC
                LIMIT 100
            ");
            $stmt->execute([':risk' => $minRisk, ':min' => $minutes]);
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private static function getIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = trim(explode(',', $_SERVER[$k])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return '0.0.0.0';
    }

    private static function sanitiseDetail(array $detail): array
    {
        // Keys that must never appear in audit logs
        static $BLOCKED_KEYS = [
            'password','password_hash','token','secret','api_key',
            'client_secret','access_token','refresh_token','ssn',
            'credit_card','card_number','cvv','pin',
        ];
        $clean = [];
        foreach ($detail as $k => $v) {
            $lk = strtolower((string)$k);
            if (in_array($lk, $BLOCKED_KEYS, true)) {
                $clean[$k] = '[REDACTED]';
            } elseif (is_array($v)) {
                $clean[$k] = self::sanitiseDetail($v);
            } elseif (is_string($v)) {
                $clean[$k] = mb_substr($v, 0, 500); // cap length
            } else {
                $clean[$k] = $v;
            }
        }
        return $clean;
    }

    public static function disable(): void { self::$enabled = false; }
    public static function enable(): void  { self::$enabled = true; }
}
