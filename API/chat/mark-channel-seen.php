<?php
declare(strict_types=1);
/**
 * mark-channel-seen.php
 * POST { channel_id } — marks the channel as seen by the current user.
 * The channel list will then show it without the "new" badge.
 *
 * Public channels are also synced into channel_members so collaboration
 * tools that use the channel_members access guard recognize normal server
 * members as having access. Private channels remain explicitly managed.
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
    $uid = (int)$me['id'];

    // Verify the user belongs to the server that owns this channel.
    $channelStmt = $db->prepare("
        SELECT c.id, c.server_id, c.is_private
        FROM channels c
        WHERE c.id = :cid
        LIMIT 1
    ");
    $channelStmt->execute([':cid' => $channelId]);
    $channel = $channelStmt->fetch(PDO::FETCH_ASSOC);

    if (!$channel) {
        http_response_code(404);
        echo json_encode(['error' => 'Channel not found']);
        exit;
    }

    $serverMemberStmt = $db->prepare("
        SELECT 1
        FROM server_members
        WHERE server_id = :sid AND user_id = :uid
        LIMIT 1
    ");
    $serverMemberStmt->execute([
        ':sid' => (int)$channel['server_id'],
        ':uid' => $uid,
    ]);

    if (!$serverMemberStmt->fetchColumn()) {
        http_response_code(403);
        echo json_encode(['error' => 'Not a member of this server']);
        exit;
    }

    // This endpoint is already the authenticated server-side record of which
    // channel the user has opened. Uploads use this state instead of trusting
    // an independently supplied destination identifier.
    $_SESSION['ecollab_upload_channel_id'] = (int)$channel['id'];
    $_SESSION['ecollab_upload_server_id'] = (int)$channel['server_id'];

    // Auto-create channel_seen table if it doesn't exist yet.
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
    ")->execute([':cid' => $channelId, ':uid' => $uid]);

    // The collaboration API currently guards tool access with channel_members.
    // Public channels are accessible to every server member, so keep that
    // access table in sync when the channel is actually opened. Private
    // channel membership is never granted automatically here.
    if ((int)$channel['is_private'] === 0) {
        $db->prepare("
            INSERT IGNORE INTO channel_members (channel_id, user_id)
            VALUES (:cid, :uid)
        ")->execute([':cid' => $channelId, ':uid' => $uid]);
    }

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log('[mark-channel-seen] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
