<?php
declare(strict_types=1);

/**
 * API/auth/ws-token.php
 *
 * Issues a short-lived, one-time WebSocket auth token for the authenticated session.
 * The JavaScript chat client fetches this token before opening the WebSocket, then
 * sends it in the first { "type": "auth", "ws_token": "<token>" } message.
 *
 * The ChatServer verifies the token against the ws_tokens table (hash comparison)
 * and deletes it on first use, preventing replay attacks.
 *
 * GET /API/auth/ws-token.php
 * Response: { "token": "<hex>" }
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/security/AuditLogger.php';
require_once dirname(__DIR__, 2) . '/security/SecurityHeaders.php';

header('Content-Type: application/json');
SecurityHeaders::send(isApi: true);
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true); // returns JSON 401 if unauthenticated

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$db = Database::getInstance();

// Ensure ws_tokens table exists
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

// Expire old tokens for this user (housekeeping)
$db->prepare("DELETE FROM ws_tokens WHERE user_id = :uid OR expires_at < NOW()")
   ->execute([':uid' => $user['id']]);

// Generate a cryptographically random token
$token     = bin2hex(random_bytes(32)); // 64 hex chars, 256 bits
$tokenHash = hash('sha256', $token);
$expiresAt = date('Y-m-d H:i:s', time() + 120); // 2-minute window

$db->prepare("
    INSERT INTO ws_tokens (user_id, token_hash, expires_at)
    VALUES (:uid, :hash, :exp)
")->execute([
    ':uid'  => $user['id'],
    ':hash' => $tokenHash,
    ':exp'  => $expiresAt,
]);

echo json_encode(['token' => $token]);
