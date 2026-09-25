<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once ROOT_PATH . '/database/config/db.php';
require_once ROOT_PATH . '/security/middleware/AuthMiddleware.php';

AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth();
$db = Database::getInstance();
$uid = (int)$user['id'];

$serversStmt = $db->prepare("SELECT s.id,s.name,s.icon_emoji,s.member_count
    FROM servers s JOIN server_members sm ON sm.server_id=s.id AND sm.user_id=:uid
    WHERE s.status='active' ORDER BY sm.joined_at DESC,s.name ASC");
$serversStmt->execute([':uid'=>$uid]);
$servers = $serversStmt->fetchAll(PDO::FETCH_ASSOC);

$serverId = (int)($_GET['server_id'] ?? 0);
if (!$serverId && $servers) $serverId = (int)$servers[0]['id'];
$allowed = false;
foreach ($servers as $server) if ((int)$server['id'] === $serverId) {$allowed=true;break;}
if (!$allowed) $serverId = $servers ? (int)$servers[0]['id'] : 0;

$workspaces=[];$files=[];$channels=[];$selectedServer=null;
foreach($servers as $server){if((int)$server['id']===$serverId){$selectedServer=$server;break;}}
if($serverId){
    $c=$db->prepare("SELECT id,name,type FROM channels WHERE server_id=:sid ORDER BY position,created_at");
    $c->execute([':sid'=>$serverId]);$channels=$c->fetchAll(PDO::FETCH_ASSOC);

    $w=$db->prepare("SELECT w.id,w.name,w.visibility,w.host_id,w.allow_create_documents,w.allow_edit_documents,w.allow_whiteboard,
        COALESCE(m.role,IF(w.host_id=:uid1,'host',NULL)) AS member_role,
        COUNT(DISTINCT wm.user_id) AS member_count,
        w.channel_id,c.name AS channel_name
        FROM collab_workspaces w JOIN channels c ON c.id=w.channel_id AND c.server_id=:sid1
        LEFT JOIN collab_workspace_members m ON m.workspace_id=w.id AND m.user_id=:uid2
        LEFT JOIN collab_workspace_members wm ON wm.workspace_id=w.id
        WHERE w.archived=0 GROUP BY w.id ORDER BY w.updated_at DESC");
    $w->execute([':uid1'=>$uid,':sid1'=>$serverId,':uid2'=>$uid]);$workspaces=$w->fetchAll(PDO::FETCH_ASSOC);

    $f=$db->prepare("SELECT uf.id,uf.original_name AS file_name,uf.file_path,uf.file_size,uf.mime_type,uf.channel_id,
        c.name AS channel_name,u.username AS uploader,uf.created_at
        FROM uploaded_files uf
        JOIN server_members sm ON sm.server_id=uf.server_id AND sm.user_id=:uid1
        LEFT JOIN channels c ON c.id=uf.channel_id
        JOIN users u ON u.id=uf.uploader_id
        WHERE uf.server_id=:sid AND uf.deleted_at IS NULL
        ORDER BY uf.created_at DESC LIMIT 100");
    $f->execute([':uid1'=>$uid,':sid'=>$serverId]);$files=$f->fetchAll(PDO::FETCH_ASSOC);

    $a=$db->prepare("SELECT ma.id,ma.file_name,ma.file_path,ma.file_size,ma.mime_type,c.name AS channel_name,
        u.username AS uploader,ma.created_at
        FROM message_attachments ma JOIN messages m ON m.id=ma.message_id AND m.is_deleted=0
        JOIN channels c ON c.id=m.channel_id AND c.server_id=:sid1
        JOIN server_members sm ON sm.server_id=c.server_id AND sm.user_id=:uid
        JOIN users u ON u.id=m.sender_id
        ORDER BY ma.created_at DESC LIMIT 100");
    $a->execute([':sid1'=>$serverId,':uid'=>$uid]);
    $files=array_merge($files,$a->fetchAll(PDO::FETCH_ASSOC));
    usort($files,static fn($x,$y)=>strcmp((string)$y['created_at'],(string)$x['created_at']));
    $files=array_slice($files,0,100);
}

function humanSize(int $bytes): string {if($bytes<1024)return $bytes.' B';if($bytes<1048576)return round($bytes/1024,1).' KB';if($bytes<1073741824)return round($bytes/1048576,1).' MB';return round($bytes/1073741824,1).' GB';}
function safeFileUrl(string $path): string {return BASE_URL.'/'.ltrim(str_replace('\\','/',$path),'/');}
$csrf=AuthMiddleware::csrfToken();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Coworkspaces & Resources – <?= htmlspecialchars(APP_NAME) ?></title><meta name="csrf-token" content="<?= htmlspecialchars($csrf,ENT_QUOTES) ?>">
<style>
body{margin:0;background:#0d1118;color:#f5f7fb;font-family:Inter,system-ui,sans-serif}.wrap{max-width:1250px;margin:auto;padding:28px}.top{display:flex;justify-content:space-between;gap:20px;align-items:center}.top a{color:#aab4c6;text-decoration:none}.layout{display:grid;grid-template-columns:260px 1fr;gap:18px;margin-top:22px}.panel{background:#171c25;border:1px solid #2a3140;border-radius:16px;padding:18px}.server{display:block;width:100%;text-align:left;background:transparent;color:#fff;border:0;border-radius:10px;padding:12px;cursor:pointer;margin:4px 0}.server:hover,.server.active{background:#242b38}.server small{display:block;color:#8994a7;margin-top:4px}.section{margin-top:22px}.section h2{margin:0 0 12px;font-size:18px}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.card{border:1px solid #2b3240;background:#131821;border-radius:12px;padding:15px}.card h3{margin:0 0 6px}.muted{color:#8e99aa;font-size:13px}.pill{display:inline-block;background:#242b38;color:#cdd5e1;padding:5px 9px;border-radius:999px;font-size:11px;margin:3px 3px 0 0}.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.btn{display:inline-block;border:1px solid #394152;background:#202633;color:#fff;text-decoration:none;border-radius:9px;padding:8px 11px;font-size:13px}.btn.primary{background:#6d4aff;border-color:#6d4aff}.resource{display:grid;grid-template-columns:42px 1fr auto;gap:10px;align-items:center;border-bottom:1px solid #29303c;padding:12px 0}.resource:last-child{border-bottom:0}.icon{width:40px;height:40px;border-radius:9px;display:grid;place-items:center;background:#242b38}.resource strong{display:block}.empty{text-align:center;padding:35px;color:#8e99aa}.select{width:100%;background:#111720;color:#fff;border:1px solid #394152;border-radius:9px;padding:10px}
@media(max-width:800px){.layout{grid-template-columns:1fr}.grid{grid-template-columns:1fr}.top{align-items:flex-start;flex-direction:column}}
</style></head><body><div class="wrap">
<header class="top"><div><a href="<?= BASE_URL ?>/modules/student/dashboard.php">← Back to Dashboard</a><h1>🤝 Coworkspaces & Resources</h1><p class="muted">Choose any server you belong to. Workspaces and shared files are server-wide.</p></div><a class="btn" href="<?= BASE_URL ?>/modules/chat/chat.php<?= $serverId?'?server_id='.$serverId:'' ?>">💬 Open Chat</a></header>
<div class="layout"><aside class="panel"><strong>My Servers</strong><?php foreach($servers as $s): ?><a class="server <?= (int)$s['id']===$serverId?'active':'' ?>" href="?server_id=<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['icon_emoji']?:'💬') ?> <?= htmlspecialchars($s['name']) ?><small><?= (int)$s['member_count'] ?> people</small></a><?php endforeach; ?><?php if(!$servers): ?><div class="empty">You are not a member of any server yet.</div><?php endif; ?></aside>
<main class="panel"><h2><?= htmlspecialchars($selectedServer['icon_emoji']??'💬') ?> <?= htmlspecialchars($selectedServer['name']??'No server selected') ?></h2>
<div class="section"><h2>Coworkspaces</h2><?php if($workspaces): ?><div class="grid"><?php foreach($workspaces as $w): ?><article class="card"><h3><?= htmlspecialchars($w['name']) ?></h3><div class="muted"><?= htmlspecialchars($w['channel_name']) ?> · <?= (int)$w['member_count'] ?> people · <?= htmlspecialchars($w['member_role']?:'Member') ?></div><div class="actions"><a class="btn primary" href="<?= BASE_URL ?>/modules/collaboration/coworkspaces.php?channel_id=<?= (int)$w['channel_id'] ?>">Open Coworkspace</a><?php if((int)$w['allow_whiteboard']): ?><a class="btn" href="<?= BASE_URL ?>/modules/whiteboard/index.php?channel_id=<?= (int)$w['channel_id'] ?>">Whiteboard</a><?php endif; ?></div></article><?php endforeach; ?></div><?php else: ?><div class="empty">No coworkspaces in this server yet.</div><?php endif; ?></div>
<div class="section"><h2>Files & Resources</h2><?php if($files): ?><?php foreach($files as $f): ?><div class="resource"><div class="icon"><?= str_starts_with((string)$f['mime_type'],'image/')?'🖼️':'📄' ?></div><div><strong><?= htmlspecialchars($f['file_name']??'Resource') ?></strong><span class="muted"><?= htmlspecialchars($f['channel_name']??'Server') ?> · <?= htmlspecialchars($f['uploader']??'') ?> · <?= humanSize((int)($f['file_size']??0)) ?></span></div><div class="actions"><a class="btn" target="_blank" rel="noopener" href="<?= htmlspecialchars(safeFileUrl((string)$f['file_path']),ENT_QUOTES) ?>">Open</a><a class="btn" download href="<?= htmlspecialchars(safeFileUrl((string)$f['file_path']),ENT_QUOTES) ?>">Download</a></div></div><?php endforeach; ?><?php else: ?><div class="empty">No shared files or resources found in this server.</div><?php endif; ?></div>
</main></div></div></body></html>
