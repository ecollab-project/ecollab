<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/services/ChannelService.php';

AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth();
require_once ROOT_PATH.'/includes/calls/workspace-frame-policy.php';

$channelService = new ChannelService();
$servers = $channelService->getServersForUser($user['id']);
$requestedServerId = (int)($_GET['server_id'] ?? 0);
$currentServer = null;
foreach ($servers as $server) {
    if ((int)$server['id'] === $requestedServerId) {
        $currentServer = $server;
        break;
    }
}
$currentServer = $currentServer ?? ($servers[0] ?? null);
$serverId = (int)($currentServer['id'] ?? 0);
$serverName = (string)($currentServer['name'] ?? 'Current Server');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>eCollab Library</title>
  <link rel="icon" type="image/webp" href="<?= BASE_URL ?>/assets/ecollab-icon.webp">
  <script src="<?= BASE_URL ?>/assets/js/accessibility-apply.js?v=settings-speed-1" defer></script>
  <style>
    :root{--bg:#0b0f1a;--panel:#111827;--card:#1a2235;--border:rgba(255,255,255,.08);--text:#f1f5f9;--muted:#94a3b8;--purple:#a855f7;--pink:#ec4899}
    *{box-sizing:border-box}body{margin:0;min-height:100vh;background:var(--bg);color:var(--text);font-family:Inter,system-ui,sans-serif}.lib-shell{max-width:1200px;margin:auto;padding:24px}.lib-top{display:flex;align-items:center;gap:14px;margin-bottom:24px}.lib-back{color:var(--muted);text-decoration:none;padding:9px 12px;border:1px solid var(--border);border-radius:9px}.lib-title{font-size:25px;font-weight:800}.lib-sub{color:var(--muted);font-size:13px;margin-top:3px}.lib-search{width:100%;padding:13px 15px;background:var(--panel);border:1px solid var(--border);border-radius:11px;color:var(--text);outline:none}.lib-tabs{display:flex;gap:8px;margin:18px 0}.lib-tab{border:1px solid var(--border);background:var(--panel);color:var(--muted);padding:10px 14px;border-radius:9px;cursor:pointer;font-weight:700}.lib-tab.active{color:white;border-color:rgba(168,85,247,.5);background:rgba(168,85,247,.16)}.lib-note{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:22px}.lib-note h2{margin:0 0 7px;font-size:18px}.lib-note p{margin:0;color:var(--muted);line-height:1.6}.lib-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:14px;margin-top:18px}.lib-placeholder{min-height:220px;background:var(--card);border:1px solid var(--border);border-radius:13px;padding:18px;color:var(--muted)}@media(max-width:600px){.lib-shell{padding:14px}.lib-title{font-size:21px}.lib-tabs{overflow:auto}.lib-tab{white-space:nowrap}.lib-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
  .lib-search-row{display:flex;align-items:center;gap:8px}.lib-search-row .lib-search{flex:1;min-width:0}.lib-search-row button{min-height:44px;flex:none}
</style>
  <meta name="csrf-token" content="<?= htmlspecialchars(AuthMiddleware::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app-design.css?v=1">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/mobile/app-mobile.css?v=2">
<script defer src="<?= BASE_URL ?>/assets/js/mobile-viewport.js?v=2"></script>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/device-layout.css?v=1">
<script defer src="<?= BASE_URL ?>/assets/js/device-layout.js?v=1"></script>
<?php require_once ROOT_PATH.'/includes/layout/color-theme.php'; ?>
</head>
<body data-mobile-surface="library">
<main class="lib-shell" data-server-id="<?= $serverId ?>">
  <header class="lib-top">
    <a class="lib-back" href="<?= BASE_URL ?>/modules/chat/chat.php?server_id=<?= $serverId ?>">← Server</a>
    <div><div class="lib-title">📚 eCollab Library</div><div class="lib-sub"><?= htmlspecialchars($serverName, ENT_QUOTES, 'UTF-8') ?></div></div>
  </header>
  <div class="lib-search-row">
  <input class="lib-search" type="search" placeholder="Search books, authors, subjects or ISBN…" aria-label="Search library">
  <button class="lib-tab" id="libraryClear" type="button" hidden>Clear search</button>
  </div>
  <div class="lib-tabs" role="group" aria-label="Book recommendations">
    <button class="lib-tab active" type="button" aria-pressed="true" data-mode="server">Recommended for this Server</button>
    <button class="lib-tab" type="button" aria-pressed="false" data-mode="me">Recommended for Me</button>
  </div>
  <section class="lib-note" id="libraryIntro">
    <h2 id="libraryHeading">Recommended for <?= htmlspecialchars($serverName, ENT_QUOTES, 'UTF-8') ?></h2>
    <p id="libraryDescription" role="status">Loading books…</p>
    <p id="libraryTracking" hidden>Your book clicks and Useful feedback help evaluate recommendations.</p>
  </section>
  <p class="lib-sub">Publication years are supplied by Open Library and may refer to a different edition.</p>
  <section class="lib-grid" id="libraryGrid">
    <div class="lib-placeholder">DOAB / OAPEN recommendations</div>
    <div class="lib-placeholder">Open textbook recommendations</div>
    <div class="lib-placeholder">Open Library recommendations</div>
  </section>
</main>
<script>
const LIB_BASE = <?= json_encode(BASE_URL) ?>;
const LIB_SERVER_ID = <?= $serverId ?>;
let libMode = 'server';
let trackingEnabled = false;
let loadVersion = 0;
const grid = document.getElementById('libraryGrid');
const search = document.querySelector('.lib-search');
const heading = document.getElementById('libraryHeading');
const description = document.getElementById('libraryDescription');
const recommendationTabs = document.querySelectorAll('[data-mode]');
const clearSearch = document.getElementById('libraryClear');
const trackingNotice = document.getElementById('libraryTracking');
const serverName = heading.textContent;
let timer;
function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));}
function renderBooks(books){
  if(!books.length){grid.innerHTML='<div class="lib-placeholder">No books found. Try another search.</div>';return;}
  grid.innerHTML=books.map(b=>`<article class="lib-placeholder" style="padding:0;overflow:hidden;color:var(--text)">
    ${b.cover?`<img src="${esc(b.cover)}" alt="" loading="lazy" style="width:100%;height:230px;object-fit:cover;background:#0f172a">`:'<div style="height:230px;display:grid;place-items:center;font-size:42px;background:#0f172a">📚</div>'}
    <div style="padding:14px"><strong style="display:block;line-height:1.35">${esc(b.title)}</strong>
    <div style="font-size:12px;color:var(--muted);margin:7px 0">${esc((b.authors||[]).slice(0,2).join(', ')||'Unknown author')}${b.year?' · Catalog year: '+esc(b.year):''}</div>
    <div style="font-size:11px;color:var(--muted)">${esc(b.source||'Open Library')}</div>
    ${b.url?`<a data-work-key="${esc(b.work_key||'')}" href="${esc(b.url)}" target="_blank" rel="noopener" style="display:inline-block;margin-top:10px;color:#c084fc;text-decoration:none;font-weight:700">${esc(b.action||'View book')} →</a>`:''}
    ${trackingEnabled && b.work_key ? `<button type="button" data-useful="${esc(b.work_key)}" style="margin-left:8px">Useful</button>` : ''}</div></article>`).join('');
}
async function loadBooks(q=''){
  const version = ++loadVersion;
  trackingEnabled=false;
  heading.textContent = q ? `Search results for “${q}”` : (libMode === 'me' ? 'Recommended for Me' : serverName);
  description.textContent = 'Loading books…';
  clearSearch.hidden = !q;
  trackingNotice.hidden = true;
  recommendationTabs.forEach(tab => {
    const active = !q && tab.dataset.mode === libMode;
    tab.classList.toggle('active', active);
    tab.setAttribute('aria-pressed', String(active));
  });
  grid.innerHTML='<div class="lib-placeholder">Loading books…</div>';
  const u=new URL(LIB_BASE+'/API/library/books.php',location.origin);
  u.searchParams.set('server_id',LIB_SERVER_ID);u.searchParams.set('mode',libMode);if(q)u.searchParams.set('q',q);
  try {
    const response = await fetch(u);
    const data = await response.json();
    if (version !== loadVersion) return;
    if (!response.ok || !data.success) throw new Error(data.error || 'Unable to load books');
    const books = data.books || [];
    trackingEnabled = data.tracking_enabled === true;
    trackingNotice.hidden = !trackingEnabled;
    renderBooks(books);
    description.textContent = `${books.length} ${books.length === 1 ? 'book' : 'books'} found.`;
  } catch (error) {
    if (version !== loadVersion) return;
    grid.replaceChildren();
    description.textContent = error.message;
  }
}

function resetSearch() {
  clearTimeout(timer);
  search.value = '';
  loadBooks();
}
recommendationTabs.forEach(button => button.addEventListener('click', () => {
  libMode = button.dataset.mode;
  resetSearch();
}));
clearSearch.addEventListener('click', () => {
  search.focus();
  resetSearch();
});

async function recordBook(key,type){
  const response=await fetch(LIB_BASE+'/API/library/book-event.php',{
    method:'POST',credentials:'same-origin',keepalive:true,
    headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="csrf-token"]').content},
    body:JSON.stringify({work_key:key,event_type:type})
  });
  if(!response.ok)throw new Error('Unable to save feedback');
}
grid.addEventListener('click',async event=>{
  if(!trackingEnabled)return;
  const link=event.target.closest('a[data-work-key]');
  if(link?.dataset.workKey)recordBook(link.dataset.workKey,'click').catch(()=>{});
  const button=event.target.closest('button[data-useful]');
  if(button){
    button.disabled=true;
    try{await recordBook(button.dataset.useful,'useful');button.textContent='Saved';}
    catch(e){button.disabled=false;button.textContent='Retry feedback';}
  }
});
search.addEventListener('input', () => {
  clearTimeout(timer);
  loadVersion++;
  clearSearch.hidden = !search.value;
  timer = setTimeout(() => loadBooks(search.value.trim()), 450);
});
loadBooks();
</script>
<?php require_once dirname(__DIR__, 2) . '/includes/calls/bootstrap.php'; ?>
</body>
</html>
