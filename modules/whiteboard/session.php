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

$stmt = $db->prepare('SELECT id,title,description,server_id,channel_id FROM collab_whiteboards wb INNER JOIN collab_workspaces cw ON cw.id=wb.workspace_id WHERE wb.id=:id AND wb.workspace_id=:wid LIMIT 1');
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
      <button class="wb-icon-btn" onclick="wbUndo()" title="Undo">&#8630;</button>
      <button class="wb-icon-btn" onclick="wbRedo()" title="Redo">&#8631;</button>
      <select class="wb-zoom-sel" id="wbZoom" onchange="wbSetZoom(this.value)"><option>50%</option><option>75%</option><option selected>100%</option><option>125%</option><option>150%</option></select>
    </div>
    <div class="wb-hdr-right">
      <span id="wbLockLabel" class="wb-lock-label"></span>
      <span class="wb-session-badge">Session #<?= $whiteboardId ?></span>
      <div class="wb-av-stack" id="wbAvStack"></div>
      <button class="wb-page-btn" onclick="wbSaveSessionNow()">Save</button>
    </div>
  </div>
  <div class="wb-body">
    <div class="wb-tools">
      <button class="wb-tbtn wb-active" data-tool="cursor" data-tip="Select" onclick="wbPickTool(this)">&#9654;</button>
      <button class="wb-tbtn" data-tool="pen" data-tip="Pen" onclick="wbPickTool(this)">&#9998;</button>
      <button class="wb-tbtn" data-tool="highlight" data-tip="Highlight" onclick="wbPickTool(this)">&#9644;</button>
      <button class="wb-tbtn" data-tool="eraser" data-tip="Eraser" onclick="wbPickTool(this)">&#9003;</button>
      <div class="wb-tsep"></div>
      <button class="wb-tbtn" data-tool="text" data-tip="Text" onclick="wbPickTool(this)">T</button>
      <button class="wb-tbtn" data-tool="sticky" data-tip="Sticky note" onclick="wbPickTool(this)">&#9632;</button>
      <button class="wb-tbtn" data-tool="arrow" data-tip="Arrow" onclick="wbPickTool(this)">&#8594;</button>
    </div>
    <div class="wb-canvas-wrap" id="wbCanvasWrap">
      <canvas id="wbCanvas"></canvas><div id="wbObjects"></div>
      <div class="wb-bottom-bar">
        <div class="wb-clr sel" style="background:#a855f7" onclick="wbClr(this,'#a855f7')"></div>
        <div class="wb-clr" style="background:#fff" onclick="wbClr(this,'#fff')"></div>
        <div class="wb-clr" style="background:#22c55e" onclick="wbClr(this,'#22c55e')"></div>
        <div class="wb-vsep"></div><button class="wb-sz-btn" onclick="wbSzDown()">-</button><span class="wb-sz-lbl" id="wbSzLbl">2px</span><button class="wb-sz-btn" onclick="wbSzUp()">+</button>
      </div>
    </div>
    <div class="wb-rsidebar" id="wbRightSidebar">
      <div class="wb-tabs"><div class="wb-tab wb-tab-active" onclick="wbTab(this,'wbPanMembers')">Members</div><div class="wb-tab" onclick="wbTab(this,'wbPanActivity')">Activity</div></div>
      <div class="wb-panel wb-panel-active" id="wbPanMembers"><div class="wb-section-lbl" id="wbMembersLabel">WHITEBOARD MEMBERS - 0</div><div id="wbMemberList"></div></div>
      <div class="wb-panel" id="wbPanActivity"><div class="wb-section-lbl">ACTIVITY</div><div class="wb-activity" id="wbActivityLog"></div></div>
    </div>
  </div>
  <div class="wb-status"><div class="wb-status-item">&#9679; <span id="wbSaveLabel">Loading session…</span></div><div class="wb-status-item" id="wbSessionStatus">Loading session…</div></div>
</div>

<script>
window.ECOLLAB={
  baseUrl:<?= json_encode(BASE_URL) ?>,
  csrfToken:<?= json_encode($csrf) ?>,
  userId:<?= (int)$user['id'] ?>,
  username:<?= json_encode($user['username']) ?>,
  currentServerId:<?= $serverId ?>,
  currentChannelId:<?= $channelId ?>,
  workspaceId:<?= $workspaceId ?>,
  whiteboardId:<?= $whiteboardId ?>,
  whiteboardStandalone:true,
  whiteboardSessionMode:true
};
window.__USER__={id:<?= (int)$user['id'] ?>,username:<?= json_encode($user['username']) ?>,role:<?= json_encode($user['role']) ?>};
window.__currentServerId=<?= $serverId ?>;
window.__currentChannelId=<?= $channelId ?>;
window.__currentWhiteboardId=<?= $whiteboardId ?>;
window.__currentWorkspaceId=<?= $workspaceId ?>;
function showToast(message){const el=document.getElementById('wbSessionStatus');if(el)el.textContent=message;}
</script>
<script src="<?= BASE_URL ?>/assets/js/chat/whiteboard.js"></script>
<script>
(function(){
  const API=<?= json_encode(BASE_URL . '/API/collaboration/whiteboards.php') ?>;
  const WID=<?= $workspaceId ?>, BID=<?= $whiteboardId ?>;
  let lastUpdated='';
  let saveTimer=null;
  let polling=null;

  function request(method, body){
    const opts={method,credentials:'same-origin',headers:{'X-CSRF-Token':window.ECOLLAB.csrfToken}};
    let url=API+'?workspace_id='+encodeURIComponent(WID)+'&whiteboard_id='+encodeURIComponent(BID);
    if(method!=='GET'){
      opts.headers['Content-Type']='application/json';
      opts.body=JSON.stringify(Object.assign({},body||{}, {workspace_id:WID,whiteboard_id:BID,csrf_token:window.ECOLLAB.csrfToken}));
    }
    return fetch(url,opts).then(async r=>{const d=await r.json().catch(()=>({error:'Invalid server response'}));if(!r.ok||!d.success)throw Error(d.error||'Whiteboard request failed');return d;});
  }

  function localState(){return {paths:wbState.paths||[],objects:wbState.objects?Object.keys(wbState.objects).map(k=>{const e=wbState.objects[k];return {id:k,x:parseFloat(e.style.left)||0,y:parseFloat(e.style.top)||0,text:e.textContent||''};}):[],savedAt:new Date().toISOString()};}

  window.wbApi=function(action,body={},method='GET'){
    if(action==='state') return request('GET').then(d=>({whiteboard:{state_json:d.whiteboard.state,locked:false,is_host:true}}));
    if(action==='save') return request('POST',{action:'save',state:body.state||localState()});
    return Promise.resolve({versions:[]});
  };

  async function saveNow(){
    if(!wbState.open)return;
    try{
      const state=localState();
      const d=await request('POST',{action:'save',state});
      lastUpdated=d.updated_at||new Date().toISOString();
      wbState.dirty=false;
      const lbl=document.getElementById('wbSaveLabel');if(lbl)lbl.textContent='Saved just now';
      showToast('Saved session #'+BID);
    }catch(e){showToast('Save failed: '+e.message);}
  }
  window.wbSaveSessionNow=saveNow;
  window.wbSaveVersion=saveNow;
  window.wbLoadVersions=function(){};
  window.wbRestoreVersion=function(){};
  window.wbToggleVersions=function(){};
  window.wbToggleLock=function(){showToast('Session locking is managed by Coworkspace permissions.');};

  const originalSend=window.wbSend;
  window.wbSend=function(payload){
    if(payload && payload.type==='wb_cursor')return;
    if(payload && payload.op){
      if(payload.op==='stroke_end'||payload.op==='sticky_add'||payload.op==='sticky_move'||payload.op==='sticky_text'||payload.op==='text_add'||payload.op==='text_move'||payload.op==='text_edit'||payload.op==='clear'||payload.op==='undo'){
        clearTimeout(saveTimer);saveTimer=setTimeout(saveNow,250);
      }
      return;
    }
    if(payload && payload.type==='wb_state_save'){clearTimeout(saveTimer);saveTimer=setTimeout(saveNow,50);return;}
    if(originalSend && !window.ECOLLAB.whiteboardSessionMode)originalSend(payload);
  };

  async function loadInitial(){
    try{
      const d=await request('GET');
      const state=d.whiteboard.state||{paths:[],objects:[]};
      lastUpdated=d.whiteboard.updated_at||'';
      wbState.paths=Array.isArray(state.paths)?state.paths:[];
      wbState.objects={};
      wbState.dirty=false;
      document.getElementById('wbBoardName').textContent=d.whiteboard.title||<?= json_encode($title) ?>;
      document.getElementById('wbSaveLabel').textContent='Saved just now';
      wbInitCanvas();
      wbState.open=true;
      wbUpdateMemberList();
      wbApplyFullState(JSON.stringify(state));
      if(polling)clearInterval(polling);
      polling=setInterval(syncRemote,2000);
      showToast('Session #'+BID+' ready');
    }catch(e){showToast('Could not load whiteboard: '+e.message);}
  }

  async function syncRemote(){
    if(!wbState.open||wbState.drawing||wbState.dirty)return;
    try{
      const d=await request('GET');
      const updated=d.whiteboard.updated_at||'';
      if(updated && updated!==lastUpdated){
        lastUpdated=updated;
        wbApplyFullState(JSON.stringify(d.whiteboard.state||{paths:[],objects:[]}));
        const lbl=document.getElementById('wbSaveLabel');if(lbl)lbl.textContent='Updated by collaborator';
      }
    }catch(e){}
  }

  window.openWhiteboard=function(){
    wbState.boardName=<?= json_encode($title) ?>;
    wbState.sessionId=BID;
    wbState.channelId=<?= $channelId ?>;
    wbState.open=true;
    wbState.dirty=false;
    loadInitial();
  };

  window.closeWhiteboard=function(){
    if(wbState.open)saveNow();
    wbState.open=false;
    if(polling)clearInterval(polling);
    location.href=<?= json_encode(BASE_URL . '/modules/collaboration/server-coworkspaces.php?server_id=' . $serverId . '&channel_id=' . $channelId) ?>;
  };

  document.addEventListener('DOMContentLoaded',function(){openWhiteboard();});
})();
</script>
</body>
</html>
