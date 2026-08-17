/* Ecollab Phase 4.6 — server/channel invites + member management. */
(function () {
  'use strict';

  const base = () => window.ECOLLAB?.baseUrl || '';
  const csrf = () => window.ECOLLAB?.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content || '';
  const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  async function request(path, action, params = {}, method = 'GET', body = null) {
    const qs = new URLSearchParams({ action, ...params });
    const opts = { method, credentials: 'same-origin', headers: {'Content-Type':'application/json','X-CSRF-Token':csrf()} };
    if (body) opts.body = JSON.stringify(body);
    const res = await fetch(`${base()}${path}?${qs}`, opts);
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.success === false) throw new Error(data.error || data.message || `Request failed (${res.status})`);
    return data;
  }

  function toast(message, type = 'info') {
    if (typeof window.showToast === 'function') return window.showToast(message, type);
    const el = document.createElement('div');
    el.textContent = message;
    el.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:100000;padding:10px 15px;border-radius:9px;background:#111827;color:#fff;font:600 13px Inter,sans-serif;box-shadow:0 12px 35px rgba(0,0,0,.4)';
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 2600);
  }

  function serverId() {
    return Number(window.ECOLLAB?.currentServerId || document.querySelector('.workspace-icon.active[data-server-id]')?.dataset.serverId || document.querySelector('.workspace-icon[data-server-id]')?.dataset.serverId || 0);
  }

  function channelId() {
    return Number(window.ECOLLAB?.currentChannelId || window._currentChannelMeta?.id || document.querySelector('.channel-item.active[data-channel-id]')?.dataset.channelId || document.querySelector('.voice-channel.active[data-channel-id]')?.dataset.channelId || 0);
  }

  function channelName() {
    return String(window._currentChannelMeta?.name || document.querySelector('.channel-item.active[data-channel-id]')?.dataset.channelName || document.getElementById('channelTitle')?.textContent || 'channel');
  }

  function styles() {
    if (document.getElementById('scmStyles')) return;
    const s = document.createElement('style');
    s.id = 'scmStyles';
    s.textContent = `
      #scmModal{position:fixed;inset:0;z-index:110000;display:none;align-items:center;justify-content:center;background:rgba(2,6,23,.72);backdrop-filter:blur(4px)}
      #scmModal .scm-box{width:min(760px,94vw);max-height:86vh;overflow:hidden;background:var(--bg-secondary,#111827);border:1px solid var(--border,rgba(255,255,255,.1));border-radius:16px;box-shadow:0 24px 80px rgba(0,0,0,.55);color:var(--text-primary,#f8fafc);font-family:Inter,sans-serif}
      #privateChannelManagerModal{display:none!important}
      .scm-head{display:flex;align-items:center;justify-content:space-between;padding:16px 18px;border-bottom:1px solid rgba(255,255,255,.08)}
      .scm-title{font-weight:800;font-size:15px}.scm-close{border:0;background:transparent;color:#94a3b8;font-size:22px;cursor:pointer}
      .scm-tabs{display:flex;gap:6px;padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.06)}
      .scm-tab{border:1px solid transparent;background:transparent;color:#94a3b8;padding:7px 11px;border-radius:8px;cursor:pointer;font-weight:700;font-size:12px}.scm-tab.on{background:rgba(168,85,247,.14);border-color:rgba(168,85,247,.3);color:#d8b4fe}
      .scm-body{padding:15px;overflow:auto;max-height:65vh}.scm-row{display:flex;align-items:center;gap:10px;padding:9px;border:1px solid rgba(255,255,255,.06);border-radius:10px;margin-bottom:7px}
      .scm-avatar{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;flex:0 0 auto}.scm-info{flex:1;min-width:0}.scm-name{font-size:12px;font-weight:700}.scm-meta{font-size:10px;color:#94a3b8;margin-top:2px}
      .scm-btn{border:1px solid rgba(168,85,247,.32);background:rgba(168,85,247,.1);color:#c084fc;border-radius:8px;padding:7px 10px;font-size:11px;font-weight:700;cursor:pointer}.scm-btn.red{border-color:rgba(239,68,68,.3);background:rgba(239,68,68,.08);color:#f87171}.scm-btn.green{border-color:rgba(34,197,94,.3);background:rgba(34,197,94,.08);color:#4ade80}
      .scm-input{width:100%;box-sizing:border-box;border:1px solid rgba(255,255,255,.1);background:#0f172a;color:#f8fafc;border-radius:9px;padding:10px 11px;outline:none;font:500 12px Inter,sans-serif}.scm-grid{display:grid;grid-template-columns:1fr auto;gap:8px;margin-bottom:12px}.scm-invite{display:flex;gap:8px}.scm-muted{font-size:11px;color:#94a3b8}.scm-empty{text-align:center;padding:30px 10px;color:#94a3b8;font-size:12px}.scm-section{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;margin:13px 0 8px}.scm-top-actions{display:flex;gap:5px;margin-left:auto}.scm-mini-btn{border:1px solid rgba(168,85,247,.25);background:rgba(168,85,247,.08);color:#c084fc;border-radius:7px;padding:4px 7px;font-size:10px;font-weight:800;cursor:pointer}
      @media(max-width:700px){#scmModal .scm-box{width:96vw}.scm-grid{grid-template-columns:1fr}.scm-invite{flex-direction:column}}
    `;
    document.head.appendChild(s);
  }

  function modal() {
    if (document.getElementById('scmModal')) return;
    styles();
    const m = document.createElement('div');
    m.id = 'scmModal';
    m.innerHTML = '<div class="scm-box" onclick="event.stopPropagation()"><div class="scm-head"><div class="scm-title" id="scmTitle">Server Management</div><button class="scm-close" onclick="window.scmClose()">×</button></div><div class="scm-tabs" id="scmTabs"></div><div class="scm-body" id="scmBody"></div></div>';
    m.onclick = e => { if (e.target === m) close(); };
    document.body.appendChild(m);
  }

  function open(title, tabs, active) {
    modal();
    document.getElementById('scmTitle').textContent = title;
    document.getElementById('scmTabs').innerHTML = tabs.map(t => `<button class="scm-tab ${t === active ? 'on' : ''}" data-scm-tab="${esc(t)}">${esc(t)}</button>`).join('');
    document.querySelectorAll('[data-scm-tab]').forEach(b => b.onclick = () => scmTab(b.dataset.scmTab));
    document.getElementById('scmModal').style.display = 'flex';
  }
  function close() { const m = document.getElementById('scmModal'); if (m) m.style.display = 'none'; }
  window.scmClose = close;

  let context = 'server', currentServer = null, currentChannel = null;

  async function openServerManager(tab = 'Invite') {
    const id = serverId();
    if (!id) return toast('Select a server first');
    context = 'server'; open('Server Management', ['Invite','Members'], tab);
    try { const d = await request('/API/server/members.php','info',{server_id:id}); currentServer = d.server; scmTab(tab); }
    catch (e) { document.getElementById('scmBody').innerHTML = `<div class="scm-empty">${esc(e.message)}</div>`; }
  }
  window.openServerManager = openServerManager;

  async function openChannelManager(tab = 'Invite') {
    const id = channelId();
    if (!id) return toast('Select a channel first');
    context = 'channel'; open('Channel Management', ['Invite','Members'], tab);
    try {
      const d = await request('/API/chat/channel-members.php','list',{channel_id:id});
      currentChannel = d.channel || {id,name:channelName()};
      scmTab(tab, d);
    } catch (e) {
      currentChannel = {id,name:channelName()};
      document.getElementById('scmBody').innerHTML = `<div class="scm-empty">${esc(e.message)}</div>`;
    }
  }
  window.openChannelManager = openChannelManager;
  window.openPrivateChannelManager = () => openChannelManager('Members');

  async function scmTab(tab, prefetched = null) {
    document.querySelectorAll('[data-scm-tab]').forEach(b => b.classList.toggle('on', b.dataset.scmTab === tab));
    const body = document.getElementById('scmBody'); if (!body) return;
    if (context === 'server') return tab === 'Invite' ? renderServerInvite(body) : renderServerMembers(body);
    return tab === 'Invite' ? renderChannelInvite(body) : renderChannelMembers(body, prefetched);
  }
  window.scmTab = scmTab;

  async function renderServerInvite(body) {
    const id = currentServer?.id || serverId();
    body.innerHTML = `<div class="scm-muted" style="margin-bottom:10px">Create a real invite link. Anyone using it will join this server as a regular member.</div><div class="scm-grid"><input id="scmMaxUses" class="scm-input" type="number" min="0" value="0" placeholder="Max uses (0 = unlimited)"><input id="scmExpires" class="scm-input" type="number" min="0" value="0" placeholder="Expires in hours (0 = never)"></div><button class="scm-btn" id="scmCreateServerInviteBtn">🔗 Create Invite Link</button><div class="scm-section">Latest invites</div><div id="scmInviteList"><div class="scm-empty">Loading…</div></div>`;
    document.getElementById('scmCreateServerInviteBtn').onclick = async () => {
      try { const d = await request('/API/server/invite.php','create',{},'POST',{server_id:id,max_uses:Number(document.getElementById('scmMaxUses').value||0),expires_hours:Number(document.getElementById('scmExpires').value||0)}); renderCreatedInvite(body,d.invite.invite_url); await loadServerInvites(); }
      catch (e) { toast(e.message); }
    };
    await loadServerInvites();
  }

  async function loadServerInvites() {
    const el = document.getElementById('scmInviteList'); if (!el) return;
    try {
      const d = await request('/API/server/invite.php','list',{server_id:currentServer?.id || serverId()});
      el.innerHTML = d.invites.length ? d.invites.map(i => `<div class="scm-row"><div class="scm-info"><div class="scm-name">Invite #${i.id}</div><div class="scm-meta">${i.use_count}${i.max_uses ? ' / '+i.max_uses : ' uses'} · ${i.revoked_at ? 'Revoked' : (i.expires_at ? 'Expires '+esc(i.expires_at) : 'Never expires')}</div></div>${!i.revoked_at ? `<button class="scm-btn red" onclick="window.scmRevokeServerInvite(${i.id})">Revoke</button>` : ''}</div>`).join('') : '<div class="scm-empty">No invites created yet.</div>';
      window.scmRevokeServerInvite = async id => { try { await request('/API/server/invite.php','revoke',{},'POST',{invite_id:id}); await loadServerInvites(); } catch(e) { toast(e.message); } };
    } catch (e) { el.innerHTML = `<div class="scm-empty">${esc(e.message)}</div>`; }
  }

  async function renderServerMembers(body) {
    const id = currentServer?.id || serverId();
    body.innerHTML = `<div class="scm-grid"><input id="scmMemberSearch" class="scm-input" placeholder="Search users to add…"><button class="scm-btn" id="scmSearchBtn">Search</button></div><div id="scmCandidates"></div><div class="scm-section">Current members</div><div id="scmMembers"><div class="scm-empty">Loading…</div></div>`;
    const search = async () => {
      try { const q = document.getElementById('scmMemberSearch').value.trim(); const d = await request('/API/server/members.php','candidates',{server_id:id,q}); document.getElementById('scmCandidates').innerHTML = d.users.length ? d.users.map(u => memberRow(u,true)).join('') : '<div class="scm-empty">No users found.</div>'; }
      catch(e) { toast(e.message); }
    };
    document.getElementById('scmSearchBtn').onclick = search;
    document.getElementById('scmMemberSearch').onkeydown = e => { if (e.key === 'Enter') search(); };
    window.scmAddMember = async uid => { try { await request('/API/server/members.php','add',{},'POST',{server_id:id,user_id:uid}); toast('Member added','success'); await search(); await loadServerMembers(); } catch(e) { toast(e.message); } };
    window.scmRemoveMember = async uid => { if (!confirm('Remove this member from the server?')) return; try { await request('/API/server/members.php','remove',{},'POST',{server_id:id,user_id:uid}); toast('Member removed','success'); await loadServerMembers(); } catch(e) { toast(e.message); } };
    await loadServerMembers();
  }

  function memberRow(u, add) {
    const [a,b] = (u.avatar_color_gradient || '#a855f7,#ec4899').split(',');
    return `<div class="scm-row"><div class="scm-avatar" style="background:linear-gradient(135deg,${a},${b})">${esc((u.full_name||u.username||'?')[0].toUpperCase())}</div><div class="scm-info"><div class="scm-name">${esc(u.full_name||u.username)}</div><div class="scm-meta">@${esc(u.username)} · ${esc(u.server_role||u.role||'student')}</div></div>${add ? `<button class="scm-btn green" onclick="window.scmAddMember(${u.id})">+ Add</button>` : (u.server_role !== 'owner' ? `<button class="scm-btn red" onclick="window.scmRemoveMember(${u.id})">Remove</button>` : '')}</div>`;
  }

  async function loadServerMembers() {
    const el = document.getElementById('scmMembers'); if (!el) return;
    try { const d = await request('/API/server/members.php','list',{server_id:currentServer?.id || serverId()}); el.innerHTML = d.members.length ? d.members.map(u => memberRow(u,false)).join('') : '<div class="scm-empty">No members.</div>'; }
    catch(e) { el.innerHTML = `<div class="scm-empty">${esc(e.message)}</div>`; }
  }

  function renderCreatedInvite(body, url) {
    const wrap = document.createElement('div'); wrap.className = 'scm-invite'; wrap.style.margin = '12px 0';
    wrap.innerHTML = `<input class="scm-input" value="${esc(url)}" readonly><button class="scm-btn green">Copy</button>`;
    wrap.querySelector('button').onclick = async () => { try { await navigator.clipboard.writeText(url); toast('Invite link copied','success'); } catch(e) { toast('Copy failed'); } };
    body.insertBefore(wrap, body.children[2] || null);
  }

  async function renderChannelInvite(body) {
    const id = currentChannel?.id || channelId();
    body.innerHTML = `<div class="scm-muted" style="margin-bottom:10px">Create a shareable invite for <strong>#${esc(currentChannel?.name||channelName())}</strong>. The link grants channel access and joins the server if needed.</div><div class="scm-grid"><input id="scmChMaxUses" class="scm-input" type="number" min="0" value="0" placeholder="Max uses (0 = unlimited)"><input id="scmChExpires" class="scm-input" type="number" min="0" value="0" placeholder="Expires in hours (0 = never)"></div><button class="scm-btn" id="scmCreateChannelInviteBtn">🔗 Create Channel Invite</button><div class="scm-section">Latest invites</div><div id="scmChannelInviteList"><div class="scm-empty">Loading…</div></div>`;
    document.getElementById('scmCreateChannelInviteBtn').onclick = async () => {
      try { const d = await request('/API/chat/channel-invite.php','create',{},'POST',{channel_id:id,max_uses:Number(document.getElementById('scmChMaxUses').value||0),expires_hours:Number(document.getElementById('scmChExpires').value||0)}); renderCreatedInvite(body,d.invite.invite_url); await loadChannelInvites(); }
      catch(e) { toast(e.message); }
    };
    await loadChannelInvites();
  }

  async function loadChannelInvites() {
    const el = document.getElementById('scmChannelInviteList'); if (!el) return;
    try {
      const d = await request('/API/chat/channel-invite.php','list',{channel_id:currentChannel?.id || channelId()});
      el.innerHTML = d.invites.length ? d.invites.map(i => `<div class="scm-row"><div class="scm-info"><div class="scm-name">Invite #${i.id}</div><div class="scm-meta">${i.use_count}${i.max_uses ? ' / '+i.max_uses : ' uses'} · ${i.revoked_at ? 'Revoked' : (i.expires_at ? 'Expires '+esc(i.expires_at) : 'Never expires')}</div></div>${!i.revoked_at ? `<button class="scm-btn red" onclick="window.scmRevokeChannelInvite(${i.id})">Revoke</button>` : ''}</div>`).join('') : '<div class="scm-empty">No channel invites yet.</div>';
      window.scmRevokeChannelInvite = async id => { try { await request('/API/chat/channel-invite.php','revoke',{},'POST',{invite_id:id}); await loadChannelInvites(); } catch(e) { toast(e.message); } };
    } catch(e) { el.innerHTML = `<div class="scm-empty">${esc(e.message)}</div>`; }
  }

  async function renderChannelMembers(body, prefetched) {
    const id = currentChannel?.id || channelId();
    try {
      const data = prefetched || await request('/API/chat/channel-members.php','list',{channel_id:id});
      currentChannel = data.channel || {id,name:channelName()};
      body.innerHTML = '<div class="scm-muted" style="margin-bottom:10px">Members of this server and their access to this channel.</div><div id="scmChannelMembers"></div>';
      const render = () => {
        const el = document.getElementById('scmChannelMembers'); if (!el) return;
        el.innerHTML = data.members.length ? data.members.map(u => `<div class="scm-row"><div class="scm-avatar" style="background:linear-gradient(135deg,#a855f7,#ec4899)">${esc((u.full_name||u.username||'?')[0].toUpperCase())}</div><div class="scm-info"><div class="scm-name">${esc(u.full_name||u.username)}</div><div class="scm-meta">${u.has_access ? '✓ Has access' : 'Locked'} · ${esc(u.server_role||'member')}</div></div>${u.has_access ? `<button class="scm-btn red" onclick="window.scmChannelAccess(${u.id},false)">Remove</button>` : `<button class="scm-btn green" onclick="window.scmChannelAccess(${u.id},true)">Add</button>`}</div>`).join('') : '<div class="scm-empty">No other server members.</div>';
      };
      render();
      window.scmChannelAccess = async (uid, add) => {
        try { await request('/API/chat/channel-members.php','',{},'POST',{action:add?'add':'remove',channel_id:currentChannel.id,user_id:uid}); toast(add?'Channel access granted':'Channel access removed','success'); const fresh = await request('/API/chat/channel-members.php','list',{channel_id:currentChannel.id}); data.members = fresh.members; render(); }
        catch(e) { toast(e.message); }
      };
    } catch(e) { body.innerHTML = `<div class="scm-empty">${esc(e.message)}</div>`; }
  }

  async function handleInviteQuery() {
    const url = new URL(window.location.href);
    const serverToken = url.searchParams.get('invite');
    const channelToken = url.searchParams.get('channel_invite');
    if (!serverToken && !channelToken) return;
    try {
      if (serverToken) { const d = await request('/API/server/invite.php','join',{},'POST',{invite_code:serverToken}); toast(`Joined ${d.name}`,'success'); }
      if (channelToken) { const d = await request('/API/chat/channel-invite.php','join',{},'POST',{invite_code:channelToken}); toast(`Joined #${d.channel_name}`,'success'); }
      ['invite','channel_invite'].forEach(k => url.searchParams.delete(k));
      history.replaceState({},'',url.toString());
      setTimeout(() => location.reload(), 350);
    } catch(e) { toast(e.message); }
  }

  function headerActions() {
    const ws = document.getElementById('wsHeader');
    if (ws && !document.getElementById('scmServerBtns')) {
      const box = document.createElement('div'); box.id = 'scmServerBtns'; box.className = 'scm-top-actions';
      box.innerHTML = '<button class="scm-mini-btn" title="Invite to server">🔗</button><button class="scm-mini-btn" title="Manage members">👥</button>';
      box.children[0].onclick = e => { e.stopPropagation(); openServerManager('Invite'); };
      box.children[1].onclick = e => { e.stopPropagation(); openServerManager('Members'); };
      ws.appendChild(box);
    }
    const header = document.querySelector('.chat-header>div:last-child');
    if (header && !document.getElementById('scmChannelInviteBtn')) {
      const b = document.createElement('button'); b.id = 'scmChannelInviteBtn'; b.className = 'header-icon-btn'; b.title = 'Channel Invite'; b.textContent = '🔗'; b.onclick = () => openChannelManager('Invite');
      const memberBtn = document.querySelector('.header-members');
      if (memberBtn) header.insertBefore(b, memberBtn); else header.appendChild(b);
    }
  }

  function boot() {
    styles();
    headerActions();
    handleInviteQuery();
    const observer = new MutationObserver(headerActions);
    observer.observe(document.body,{subtree:true,childList:true});
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',boot); else boot();
})();
