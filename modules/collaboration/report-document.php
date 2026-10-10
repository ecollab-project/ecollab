<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2).'/config.php';
require_once ROOT_PATH.'/database/config/db.php';
require_once ROOT_PATH.'/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH.'/services/DocumentAccessService.php';
AuthMiddleware::startSession(); $user = AuthMiddleware::requireAuth();
try {
    $id = (int)($_GET['id'] ?? 0);
    $access = DocumentAccessService::get(Database::getInstance(), $id, (int)$user['id']);
    $document = $access['document']; $csrf = AuthMiddleware::csrfToken();
} catch (Throwable $e) { http_response_code(in_array($e->getCode(), [403,404], true) ? $e->getCode() : 500); exit('Document unavailable.'); }
function reportEscape(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
header('Cache-Control: no-store');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Report document · eCollab</title>
<link rel="stylesheet" href="<?=reportEscape(BASE_URL)?>/assets/css/app-design.css?v=1">
<style>body{margin:0;background:#0f172a;color:#e2e8f0;font:16px system-ui}main{max-width:580px;margin:40px auto;padding:24px}label{display:block;margin:20px 0 8px}select,textarea,button{box-sizing:border-box;font:inherit;border-radius:8px;padding:12px;border:1px solid #475569}select,textarea{width:100%;background:#1e293b;color:inherit}textarea{min-height:150px}button{margin-top:20px;background:#818cf8;color:#111827;cursor:pointer}a{color:#a5b4fc}button:disabled{opacity:.6}</style></head>
<body><main><h1>Report document</h1><p><?=reportEscape($document['title'])?></p><p>Reports are reviewed by this server’s moderators. The document remains open in your original tab.</p>
<?php if ((int)$document['created_by'] === (int)$user['id']): ?><p>You cannot report your own document.</p><?php else: ?>
<form id="report"><label for="reason">Reason</label><select id="reason" required><option value="">Choose a reason</option><option value="spam">Spam</option><option value="harassment">Harassment</option><option value="inappropriate">Inappropriate content</option><option value="phishing">Phishing</option><option value="other">Other</option></select><label for="description">Details (optional)</label><textarea id="description" maxlength="2000"></textarea><button type="submit">Submit report</button><p id="status" role="status" aria-live="polite"></p></form>
<script>
document.getElementById('report').addEventListener('submit',async(event)=>{
event.preventDefault();const form=event.currentTarget,button=form.querySelector('button'),status=document.getElementById('status');button.disabled=true;status.textContent='Submitting…';
try{const response=await fetch(<?=json_encode(BASE_URL.'/API/collaboration/report-document.php', JSON_HEX_TAG)?>,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':<?=json_encode($csrf)?>},body:JSON.stringify({id:<?=$id?>,reason:document.getElementById('reason').value,description:document.getElementById('description').value})});const data=await response.json();if(!response.ok||!data.success)throw Error(data.error||'Report failed.');status.textContent='Report submitted to the server moderation queue.';form.querySelectorAll('select,textarea').forEach(el=>el.disabled=true);}catch(error){status.textContent=error.message;button.disabled=false;}
});
</script><?php endif ?>
<p><a href="<?=reportEscape(BASE_URL.'/modules/collaboration/document.php?id='.$id.'&workspace_id='.(int)$document['workspace_id'])?>">Return to document</a></p></main></body></html>
