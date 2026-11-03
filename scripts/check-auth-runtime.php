<?php
declare(strict_types=1);
/** Read-only login deployment checks. No credentials, rows, tokens or mail output. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
$root = dirname(__DIR__);
$failures = 0;
function authCheck(bool $ok, string $name): void {
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$ok) ++$failures;
}
echo 'CLI PHP ' . PHP_VERSION . '; validate the FPM runtime separately.' . PHP_EOL;
authCheck(PHP_VERSION_ID >= 80200, 'PHP >= 8.2');
foreach (['pdo_mysql','mbstring','curl','sodium'] as $ext) authCheck(extension_loaded($ext), 'extension ' . $ext);
$files = ['config.php','database/config/db.php','API/auth/login.php',
    'security/middleware/AuthMiddleware.php','security/csrf/csrf.php',
    'security/rate-limit/RateLimiter.php','security/ClientIp.php',
    'security/AuditLogger.php','security/SecurityHeaders.php',
    'security/AccountLockout.php','security/FieldEncryption.php',
    'security/SchemaVersion.php','security/forgot-password/OtpService.php','services/AuthService.php'];
foreach ($files as $file) authCheck(is_readable($root . '/' . $file), 'readable ' . $file);
authCheck(is_dir($root . '/logs') && is_writable($root . '/logs'), 'application error-log directory writable by this user');
if ($failures) exit(1);
try {
    // Match the endpoint's direct includes; don't load config twice via Composer.
    require_once $root . '/config.php';
    require_once $root . '/database/config/db.php';
    require_once $root . '/services/AuthService.php';
    require_once $root . '/security/SecurityHeaders.php';
    authCheck(true, 'auth dependency declarations load');
    $db = Database::getInstance();
    authCheck((int)$db->query('SELECT 1')->fetchColumn() === 1, 'database connection');
    foreach (['users','rate_limit_log','otp_codes','account_lockouts','ip_blocks',
              'failed_login_analytics','security_audit_log'] as $table) {
        try { $db->query('SELECT 1 FROM `' . $table . '` LIMIT 0'); authCheck(true, 'table ' . $table); }
        catch (PDOException $e) { authCheck(false, 'table ' . $table . ' (check migrations)'); }
    }
    $columns = $db->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['id','username','email','student_id','full_name','password_hash','role','status',
              'email_verified','avatar_color_gradient','deleted_at',
              'is_online','last_seen_at','remember_token'] as $column) {
        authCheck(in_array($column, $columns, true), 'users.' . $column);
    }
    echo 'No data, migrations, configuration, messages or delivery services were changed.' . PHP_EOL;
} catch (Throwable $e) {
    authCheck(false, 'auth bootstrap/database: ' . get_class($e) . ' code=' . (string)$e->getCode()
        . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
}
exit($failures ? 1 : 0);
