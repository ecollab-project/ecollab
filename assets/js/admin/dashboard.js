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
  document.getElementById('bcText').textContent = bc[id] || id;
  closeAllDropdowns();
  if (id==='analytics') initAnalyticsCharts();
  if (id==='aimatching') initMatchingChart();
  if (id==='reports') loadReports();
}

// ═══ MODALS ═══
let currentCtxUser = '';
function openModal(id, param) {
  closeAllDropdowns();
  // Set params
  if (param) {
    if (id==='banModal') { document.getElementById('banTarget').textContent = param; }
    if (id==='kickModal') { document.getElementById('kickTarget').textContent = param; }
    if (id==='muteModal') { document.getElementById('muteTarget').textContent = param; }
    if (id==='warnModal') { document.getElementById('warnTarget').textContent = param; }
    if (id==='editRoleModal') { const t=document.getElementById('editRoleTarget'); if(t) t.value=param; }
    if (id==='editPermsModal') { const t=document.getElementById('editPermsRole'); if(t) t.textContent=param; }
    if (id==='deleteRoleModal') { document.getElementById('deleteRoleTarget').textContent=param; }
    if (id==='serverDetailModal') { document.getElementById('serverDetailTitle').textContent='Server — '+param; document.getElementById('serverDetailName').textContent=param; }
    if (id==='serverPermsModal') { }
    if (id==='deleteServerModal') { document.getElementById('deleteServerTarget').textContent=param; }
    if (id==='editChannelModal') { document.getElementById('editChannelName').value=param; }
    if (id==='modActionDetailModal') {
      const types={ban:'BAN — Permanent',kick:'KICK — Session Removal',warn:'WARNING — Formal Notice',mute:'MUTE — 1 Hour'};
      document.getElementById('modDetailTitle').textContent='Moderation — '+param.toUpperCase();
      document.getElementById('modDetailType').textContent=types[param]||param;
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
  const d = document.getElementById('notifDrop');
  const isOpen = d.classList.contains('show');
  closeAllDropdowns();
  if (!isOpen) d.classList.add('show');
}
function toggleProfileDrop() {
  const d = document.getElementById('profileDrop');
  const isOpen = d.classList.contains('show');
  closeAllDropdowns();
  if (!isOpen) d.classList.add('show');
}
function closeAllDropdowns() {
  document.getElementById('notifDrop').classList.remove('show');
  document.getElementById('profileDrop').classList.remove('show');
  hideSearchDrop();
}
document.addEventListener('click', e => {
  if (!e.target.closest('#notifBtn')) document.getElementById('notifDrop').classList.remove('show');
  if (!e.target.closest('#profileChip')) document.getElementById('profileDrop').classList.remove('show');
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
  if (document.getElementById('globalSearch').value.length > 0)
    document.getElementById('searchDrop').classList.add('show');
}
function hideSearchDrop() {
  document.getElementById('searchDrop').classList.remove('show');
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
  const rows = document.querySelectorAll('#usersTable tr');
  rows.forEach(r => {
    r.style.display = r.textContent.toLowerCase().includes(q.toLowerCase()) ? '' : 'none';
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
  showToast('Exporting ' + type + ' data...', 'info', '⬇️');
  setTimeout(()=>showToast('Export complete — download started', 'success', '✅'), 1000);
}

// ═══ MODERATION ═══
function issueModerationAction() { closeModal('issueBanModal'); showToast('Moderation action issued', 'success', '🔨'); }

// ═══ SYSTEM HEALTH ═══
function refreshHealth() {
  showToast('Refreshing metrics...', 'info', '🔄');
  const cpu = Math.floor(Math.random()*60+10);
  const mem = Math.floor(Math.random()*50+30);
  document.getElementById('cpuVal').textContent=cpu+'%';
  document.getElementById('cpuBar').style.width=cpu+'%';
  document.getElementById('memVal').textContent=mem+'%';
  document.getElementById('memBar').style.width=mem+'%';
  setTimeout(()=>showToast('Metrics updated', 'success', '✅'), 800);
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
  if (!ctx || ctx._c) return;
  ctx._c = new Chart(ctx, { type:'line', data:{ labels:['May 13','May 14','May 15','May 16','May 17','May 18','May 19'], datasets:[{ data:[40,55,48,70,85,95,110], borderColor:'#ff4fd8', backgroundColor:'rgba(255,79,216,0.1)', borderWidth:2, fill:true, tension:0.4, pointBackgroundColor:'#ff4fd8', pointRadius:3 }] }, options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{ x:{grid:{color:'rgba(255,255,255,0.05)'},border:{display:false}}, y:{grid:{color:'rgba(255,255,255,0.05)'},border:{display:false},min:0,max:120,ticks:{stepSize:20}} } } });
}
function initEngagementChart() {
  const ctx = document.getElementById('engagementChart');
  if (!ctx || ctx._c) return;
  ctx._c = new Chart(ctx, { type:'bar', data:{ labels:['#ai-study','#proj-help','#general','#resources','#thesis','#random'], datasets:[{ label:'Messages', data:[220,180,200,140,160,190], backgroundColor:'rgba(255,79,216,0.7)', borderRadius:4 },{ label:'Active Users', data:[100,120,130,90,110,80], backgroundColor:'rgba(59,130,246,0.7)', borderRadius:4 }] }, options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{ x:{grid:{display:false},border:{display:false}}, y:{grid:{color:'rgba(255,255,255,0.05)'},border:{display:false},min:0,max:250} } } });
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
