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
$workspaceId = (int)($_GET['id'] ?? 0);
$workspace = $workspaceId > 0 ? CoworkspaceService::get($db, $workspaceId, $uid) : null;
if (!$workspace) { http_response_code(404); exit('Coworkspace not found.'); }

$stmt = $db->prepare('SELECT id,title,file_name,file_type,version,updated_at FROM collab_documents WHERE workspace_id=:wid ORDER BY updated_at DESC');
$stmt->execute([':wid' => $workspaceId]);
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $db->prepare('SELECT u.id,u.username,u.full_name,m.role FROM collab_workspace_members m INNER JOIN users u ON u.id=m.user_id WHERE m.workspace_id=:wid ORDER BY FIELD(m.role,"host","editor","member","viewer"),u.full_name');
$stmt->execute([':wid' => $workspaceId]);
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);
$csrf = AuthMiddleware::csrfToken();
$host = (int)$workspace['host_id'] === $uid;
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($workspace['name']) ?> – Coworkspace</title><meta name="csrf-token" content="<?= htmlspecialchars($csrf,ENT_QUOTES,'UTF-8') ?>"><link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/desktop/external-collab.css?v=3"><style>body{margin:0;background:#0f1117;color:#f5f7fb;font-family:Inter,system-ui,sans-serif}.page{max-width:1200px;margin:auto;padding:28px}.top{display:flex;justify-content:space-between;gap:20px;align-items:start}.top a{color:#aeb8ca;text-decoration:none}.pill{padding:5px 10px;border-radius:999px;background:#252b38;font-size:12px}.layout{display:grid;grid-template-columns:1fr 300px;gap:18px;margin-top:22px}.box{background:#171a23;border:1px solid #2b303d;border-radius:16px;padding:20px}.tools{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin:18px 0}.tool{border:1px solid #303646;background:#141720;border-radius:12px;padding:18px;color:#fff;text-decoration:none}.tool h3{margin:0 0 7px}.muted{color:#929caf;font-size:13px}.doc{display:flex;align-items:center;gap:12px;border-top:1px solid #292e39;padding:13px 0;color:#fff;text-decoration:none}.doc:first-child{border-top:0}.members div{padding:10px 0;border-bottom:1px solid #292e39}.btn{border:1px solid #3a4151;background:#202532;color:#fff;border-radius:8px;padding:9px 12px;cursor:pointer}.primary{background:#6d4aff;border-color:#6d4aff}@media(max-width:800px){.layout{grid-template-columns:1fr}.tools{grid-template-columns:1fr}}</style></head><body><div class="page"><div class="top"><div><a href="<?= BASE_URL ?>/modules/collaboration/coworkspaces.php?channel_id=<?= (int)$workspace['channel_id'] ?>">← Coworkspaces</a><h1><?= (string)$workspace['visibility']==='private'?'🔒':'🟢' ?> <?= htmlspecialchars($workspace['name']) ?></h1><p class="muted">Persistent Coworkspace · <?= htmlspecialchars((string)$workspace['visibility']) ?> · <?= count($members) ?> members</p></div><span class="pill"><?= $host?'HOST':htmlspecialchars((string)$workspace['member_role']) ?></span></div><div class="layout"><main><section class="box"><h2>Collaboration</h2><div class="tools"><a class="tool" href="<?= BASE_URL ?>/modules/collaboration/index.php?channel_id=<?= (int)$workspace['channel_id'] ?>"><h3>📄 Documents</h3><p class="muted"><?= count($documents) ?> documents · ONLYOFFICE</p></a><a class="tool" href="<?= BASE_URL ?>/modules/whiteboard/index.php?channel_id=<?= (int)$workspace['channel_id'] ?>"><h3>🖊 Whiteboard</h3><p class="muted">Shared channel whiteboard</p></a></div><h2>Workspace Documents</h2><?php if (!$documents): ?><p class="muted">No documents are linked to this Coworkspace yet. Create one in the Collaboration Hub, then link it here.</p><?php else: foreach($documents as $doc): ?><a class="doc" href="<?= BASE_URL ?>/modules/collaboration/documents/editor.php?channel_id=<?= (int)$workspace['channel_id'] ?>&id=<?= (int)$doc['id'] ?>"><span><?= $doc['file_type']==='xlsx'?'📊':($doc['file_type']==='pptx'?'📽️':'📄') ?></span><span><b><?= htmlspecialchars($doc['title']) ?></b><small class="muted"> <?= strtoupper(htmlspecialchars($doc['file_type'])) ?> · v<?= (int)$doc['version'] ?></small></span></a><?php endforeach; endif; ?></section></main><aside><section class="box"><h2>👥 Members</h2><div class="members"><?php foreach($members as $m): ?><div><b><?= htmlspecialchars((string)($m['full_name'] ?: $m['username'])) ?></b><br><span class="muted"><?= htmlspecialchars($m['role']) ?></span></div><?php endforeach; ?></div><?php if ($host): ?><p class="muted">Host controls are enforced server-side. Manage invitations, roles and visibility through the Coworkspace settings API.</p><?php endif; ?></section></aside></div></div></body></html>
