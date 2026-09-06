<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once ROOT_PATH . '/security/middleware/AuthMiddleware.php';

AuthMiddleware::startSession();
AuthMiddleware::requireAuth();

$channelId = (int)($_GET['channel_id'] ?? 0);
$target = BASE_URL . '/modules/collaboration/coworkspaces.php';
if ($channelId > 0) {
    $target .= '?channel_id=' . rawurlencode((string)$channelId);
}

header('Cache-Control: no-store, private');
header('Location: ' . $target, true, 302);
exit;
