<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once ROOT_PATH . '/database/config/db.php';
require_once ROOT_PATH . '/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH . '/services/CollaboraService.php';
AuthMiddleware::startSession(); $user = AuthMiddleware::requireAuth();
header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
function coEscape(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
try {
    if (!CollaboraService::enabled()) throw new RuntimeException('Collabora is disabled.', 404);
    $db = Database::getInstance(); $id = (int)($_GET['id'] ?? 0);
    $a = DocumentAccessService::get($db, $id, (int)$user['id']); $d = $a['document'];
    $backUrl = BASE_URL . '/modules/collaboration/server-coworkspaces.php?server_id=' . (int)$a['workspace']['server_id'] . '&channel_id=' . (int)$a['workspace']['channel_id'];
    $url = CollaboraService::editorUrl($d['file_type'], $a['permission'] === 'edit', $id);
    $issued = CollaboraService::issue($db, $id, (int)$user['id']);
    $csrf = AuthMiddleware::csrfToken();
    $versions = [];
    if ($a['owner']) {
        $s = $db->prepare('SELECT version,created_at FROM collab_document_versions WHERE document_id=? ORDER BY version DESC LIMIT 50');
        $s->execute([$id]); $versions = $s->fetchAll(PDO::FETCH_ASSOC);
    }
    $nonce = bin2hex(random_bytes(16));
    $origin = CollaboraService::origin($url);
    require_once dirname(__DIR__, 2) . '/includes/calls/origins.php';
$callOrigins = ecollabCallOrigins();
header("Content-Security-Policy: default-src 'self'; connect-src 'self' $callOrigins; media-src 'self' blob:; script-src 'nonce-$nonce'; style-src 'self' 'nonce-$nonce'; frame-src $origin; form-action $origin; object-src 'none'; base-uri 'none'; frame-ancestors 'self'");
} catch (Throwable $e) {
    http_response_code(in_array($e->getCode(), [403,404], true) ? $e->getCode() : 503);
    exit('The editor is unavailable. Check document access and the Collabora configuration.');
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=coEscape($d['title'])?> · eCollab</title>
<style nonce="<?=$nonce?>">
*{box-sizing:border-box}body{margin:0;background:#111827;color:#eef2ff;font:14px system-ui}header{display:flex;align-items:center;gap:16px;padding:12px 18px;flex-wrap:wrap}h1{font-size:17px;margin:0;flex:1}a{color:#a5b4fc}button,select,textarea,input{font:inherit;padding:9px;border:1px solid #475569;border-radius:6px}button{cursor:pointer;background:#818cf8;color:#111827}main{display:flex;height:calc(100dvh - 70px)}iframe{flex:1;border:0;background:white;min-width:0}aside{width:320px;overflow:auto;padding:16px;background:#1e293b}textarea{width:100%;min-height:160px;background:#0f172a;color:white;margin:12px 0}pre{white-space:pre-wrap;overflow-wrap:anywhere;font:inherit}small{color:#cbd5e1}details{margin:20px 0}#notice{padding:8px;color:#fcd34d}@media(max-width:750px){main{flex-direction:column;height:auto}iframe{height:75dvh;flex:auto}aside{width:100%}}
</style><link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app-design.css?v=1">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/mobile/app-mobile.css?v=2">
<script nonce="<?=$nonce?>" defer src="<?= BASE_URL ?>/assets/js/mobile-viewport.js?v=1"></script>
</head><body data-mobile-surface="editor">
<header><a href="<?=coEscape($backUrl)?>">← Coworkspace</a><h1><?=coEscape($d['title'])?></h1><span><?=coEscape($a['permission'])?></span><a href="<?=coEscape(BASE_URL)?>/API/collaboration/document-download.php?id=<?=$id?>">Download</a></header>
<?php if ($a['permission'] === 'comment'): ?><div id="notice">Comment permission currently opens as view-only in Collabora.</div><?php endif ?>
<main><iframe name="office" title="Collaborative document editor" allow="clipboard-read; clipboard-write; fullscreen"></iframe><aside>
<?php if (env('DOCUMENT_ML_ENABLED','false')==='true'): ?><details><summary>Related documents</summary><small>Compare titles of documents you can access in this workspace.</small><p><button id="related">Find related files</button></p><div id="related-result" role="status"></div></details><?php endif ?>
<h2>Jarred</h2><small>Paste the excerpt you want help with. Only submitted text goes to the configured AI service. Review suggestions before applying them.</small>
<textarea id="excerpt" maxlength="12000" placeholder="Paste text or a spreadsheet formula"></textarea>
<select id="task"><option value="summarize">Summarize</option><option value="rewrite">Improve writing</option><option value="formula">Explain formula</option><option value="outline">Presentation outline</option></select>
<button id="ask" <?=env('DOCUMENT_AI_ENABLED','false')==='true'?'':'disabled'?>>Suggest</button><pre id="result" role="status"></pre>
<details><summary>Version history</summary><p>Current version: <?=(int)$d['version']?>. Refresh after saving to update this list.</p>
<?php if (!$a['owner']): ?><p>History is available to document owners and workspace hosts.</p><?php endif ?>
<?php foreach ($versions as $v): ?><p><a href="<?=coEscape(BASE_URL)?>/API/collaboration/document-download.php?id=<?=$id?>&amp;version=<?=(int)$v['version']?>">Download version <?=(int)$v['version']?></a></p><?php endforeach ?></details>
<details><summary>Import a file</summary><p>Imports start private.</p><input id="upload-title" placeholder="Document title" maxlength="200"><input id="upload-file" type="file" accept=".docx,.xlsx,.pptx"><button id="upload">Upload</button><p id="upload-result" role="status"></p></details>
<?php if ($a['owner']): ?><details><summary>Rename</summary><input id="new-title" value="<?=coEscape($d['title'])?>" maxlength="200"><button id="rename">Rename</button><p id="rename-result" role="status"></p></details><details><summary>Sharing</summary><p>Public means members with access to this workspace.</p><select id="visibility"><option value="private" <?=$d['visibility']==='private'?'selected':''?>>Private</option><option value="public" <?=$d['visibility']==='public'?'selected':''?>>Workspace</option></select><select id="public-permission"><option value="view" <?=$d['public_permission']==='view'?'selected':''?>>Viewer</option><option value="edit" <?=$d['public_permission']==='edit'?'selected':''?>>Editor</option></select><button id="sharing">Save sharing</button><p id="sharing-result" role="status"></p><small>Individual grants remain managed through eCollab resource sharing.</small></details><?php endif ?>
</aside></main>
<form id="launch" action="<?=coEscape($url)?>" method="post" target="office"><input type="hidden" name="access_token" value="<?=coEscape($issued['token'])?>"><input type="hidden" name="access_token_ttl" value="<?=$issued['ttl']?>"></form>
<script nonce="<?=$nonce?>">
const base=<?=json_encode(BASE_URL,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>, csrf=<?=json_encode($csrf)?>, id=<?=$id?>, wid=<?=(int)$d['workspace_id']?>;
document.getElementById('launch').submit();
document.getElementById('launch').remove();
document.getElementById('related')?.addEventListener('click',async()=>{const out=document.getElementById('related-result');out.textContent='Finding files…';try{const res=await fetch(base+'/API/collaboration/document-related.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({id})});const data=await res.json();out.textContent='';for(const file of data.related||[]){const p=document.createElement('p'),a=document.createElement('a');a.textContent=file.title;a.href=file.url;p.append(a);out.append(p);}if(!out.children.length)out.textContent='No semantic suggestions available.';}catch{out.textContent='Suggestions unavailable.';}});
document.getElementById('ask').addEventListener('click',async()=>{const b=document.getElementById('ask'),r=document.getElementById('result');b.disabled=true;r.textContent='Preparing suggestion…';try{const res=await fetch(base+'/API/collaboration/document-ai.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({id,action:document.getElementById('task').value,text:document.getElementById('excerpt').value})});const data=await res.json();r.textContent=data.suggestion||data.error||'Suggestion unavailable.';}catch{r.textContent='Suggestion unavailable. Your document is unaffected.';}finally{b.disabled=false;}});
document.getElementById('upload').addEventListener('click',async()=>{const out=document.getElementById('upload-result'),f=new FormData();f.set('action','upload');f.set('workspace_id',wid);f.set('title',document.getElementById('upload-title').value);const file=document.getElementById('upload-file').files[0];if(!file){out.textContent='Choose a file.';return;}f.set('file',file);out.textContent='Uploading…';try{const res=await fetch(base+'/API/collaboration/document-manage.php',{method:'POST',headers:{'X-CSRF-Token':csrf},body:f});const data=await res.json();out.textContent=data.error||'Imported. ';if(data.url){const a=document.createElement('a');a.href=data.url;a.textContent='Open imported file';a.target='_blank';a.rel='noopener';out.append(a);}}catch{out.textContent='Upload failed.';}});
document.getElementById('rename')?.addEventListener('click',async()=>{const out=document.getElementById('rename-result');try{const res=await fetch(base+'/API/collaboration/document-manage.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({action:'rename',id,title:document.getElementById('new-title').value})});const data=await res.json();out.textContent=data.success?'Renamed. Reopen after saving to update the editor title.':data.error;}catch{out.textContent='Rename failed.';}});
document.getElementById('sharing')?.addEventListener('click',async()=>{const r=document.getElementById('sharing-result');try{const res=await fetch(base+'/API/collaboration/resource-access.php',{method:'PATCH',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({workspace_id:wid,resource_type:'document',resource_id:id,visibility:document.getElementById('visibility').value,public_permission:document.getElementById('public-permission').value})});const data=await res.json();r.textContent=data.success?'Sharing updated.':data.error||'Update failed.';}catch{r.textContent='Update failed.';}});
</script><?php require_once dirname(__DIR__, 2) . '/includes/calls/bootstrap.php'; ?>
</body></html>
