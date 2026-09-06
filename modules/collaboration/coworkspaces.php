<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once ROOT_PATH . '/database/config/db.php';
require_once ROOT_PATH . '/security/middleware/AuthMiddleware.php';

AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth();
$channelId = (int)($_GET['channel_id'] ?? 0);
$db = Database::getInstance();

$channel = null;
$workspaces = [];
if ($channelId > 0) {
    $stmt = $db->prepare(
        'SELECT c.id,c.name FROM channels c
         INNER JOIN channel_members cm ON cm.channel_id=c.id
         WHERE c.id=:cid AND cm.user_id=:uid LIMIT 1'
    );
    $stmt->execute([':cid' => $channelId, ':uid' => (int)$user['id']]);
    $channel = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($channel) {
        $stmt = $db->prepare(
            'SELECT w.id,w.name,w.visibility,w.host_id,w.allow_create_documents,w.allow_edit_documents,
                    w.allow_whiteboard,w.allow_member_invites,
                    COALESCE(m.role,IF(w.host_id=:uid_host,"host",NULL)) AS member_role,
                    (SELECT COUNT(*) FROM collab_workspace_members x WHERE x.workspace_id=w.id) AS member_count
             FROM collab_workspaces w
             LEFT JOIN collab_workspace_members m ON m.workspace_id=w.id AND m.user_id=:uid_member
             WHERE w.channel_id=:cid AND w.archived=0
             ORDER BY w.updated_at DESC'
        );
        $stmt->execute([':uid_host' => (int)$user['id'], ':uid_member' => (int)$user['id'], ':cid' => $channelId]);
        $workspaces = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
$csrf = AuthMiddleware::csrfToken();
$initials = strtoupper(substr((string)($user['full_name'] ?: $user['username']), 0, 2));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Coworkspaces – <?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></title>
<meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/desktop/external-collab.css?v=3">
<style>
body{margin:0;background:#0f1117;color:#f5f7fb;font-family:Inter,system-ui,sans-serif}.cw{max-width:1180px;margin:auto;padding:28px}.cw-top{display:flex;justify-content:space-between;align-items:center;gap:20px}.cw-top a{color:#aeb8ca;text-decoration:none}.cw-grid{display:grid;grid-template-columns:280px 1fr;gap:20px;margin-top:24px}.cw-side,.cw-main{background:#171a23;border:1px solid #292e3b;border-radius:16px}.cw-side{padding:16px}.cw-main{padding:24px}.cw-side h3{font-size:12px;text-transform:uppercase;color:#8e98aa;letter-spacing:.08em}.cw-item{display:block;width:100%;box-sizing:border-box;text-align:left;background:transparent;border:0;color:#fff;padding:12px;border-radius:10px;cursor:pointer;margin:4px 0}.cw-item:hover,.cw-item.active{background:#232837}.cw-item small{display:block;color:#8993a7;margin-top:4px}.cw-title{display:flex;justify-content:space-between;align-items:start;gap:16px}.cw-title h2{margin:0 0 6px}.cw-title p{margin:0;color:#98a1b2}.pill{display:inline-flex;padding:5px 9px;border-radius:999px;font-size:11px;background:#242a38;color:#c8d0de}.green{color:#8ee6a8}.lock{color:#ffd479}.cw-actions{display:flex;gap:10px;margin:22px 0;flex-wrap:wrap}.btn{border:1px solid #363d4d;background:#202532;color:#fff;border-radius:9px;padding:9px 13px;cursor:pointer}.btn.primary{background:#6d4aff;border-color:#6d4aff}.cards{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.card{border:1px solid #2d3340;border-radius:12px;padding:16px;background:#141720}.card h4{margin:0 0 6px}.muted{color:#8e98aa;font-size:13px}.empty{padding:42px 15px;text-align:center;color:#9aa4b6}.overlay{position:fixed;inset:0;background:rgba(0,0,0,.65);display:none;align-items:center;justify-content:center;padding:20px}.modal{width:min(560px,100%);background:#181c26;border:1px solid #333949;border-radius:16px;padding:22px}.modal label{display:block;color:#b8c0cf;font-size:13px;margin:14px 0 6px}.modal input,.modal select{width:100%;box-sizing:border-box;background:#10131a;color:#fff;border:1px solid #363d4d;border-radius:8px;padding:10px}.choices{display:grid;grid-template-columns:1fr 1fr;gap:10px}.choice{border:1px solid #363d4d;border-radius:10px;padding:12px;cursor:pointer}.choice input{width:auto}.check{display:block;margin:9px 0;color:#b8c0cf;font-size:13px}.notice{margin:12px 0;padding:10px;border-radius:9px;background:#232837;color:#cbd3e0}.danger{color:#ff9b9b}@media(max-width:800px){.cw-grid{grid-template-columns:1fr}.cards{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="cw">
<header class="cw-top"><div><a href="<?= BASE_URL ?>/modules/collaboration/index.php?channel_id=<?= $channelId ?>">← Back to Collaboration Hub</a><h1>🤝 Coworkspaces</h1><p class="muted">Persistent collaboration spaces for documents and whiteboards.</p></div><div class="pill">👤 <?= htmlspecialchars($initials) ?></div></header>
<?php if (!$channel): ?>
<section class="cw-main empty"><h2>Select an authorized channel</h2><p>Open this page from a channel you belong to.</p></section>
<?php else: ?>
<div class="cw-grid">
<aside class="cw-side"><h3>Your Coworkspaces</h3><div id="workspace-list">
<?php foreach ($workspaces as $w): ?>
<button class="cw-item" data-id="<?= (int)$w['id'] ?>"><span><?= (string)$w['visibility']==='private'?'🔒':'🟢' ?> <?= htmlspecialchars((string)$w['name']) ?></span><small><?= (int)$w['member_count'] ?> people · <?= htmlspecialchars((string)($w['member_role'] ?: 'Join')) ?></small></button>
<?php endforeach; ?>
</div><button class="btn primary" id="createBtn">＋ Create Coworkspace</button></aside>
<main class="cw-main" id="detail"><div class="empty"><h2>Select a Coworkspace</h2><p>Create one or select a Coworkspace from the list.</p></div></main>
</div>
<?php endif; ?>
</div>
<div class="overlay" id="overlay"><form class="modal" id="createForm"><h2>Create Coworkspace</h2><label>Name</label><input name="name" maxlength="200" placeholder="Thesis Project" required><label>Type</label><div class="choices"><label class="choice"><input type="radio" name="visibility" value="public" checked> <b>Public</b><br><span class="muted">Anyone in this channel can join.</span></label><label class="choice"><input type="radio" name="visibility" value="private"> <b>Private</b><br><span class="muted">Only invited or approved members can join.</span></label></div><label>Tools & permissions</label><label class="check"><input type="checkbox" name="allow_create_documents" checked> Allow members to create documents</label><label class="check"><input type="checkbox" name="allow_edit_documents" checked> Allow members to edit documents</label><label class="check"><input type="checkbox" name="allow_whiteboard" checked> Allow members to use whiteboard</label><label class="check"><input type="checkbox" name="allow_member_invites"> Allow editors to invite others</label><div class="cw-actions"><button type="button" class="btn" id="cancelBtn">Cancel</button><button class="btn primary">Create</button></div><div id="createError" class="notice danger" hidden></div></form></div>
<script>
const API='<?= BASE_URL ?>/API/collaboration/workspaces.php', MEMBERS='<?= BASE_URL ?>/API/collaboration/workspace-members.php', CSRF='<?= htmlspecialchars($csrf,ENT_QUOTES,'UTF-8') ?>', CHANNEL=<?= $channelId ?>;
const state={workspaces:<?= json_encode($workspaces,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>};
const esc=s=>String(s??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
async function post(url,data){const r=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify({...data,csrf_token:CSRF})});const d=await r.json();if(!r.ok||!d.success)throw new Error(d.error||'Request failed');return d}
function openWorkspace(id){const w=state.workspaces.find(x=>Number(x.id)===Number(id));if(!w)return;document.querySelectorAll('.cw-item').forEach(x=>x.classList.toggle('active',Number(x.dataset.id)===Number(id)));const joined=!!w.member_role;const host=String(w.member_role)==='host';document.getElementById('detail').innerHTML=`<div class="cw-title"><div><span class="pill">${w.visibility==='private'?'🔒 PRIVATE':'🟢 PUBLIC'}</span><h2>${esc(w.name)}</h2><p>${Number(w.member_count)} collaborators · ${joined?'Your role: '+esc(w.member_role):'Not joined yet'}</p></div>${host?'<button class="btn" id="settingsBtn">⚙ Settings</button>':''}</div><div class="cw-actions">${joined?'<button class="btn primary" id="enterBtn">Open Workspace</button>':(w.visibility==='public'?'<button class="btn primary" id="joinBtn">Join Coworkspace</button>':'<button class="btn primary" id="requestBtn">Request Access</button>')}</div><div class="cards"><div class="card"><h4>📄 Documents</h4><p class="muted">ONLYOFFICE Word, Excel and PowerPoint documents can live inside this Coworkspace.</p></div><div class="card"><h4>🖊 Whiteboard</h4><p class="muted">Shared whiteboard access is controlled by the Coworkspace permissions.</p></div><div class="card"><h4>👥 Members</h4><p class="muted">Host, editor, member and viewer roles are enforced server-side.</p></div><div class="card"><h4>🟢 Presence</h4><p class="muted">The workspace is persistent; live presence remains a real-time layer rather than workspace membership.</p></div></div>`;document.getElementById('joinBtn')?.addEventListener('click',async()=>{await post(API,{action:'join',workspace_id:id});location.reload()});document.getElementById('requestBtn')?.addEventListener('click',async()=>{await post(API,{action:'request_access',workspace_id:id});alert('Access request sent to the host.');});document.getElementById('enterBtn')?.addEventListener('click',()=>location.href='<?= BASE_URL ?>/modules/collaboration/index.php?channel_id='+CHANNEL+'&workspace_id='+id);document.getElementById('settingsBtn')?.addEventListener('click',()=>alert('Host settings are available through the workspace API and can be wired into this panel without bypassing server authorization.'))}
document.querySelectorAll('.cw-item').forEach(x=>x.addEventListener('click',()=>openWorkspace(x.dataset.id)));
document.getElementById('createBtn')?.addEventListener('click',()=>document.getElementById('overlay').style.display='flex');document.getElementById('cancelBtn')?.addEventListener('click',()=>document.getElementById('overlay').style.display='none');document.getElementById('createForm')?.addEventListener('submit',async e=>{e.preventDefault();const f=e.currentTarget,d=Object.fromEntries(new FormData(f));try{await post(API,{action:'create',channel_id:CHANNEL,...d,allow_create_documents:f.allow_create_documents.checked,allow_edit_documents:f.allow_edit_documents.checked,allow_whiteboard:f.allow_whiteboard.checked,allow_member_invites:f.allow_member_invites.checked});location.reload()}catch(err){const el=document.getElementById('createError');el.textContent=err.message;el.hidden=false}});
</script>
</body>
</html>
