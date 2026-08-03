<?php
/**
 * TEMPORARY DEBUG TOOL — DELETE AFTER USE
 * Hit this URL to diagnose send-message 500 errors.
 * Usage: http://localhost/ecollab_sample5/ecollab/API/chat/debug-check.php
 */
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: application/json');

$checks = [];

// 1. PHP version
$checks['php_version'] = PHP_VERSION;

// 2. Config load
try {
    require_once dirname(__DIR__, 2) . '/config.php';
    $checks['config'] = 'OK';
    $checks['app_debug'] = APP_DEBUG;
    $checks['db_name'] = DB_NAME;
    $checks['db_host'] = DB_HOST;
    $checks['base_url'] = BASE_URL;
} catch (Throwable $e) {
    $checks['config'] = 'FAIL: ' . $e->getMessage();
}

// 3. DB connection
try {
    require_once dirname(__DIR__, 2) . '/database/config/db.php';
    $db = Database::getInstance();
    $db->query('SELECT 1');
    $checks['database'] = 'OK — connected to ' . DB_NAME;
} catch (Throwable $e) {
    $checks['database'] = 'FAIL: ' . $e->getMessage();
}

// 4. Session / auth
try {
    require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
    AuthMiddleware::startSession();
    $checks['session_id'] = session_id() ?: 'none';
    $checks['session_user_id'] = $_SESSION['user_id'] ?? 'NOT SET — not logged in';
    $checks['csrf_token_set'] = !empty($_SESSION['csrf_token']) ? 'YES' : 'NO';
} catch (Throwable $e) {
    $checks['session'] = 'FAIL: ' . $e->getMessage();
}

// 5. MessageService load
try {
    require_once dirname(__DIR__, 2) . '/services/MessageService.php';
    $checks['message_service'] = 'OK';
} catch (Throwable $e) {
    $checks['message_service'] = 'FAIL: ' . $e->getMessage();
}

// 6. Tables check
try {
    $tables = $db->query("SHOW TABLES LIKE 'messages'")->fetchAll();
    $checks['messages_table'] = count($tables) > 0 ? 'EXISTS' : 'MISSING';
    $tables2 = $db->query("SHOW TABLES LIKE 'server_members'")->fetchAll();
    $checks['server_members_table'] = count($tables2) > 0 ? 'EXISTS' : 'MISSING';
} catch (Throwable $e) {
    $checks['tables'] = 'FAIL: ' . $e->getMessage();
}

echo json_encode($checks, JSON_PRETTY_PRINT);
