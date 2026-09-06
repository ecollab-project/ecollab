<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once ROOT_PATH . '/security/middleware/AuthMiddleware.php';

AuthMiddleware::startSession();
AuthMiddleware::requireAuth();

$channelId = (int)($_GET['channel_id'] ?? 0);
$serverId = (int)($_GET['server_id'] ?? 0);
$target = BASE_URL . '/modules/collaboration/server-coworkspaces.php';
$params = [];
if ($channelId > 0) $params['channel_id'] = $channelId;
if ($serverId > 0) $params['server_id'] = $serverId;
if ($params) $target .= '?' . http_build_query($params);

header('Cache-Control: no-store, private');
header('Location: ' . $target, true, 302);
exit;
