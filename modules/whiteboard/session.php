<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once ROOT_PATH . '/database/config/db.php';
require_once ROOT_PATH . '/security/middleware/AuthMiddleware.php';

AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth();
$db = Database::getInstance();

$workspaceId = (int)($_GET['workspace_id'] ?? 0);
$whiteboardId = (int)($_GET['whiteboard_id'] ?? 0);
$channelId = (int)($_GET['channel_id'] ?? 0);

if ($workspaceId < 1 || $whiteboardId < 1 || $channelId < 1) {
    http_response_code(400);
    exit('A Coworkspace whiteboard session is required.');
}

$stmt = $db->prepare('SELECT wb.id AS id, wb.title, wb.description, cw.server_id, cw.channel_id FROM collab_whiteboards wb INNER JOIN collab_workspaces cw ON cw.id=wb.workspace_id WHERE wb.id=:id AND wb.workspace_id=:wid LIMIT 1');
$stmt->execute([':id' => $whiteboardId, ':wid' => $workspaceId]);
$board = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$board) {
    http_response_code(404);
    exit('Whiteboard session not found.');
}

$serverId = (int)$board['server_id'];
$workspaceChannelId = (int)$board['channel_id'];
if ($serverId < 1 || $workspaceChannelId < 1 || $workspaceChannelId !== $channelId) {
    http_response_code(400);
    exit('Invalid Coworkspace navigation context.');
}

$stmt = $db->prepare('SELECT id FROM channels WHERE id=:cid LIMIT 1');
$stmt->execute([':cid' => $channelId]);
if (!$stmt->fetchColumn()) {
    http_response_code(404);
    exit('Coworkspace channel not found.');
}

$csrf = AuthMiddleware::csrfToken();
$title = (string)$board['title'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> – Whiteboard</title>
<meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
<script src="<?= BASE_URL ?>/assets/js/accessibility-apply.js" defer></script>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/desktop/whiteboard.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/mobile/whiteboard-mobile.css">
<style>
html,body{margin:0;height:100%;overflow:hidden;background:#0b0f1a}
#wbOverlay{display:flex!important;position:relative!important;inset:auto!important;height:100vh!important}
.wb-page-back{color:#94a3b8;text-decoration:none;font-size:12px;margin-right:8px}
.wb-session-badge{font-size:10px;color:#94a3b8;padding:4px 8px;border:1px solid rgba(255,255,255,.1);border-radius:999px;margin-right:8px}
.wb-version-panel{position:absolute;right:14px;top:58px;z-index:80;width:270px;max-height:60vh;overflow:auto;background:#121826;border:1px solid rgba(255,255,255,.12);padding:12px;border-radius:10px;display:none}
.wb-version-panel.open{display:block}
.wb-version-row{display:flex;align-items:center;gap:7px;padding:7px 0;border-top:1px solid rgba(255,255,255,.06);font-size:11px;color:#cbd5e1}
.wb-version-row a{color:#a855f7;margin-left:auto}
.wb-page-btn{height:30px;padding:0 10px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.06);color:#e2e8f0;border-radius:7px;cursor:pointer;font-size:11px}
.wb-locked #wbCanvas{cursor:not-allowed!important}
.wb-lock-label{font-size:11px;color:#fbbf24;margin-right:5px}
</style>
</head>
<body>
<div id="wbOverlay" class="wb-visible">
  <div class="wb-hdr">
    <a class="wb-page-back" href="<?= BASE_URL ?>/modules/collaboration/whiteboards.php?server_id=<?= $serverId ?>&channel_id=<?= $channelId ?>&workspace_id=<?= $workspaceId ?>">← Whiteboards</a>
    <div class="wb-hdr-logo">&#9997;</div>
    <div class="wb-hdr-titles">
      <div class="wb-hdr-title" id="wbBoardName"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></div>
      <div class="wb-hdr-sub"><?= htmlspecialchars((string)($board['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?: 'Independent Coworkspace session' ?></div>
    </div>
    <div class="wb-hdr-center">