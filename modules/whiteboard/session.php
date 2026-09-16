<?php

declare(strict_types=1);

// Session wrapper for Coworkspace whiteboards. The existing canvas remains the
// rendering engine, while this wrapper injects session-specific persistence
// without duplicating the large whiteboard UI.
$workspaceId = (int)($_GET['workspace_id'] ?? 0);
$whiteboardId = (int)($_GET['whiteboard_id'] ?? 0);
$channelId = (int)($_GET['channel_id'] ?? 0);
if ($workspaceId < 1 || $whiteboardId < 1 || $channelId < 1) {
    http_response_code(400);
    exit('A Coworkspace whiteboard session is required.');
}

ob_start();
require __DIR__ . '/index.php';
$html = ob_get_clean();

$script = '<script>\n'
    . 'window.ECOLLAB=window.ECOLLAB||{};'
    . 'window.ECOLLAB.whiteboardId=' . $whiteboardId . ';'
    . 'window.ECOLLAB.workspaceId=' . $workspaceId . ';'
    . 'window.ECOLLAB.currentChannelId=' . $channelId . ';'
    . 'window.__currentWhiteboardId=' . $whiteboardId . ';'
    . 'window.__currentWorkspaceId=' . $workspaceId . ';'
    . '(function(){'
    . 'const base=window.ECOLLAB.baseUrl||"";'
    . 'const api=base+"/API/collaboration/whiteboards.php";'
    . 'const csrf=window.ECOLLAB.csrfToken||document.querySelector("meta[name=csrf-token]")?.content||"";'
    . 'window.wbApi=function(action,body={},method="GET"){'
    . 'const id=window.ECOLLAB.whiteboardId,wid=window.ECOLLAB.workspaceId;'
    . 'let url=api+"?workspace_id="+encodeURIComponent(wid)+"&whiteboard_id="+encodeURIComponent(id);'
    . 'const opts={method,credentials:"same-origin",headers:{"X-CSRF-Token":csrf,"Content-Type":"application/json"}};'
    . 'if(method!=="GET"){opts.body=JSON.stringify({...body,workspace_id:wid,whiteboard_id:id,csrf_token:csrf});}'
    . 'return fetch(url,opts).then(async r=>{const d=await r.json().catch(()=>({error:"Invalid server response"}));if(!r.ok||!d.success)throw Error(d.error||"Whiteboard request failed");return action==="state"?{whiteboard:{state_json:d.whiteboard.state,locked:false,is_host:true}}:d;});'
    . '};'
    . 'window.wbSaveVersion=async function(){'
    . 'const state={paths:window.wbState?.paths||[],savedAt:new Date().toISOString()};'
    . 'await window.wbApi("save",{state},"POST");window.wbState&&(window.wbState.dirty=false);document.getElementById("wbSaveLabel")&&(document.getElementById("wbSaveLabel").textContent="Saved just now");'
    . '};'
    . 'window.wbLoadVersions=async function(){const el=document.getElementById("wbVersionList");if(el)el.innerHTML="<div class=\\"wb-version-row\\">Session snapshots are stored automatically.</div>";};'
    . 'window.wbRestoreVersion=async function(){window.showToast?.("Version restore is not available for Coworkspace sessions yet.","info");};'
    . '})();'
    . '</script>\n';

$html = str_replace('</body>', $script . '</body>', $html);
echo $html;
