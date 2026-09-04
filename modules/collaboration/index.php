<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once ROOT_PATH . '/database/config/db.php';
require_once ROOT_PATH . '/security/middleware/AuthMiddleware.php';

AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth();

$db = Database::getInstance();
$channelId = (int)($_GET['channel_id'] ?? 0);
$channel = null;
$canUseWorkspace = false;

if ($channelId > 0) {
    $stmt = $db->prepare(<<<SQL
        SELECT c.id, c.name
        FROM channels c
        INNER JOIN channel_members cm ON cm.channel_id = c.id
        WHERE c.id = :cid AND cm.user_id = :uid
        LIMIT 1
    SQL);
    $stmt->execute([':cid' => $channelId, ':uid' => (int)$user['id']]);
    $channel = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $canUseWorkspace = $channel !== null;
}

$etherpadUrl = rtrim((string)env('ETHERPAD_URL', ''), '/');
$excalidrawUrl = rtrim((string)env('EXCALIDRAW_URL', ''), '/');
$onlyofficeUrl = rtrim((string)env('ONLYOFFICE_EDITOR_URL', ''), '/');

// Build a CSP specifically for this page. External frame origins are taken
// only from server-side environment configuration; arbitrary query-string
// URLs are never accepted.
$frameOrigins = [];
foreach ([$etherpadUrl, $excalidrawUrl, $onlyofficeUrl] as $configuredUrl) {
    if ($configuredUrl === '') continue;
    $parts = parse_url($configuredUrl);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) continue;
    if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) continue;
    $origin = strtolower($parts['scheme']) . '://' . $parts['host'];
    if (isset($parts['port'])) $origin .= ':' . (int)$parts['port'];
    $frameOrigins[] = $origin;
}
$frameOrigins = array_values(array_unique($frameOrigins));
$frameSrc = "'self'" . ($frameOrigins ? ' ' . implode(' ', $frameOrigins) : '');
$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; font-src 'self' data: https:; connect-src 'self'; frame-src {$frameSrc}; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, private');

// Do not expose APP_KEY. It is only used server-side to create a stable,
// non-guessable Etherpad pad name for each authorized eCollab channel.
$padName = $channelId > 0
    ? 'ecollab-' . substr(hash_hmac('sha256', 'channel:' . $channelId, (string)APP_KEY), 0, 32)
    : '';
$etherpadEmbed = $etherpadUrl && $padName
    ? $etherpadUrl . '/p/' . rawurlencode($padName) . '?showChat=false&showLineNumbers=false'
    : '';

$initials = strtoupper(substr($user['full_name'] ?: $user['username'], 0, 2));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Collaboration Hub – <?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="csrf-token" content="<?= htmlspecialchars(AuthMiddleware::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/desktop/external-collab.css?v=1">
</head>
<body>
<div class="ec-shell">
    <header class="ec-header">
        <div>
            <a class="ec-back" href="<?= BASE_URL ?>/modules/chat/chat.php">← Back to eCollab</a>
            <h1>Collaboration Hub</h1>
            <p>One eCollab workspace for documents, whiteboards and shared notes.</p>
        </div>
        <div class="ec-user"><span class="ec-avatar"><?= htmlspecialchars($initials) ?></span><?= htmlspecialchars($user['full_name'] ?: $user['username']) ?></div>
    </header>

    <?php if (!$canUseWorkspace): ?>
        <section class="ec-empty">
            <div class="ec-empty-icon">👥</div>
            <h2>Select an authorized channel</h2>
            <p>Open this page with a channel you belong to, for example <code>?channel_id=123</code>. eCollab will reject channels you are not a member of.</p>
        </section>
    <?php else: ?>
        <section class="ec-workspace-head">
            <div>
                <span class="ec-kicker">WORKSPACE</span>
                <h2><?= htmlspecialchars((string)$channel['name']) ?></h2>
            </div>
            <span class="ec-security">🔒 eCollab membership protected</span>
        </section>

        <nav class="ec-tabs" aria-label="Collaboration tools">
            <button class="active" data-tab="notes">📝 Shared Notes</button>
            <button data-tab="whiteboard">🎨 Whiteboard</button>
            <button data-tab="documents">📄 Documents</button>
        </nav>

        <main>
            <section class="ec-panel active" id="tab-notes">
                <?php if ($etherpadEmbed): ?>
                    <div class="ec-panel-head"><div><h3>Shared Notes</h3><p>Real-time multi-user editing powered by your Etherpad instance.</p></div><span class="ec-live">● LIVE</span></div>
                    <iframe class="ec-frame" src="<?= htmlspecialchars($etherpadEmbed, ENT_QUOTES, 'UTF-8') ?>" title="eCollab shared notes"></iframe>
                <?php else: ?>
                    <div class="ec-config"><span>📝</span><div><h3>Etherpad is not configured yet</h3><p>Set <code>ETHERPAD_URL</code> in your local <code>.env</code>, start Etherpad, then reload this workspace.</p></div></div>
                <?php endif; ?>
            </section>

            <section class="ec-panel" id="tab-whiteboard">
                <div class="ec-panel-head"><div><h3>Whiteboard</h3><p>Use an Excalidraw-compatible deployment without replacing eCollab's own whiteboard implementation.</p></div><span class="ec-badge">OPEN SOURCE</span></div>
                <?php if ($excalidrawUrl): ?>
                    <iframe class="ec-frame" src="<?= htmlspecialchars($excalidrawUrl, ENT_QUOTES, 'UTF-8') ?>" title="eCollab whiteboard"></iframe>
                <?php else: ?>
                    <div class="ec-config"><span>🎨</span><div><h3>Excalidraw is not configured yet</h3><p>Set <code>EXCALIDRAW_URL</code> to your approved Excalidraw deployment. The eCollab native whiteboard remains available separately.</p></div></div>
                <?php endif; ?>
            </section>

            <section class="ec-panel" id="tab-documents">
                <div class="ec-panel-head"><div><h3>Collaborative Documents</h3><p>ONLYOFFICE Docs provides Word-like documents, spreadsheets and presentations with real-time co-editing.</p></div><span class="ec-badge">COMMUNITY EDITION</span></div>
                <?php if ($onlyofficeUrl): ?>
                    <iframe class="ec-frame" src="<?= htmlspecialchars($onlyofficeUrl, ENT_QUOTES, 'UTF-8') ?>" title="eCollab collaborative documents"></iframe>
                <?php else: ?>
                    <div class="ec-config"><span>📄</span><div><h3>ONLYOFFICE connector is not configured yet</h3><p>Set <code>ONLYOFFICE_EDITOR_URL</code> after installing ONLYOFFICE Docs Community Edition and wiring its storage/callback integration. Do not expose the Document Server directly as a document URL.</p></div></div>
                <?php endif; ?>
                <div class="ec-note">eCollab controls authentication and channel membership. The document service should enforce its own signed integration requests and callback validation.</div>
            </section>
        </main>
    <?php endif; ?>
</div>
<script nonce="<?= htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') ?>">
(function () {
    const buttons = document.querySelectorAll('.ec-tabs button');
    const panels = document.querySelectorAll('.ec-panel');
    buttons.forEach(button => button.addEventListener('click', () => {
        const tab = button.dataset.tab;
        buttons.forEach(b => b.classList.toggle('active', b === button));
        panels.forEach(p => p.classList.toggle('active', p.id === 'tab-' + tab));
    }));
})();
</script>
</body>
</html>
