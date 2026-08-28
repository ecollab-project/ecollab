<?php
declare(strict_types=1);

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

if ($level !== 'full') {
    $dbOk = true;
    try {
        Database::getInstance()->query('SELECT 1');
    } catch (Throwable) {
        $dbOk = false;
    }
    http_response_code($dbOk ? 200 : 503);
    echo json_encode([
        'status' => $dbOk ? 'ok' : 'degraded',
        'timestamp' => date('c'),
        'database' => $dbOk ? 'connected' : 'unreachable',
    ]);
    exit;
}

$user = AuthMiddleware::requireAuth(true);
$role = $user['role'] ?? '';
if (!in_array($role, ['admin', 'super_admin'], true)) {
    AuditLogger::violation(
        AuditLogger::UNAUTHORIZED_ACCESS,
        ['endpoint' => 'health?level=full', 'role' => $role],
        AuditLogger::RISK_MEDIUM
    );
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admin access required']);
    exit;
}

$db = null;
$dbError = null;
try {
    $db = Database::getInstance();
    $db->query('SELECT 1');
} catch (Throwable $e) {
    $dbError = $e;
    error_log('[health] database diagnostics failed: ' . $e->getMessage());
}

$schema = $db ? SchemaVersion::diagnostics() : [
    'schema_migrations_table' => false,
    'features' => [],
];
unset($schema['error']);

$migrations = [];
if ($db && SchemaVersion::hasTable('schema_migrations')) {
    try {
        $stmt = $db->query('SELECT filename, applied_at, duration_ms, success FROM schema_migrations ORDER BY filename');
        $applied = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $dir = dirname(__DIR__, 2) . '/database/migrations';
        $diskFiles = array_map('basename', glob($dir . '/*.sql') ?: []);
        sort($diskFiles);
        foreach ($diskFiles as $f) {
            $match = null;
            foreach ($applied as $a) {
                if ($a['filename'] === $f) { $match = $a; break; }
            }
            $migrations[] = [
                'filename' => $f,
                'status' => $match ? ($match['success'] ? 'applied' : 'failed') : 'pending',
                'applied_at' => $match['applied_at'] ?? null,
                'duration_ms' => $match['duration_ms'] ?? null,
            ];
        }
    } catch (Throwable $e) {
        error_log('[health] migration diagnostics failed: ' . $e->getMessage());
        $migrations[] = ['filename' => '*', 'status' => 'unavailable'];
    }
} else {
    $migrations[] = ['filename' => '*', 'status' => 'unknown'];
}

$security = [
    'audit_logging_active' => SchemaVersion::hasTable('security_audit_log'),
    'account_lockout_active' => SchemaVersion::hasTable('account_lockouts'),
    'ip_blocking_active' => SchemaVersion::hasTable('ip_blocks'),
    'field_encryption_available' => SchemaVersion::sodiumAvailable(),
    'field_encryption_table' => SchemaVersion::hasTable('user_encrypted_pii'),
    'csrf_protection' => true,
    'rate_limiting' => true,
];

$recentRisk = [];
if ($security['audit_logging_active']) {
    try {
        $recentRisk = AuditLogger::recentHighRisk(60, 60);
        $recentRisk = array_map(static function ($r) {
            return [
                'event_type' => $r['event_type'],
                'risk_score' => (int)$r['risk_score'],
                'created_at' => $r['created_at'],
            ];
        }, array_slice($recentRisk, 0, 10));
    } catch (Throwable $e) {
        error_log('[health] risk diagnostics failed: ' . $e->getMessage());
    }
}

$websocket = [
    'ws_url' => defined('WS_URL') ? WS_URL : null,
    'ws_relay_table' => SchemaVersion::hasTable('ws_relay'),
    'ws_tokens_table' => SchemaVersion::hasTable('ws_tokens'),
];
if ($db && SchemaVersion::hasTable('ws_relay')) {
    try {
        $websocket['pending_relay_messages'] = (int)$db->query('SELECT COUNT(*) FROM ws_relay')->fetchColumn();
    } catch (Throwable $e) {
        error_log('[health] WebSocket diagnostics failed: ' . $e->getMessage());
        $websocket['pending_relay_messages'] = null;
    }
}

$environment = [
    'php_version' => PHP_VERSION,
    'app_debug' => APP_DEBUG,
    'bcrypt_cost' => BCRYPT_COST,
    'app_key_set' => defined('APP_KEY') && APP_KEY !== '',
    'session_secure' => defined('SESSION_SECURE') && SESSION_SECURE,
];

echo json_encode([
    'ok' => $dbError === null,
    'timestamp' => date('c'),
    'database' => [
        'connected' => $dbError === null,
        'table_count' => $schema['table_count'] ?? null,
    ],
    'schema' => $schema,
    'migrations' => $migrations,
    'security' => $security,
    'recent_high_risk_events' => $recentRisk,
    'websocket' => $websocket,
    'environment' => $environment,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
