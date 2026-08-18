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

// Only remove expired tokens. Do NOT delete all tokens for this user:
// another tab/device or duplicate bootstrap may already be authenticating
// with a still-valid token.
$db->exec("DELETE FROM ws_tokens WHERE expires_at < NOW()");

$token     = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $token);
$expiresAt = date('Y-m-d H:i:s', time() + 120);

$db->prepare("
    INSERT INTO ws_tokens (user_id, token_hash, expires_at)
    VALUES (:uid, :hash, :exp)
")->execute([
    ':uid'  => $user['id'],
    ':hash' => $tokenHash,
    ':exp'  => $expiresAt,
]);

echo json_encode(['token' => $token]);
