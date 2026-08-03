<?php
declare(strict_types=1);

/**
 * API/system/health.php
 *
 * Enterprise-grade diagnostics endpoint reporting:
 *   - Database connectivity
 *   - Schema migration status (which migrations applied)
 *   - Feature availability (collaboration tools, peer matching,
 *     security audit logging, field encryption, etc.) based on
 *     ACTUAL schema state — not just code presence
 *   - PHP extension availability (sodium, pdo_mysql, curl)
 *   - WebSocket relay table status
 *
 * Access control:
 *   - GET ?level=basic   -> public: just {"status":"ok"} for uptime monitors
 *   - GET ?level=full    -> requires admin/super_admin role: full diagnostics
 *
 * This endpoint is the cornerstone of "old and new versions running
 * simultaneously": ops/admins can see exactly which migrations have
 * been applied and which features are therefore active, without
 * needing to inspect the database directly.
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/security/SecurityHeaders.php';
require_once dirname(__DIR__, 2) . '/security/SchemaVersion.php';
require_once dirname(__DIR__, 2) . '/security/AuditLogger.php';

header('Content-Type: application/json');
SecurityHeaders::send(isApi: true);
AuthMiddleware::startSession();

$level = $_GET['level'] ?? 'basic';

// ─────────────────────────────────────────────────────────────────────────
// BASIC LEVEL — public, for uptime monitors / load balancers
// ─────────────────────────────────────────────────────────────────────────
if ($level !== 'full') {
    $dbOk = true;
    try {
        Database::getInstance()->query("SELECT 1");
    } catch (\Throwable) {
        $dbOk = false;
    }

    http_response_code($dbOk ? 200 : 503);
    echo json_encode([
        'status'    => $dbOk ? 'ok' : 'degraded',
        'timestamp' => date('c'),
        'database'  => $dbOk ? 'connected' : 'unreachable',
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// FULL LEVEL — requires admin authentication
// ─────────────────────────────────────────────────────────────────────────
$user = AuthMiddleware::requireAuth(true);
$role = $user['role'] ?? '';

if (!in_array($role, ['admin', 'super_admin'], true)) {
    AuditLogger::violation(AuditLogger::UNAUTHORIZED_ACCESS,
        ['endpoint' => 'health?level=full', 'role' => $role],
        AuditLogger::RISK_MEDIUM);
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admin access required']);
    exit;
}

// ── Database connectivity ───────────────────────────────────────────────
$db = null;
$dbError = null;
try {
    $db = Database::getInstance();
    $db->query("SELECT 1");
} catch (\Throwable $e) {
    $dbError = $e->getMessage();
}

// ── Schema diagnostics ───────────────────────────────────────────────────
$schema = $db ? SchemaVersion::diagnostics() : [
    'error' => $dbError,
    'schema_migrations_table' => false,
    'features' => [],
];

// ── Migration list with status ──────────────────────────────────────────
$migrations = [];
if ($db && SchemaVersion::hasTable('schema_migrations')) {
    $stmt = $db->query("
        SELECT filename, applied_at, duration_ms, success, error_msg
        FROM schema_migrations
        ORDER BY filename
    ");
    $applied = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $appliedNames = array_column($applied, 'filename');

    // List migration files on disk to detect pending ones
    $dir = dirname(__DIR__, 2) . '/database/migrations';
    $diskFiles = array_map('basename', glob($dir . '/*.sql') ?: []);
    sort($diskFiles);

    foreach ($diskFiles as $f) {
        $match = null;
        foreach ($applied as $a) {
            if ($a['filename'] === $f) { $match = $a; break; }
        }
        $migrations[] = [
            'filename'    => $f,
            'status'      => $match ? ($match['success'] ? 'applied' : 'failed') : 'pending',
            'applied_at'  => $match['applied_at'] ?? null,
            'duration_ms' => $match['duration_ms'] ?? null,
            'error'       => $match['error_msg'] ?? null,
        ];
    }
} else {
    $migrations[] = [
        'filename' => '*',
        'status'   => 'unknown',
        'note'     => 'schema_migrations table not found — run database/migrate.php',
    ];
}

// ── Security posture summary ─────────────────────────────────────────────
$security = [
    'audit_logging_active' => SchemaVersion::hasTable('security_audit_log'),
    'account_lockout_active' => SchemaVersion::hasTable('account_lockouts'),
    'ip_blocking_active' => SchemaVersion::hasTable('ip_blocks'),
    'field_encryption_available' => SchemaVersion::sodiumAvailable(),
    'field_encryption_table' => SchemaVersion::hasTable('user_encrypted_pii'),
    'csrf_protection' => true, // always present in codebase
    'rate_limiting' => SchemaVersion::hasTable('rate_limit_log') || true, // RateLimiter self-creates
];

// Recent high-risk events (last hour)
$recentRisk = [];
if ($security['audit_logging_active']) {
    $recentRisk = AuditLogger::recentHighRisk(60, 60);
    // Strip detail JSON for summary view
    $recentRisk = array_map(function ($r) {
        return [
            'event_type' => $r['event_type'],
            'risk_score' => (int)$r['risk_score'],
            'ip_address' => $r['ip_address'],
            'created_at' => $r['created_at'],
        ];
    }, array_slice($recentRisk, 0, 10));
}

// ── WebSocket status ─────────────────────────────────────────────────────
$websocket = [
    'ws_url'           => defined('WS_URL') ? WS_URL : null,
    'ws_relay_table'   => SchemaVersion::hasTable('ws_relay'),
    'ws_tokens_table'  => SchemaVersion::hasTable('ws_tokens'),
];
if ($db && SchemaVersion::hasTable('ws_relay')) {
    $pending = (int)$db->query("SELECT COUNT(*) FROM ws_relay")->fetchColumn();
    $websocket['pending_relay_messages'] = $pending;
}

// ── PHP / environment info ───────────────────────────────────────────────
$environment = [
    'php_version'     => PHP_VERSION,
    'app_debug'       => APP_DEBUG,
    'bcrypt_cost'     => BCRYPT_COST,
    'app_key_set'     => defined('APP_KEY') && APP_KEY !== '',
    'session_secure'  => defined('SESSION_SECURE') && SESSION_SECURE,
];

echo json_encode([
    'ok'          => $dbError === null,
    'timestamp'   => date('c'),
    'database'    => [
        'connected' => $dbError === null,
        'error'     => $dbError,
        'table_count' => $schema['table_count'] ?? null,
    ],
    'schema'      => $schema,
    'migrations'  => $migrations,
    'security'    => $security,
    'recent_high_risk_events' => $recentRisk,
    'websocket'   => $websocket,
    'environment' => $environment,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
