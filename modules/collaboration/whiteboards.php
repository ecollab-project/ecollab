<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once ROOT_PATH . '/database/config/db.php';
require_once ROOT_PATH . '/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH . '/services/CoworkspaceService.php';

AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth();
$db = Database::getInstance();
$uid = (int)$user['id'];
$workspaceId = (int)($_GET['workspace_id'] ?? 0);
$workspace = $workspaceId > 0 ? CoworkspaceService::get($db, $workspaceId, $uid) : null;
if (!$workspace || (int)($workspace['allow_whiteboard'] ?? 0) !== 1) { http_response_code(404); exit('Whiteboards are unavailable for this Coworkspace.'); }
$serverId = (int)($workspace['server_id'] ?? 0);
$channelId = (int)($workspace['channel_id'] ?? 0);
$csrf = AuthMiddleware::csrfToken();
$role = (string)($workspace['member_role'] ?? '');
$canCreate = in_array($role, ['host','editor','member'], true);
$host = $role === 'host' || (int)$workspace['host_id'] === $uid;
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Whiteboards – <?= htmlspecialchars((string)$workspace['name']) ?></title><meta name="csrf-token" content="<?= htmlspecialchars($csrf,ENT_QUOTES,'UTF-8') ?>"><script src="<?= BASE_URL ?>/assets/js/accessibility-apply.js?v=settings-speed-1" defer></script><link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/desktop/external-collab.css?v=3"><style>body{margin:0;background:#0f1117;color:#f5f7fb;font-family:Inter,system-ui,sans-serif}.page{max-width:1180px;margin:auto;padding:28px}.top{display:flex;justify-content:space-between;align-items:start;gap:20px}.top a{color:#aeb8ca;text-decoration:none}.muted{color:#929caf;font-size:13px}.pill{display:inline-flex;padding:5px 10px;border-radius:999px;background:#252b38;font-size:11px}.actions{display:flex;gap:9px;align-items:center}.btn{border:1px solid #3a4151;background:#202532;color:#fff;border-radius:9px;padding:9px 13px;cursor:pointer}.primary{background:#6d4aff;border-color:#6d4aff}.search{width:100%;box-sizing:border-box;margin:20px 0 14px;background:#141720;color:#fff;border:1px solid #303646;border-radius:10px;padding:12px}.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.card{background:#171a23;border:1px solid #2b303d;border-radius:15px;padding:17px;min-height:150px;display:flex;flex-direction:column}.card:hover{border-color:#4b556b}.icon{font-size:26px}.card h3{margin:12px 0 5px}.meta{margin-top:auto;padding-top:15px;display:flex;justify-content:space-between;gap:8px;align-items:center}.danger{color:#ffb2b2;border-color:#713b45}@media(max-width:900px){.grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:600px){.grid{grid-template-columns:1fr}.top{flex-direction:column}.actions{width:100%}}</style><link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app-design.css?v=1">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/mobile/app-mobile.css?v=2">
<script defer src="<?= BASE_URL ?>/assets/js/mobile-viewport.js?v=2"></script>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/device-layout.css?v=1">
<script defer src="<?= BASE_URL ?>/assets/js/device-layout.js?v=1"></script>
<?php require_once ROOT_PATH.'/includes/layout/color-theme.php'; ?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/resource-creation.css?v=1">
<script src="<?= BASE_URL ?>/assets/js/collaboration/create-resource.js?v=1"></script>
</head><body data-mobile-surface="coworkspace"><div class="page"><header class="top"><div><a href="<?= BASE_URL ?>/modules/collaboration/server-coworkspaces.php?server_id=<?= $serverId ?>&channel_id=<?= $channelId ?>">← Server Coworkspace</a><h1>🖊 Whiteboards</h1><p class="muted">Independent whiteboard sessions inside this Coworkspace.</p></div><div class="actions"><span class="pill"><?= htmlspecialchars($role) ?></span><?php if($canCreate): ?><button class="btn primary" id="newBtn">＋ New Whiteboard</button><?php endif; ?></div></header><input id="search" class="search" placeholder="Search whiteboards..."><div id="grid" class="grid"><div class="muted">Loading whiteboards...</div></div></div><?php require ROOT_PATH.'/includes/layout/create-collab-resource.php'; ?><script>const BASE='<?= BASE_URL ?>',API=BASE+'/API/collaboration/whiteboards.php',CSRF='<?= htmlspecialchars($csrf,ENT_QUOTES,'UTF-8') ?>',WID=<?= $workspaceId ?>,CHANNEL=<?= $channelId ?>,SERVER=<?= $serverId ?>,CAN_DELETE=<?= $host?'true':'false' ?>;let boards=[];const esc=s=>String(s??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));async function req(method,data={}){const opt={method,credentials:'same-origin',headers:{'X-CSRF-Token':CSRF}};let url=API+'?workspace_id='+encodeURIComponent(WID);if(method==='GET'){url+='&'+new URLSearchParams(data)}else{opt.headers['Content-Type']='application/json';opt.body=JSON.stringify({...data,workspace_id:WID,csrf_token:CSRF})}const r=await fetch(url,opt),d=await r.json().catch(()=>({error:'Invalid server response'}));if(!r.ok||!d.success)throw Error(d.error||'Request failed');return d}function render(){const q=document.getElementById('search').value.trim().toLowerCase();const list=boards.filter(b=>!q||String(b.title).toLowerCase().includes(q)||String(b.description||'').toLowerCase().includes(q));document.getElementById('grid').innerHTML=list.length?list.map(b=>`<article class="card"><div class="icon">🖊</div><h3>${esc(b.title)}</h3><p class="muted">${esc(b.description||'No description')}</p><div class="meta"><span class="muted">Updated ${esc(b.updated_at||'')}</span><span><button class="btn" onclick="openBoard(${Number(b.id)})">Open</button> ${CAN_DELETE?`<button class="btn danger" onclick="deleteBoard(${Number(b.id)})">Delete</button>`:''}</span></div></article>`).join(''):'<div class="muted">No whiteboards yet. Create one to start a new session.</div>'}async function load(){try{boards=(await req('GET')).whiteboards||[];render()}catch(e){document.getElementById('grid').innerHTML='<div class="muted">'+esc(e.message)+'</div>'}}function openBoard(id){const url=BASE+'/modules/whiteboard/session.php?server_id='+encodeURIComponent(SERVER)+'&channel_id='+encodeURIComponent(CHANNEL)+'&workspace_id='+encodeURIComponent(WID)+'&whiteboard_id='+encodeURIComponent(id);if(window.EcollabCallContinuity)window.EcollabCallContinuity.navigate(url);else location.href=url}async function deleteBoard(id){if(!confirm('Delete this whiteboard session permanently?'))return;try{await req('POST',{whiteboard_id:id,action:'delete'});await load()}catch(e){alert(e.message)}}document.getElementById('search').addEventListener('input',render);const createBoard = window.EcollabCreateResource(async (_kind, _workspace, fields) => {
  const {title, description, visibility} = fields;
  const data = await req('POST', {action:'create', title, description, visibility});
  openBoard(data.whiteboard.id);
});
document.getElementById('newBtn')?.addEventListener('click', () => createBoard('whiteboard', [
  {id:WID, name:<?= json_encode((string)$workspace['name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>}
]));
load();</script><?php require_once dirname(__DIR__, 2) . '/includes/calls/bootstrap.php'; ?>
</body></html>
