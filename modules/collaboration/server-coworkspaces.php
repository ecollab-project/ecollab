<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once ROOT_PATH . '/database/config/db.php';
require_once ROOT_PATH . '/security/middleware/AuthMiddleware.php';

AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth();
$csrf = AuthMiddleware::csrfToken();
$channelId = (int)($_GET['channel_id'] ?? 0);
$serverId = (int)($_GET['server_id'] ?? 0);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Collabs – <?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#0f1117;color:#f5f7fb;font-family:Inter,system-ui,sans-serif}.shell{max-width:1180px;margin:auto;padding:28px}.top{display:flex;justify-content:space-between;align-items:center;gap:20px}.back{color:#aeb8ca;text-decoration:none}.layout{display:grid;grid-template-columns:250px 1fr;gap:20px;margin-top:24px}.panel{background:#171a23;border:1px solid #292e3b;border-radius:16px}.side{padding:16px}.main{padding:26px}.label{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:#8e98aa}.server{margin:7px 0 24px;font-size:15px;color:#e3e8f1;font-weight:600}.nav-item{width:100%;border:0;background:transparent;color:#cbd3e0;text-align:left;padding:12px;border-radius:10px;cursor:pointer;margin:3px 0;font-size:14px}.nav-item:hover,.nav-item.active{background:#232837;color:#fff}.empty{text-align:center;padding:48px 20px;color:#9aa4b6}.title{display:flex;justify-content:space-between;gap:15px;align-items:flex-start}.title h1{margin:8px 0}.muted{color:#98a1b2}.pill{display:inline-flex;padding:5px 9px;border-radius:999px;background:#242a38;font-size:11px}.green{color:#8ee6a8}.cards{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:24px}.card{padding:20px;border:1px solid #2d3340;border-radius:12px;background:#141720;cursor:pointer}.card:hover{background:#1a1e29}.card strong{display:block;margin-bottom:7px;font-size:15px}.card .count{margin-top:15px;font-size:12px;color:#8ee6a8}.notice{padding:12px;border-radius:10px;background:#232837;color:#cbd3e0}@media(max-width:800px){.layout,.cards{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="shell">
<header class="top"><div><a class="back" id="backToChat" href="<?= BASE_URL ?>/modules/chat/chat.php">← Back to Chat</a><h1>🤝 Collabs</h1><p class="muted">Your selected Chat server has one shared collaboration container. All of its channels use the same Documents and Whiteboard space.</p></div><span class="pill">👤 <?= htmlspecialchars((string)($user['full_name'] ?: $user['username']), ENT_QUOTES, 'UTF-8') ?></span></header>
<div class="layout">
<aside class="panel side">
  <div class="label">Server</div>
  <div id="serverName" class="server">Loading server…</div>
  <div class="label">Collabs</div>
  <button class="nav-item active" id="documentsNav">📄 Documents</button>
  <button class="nav-item" id="whiteboardNav">🖊 Whiteboard</button>
</aside>
<main class="panel main" id="detail"><div class="empty"><h2>Loading your server Coworkspace…</h2><p>The Coworkspace is created automatically when a server member enters Collabs.</p></div></main>
</div>
</div>
<script>
const API='<?= BASE_URL ?>/API/collaboration/server-coworkspaces.php',CSRF='<?= htmlspecialchars($csrf,ENT_QUOTES,'UTF-8') ?>',CHANNEL=<?= $channelId ?>,INITIAL_SERVER=<?= $serverId ?>,BASE='<?= BASE_URL ?>';
const state={server:null,workspace:null,view:'documents'};
const esc=s=>String(s??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
async function req(data={},method='GET'){const opt={method,headers:{'X-CSRF-Token':CSRF}};if(method!=='GET'){opt.headers['Content-Type']='application/json';opt.body=JSON.stringify({...data,csrf_token:CSRF})}const q=method==='GET'?'?'+new URLSearchParams(data):'';const r=await fetch(API+q,opt);const d=await r.json().catch(()=>({error:'Invalid server response'}));if(!r.ok||!d.success)throw new Error(d.error||'Request failed');return d}
function render(){const w=state.workspace;if(!w)return;document.getElementById('serverName').textContent=state.server.name;document.getElementById('documentsNav').classList.toggle('active',state.view==='documents');document.getElementById('whiteboardNav').classList.toggle('active',state.view==='whiteboard');const active=Number(w.active_count||0),members=Number(w.member_count||0);const content=state.view==='documents'?`<div class="title"><div><span class="pill">📄 DOCUMENTS</span><h1>${esc(w.name)}</h1><p class="muted">Shared documents for everyone in <b>${esc(state.server.name)}</b>.</p></div><span class="pill green">🟢 ${active} active</span></div><div class="cards"><div class="card"><strong>📄 Word Documents</strong><span class="muted">Create and open collaborative word-processing documents.</span><div class="count">${members} collaborators</div></div><div class="card"><strong>📊 Excel Spreadsheets</strong><span class="muted">Shared spreadsheets available to this server's Coworkspace.</span><div class="count">${members} collaborators</div></div><div class="card"><strong>📽 PowerPoint Presentations</strong><span class="muted">Shared presentations available to this server's Coworkspace.</span><div class="count">${members} collaborators</div></div></div>`:`<div class="title"><div><span class="pill">🖊 WHITEBOARD</span><h1>${esc(state.server.name)} Whiteboard</h1><p class="muted">A shared whiteboard independent from individual Chat channels.</p></div><span class="pill green">🟢 ${active} active</span></div><div class="card" style="margin-top:24px;cursor:default"><strong>🖊 Active collaborators</strong><p class="muted">${members} server collaborators have access to this Coworkspace. The live Whiteboard surface can be opened here without changing the Chat channel.</p></div>`;document.getElementById('detail').innerHTML=content}
async function boot(){try{const params=INITIAL_SERVER?{server_id:INITIAL_SERVER}:{channel_id:CHANNEL};const d=await req(params);state.server=d.server;state.workspace=(d.workspaces||[])[0]||null;if(!state.workspace)throw new Error('The server Coworkspace could not be synchronized.');document.getElementById('backToChat').href=BASE+'/modules/chat/chat.php?server_id='+encodeURIComponent(state.server.id);render()}catch(e){document.getElementById('serverName').textContent='Unable to load';document.getElementById('detail').innerHTML='<div class="notice">'+esc(e.message)+'</div>'}}
document.getElementById('documentsNav').onclick=()=>{state.view='documents';render()};document.getElementById('whiteboardNav').onclick=()=>{state.view='whiteboard';render()};boot();
</script>
</body></html>
