Chart.defaults.color = '#94a3b8';
Chart.defaults.font.family = 'Plus Jakarta Sans';
Chart.defaults.font.size = 10;

// ═══ NAVIGATION ═══
const bc = {overview:'Overview',users:'Users',roles:'Roles & Permissions',courses:'Course & Tags',aimatching:'AI Matching',reports:'Reports',servers:'Servers',channels:'Channels',settings:'Settings',moderation:'Moderation',analytics:'Analytics',activitylogs:'Activity Logs',syshealth:'System Health',announcements:'Announcements',feedback:'Feedback & Reports'};

function showPage(id, navEl) {
  document.querySelectorAll('.page-section').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  const p = document.getElementById('page-'+id);
  if (p) p.classList.add('active');
  if (navEl) navEl.classList.add('active');
  else {
    document.querySelectorAll('.nav-item').forEach(n => {
      if (n.getAttribute('onclick') && n.getAttribute('onclick').includes("'"+id+"'")) n.classList.add('active');
    });
  }
  const bcText = document.getElementById('bcText');
  if (bcText) bcText.textContent = bc[id] || id;
  closeAllDropdowns();
  if (id==='analytics') initAnalyticsCharts();
  if (id==='aimatching') initMatchingChart();
  if (id==='reports') loadReports();
  if (id==='facilitatorrequests') loadFacilitatorRequests();
}

// ═══ MODALS ═══
let currentCtxUser = '';
function openModal(id, param) {
  closeAllDropdowns();
  // Set params
  if (param) {
    if (id==='banModal') { const t=document.getElementById('banTarget')||document.getElementById('banUsername'); if(t)t.textContent=param; }
    if (id==='kickModal') { const t=document.getElementById('kickTarget')||document.getElementById('kickUsername'); if(t)t.textContent=param; }
    if (id==='muteModal') { const t=document.getElementById('muteTarget')||document.getElementById('muteUsername'); if(t)t.textContent=param; }
    if (id==='warnModal') { const t=document.getElementById('warnTarget'); if(t)t.textContent=param; }
    if (id==='editRoleModal') { const t=document.getElementById('editRoleTarget'); if(t) t.value=param; }
    if (id==='editPermsModal') { const t=document.getElementById('editPermsRole'); if(t) t.textContent=param; }
    if (id==='deleteRoleModal') { const t=document.getElementById('deleteRoleTarget')||document.getElementById('drRole'); if(t)t.textContent=param; }
    if (id==='serverDetailModal') { const a=document.getElementById('serverDetailTitle')||document.getElementById('sdmTitle'); const b=document.getElementById('serverDetailName'); if(a)a.textContent='Server — '+param;if(b)b.textContent=param; }
    if (id==='serverPermsModal') { }
    if (id==='deleteServerModal') { const t=document.getElementById('deleteServerTarget')||document.getElementById('delSrvName');if(t)t.textContent=param; }
    if (id==='editChannelModal') { const t=document.getElementById('editChannelName')||document.getElementById('ecInput');if(t)t.value=param;const n=document.getElementById('ecName');if(n)n.textContent=param; }
    if (id==='modActionDetailModal') {
      const types={ban:'BAN — Permanent',kick:'KICK — Session Removal',warn:'WARNING — Formal Notice',mute:'MUTE — 1 Hour'};
      const a=document.getElementById('modDetailTitle'),b=document.getElementById('modDetailType');if(a)a.textContent='Report — '+param.toUpperCase();if(b)b.textContent=types[param]||param;
    }
  }
  const overlay = document.getElementById(id);
  if (overlay) { overlay.classList.add('show'); document.body.style.overflow='hidden'; }
}
function closeModal(id) {
  const overlay = document.getElementById(id);
  if (overlay) { overlay.classList.remove('show'); document.body.style.overflow=''; }
}
// Close modal on overlay click
document.querySelectorAll('.modal-overlay').forEach(o => {
  o.addEventListener('click', e => { if (e.target === o) closeModal(o.id); });
});
// ESC key
document.addEventListener('keydown', e => {
  if (e.key==='Escape') {
    document.querySelectorAll('.modal-overlay.show').forEach(o => closeModal(o.id));
    closeAllDropdowns(); closeCtx();
  }
});

// ═══ DROPDOWNS ═══
function toggleNotifDrop() {
  const d = document.getElementById('notifDrop') || document.getElementById('nDrop');
  if (!d) return;
  const isOpen = d.classList.contains('show');
  closeAllDropdowns();
  if (!isOpen) d.classList.add('show');
}
function toggleNotif(){ toggleNotifDrop(); }
function togglePDrop(){ toggleProfileDrop(); }
function toggleProfileDrop() {
  const d = document.getElementById('profileDrop') || document.getElementById('pDrop');
  if (!d) return;
  const isOpen = d.classList.contains('show');
  closeAllDropdowns();
  if (!isOpen) d.classList.add('show');
}
function closeAllDropdowns() {
  [document.getElementById('notifDrop'),document.getElementById('nDrop'),document.getElementById('profileDrop'),document.getElementById('pDrop')].forEach(el=>el?.classList.remove('show'));
  hideSearchDrop();
}
document.addEventListener('click', e => {
  if (!e.target.closest('#notifBtn') && !e.target.closest('#nBtn') && !e.target.closest('#nWrap')) (document.getElementById('notifDrop')||document.getElementById('nDrop'))?.classList.remove('show');
  if (!e.target.closest('#profileChip') && !e.target.closest('#pWrap')) (document.getElementById('profileDrop')||document.getElementById('pDrop'))?.classList.remove('show');
  if (!e.target.closest('#searchBar')) hideSearchDrop();
  if (!e.target.closest('#ctxMenu') && !e.target.closest('.btn-more')) closeCtx();
});

// ═══ NOTIFICATIONS ═══
function handleNotifClick(el, msg) {
  el.classList.remove('unread');
  el.querySelector('.nd-dot') && el.querySelector('.nd-dot').remove();
  updateNotifBadge();
  showToast(msg, 'info', '🔔');
}
function clearNotifs() {
  document.querySelectorAll('.nd-item').forEach(i => { i.classList.remove('unread'); const d=i.querySelector('.nd-dot'); if(d)d.remove(); });
  document.getElementById('notifBadge').style.display='none';
  showToast('All notifications cleared', 'success', '✓');
}
function updateNotifBadge() {
  const count = document.querySelectorAll('.nd-item.unread').length;
  const badge = document.getElementById('notifBadge');
  if (count === 0) badge.style.display='none';
  else { badge.style.display='flex'; badge.textContent=count; }
}

// ═══ SEARCH ═══
function handleSearch(v) {
  if (v.length > 0) showSearchDrop(); else hideSearchDrop();
}
function showSearchDrop() {
  const input=document.getElementById('globalSearch'), drop=document.getElementById('searchDrop');
  if (input && drop && input.value.length > 0) drop.classList.add('show');
}
function hideSearchDrop() {
  document.getElementById('searchDrop')?.classList.remove('show');
}

// ═══ CONTEXT MENU ═══
function openContextMenu(e, username) {
  e.stopPropagation();
  currentCtxUser = username;
  const m = document.getElementById('ctxMenu');
  m.style.left = e.clientX + 'px';
  m.style.top = e.clientY + 'px';
  m.classList.add('show');
}
function closeCtx() { document.getElementById('ctxMenu').classList.remove('show'); }
function openUserProfileFromCtx() {
  closeCtx();
  openUserProfile(currentCtxUser, currentCtxUser[0], '#ff4fd8,#7c5cff', 'Student', 'Computer Science', 'Active', '2025');
}

// ═══ USER PROFILE ═══
function openUserProfile(name, initial, grad, role, course, status, joinDate) {
  document.getElementById('profileModalTitle').textContent = name;
  document.getElementById('profileModalName').textContent = name;
  const av = document.getElementById('profileAvLg');
  av.textContent = initial;
  av.style.background = 'linear-gradient(135deg,' + grad + ')';
  const roleEl = document.getElementById('profileModalRole');
  roleEl.textContent = role;
  const roleColors = {Student:'rgba(34,197,94,0.15),var(--green)',Facilitator:'rgba(245,158,11,0.15),var(--yellow)',Moderator:'rgba(0,212,255,0.15),var(--blue)',Admin:'rgba(255,79,216,0.15),var(--pink)'};
  const rc = roleColors[role] ? roleColors[role].split(',') : ['rgba(148,163,184,0.1)','var(--muted)'];
  roleEl.style.background = rc[0]; roleEl.style.color = rc[1];
  document.getElementById('profileCourse').textContent = course;
  document.getElementById('profileStatus').textContent = '● ' + status;
  document.getElementById('profileJoinDate').textContent = joinDate;
  // reset tabs
  document.querySelectorAll('#userProfileModal .tab-btn').forEach(b=>b.classList.remove('active'));
  document.querySelectorAll('#userProfileModal .tab-content').forEach(c=>c.classList.remove('active'));
  document.querySelector('#userProfileModal .tab-btn').classList.add('active');
  document.getElementById('profileInfo').classList.add('active');
  openModal('userProfileModal');
}

// ═══ TAB SWITCHING ═══
function switchTab(btn, contentId) {
  const modal = btn.closest('.modal');
  modal.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
  modal.querySelectorAll('.tab-content').forEach(c=>c.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById(contentId).classList.add('active');
}

// ═══ SETTINGS ═══
function switchSettingsTab(btn, tab) {
  document.querySelectorAll('.settings-tab').forEach(t=>t.classList.remove('active'));
  btn.classList.add('active');
  ['general','appearance','security','notifications','privacy'].forEach(t => {
    const el = document.getElementById('settings'+t.charAt(0).toUpperCase()+t.slice(1));
    if (el) el.style.display = t===tab ? 'block' : 'none';
  });
}
function toggleSetting(el, name) {
  el.classList.toggle('on');
  const state = el.classList.contains('on') ? 'enabled' : 'disabled';
  showToast(name + ' ' + state, 'success', '⚙️');
}
function saveSettings() {
  showToast('Settings saved successfully', 'success', '✅');
}

// ═══ MODERATION ACTIONS ═══
function moderateAction(action, item, reporter) {
  if (action==='approve') showToast('Action approved for: ' + item, 'success', '✅');
  else showToast('Action denied for: ' + item, 'info', '❌');
}
function filterModLog(btn, type) {
  document.querySelectorAll('#page-moderation .log-filter-btn').forEach(b=>b.classList.remove('active'));
  btn.classList.add('active');
  document.querySelectorAll('#modLogContainer .log-entry').forEach(e => {
    e.style.display = (type==='all' || e.dataset.type===type) ? 'flex' : 'none';
  });
}
async function loadReports(status = 'pending') {
  const container = document.getElementById('reportsContainer');
  if (!container) return;
  container.innerHTML = '<div style="padding:20px;color:var(--muted);text-align:center;">Loading reports...</div>';
  try {
    const base = window.ECOLLAB?.baseUrl || '';
    const res = await fetch(`${base}/API/admin/dashboard-data.php?action=get_reports&status=${status}`, { credentials: 'same-origin' });
    const data = await res.json();
    if (!data.success || !data.reports.length) {
      container.innerHTML = '<div style="padding:30px;color:var(--muted);text-align:center;">✅ No ' + status + ' reports.</div>';
      return;
    }
    // Update badge
    const badge = document.querySelector('#page-reports .filter-bar span[style*="red"]');
    if (badge) badge.textContent = data.pending_count + ' pending';

    container.innerHTML = data.reports.map(r => `
      <div class="report-item" data-report-id="${r.id}" style="background:var(--card);border:1px solid var(--border);border-radius:10px;padding:16px;margin-bottom:10px;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap;">
          <div style="flex:1;min-width:0;">
            <div style="font-size:12px;color:var(--muted);margin-bottom:4px;">
              <strong style="color:var(--text)">${escHtml(r.reporter_username || '?')}</strong> reported
              <strong style="color:#f87171;">${escHtml(r.reported_username || '?')}</strong>
              ${r.server_name ? '· in <strong>' + escHtml(r.server_name) + '</strong>' : ''}
              <span style="margin-left:8px;opacity:.6;">${new Date(r.created_at).toLocaleDateString()}</span>
            </div>
            <div style="font-size:12px;background:rgba(239,68,68,0.07);border-left:3px solid #ef4444;padding:6px 10px;border-radius:0 6px 6px 0;margin:6px 0;color:var(--text);">
              ${escHtml((r.message_content || '[message deleted]').substring(0, 200))}
            </div>
            <div style="font-size:11px;color:var(--muted);">
              Reason: <span style="color:#f87171;font-weight:600;">${r.reason}</span>
              ${r.description ? ' · ' + escHtml(r.description.substring(0, 100)) : ''}
            </div>
          </div>
          <div style="display:flex;gap:6px;flex-shrink:0;">
            <span style="padding:3px 8px;border-radius:20px;font-size:11px;font-weight:700;background:${r.status==='pending'?'rgba(251,191,36,0.15)':r.status==='resolved'?'rgba(34,197,94,0.15)':'rgba(107,114,128,0.15)'};color:${r.status==='pending'?'#fbbf24':r.status==='resolved'?'#22c55e':'#9ca3af'};">${r.status}</span>
          </div>
        </div>
        ${r.status === 'pending' ? `
        <div style="display:flex;gap:8px;margin-top:12px;padding-top:10px;border-top:1px solid var(--border);">
          <button onclick="resolveReport(this,'resolved')" style="padding:6px 14px;background:rgba(239,68,68,0.15);color:#f87171;border:1px solid rgba(239,68,68,0.3);border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;">🗑 Delete Message</button>
          <button onclick="resolveReport(this,'dismissed')" style="padding:6px 14px;background:var(--bg-hover);color:var(--muted);border:1px solid var(--border);border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;">Dismiss</button>
        </div>` : ''}
      </div>
    `).join('');
  } catch(e) {
    container.innerHTML = '<div style="padding:20px;color:#f87171;text-align:center;">Failed to load reports.</div>';
  }
}

function escHtml(s) { const d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }

async function resolveReport(btn, resolution) {
  const row = btn.closest('.report-item');
  const reportId = parseInt(row?.dataset?.reportId);
  if (!reportId) return;
  btn.disabled = true;
  row.style.opacity = '0.5';
  try {
    const base = window.ECOLLAB?.baseUrl || '';
    const csrfRes = await fetch(`${base}/API/auth/csrf-token.php`);
    const csrfData = await csrfRes.json();
    await fetch(`${base}/API/admin/dashboard-data.php?action=resolve_report`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfData.token || '' },
      body: JSON.stringify({ report_id: reportId, resolution }),
    });
    row.remove();
    showToast(resolution === 'resolved' ? '🗑 Message deleted & report resolved' : '✅ Report dismissed', 'success');
  } catch(e) {
    btn.disabled = false;
    row.style.opacity = '1';
    showToast('Failed to resolve report', 'error');
  }
}

// ═══ USER ACTIONS ═══
function executeBan() {
  closeModal('banModal');
  showToast('User banned successfully', 'success', '🚫');
  addLogEntry('red', 'Ban action issued');
}
function executeKick() {
  closeModal('kickModal');
  showToast('User kicked from session', 'success', '👢');
}
function executeMute() {
  closeModal('muteModal');
  showToast('User muted successfully', 'success', '🔇');
}
function executeWarn() {
  closeModal('warnModal');
  showToast('Warning issued to user', 'warning', '⚠️');
}
function createUser() {
  const fn = document.getElementById('newUserFN').value;
  const ln = document.getElementById('newUserLN').value;
  const un = document.getElementById('newUserUN').value;
  if (!fn || !un) { showToast('Please fill required fields', 'error', '❌'); return; }
  closeModal('createUserModal');
  showToast('User ' + (un||fn+' '+ln) + ' created', 'success', '✅');
  addLogEntry('green', 'New user created: ' + (un||fn));
}
function saveUser() { closeModal('editUserModal'); showToast('User updated successfully', 'success', '✅'); }
function saveRole() { closeModal('editRoleModal'); showToast('Role updated successfully', 'success', '✅'); }

// ═══ ROLE ACTIONS ═══
function createRole() {
  const name = document.getElementById('newRoleName').value;
  if (!name) { showToast('Please enter a role name', 'error', '❌'); return; }
  closeModal('createRoleModal');
  showToast('Role "' + name + '" created', 'success', '✅');
}
function savePermissions() {
  const modal = document.querySelector('.modal-overlay.show');
  if (modal) closeModal(modal.id);
  showToast('Permissions saved successfully', 'success', '✅');
}
function deleteRole() {
  const target = document.getElementById('deleteRoleTarget').textContent;
  closeModal('deleteRoleModal');
  showToast('Role "' + target + '" deleted', 'success', '🗑');
}

// ═══ COURSE/TAG ACTIONS ═══
function createCourse() {
  const name = document.getElementById('newCourseName').value;
  if (!name) { showToast('Please enter a course name', 'error', '❌'); return; }
  closeModal('createCourseModal');
  showToast('Course "' + name + '" created', 'success', '✅');
}
let selectedTagBg = 'rgba(255,79,216,0.15)', selectedTagColor = 'var(--pink)';
function selectTagColor(el, bg, color) {
  document.querySelectorAll('#tagColorPicker div').forEach(d=>d.style.border='none');
  el.style.border = '2px solid white';
  selectedTagBg = bg; selectedTagColor = color;
}
function createTag() {
  const name = document.getElementById('newTagName').value;
  if (!name) { showToast('Please enter a tag name', 'error', '❌'); return; }
  closeModal('createTagModal');
  const grid = document.getElementById('tagsGrid');
  const tag = document.createElement('span');
  tag.className = 'tag-item';
  tag.style.cssText = 'background:'+selectedTagBg+';color:'+selectedTagColor;
  tag.innerHTML = name + ' <span class="tag-x" onclick="removeTag(this,\''+name+'\')">✕</span>';
  grid.appendChild(tag);
  document.getElementById('newTagName').value = '';
  showToast('Tag "' + name + '" added', 'success', '✅');
}
function removeTag(el, name) {
  if (!confirm('Remove tag "'+name+'"?')) return;
  el.closest('.tag-item').remove();
  showToast('Tag "' + name + '" removed', 'info', '🗑');
}

// ═══ AI ═══
function saveAIConfig() { closeModal('aiConfigModal'); showToast('AI configuration saved', 'success', '✅'); }

// ═══ SERVER/CHANNEL ═══
function createServer() {
  const name = document.getElementById('newServerName').value;
  if (!name) { showToast('Please enter a server name', 'error', '❌'); return; }
  closeModal('createServerModal');
  const list = document.getElementById('serversList');
  const item = document.createElement('div');
  item.className = 'server-item';
  item.innerHTML = `<div class="srv-icon" style="background:rgba(124,92,255,0.15)">🖥</div><div class="srv-name">${name}</div><div class="srv-count">👥 0 members</div><div class="srv-status-dot"></div><div class="action-btns" style="margin-left:12px"><button class="btn-view" onclick="openModal('serverDetailModal','${name}')">Manage</button><button class="btn-deny" onclick="openModal('deleteServerModal','${name}')">Delete</button></div>`;
  list.appendChild(item);
  document.getElementById('newServerName').value = '';
  showToast('Server "' + name + '" created', 'success', '✅');
}
function saveServer() { closeModal('serverDetailModal'); showToast('Server updated', 'success', '✅'); }
function deleteServer() {
  const confirm = document.getElementById('deleteServerConfirm').value;
  const target = document.getElementById('deleteServerTarget').textContent;
  if (confirm !== target) { showToast('Server name does not match', 'error', '❌'); return; }
  closeModal('deleteServerModal');
  showToast('Server "' + target + '" deleted', 'success', '🗑');
}

let selectedChannelType = 'text';
function selectChannelType(type) {
  selectedChannelType = type;
  ['text','voice','whiteboard'].forEach(t => {
    const el = document.getElementById('chType'+t.charAt(0).toUpperCase()+t.slice(1));
    if (el) { el.style.background='rgba(255,255,255,0.03)'; el.style.border='1px solid var(--border)'; el.style.color='var(--muted)'; }
  });
  const active = document.getElementById('chType'+type.charAt(0).toUpperCase()+type.slice(1));
  if (active) { active.style.background='rgba(255,79,216,0.15)'; active.style.border='2px solid var(--pink)'; active.style.color='var(--text)'; }
}
function createChannel() {
  const name = document.getElementById('newChannelName').value;
  if (!name) { showToast('Please enter a channel name', 'error', '❌'); return; }
  closeModal('createChannelModal');
  const list = document.getElementById('channelsList');
  const icons = {text:'#',voice:'🔊',whiteboard:'🎨'};
  const badges = {text:'Text',voice:'Voice',whiteboard:'Whiteboard'};
  const item = document.createElement('div');
  item.className = 'channel-item';
  item.innerHTML = `<div class="ch-type-icon">${icons[selectedChannelType]}</div><span class="ch-name">${name}</span><span class="ch-badge">${badges[selectedChannelType]}</span><span style="color:var(--muted);font-size:11px;margin-left:auto;margin-right:12px">0 members</span><div class="action-btns"><button class="btn-view" onclick="openModal('editChannelModal','${name}')">Edit</button><button class="btn-deny" onclick="openModal('deleteChannelModal','${name}')">Delete</button></div>`;
  list.appendChild(item);
  document.getElementById('newChannelName').value = '';
  showToast('Channel "' + name + '" created', 'success', '✅');
}
function saveChannel() { closeModal('editChannelModal'); showToast('Channel updated', 'success', '✅'); }
function deleteChannel() { closeModal('deleteChannelModal'); showToast('Channel deleted', 'success', '🗑'); }

// ═══ ROOM ACTIONS ═══
function joinRoom(name) { showToast('Joined ' + name, 'success', '✅'); }
function joinRoomModal() {
  const code = document.getElementById('joinRoomCode').value;
  if (!code) { showToast('Please enter a room code', 'error', '❌'); return; }
  closeModal('joinRoomModal');
  showToast('Joined room: ' + code, 'success', '✅');
}

// ═══ ANNOUNCEMENTS ═══
function sendAnnouncement() {
  const title = document.getElementById('announcTitle').value;
  const msg = document.getElementById('announcMsg').value;
  if (!title || !msg) { showToast('Please fill title and message', 'error', '❌'); return; }
  const server = document.getElementById('announcServer').value;
  showToast('Announcement broadcast to ' + server, 'success', '📢');
  addLogEntry('blue', 'Announcement sent: ' + title);
  const list = document.getElementById('announcList');
  const item = document.createElement('div');
  item.style.cssText='padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;gap:12px';
  item.innerHTML=`<div style="width:8px;height:8px;border-radius:50%;background:var(--pink);margin-top:5px;flex-shrink:0"></div><div style="flex:1"><div style="font-size:13px;font-weight:600">${title}</div><div style="font-size:11px;color:var(--muted);margin-top:2px">${server} • Just now</div></div><div style="display:flex;gap:6px"><button class="btn-sm btn-outline" onclick="showToast('Pinned','success','📌')">Pin</button><button class="btn-deny" onclick="this.closest('div[style]').remove();showToast('Deleted','success','🗑')">Delete</button></div>`;
  list.insertBefore(item, list.firstChild);
  document.getElementById('announcTitle').value='';
  document.getElementById('announcMsg').value='';
}
function pinAnnouncement() {
  const title = document.getElementById('announcTitle').value;
  if (!title) { showToast('Please enter a title first', 'error', '❌'); return; }
  showToast('Message pinned', 'success', '📌');
}
function scheduleAnnouncement() {
  const t = document.getElementById('announcSchedule').value;
  if (!t) { showToast('Please set a schedule time first', 'error', '❌'); return; }
  showToast('Announcement scheduled', 'success', '🕐');
}
function previewAnnouncement() {
  document.getElementById('previewTitle').textContent = document.getElementById('announcTitle').value || '(No title)';
  document.getElementById('previewMsg').textContent = document.getElementById('announcMsg').value || '(No message)';
  document.getElementById('previewServer').textContent = document.getElementById('announcServer').value;
  openModal('announcPreviewModal');
}

// ═══ FEEDBACK ═══
function resolveFeedback(btn, status) {
  const row = btn.closest('.fb-item');
  const statusEl = row.querySelector('.fb-status');
  statusEl.className = 'fb-status resolved';
  statusEl.textContent = status;
  btn.remove();
  showToast('Feedback marked as ' + status, 'success', '✅');
}

// ═══ FILTERS ═══
function filterUsersTable(q) {
  const usersPage=document.getElementById('page-users');
  if(usersPage?.classList.contains('active')) { applyUserFilters(); return; }
  document.querySelectorAll('#recentUsersTable tr,#usersTable tr').forEach(r => {
    r.style.display = r.textContent.toLowerCase().includes(String(q).toLowerCase()) ? '' : 'none';
  });
}

// ═══ PASSWORD ═══
function changePassword() {
  const p1 = document.getElementById('newPass1').value;
  const p2 = document.getElementById('newPass2').value;
  if (!p1 || p1 !== p2) { showToast('Passwords do not match', 'error', '❌'); return; }
  closeModal('changePasswordModal');
  showToast('Password updated successfully', 'success', '🔒');
}

// ═══ PROFILE ═══
function saveProfile() { closeModal('editProfileModal'); showToast('Profile updated', 'success', '✅'); }

// ═══ LOGOUT ═══
function logout() {
  closeModal('logoutModal');
  showToast('Signing out...', 'info', '🚪');
  setTimeout(()=>{ document.body.innerHTML='<div style="display:flex;align-items:center;justify-content:center;height:100vh;flex-direction:column;gap:16px;background:#070b14;color:#fff;font-family:Plus Jakarta Sans,sans-serif"><div style="font-size:32px">🔷</div><div style="font-size:24px;font-weight:800">Ecollab</div><div style="color:#94a3b8">You have been signed out.</div><button onclick="location.reload()" style="margin-top:12px;padding:10px 24px;background:linear-gradient(135deg,#ff4fd8,#7c5cff);border:none;border-radius:10px;color:#fff;font-size:14px;font-weight:700;cursor:pointer">Sign In Again</button></div>'; }, 1000);
}

// ═══ EXPORT ═══
function exportData(type) {
  const base=window.ECOLLAB_BASE||'';
  if(type==='users'||type==='all'){
    window.location.href=base+'/API/admin/dashboard-data.php?action=export';
    return;
  }
  const rows=[...document.querySelectorAll(type==='modlogs'?'#modLogContainer .log-entry':type==='logs'?'.log-list .log-item':'#page-'+type+' table tr')];
  if(!rows.length){showToast('No '+type+' data available to export.','warning','⚠️');return;}
  const csv=rows.filter(r=>r.offsetParent!==null).map(r=>[...r.querySelectorAll('th,td,.le-main,.le-sub,.le-time,.log-time,.log-msg')].map(x=>'"'+x.textContent.trim().replaceAll('"','""')+'"').join(',')).join('\n');
  const blob=new Blob([csv],{type:'text/csv;charset=utf-8'}),a=document.createElement('a');
  a.href=URL.createObjectURL(blob);a.download='ecollab-'+type+'-'+new Date().toISOString().slice(0,10)+'.csv';a.click();URL.revokeObjectURL(a.href);
}

// ═══ MODERATION ═══
function issueModerationAction() { closeModal('issueBanModal'); showToast('Moderation action issued', 'success', '🔨'); }

// ═══ SYSTEM HEALTH ═══
async function refreshHealth() {
  showToast('Loading live VPS metrics...','info','🔄');
  try{
    const r=await fetch((window.ECOLLAB_BASE||'')+'/API/admin/system-health.php',{credentials:'same-origin'}),d=await r.json();
    if(!r.ok||!d.success) throw new Error(d.error||'Health request failed');
    const h=d.health,m=h.memory||{};
    const set=(id,v)=>{const e=document.getElementById(id);if(e)e.textContent=v};
    set('cpuVal',h.cpu_percent==null?'N/A':h.cpu_percent+'%'); set('memVal',m.percent==null?'N/A':m.percent+'%'); set('diskVal',h.disk_percent==null?'N/A':h.disk_percent+'%'); set('phpVal',h.php_version||'N/A');
    const up=Number(h.uptime_seconds||0);set('uptimeVal',up?Math.floor(up/86400)+'d '+Math.floor((up%86400)/3600)+'h':'N/A');
    const cpu=document.getElementById('cpuBar'),mem=document.getElementById('memBar');if(cpu)cpu.style.width=(h.cpu_percent||0)+'%';if(mem)mem.style.width=(m.percent||0)+'%';
    showToast('Live VPS metrics updated','success','✅');
  }catch(e){showToast(e.message||'Unable to read VPS metrics','error','❌');}
}
function clearLogs() { closeModal('clearLogsModal'); showToast('Error logs cleared', 'success', '🗑'); }

// ═══ TOAST ═══
function showToast(msg, type='info', icon='ℹ️') {
  const c = document.getElementById('toastContainer');
  const t = document.createElement('div');
  t.className = 'toast ' + type;
  t.innerHTML = `<span class="toast-icon">${icon}</span><span class="toast-msg">${msg}</span><span class="toast-close" onclick="this.parentElement.remove()">✕</span>`;
  c.appendChild(t);
  setTimeout(()=>{ t.style.animation='toastOut 0.3s ease forwards'; setTimeout(()=>t.remove(), 300); }, 3500);
}

// ═══ LOG ENTRY ═══
function addLogEntry(color, msg) {
  const list = document.getElementById('systemLogList');
  const now = new Date();
  const ts = now.toISOString().replace('T',' ').substring(0,19);
  const item = document.createElement('div');
  item.className = 'log-item';
  item.innerHTML = `<div class="log-dot ${color}"></div><div class="log-time">${ts}</div><div class="log-msg">${msg}</div>`;
  list.insertBefore(item, list.firstChild);
  if (list.children.length > 8) list.lastChild.remove();
}

// ═══ CHARTS ═══
function initSessionsChart() {
  const ctx = document.getElementById('sessionsChart');
  if (!ctx) return;
  const data = Array.isArray(window.ADMIN_DATA?.sessData) ? window.ADMIN_DATA.sessData : [];
  const labels = data.map((_, i) => 'Day ' + (i + 1));
  if (!data.length) {
    const wrap = ctx.parentElement;
    if (wrap) wrap.innerHTML = '<div class="dashboard-empty-state">No session data available.</div>';
    return;
  }
  ctx._c = new Chart(ctx, { type:'line', data:{ labels, datasets:[{ data, borderColor:'#ff4fd8', backgroundColor:'rgba(255,79,216,0.1)', borderWidth:2, fill:true, tension:0.4, pointBackgroundColor:'#ff4fd8', pointRadius:3 }] }, options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{ x:{grid:{color:'rgba(255,255,255,0.05)'},border:{display:false}}, y:{grid:{color:'rgba(255,255,255,0.05)'},border:{display:false},min:0}} } });
}
function initEngagementChart() {
  const ctx = document.getElementById('engagementChart');
  if (!ctx) return;
  const data = Array.isArray(window.ADMIN_DATA?.engData) ? window.ADMIN_DATA.engData : [];
  const labels = data.map((_, i) => 'Day ' + (i + 1));
  if (!data.length) {
    const wrap = ctx.parentElement;
    if (wrap) wrap.innerHTML = '<div class="dashboard-empty-state">No engagement data available.</div>';
    return;
  }
  ctx._c = new Chart(ctx, { type:'bar', data:{ labels, datasets:[{ label:'Messages', data, backgroundColor:'rgba(255,79,216,0.7)', borderRadius:4 }] }, options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{ x:{grid:{display:false},border:{display:false}}, y:{grid:{color:'rgba(255,255,255,0.05)'},border:{display:false},min:0} } } });
}
function initRingChart() {
  const canvas = document.getElementById('ringChart');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  const g = ctx.createLinearGradient(0,0,70,70);
  g.addColorStop(0,'#00d4ff'); g.addColorStop(1,'#7c5cff');
  ctx.clearRect(0,0,70,70);
  ctx.beginPath(); ctx.arc(35,35,28,0,Math.PI*2);
  ctx.strokeStyle='rgba(255,255,255,0.08)'; ctx.lineWidth=7; ctx.stroke();
  ctx.beginPath(); ctx.arc(35,35,28,-Math.PI/2,-Math.PI/2+Math.PI*2*0.987);
  ctx.strokeStyle=g; ctx.lineWidth=7; ctx.lineCap='round'; ctx.stroke();
}

let analyticsInited=false, matchInited=false;
function initAnalyticsCharts() {
  if (analyticsInited) return; analyticsInited=true;
  new Chart(document.getElementById('dauChart'),{type:'line',data:{labels:['Mon','Tue','Wed','Thu','Fri','Sat','Sun'],datasets:[{data:[620,780,850,790,920,680,540],borderColor:'#00d4ff',backgroundColor:'rgba(0,212,255,0.1)',borderWidth:2,fill:true,tension:0.4,pointBackgroundColor:'#00d4ff',pointRadius:3}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:{color:'rgba(255,255,255,0.05)'},border:{display:false}},y:{grid:{color:'rgba(255,255,255,0.05)'},border:{display:false}}}}});
  new Chart(document.getElementById('sessFreqChart'),{type:'bar',data:{labels:['Mon','Tue','Wed','Thu','Fri','Sat','Sun'],datasets:[{data:[24,38,42,35,47,28,18],backgroundColor:'rgba(124,92,255,0.7)',borderRadius:5}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:{display:false},border:{display:false}},y:{grid:{color:'rgba(255,255,255,0.05)'},border:{display:false}}}}});
  new Chart(document.getElementById('courseChart'),{type:'bar',data:{labels:['CS','IT','Data Struct','Algorithms','ML','Web Dev','Mobile','DB'],datasets:[{data:[245,180,160,145,130,120,95,85],backgroundColor:['rgba(255,79,216,0.7)','rgba(0,212,255,0.7)','rgba(34,197,94,0.7)','rgba(124,92,255,0.7)','rgba(245,158,11,0.7)','rgba(239,68,68,0.7)','rgba(255,79,216,0.5)','rgba(0,212,255,0.5)'],borderRadius:5}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:{display:false},border:{display:false}},y:{grid:{color:'rgba(255,255,255,0.05)'},border:{display:false}}}}});
}
function initMatchingChart() {
  if (matchInited) return; matchInited=true;
  new Chart(document.getElementById('matchingChart'),{type:'line',data:{labels:['Jan','Feb','Mar','Apr','May'],datasets:[{label:'Accuracy',data:[92,94,96,97.5,98.7],borderColor:'#22c55e',backgroundColor:'rgba(34,197,94,0.1)',borderWidth:2,fill:true,tension:0.4,pointBackgroundColor:'#22c55e',pointRadius:4}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:{color:'rgba(255,255,255,0.05)'},border:{display:false}},y:{grid:{color:'rgba(255,255,255,0.05)'},border:{display:false},min:88,max:100}}}});
}

// ═══ MISC ═══
function saveAIConfig() { closeModal('aiConfigModal'); showToast('AI configuration saved', 'success', '✅'); }

window.addEventListener('load', () => {
  setTimeout(()=>{ initSessionsChart(); initEngagementChart(); initRingChart(); }, 150);
});

// ═══ UNIFIED AUTH INTEGRATION ═══
function doLogout(){
  fetch((window.ECOLLAB_BASE||'')+'/API/auth/logout.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="csrf-token"]')?.content||''},body:'{}'})
    .then(()=>{ window.location.href=(window.ECOLLAB_BASE||'')+'/modules/auth/login.php'; })
    .catch(()=>{ window.location.href=(window.ECOLLAB_BASE||'')+'/modules/auth/login.php'; });
}
function goToChat(){ window.location.href=(window.ECOLLAB_BASE||'')+'/modules/chat/chat.php'; }


// ═══ FACILITATOR REQUESTS ═══
function facReqEsc(value){
  return String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
}
async function loadFacilitatorRequests(status){
  const filter=document.getElementById('facReqStatusFilter');
  status=status || (filter ? filter.value : 'pending');
  const tbody=document.getElementById('facilitatorRequestsTable');
  const count=document.getElementById('facReqCount');
  if(!tbody) return;
  tbody.innerHTML='<tr><td colspan="6" class="dashboard-empty-state">Loading facilitator requests…</td></tr>';
  try{
    const res=await fetch((window.ECOLLAB_BASE||'')+'/API/admin/facilitator-requests.php?status='+encodeURIComponent(status),{credentials:'same-origin'});
    const data=await res.json();
    if(!res.ok || !data.success) throw new Error(data.error||'Unable to load facilitator requests.');
    const rows=Array.isArray(data.requests)?data.requests:[];
    if(count) count.textContent=rows.length+' request'+(rows.length===1?'':'s');
    if(!rows.length){
      tbody.innerHTML='<tr><td colspan="6" class="dashboard-empty-state">No '+facReqEsc(status)+' facilitator requests.</td></tr>';
      return;
    }
    tbody.innerHTML=rows.map(r=>{
      const name=facReqEsc(r.full_name||r.username||('User #'+r.user_id));
      const username=facReqEsc(r.username||'');
      const email=facReqEsc(r.email||'');
      const reason=facReqEsc(r.reason||'No reason provided.');
      const proofName=facReqEsc(r.proof_original_name||'Proof');
      const proofPath=String(r.proof_path||'').replace(/^\/+/, '');
      const proofUrl=proofPath ? (window.ECOLLAB_BASE||'')+'/'+proofPath.split('/').map(encodeURIComponent).join('/') : '';
      const submitted=facReqEsc(r.created_at||'');
      const state=facReqEsc(r.status||'pending');
      const pending=r.status==='pending';
      return '<tr>'+
        '<td><div class="u-name-main">'+name+'</div><div class="u-handle">@'+username+' · '+email+'</div></td>'+
        '<td style="max-width:320px;white-space:normal">'+reason+'</td>'+
        '<td>'+(proofUrl?'<a class="btn-view" href="'+facReqEsc(proofUrl)+'" target="_blank" rel="noopener">View '+proofName+'</a>':'<span style="color:var(--muted)">Unavailable</span>')+'</td>'+
        '<td style="color:var(--muted)">'+submitted+'</td>'+
        '<td><span class="pill '+(state==='approved'?'active':'offline')+'">'+state.toUpperCase()+'</span></td>'+
        '<td>'+(pending?'<div class="action-btns"><button class="btn-approve" onclick="reviewFacilitatorRequest('+Number(r.id)+',\'approve\')">Approve</button><button class="btn-deny" onclick="reviewFacilitatorRequest('+Number(r.id)+',\'reject\')">Reject</button></div>':(r.review_note?'<span title="'+facReqEsc(r.review_note)+'">Reviewed</span>':'Reviewed'))+'</td>'+
      '</tr>';
    }).join('');
  }catch(err){
    if(count) count.textContent='Load failed';
    tbody.innerHTML='<tr><td colspan="6" class="dashboard-empty-state">'+facReqEsc(err.message||'Unable to load facilitator requests.')+'</td></tr>';
  }
}
async function reviewFacilitatorRequest(requestId, decision){
  let note='';
  if(decision==='reject'){
    note=window.prompt('Reason for rejecting this facilitator request:','') ?? '';
    if(note===null) return;
  }else if(!window.confirm('Approve this request and promote the student to Facilitator?')){
    return;
  }
  try{
    const res=await fetch((window.ECOLLAB_BASE||'')+'/API/admin/facilitator-requests.php',{
      method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-CSRF-Token':ADMIN_DATA.csrfToken||''},
      body:JSON.stringify({request_id:Number(requestId),decision:decision,note:note})
    });
    const data=await res.json();
    if(!res.ok || !data.success) throw new Error(data.error||'Unable to review request.');
    showToast(decision==='approve'?'Student promoted to Facilitator.':'Facilitator request rejected.','success',decision==='approve'?'✅':'🛡️');
    await loadFacilitatorRequests();
  }catch(err){
    showToast(err.message||'Unable to review request.','error','⚠️');
  }
}

function applyUserFilters(){
  const q=(document.querySelector('#page-users .filter-search input')?.value||'').toLowerCase();
  const course=(document.getElementById('userCourseFilter')?.value||'').toLowerCase();
  const role=(document.getElementById('userRoleFilter')?.value||'').toLowerCase();
  const status=(document.getElementById('userStatusFilter')?.value||'').toLowerCase();
  document.querySelectorAll('#usersTable tr').forEach(row=>{
    if(row.querySelector('.dashboard-empty-state')) return;
    const text=row.textContent.toLowerCase();
    const cells=row.querySelectorAll('td');
    const rowRole=(cells[1]?.textContent||'').trim().toLowerCase();
    const rowCourse=(cells[2]?.textContent||'').trim().toLowerCase();
    const rowStatus=(cells[3]?.textContent||'').trim().toLowerCase();
    row.style.display=(!q||text.includes(q))&&(!course||rowCourse.includes(course))&&(!role||rowRole===role)&&(!status||rowStatus.includes(status))?'':'none';
  });
}
function openChannelPermissions(name){
  showToast('Permission editor opened for '+name+'.','info','🔐');
  const modal=document.getElementById('editPermsModal');
  const label=document.getElementById('epRole');
  if(label) label.textContent=name;
  if(modal) openModal('editPermsModal');
}
function setAnalyticsRange(days){
  showToast('Analytics range set to last '+days+' days.','info','📊');
  initAnalyticsCharts();
}

async function recommendGroup(){
 const topic=document.getElementById('groupTopic')?.value.trim()||'',task=document.getElementById('groupTask')?.value.trim()||'',group_size=Number(document.getElementById('groupSize')?.value||4);
 if(!topic&&!task){showToast('Enter a topic or task first.','error','⚠️');return;}
 const btn=document.getElementById('recommendGroupBtn'),out=document.getElementById('groupRecommendationResults'),status=document.getElementById('aiModelStatus');btn.disabled=true;btn.textContent='Thinking…';status.textContent='Calling VPS local LLM…';out.innerHTML='<div class="dashboard-empty-state">Analyzing real user profiles…</div>';
 try{const r=await fetch((window.ECOLLAB_BASE||'')+'/API/admin/group-recommendations.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':ADMIN_DATA.csrfToken},body:JSON.stringify({topic,task,group_size})}),d=await r.json();if(!r.ok||!d.success)throw new Error(d.error||'Recommendation failed');status.textContent=(d.model||'Local LLM')+' · '+d.candidate_count+' real candidates';out.innerHTML=d.groups.length?d.groups.map((g,i)=>'<div class="report-item"><div class="ri-icon">✨</div><div class="ri-body"><div class="ri-title">Recommended Group '+(i+1)+'</div><div class="ri-meta">'+escHtml(g.reason||'Balanced profile match')+'</div><div style="margin-top:8px">'+g.members.map(m=>'<span class="perm-tag granted" title="'+escHtml(m.fit||'')+'">'+escHtml(m.name||m.username)+' · '+escHtml(m.role)+'</span>').join(' ')+'</div></div></div>').join(''):'<div class="dashboard-empty-state">No valid group could be formed from current profiles.</div>';}catch(e){status.textContent='Local LLM unavailable';out.innerHTML='<div class="dashboard-empty-state">'+escHtml(e.message)+'</div>';}finally{btn.disabled=false;btn.textContent='✨ Recommend Group';}
}
async function loadReportLogs(){
 const box=document.getElementById('modLogContainer');if(!box)return;box.innerHTML='<div class="dashboard-empty-state">Loading report logs…</div>';
 try{const r=await fetch((window.ECOLLAB_BASE||'')+'/API/admin/dashboard-data.php?action=get_reports&status=all',{credentials:'same-origin'}),d=await r.json();if(!r.ok||!d.success)throw new Error(d.error||'Failed');box.innerHTML=(d.reports||[]).length?d.reports.map(x=>'<div class="log-entry"><div class="log-type-badge '+(x.status==='resolved'?'':'warn')+'">'+escHtml(x.status||'pending').toUpperCase()+'</div><div class="le-info"><div class="le-main">'+escHtml(x.reason||'Report')+'</div><div class="le-sub">'+escHtml(x.reporter_username||'Unknown')+' · '+escHtml(x.server_name||'Unknown server')+'</div></div><div class="le-time">'+escHtml(x.created_at||'')+'</div></div>').join(''):'<div class="dashboard-empty-state">No report logs recorded.</div>';}catch(e){box.innerHTML='<div class="dashboard-empty-state">Unable to load report logs.</div>';}
}
