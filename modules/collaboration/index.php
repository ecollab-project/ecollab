<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once ROOT_PATH . '/database/config/db.php';
require_once ROOT_PATH . '/security/middleware/AuthMiddleware.php';

AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth();
$db = Database::getInstance();

$channelId = (int)($_GET['channel_id'] ?? 0);
$serverId = (int)($_GET['server_id'] ?? 0);

// The Collaboration Hub is the routing bridge into Server Coworkspaces.
// If an older/internal link only carries channel_id, recover server_id from
// the channel instead of dropping the server context during navigation.
if ($serverId < 1 && $channelId > 0) {
    $stmt = $db->prepare(
        'SELECT c.server_id
         FROM channels c
         INNER JOIN channel_members cm ON cm.channel_id = c.id
         WHERE c.id = :cid AND cm.user_id = :uid
         LIMIT 1'
    );
    $stmt->execute([':cid' => $channelId, ':uid' => (int)$user['id']]);
    $serverId = (int)($stmt->fetchColumn() ?: 0);
}

$target = BASE_URL . '/modules/collaboration/server-coworkspaces.php';
$params = [];
if ($serverId > 0) $params['server_id'] = $serverId;
if ($channelId > 0) $params['channel_id'] = $channelId;
if ($params) $target .= '?' . http_build_query($params);

header('Cache-Control: no-store, private');
header('Location: ' . $target, true, 302);
exit;
