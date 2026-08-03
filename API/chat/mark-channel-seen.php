<?php
declare(strict_types=1);
/**
 * mark-channel-seen.php
 * POST { channel_id } — marks the channel as seen by the current user.
 * The channel list will then show it without the "(new)" badge.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$me = AuthMiddleware::requireAuth(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$channelId = (int)($body['channel_id'] ?? 0);

if (!$channelId) {
    http_response_code(400);
    echo json_encode(['error' => 'channel_id required']);
    exit;
}

try {
    $db = Database::getInstance();

    // Auto-create channel_seen table if it doesn't exist yet
    $db->exec("
        CREATE TABLE IF NOT EXISTS channel_seen (
            channel_id  INT UNSIGNED    NOT NULL,
            user_id     BIGINT UNSIGNED NOT NULL,
            first_seen_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (channel_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $db->prepare("
        INSERT IGNORE INTO channel_seen (channel_id, user_id)
        VALUES (:cid, :uid)
    ")->execute([':cid' => $channelId, ':uid' => $me['id']]);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log('[mark-channel-seen] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
