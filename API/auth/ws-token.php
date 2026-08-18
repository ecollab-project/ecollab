<?php
declare(strict_types=1);

/**
 * API/auth/ws-token.php
 *
 * Issues a short-lived WebSocket auth token for the authenticated session.
 * Multiple valid tokens may coexist for the same user so duplicate browser
 * bootstraps, tabs, and devices cannot invalidate a token already being used.
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/security/AuditLogger.php';
require_once dirname(__DIR__, 2) . '/security/SecurityHeaders.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
SecurityHeaders::send(isApi: true);
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$db = Database::getInstance();

$db->exec("
    CREATE TABLE IF NOT EXISTS ws_tokens (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id     INT UNSIGNED    NOT NULL,
        token_hash  CHAR(64)        NOT NULL,
        expires_at  DATETIME        NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uk_token_hash (token_hash),
        KEY idx_user_id (user_id),
        KEY idx_expires_at (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Use the database clock for both creation and validation. PHP's date.timezone
// and MySQL's time_zone can differ on local XAMPP installations; storing a
// PHP-formatted local timestamp while validating with MySQL NOW() can make a
// freshly-issued token appear expired or not-yet-valid. UTC_TIMESTAMP() keeps
// both sides on the same clock regardless of local timezone configuration.
$db->exec("DELETE FROM ws_tokens WHERE expires_at < UTC_TIMESTAMP()");

$token     = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $token);

$db->prepare("
    INSERT INTO ws_tokens (user_id, token_hash, expires_at)
    VALUES (:uid, :hash, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 120 SECOND))
")->execute([
    ':uid'  => $user['id'],
    ':hash' => $tokenHash,
]);

echo json_encode(['token' => $token]);
