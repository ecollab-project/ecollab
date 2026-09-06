<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once ROOT_PATH . '/database/config/db.php';
require_once ROOT_PATH . '/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH . '/services/OnlyOfficeService.php';
require_once ROOT_PATH . '/services/CoworkspaceService.php';

AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth();
$db = Database::getInstance();
$channelId = (int)($_GET['channel_id'] ?? 0);
$documentId = (int)($_GET['id'] ?? 0);

$member = $db->prepare('SELECT c.id, c.name FROM channels c INNER JOIN channel_members cm ON cm.channel_id = c.id WHERE c.id = :cid AND cm.user_id = :uid LIMIT 1');
$member->execute([':cid' => $channelId, ':uid' => (int)$user['id']]);
$channel = $member->fetch(PDO::FETCH_ASSOC) ?: null;
if (!$channel) { http_response_code(403); exit('Channel access denied.'); }

$stmt = $db->prepare('SELECT * FROM collab_documents WHERE id = :id AND channel_id = :cid LIMIT 1');
$stmt->execute([':id' => $documentId, ':cid' => $channelId]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
if (!$doc) { http_response_code(404); exit('Document not found.'); }

$workspace = null;
$workspaceRole = null;
if (!empty($doc['workspace_id'])) {
    try {
        $workspace = CoworkspaceService::get($db, (int)$doc['workspace_id'], (int)$user['id']);
        if ((int)$workspace['channel_id'] !== $channelId) { http_response_code(403); exit('Coworkspace channel mismatch.'); }
        $workspaceRole = (string)($workspace['member_role'] ?? '');
    } catch (Throwable $e) {
        http_response_code((int)$e->getCode() === 403 ? 403 : 404);
        exit((int)$e->getCode() === 403 ? 'Coworkspace access denied.' : 'Coworkspace not found.');
    }
}

$canEdit = true;
if ($workspace) {
    $canEdit = in_array($workspaceRole, ['host', 'editor'], true)
        || ($workspaceRole === 'member' && (int)$workspace['allow_edit_documents'] === 1);
    if ($workspaceRole === 'viewer' || $workspaceRole === '') $canEdit = false;
}

$serverUrl = OnlyOfficeService::documentServerUrl();
if ($serverUrl === '') { http_response_code(503); exit('ONLYOFFICE_DOCUMENT_SERVER_URL is not configured.'); }
$apiUrl = $serverUrl . '/web-apps/apps/api/documents/api.js';
$permissions = [
    'edit' => $canEdit,
    'download' => true,
    'print' => true,
    'comment' => $canEdit,
    'review' => $canEdit,
];
$config = [
    'document' => [
        'fileType' => (string)$doc['file_type'],
        'key' => (string)$doc['document_key'],
        'title' => (string)$doc['file_name'],
        'url' => OnlyOfficeService::signedFileUrl((int)$doc['id'], (string)$doc['document_key']),
        'permissions' => $permissions,
    ],
    'documentType' => match ((string)$doc['file_type']) {
        'xlsx' => 'cell',
        'pptx' => 'slide',
        default => 'word',
    },
    'editorConfig' => [
        'mode' => $canEdit ? 'edit' : 'view',
        'lang' => 'en',
        'callbackUrl' => OnlyOfficeService::callbackUrl((int)$doc['id']),
        'user' => [
            'id' => (string)$user['id'],
            'name' => (string)($user['full_name'] ?: $user['username']),
        ],
        'coEditing' => [
            'mode' => 'fast',
            'change' => $canEdit,
        ],
        'customization' => [
            'autosave' => true,
            'forcesave' => false,
            'compactHeader' => false,
        ],
    ],
];
$config['token'] = OnlyOfficeService::sign($config);
$nonce = base64_encode(random_bytes(16));
$origin = parse_url($serverUrl, PHP_URL_SCHEME) . '://' . parse_url($serverUrl, PHP_URL_HOST);
if (parse_url($serverUrl, PHP_URL_PORT)) $origin .= ':' . (int)parse_url($serverUrl, PHP_URL_PORT);
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}' {$origin}; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: {$origin}; font-src 'self' data: {$origin}; connect-src 'self' {$origin}; frame-src {$origin}; frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, private');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars((string)$doc['title']) ?> – eCollab</title>
<style>
html,body,#placeholder{margin:0;width:100%;height:100%;overflow:hidden;font-family:system-ui,sans-serif} body{background:#f5f5f5}.top{height:44px;display:flex;align-items:center;gap:12px;padding:0 16px;background:#fff;border-bottom:1px solid #ddd;box-sizing:border-box}.top a{color:#555;text-decoration:none}.top strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.editor{height:calc(100% - 44px)}
</style>
<script nonce="<?= htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') ?>" src="<?= htmlspecialchars($apiUrl, ENT_QUOTES, 'UTF-8') ?>"></script>
</head>
<body>
<div class="top"><a href="<?= BASE_URL ?>/modules/collaboration/coworkspaces.php?channel_id=<?= $channelId ?><?= $workspace ? '&workspace_id=' . (int)$workspace['id'] : '' ?>">← Back</a><strong><?= htmlspecialchars((string)$doc['title']) ?></strong><span>• <?= htmlspecialchars((string)$channel['name']) ?></span><?php if ($workspace): ?><span>• <?= htmlspecialchars((string)$workspace['name']) ?></span><?php endif; ?></div>
<div id="placeholder" class="editor"></div>
<script nonce="<?= htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') ?>">
window.addEventListener('load', function () {
    const config = <?= json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    new DocsAPI.DocEditor('placeholder', config);
});
</script>
</body>
</html>
