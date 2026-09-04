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
$documents = [];

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
    if ($canUseWorkspace) {
        $docs = $db->prepare('SELECT id, title, file_name, file_type, updated_at FROM collab_documents WHERE channel_id = :cid ORDER BY updated_at DESC');
        $docs->execute([':cid' => $channelId]);
        $documents = $docs->fetchAll(PDO::FETCH_ASSOC);
    }
}

$etherpadUrl = rtrim((string)env('ETHERPAD_URL', ''), '/');
$excalidrawUrl = rtrim((string)env('EXCALIDRAW_URL', ''), '/');
$onlyofficeServerUrl = rtrim((string)env('ONLYOFFICE_DOCUMENT_SERVER_URL', ''), '/');

$frameOrigins = [];
foreach ([$etherpadUrl, $excalidrawUrl] as $configuredUrl) {
    if ($configuredUrl === '') continue;
    $parts = parse_url($configuredUrl);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) continue;
    if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) continue;
    $origin = strtolower($parts['scheme']) . '://' . $parts['host'];
    if (isset($parts['port'])) $origin .= ':' . (int)$parts['port'];
    $frameOrigins[] = $origin;
}
$frameSrc = "'self'" . ($frameOrigins ? ' ' . implode(' ', $frameOrigins) : '');
$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; font-src 'self' data: https:; connect-src 'self'; frame-src {$frameSrc}; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, private');

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
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/desktop/external-collab.css?v=2">
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
            <div><span class="ec-kicker">WORKSPACE</span><h2><?= htmlspecialchars((string)$channel['name']) ?></h2></div>
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
                <div class="ec-panel-head">
                    <div><h3>Collaborative Documents</h3><p>Word documents, spreadsheets and presentations can be edited by multiple channel members in real time.</p></div>
                    <span class="ec-badge">ONLYOFFICE</span>
                </div>
                <?php if ($onlyofficeServerUrl): ?>
                    <div class="ec-doc-create">
                        <form method="post" action="<?= BASE_URL ?>/API/collaboration/documents.php" id="oo-create-form">
                            <input type="hidden" name="channel_id" value="<?= $channelId ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthMiddleware::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                            <input name="title" maxlength="220" placeholder="Document name" required>
                            <select name="type" aria-label="Document type"><option value="docx">Word document</option><option value="xlsx">Spreadsheet</option><option value="pptx">Presentation</option></select>
                            <button type="submit">＋ Create</button>
                        </form>
                    </div>
                    <div id="oo-create-error" class="ec-error" hidden></div>
                    <?php if ($documents): ?>
                        <div class="ec-doc-list">
                        <?php foreach ($documents as $document): ?>
                            <a class="ec-doc-item" href="<?= BASE_URL ?>/modules/collaboration/documents/editor.php?channel_id=<?= $channelId ?>&id=<?= (int)$document['id'] ?>">
                                <span class="ec-doc-icon"><?= $document['file_type'] === 'xlsx' ? '📊' : ($document['file_type'] === 'pptx' ? '📽️' : '📄') ?></span>
                                <span><strong><?= htmlspecialchars((string)$document['title']) ?></strong><small><?= strtoupper(htmlspecialchars((string)$document['file_type'])) ?> · Updated <?= htmlspecialchars((string)$document['updated_at']) ?></small></span>
                                <span>Open →</span>
                            </a>
                        <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="ec-config"><span>📄</span><div><h3>No collaborative documents yet</h3><p>Create the first document above. It will be stored by eCollab and opened through ONLYOFFICE Docs.</p></div></div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="ec-config"><span>📄</span><div><h3>ONLYOFFICE is not configured yet</h3><p>Set <code>ONLYOFFICE_DOCUMENT_SERVER_URL</code> and <code>ONLYOFFICE_JWT_SECRET</code> after installing ONLYOFFICE Docs Community Edition. The editor uses a signed document URL and validated callbacks; a raw Document Server iframe is not used.</p></div></div>
                <?php endif; ?>
                <div class="ec-note">eCollab owns authentication, channel membership and document storage. ONLYOFFICE provides the editor and real-time co-editing.</div>
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
    const form = document.getElementById('oo-create-form');
    if (form) form.addEventListener('submit', async function (event) {
        event.preventDefault();
        const error = document.getElementById('oo-create-error');
        error.hidden = true;
        try {
            const body = Object.fromEntries(new FormData(form).entries());
            const response = await fetch(form.action, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': body.csrf_token},
                body: JSON.stringify(body)
            });
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.error || 'Could not create document.');
            window.location.href = '<?= BASE_URL ?>/modules/collaboration/documents/editor.php?channel_id=<?= $channelId ?>&id=' + encodeURIComponent(data.document.id);
        } catch (e) {
            error.textContent = e.message;
            error.hidden = false;
        }
    });
})();
</script>
</body>
</html>
