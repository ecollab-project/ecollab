<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once ROOT_PATH . '/database/config/db.php';
require_once ROOT_PATH . '/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH . '/services/CoworkspaceService.php';
require_once ROOT_PATH . '/services/OnlyOfficeService.php';

AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth();
$db = Database::getInstance();
$uid = (int)$user['id'];
$documentId = (int)($_GET['id'] ?? 0);
$workspaceId = (int)($_GET['workspace_id'] ?? 0);

if ($documentId < 1 || $workspaceId < 1) {
    http_response_code(400);
    exit('Document and Coworkspace are required.');
}

try {
    $workspace = CoworkspaceService::get($db, $workspaceId, $uid);
    $stmt = $db->prepare(
        'SELECT d.*
         FROM collab_documents d
         WHERE d.id = :did AND d.workspace_id = :wid
         LIMIT 1'
    );
    $stmt->execute([':did' => $documentId, ':wid' => $workspaceId]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$document) throw new RuntimeException('Document not found.', 404);

    $role = (string)($workspace['member_role'] ?? '');
    $canEdit = in_array($role, ['host', 'editor', 'member'], true)
        && (int)$workspace['allow_edit_documents'] === 1;
    $canDelete = in_array($role, ['host', 'editor'], true);

    $documentType = match ((string)$document['file_type']) {
        'xlsx' => 'cell',
        'pptx' => 'slide',
        default => 'word',
    };

    $displayName = (string)($user['full_name'] ?? $user['username'] ?? 'User');
    $config = [
        'documentType' => $documentType,
        'document' => [
            'fileType' => (string)$document['file_type'],
            'key' => (string)$document['document_key'],
            'title' => (string)$document['file_name'],
            'url' => OnlyOfficeService::signedFileUrl($documentId, (string)$document['document_key']),
            'permissions' => [
                'edit' => $canEdit,
                'download' => true,
                'print' => true,
                'comment' => true,
                'review' => true,
            ],
        ],
        'editorConfig' => [
            'mode' => $canEdit ? 'edit' : 'view',
            'callbackUrl' => OnlyOfficeService::callbackUrl($documentId),
            'user' => [
                'id' => (string)$uid,
                'name' => $displayName,
            ],
        ],
        'height' => '100%',
        'width' => '100%',
        'type' => 'desktop',
    ];
    $config['token'] = OnlyOfficeService::sign($config);
    $documentServer = OnlyOfficeService::documentServerUrl();
    if ($documentServer === '') throw new RuntimeException('ONLYOFFICE_DOCUMENT_SERVER_URL is not configured.');
} catch (Throwable $e) {
    $status = (int)$e->getCode();
    http_response_code(($status >= 400 && $status < 600) ? $status : 500);
    exit(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars((string)$document['title'], ENT_QUOTES, 'UTF-8') ?> – Collabs</title>
<style>
html,body{height:100%;width:100%;margin:0;padding:0;background:#0f1117;color:#f5f7fb;font-family:Inter,system-ui,sans-serif;overflow:hidden}body{display:flex;flex-direction:column}.bar{height:52px;min-height:52px;box-sizing:border-box;display:flex;align-items:center;gap:12px;padding:0 16px;background:#171a23;border-bottom:1px solid #292e3b}.back{color:#cbd3e0;text-decoration:none;flex-shrink:0}.title{font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}.presence{margin-left:auto;color:#aeb8ca;font-size:13px;white-space:nowrap;flex-shrink:0}.editor{flex:1;min-height:0;min-width:0;width:100%;height:calc(100vh - 52px);overflow:hidden}.action{border:1px solid #303646;background:#141720;color:#cbd3e0;border-radius:8px;padding:7px 10px;cursor:pointer}.action:hover{background:#252a38}.danger{color:#ff9b9b;border-color:#63343a}.modal-backdrop{display:none;position:fixed;inset:0;background:#0009;z-index:1000;align-items:center;justify-content:center;padding:18px}.modal-backdrop.open{display:flex}.modal{width:min(460px,100%);background:#171a23;border:1px solid #303646;border-radius:14px;box-shadow:0 20px 60px #0008;padding:20px}.modal h3{margin:0 0 15px}.setting{padding:12px 0;border-bottom:1px solid #292e3b}.setting:last-of-type{border-bottom:0}.setting-label{font-size:11px;text-transform:uppercase;color:#8993a6;letter-spacing:.06em}.setting-value{margin-top:4px}.modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:18px}@media(max-width:768px){.bar{height:48px;min-height:48px;padding:0 10px}.presence{font-size:11px;max-width:110px;overflow:hidden;text-overflow:ellipsis}.editor{height:calc(100dvh - 48px)}}
</style>
<script src="<?= BASE_URL ?>/assets/js/accessibility-apply.js" defer></script>
</head>
<body>
<header class="bar">
<a class="back" href="<?= htmlspecialchars(BASE_URL . '/modules/collaboration/server-coworkspaces.php?server_id=' . (int)$workspace['server_id'], ENT_QUOTES, 'UTF-8') ?>">← Collabs</a>
<span class="title">📄 <?= htmlspecialchars((string)$document['title'], ENT_QUOTES, 'UTF-8') ?></span>
<span id="presence" class="presence">Checking collaborators…</span>
<button class="action" type="button" onclick="openSettings()">⚙ Settings</button>
<?php if ($canDelete): ?><button class="action danger" type="button" onclick="deleteDocument()">🗑 Delete</button><?php endif; ?>
</header>
<div id="onlyoffice-editor" class="editor"></div>
<div id="settingsModal" class="modal-backdrop" onclick="if(event.target===this)closeSettings()">
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="settingsTitle">
<h3 id="settingsTitle">Document settings</h3>
<div class="setting"><div class="setting-label">Document</div><div class="setting-value"><?= htmlspecialchars((string)$document['title'], ENT_QUOTES, 'UTF-8') ?></div></div>
<div class="setting"><div class="setting-label">Format</div><div class="setting-value"><?= htmlspecialchars(strtoupper((string)$document['file_type']), ENT_QUOTES, 'UTF-8') ?></div></div>
<div class="setting"><div class="setting-label">Coworkspace role</div><div class="setting-value"><?= htmlspecialchars(ucfirst($role), ENT_QUOTES, 'UTF-8') ?></div></div>
<div class="setting"><div class="setting-label">Editing</div><div class="setting-value"><?= $canEdit ? 'Enabled for your role' : 'View only' ?></div></div>
<div class="setting"><div class="setting-label">Access</div><div class="setting-value">Controlled by this Coworkspace's membership and document-edit permission.</div></div>
<div class="modal-actions"><button class="action" type="button" onclick="closeSettings()">Close</button><?php if ($canDelete): ?><button class="action danger" type="button" onclick="closeSettings();deleteDocument()">Delete document</button><?php endif; ?></div>
</div></div>
<script src="<?= htmlspecialchars($documentServer, ENT_QUOTES, 'UTF-8') ?>/web-apps/apps/api/documents/api.js"></script>
<script>
const OO_CONFIG=<?= json_encode($config,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
const PRESENCE_API=<?= json_encode(BASE_URL.'/API/collaboration/document-presence.php') ?>;
const DELETE_API=<?= json_encode(BASE_URL.'/API/collaboration/delete-document.php') ?>;
const DOC_ID=<?= $documentId ?>;
const WORKSPACE_ID=<?= $workspaceId ?>;
const CSRF=<?= json_encode(AuthMiddleware::csrfToken()) ?>;
const CAN_DELETE=<?= $canDelete?'true':'false' ?>;
let editor;
function openSettings(){document.getElementById('settingsModal').classList.add('open')}
function closeSettings(){document.getElementById('settingsModal').classList.remove('open')}
async function deleteDocument(){
 if(!CAN_DELETE)return;
 if(!confirm('Delete this document permanently? This also removes its stored file.'))return;
 try{
  const r=await fetch(DELETE_API,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify({document_id:DOC_ID,workspace_id:WORKSPACE_ID,csrf_token:CSRF})});
  const d=await r.json();
  if(!r.ok||!d.success)throw new Error(d.error||'Unable to delete document.');
  window.location.href=<?= json_encode(BASE_URL.'/modules/collaboration/server-coworkspaces.php?server_id='.(int)$workspace['server_id']) ?>;
 }catch(e){alert(e.message)}
}
function updatePresence(){fetch(PRESENCE_API+'?document_id='+DOC_ID,{credentials:'same-origin'}).then(r=>r.json()).then(d=>{const p=d.presence||[];const editing=p.filter(x=>x.mode==='editing').length;const viewing=p.length-editing;document.getElementById('presence').textContent=p.length?(editing+' editing · '+viewing+' viewing'):'Only you'}).catch(()=>{})}
function heartbeat(){fetch(PRESENCE_API,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify({document_id:DOC_ID,mode:OO_CONFIG.document.permissions.edit?'editing':'viewing',csrf_token:CSRF})}).then(updatePresence).catch(()=>{})}
function isMobileDevice(){return window.matchMedia('(max-width:768px)').matches||/Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent)}
function initializeOnlyOffice(){if(!window.DocsAPI){document.getElementById('onlyoffice-editor').textContent='ONLYOFFICE editor could not be loaded.';return}OO_CONFIG.type=isMobileDevice()?'mobile':'desktop';editor=new DocsAPI.DocEditor('onlyoffice-editor',OO_CONFIG)}
initializeOnlyOffice();heartbeat();setInterval(heartbeat,20000);setInterval(updatePresence,10000);
</script>
</body>
</html>
