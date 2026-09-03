// ══════════════════════════════════════════════════════════════
// Whiteboard — Real-time collaborative via WebSocket
// Supports: pen, highlight, arrow, eraser, sticky, text, undo, clear
// Live cursors, member list, chat, activity log
// ══════════════════════════════════════════════════════════════

const wbState = {
  tool: 'cursor', color: '#a855f7', strokeSize: 2,
  drawing: false, paths: [], currentPath: null,
  zoom: 1, open: false,
  // Collab
  channelId: null,
  sessionId: null,
  isOwner: false,
  boardName: '',
  members: [],      // [{user_id, username, color, grad, initial, isYou}]
  chatMessages: [],
  // Remote cursors  { user_id => { x, y, color, initial, username, el } }
  remoteCursors: {},
  // Remote in-progress strokes { path_id => { points[], color, size, tool } }
  remoteStrokes: {},
  // Sticky/text objects { obj_id => DOMElement }
  objects: {},
  // Next local obj ids
  _objSeq: 0,
  // Pending path_id sequence
  _pathSeq: 0,
  // Cursor throttle
  _lastCursorSend: 0,
  locked: false,
  dirty: false,
};

// ── Avatar colour pool ──────────────────────────────────────
const WB_COLORS = [
  ['#3b82f6','#1d4ed8'], ['#ec4899','#be185d'],
  ['#14b8a6','#0f766e'], ['#6366f1','#4338ca'],
  ['#f59e0b','#b45309'], ['#22c55e','#15803d'],
  ['#a855f7','#7c3aed'], ['#ef4444','#b91c1c'],
];
function wbPickColor(idx) { return WB_COLORS[idx % WB_COLORS.length]; }

// ── Current user ─────────────────────────────────────────
function wbGetCurrentUser() {
  const u = window.__USER__ || {};
  return { id: u.id || 0, name: u.username || u.name || 'You', role: u.role || '' };
}

// ── WebSocket access (shared with main chat.js) ───────────
function wbGetWs() {
  // Reuse the global WS created by chat.js
  return window.__ws || window.chatSocket || null;
}

// ══════════════════════════════════════════════════════════
//  WebSocket message handler — called by chat.js dispatcher
// ══════════════════════════════════════════════════════════
function wbHandleWsMessage(msg) {
  switch (msg.type) {

    case 'wb_joined':
      wbOnJoined(msg);
      break;

    case 'wb_peer_joined':
      wbOnPeerJoined(msg.peer);
      break;

    case 'wb_peer_left':
      wbOnPeerLeft(msg.user_id, msg.username);
      break;

    case 'wb_op':
      wbApplyRemoteOp(msg);
      break;

    case 'wb_cursor':
      wbUpdateRemoteCursor(msg);
      break;

    case 'wb_state':
      wbApplyFullState(msg.state_json);
      break;

    case 'wb_state_saved':
      // Acknowledged — nothing to do
      break;
    case 'wb_lock_changed':
      wbState.locked = !!msg.locked;
      wbApplyLockState();
      break;
    case 'wb_version_saved':
      if (msg.user_id !== wbGetCurrentUser().id) wbLoadVersions();
      break;
    case 'wb_state_reverted':
      wbApplyFullState(msg.state_json);
      wbState.dirty = false;
      wbLoadVersions();
      break;
    case 'wb_locked':
      wbState.locked = true;
      wbApplyLockState();
      break;
  }
}

// Register with global dispatcher if chat.js exports one
if (window.__wsMsgHandlers) {
  window.__wsMsgHandlers['wb_joined']     = wbHandleWsMessage;
  window.__wsMsgHandlers['wb_peer_joined']= wbHandleWsMessage;
  window.__wsMsgHandlers['wb_peer_left']  = wbHandleWsMessage;
  window.__wsMsgHandlers['wb_op']         = wbHandleWsMessage;
  window.__wsMsgHandlers['wb_cursor']     = wbHandleWsMessage;
  window.__wsMsgHandlers['wb_state']      = wbHandleWsMessage;
  window.__wsMsgHandlers['wb_state_saved']= wbHandleWsMessage;
  window.__wsMsgHandlers['wb_locked'] = wbHandleWsMessage;
  window.__wsMsgHandlers['wb_lock_changed'] = wbHandleWsMessage;
  window.__wsMsgHandlers['wb_version_saved'] = wbHandleWsMessage;
  window.__wsMsgHandlers['wb_state_reverted'] = wbHandleWsMessage;
}

// ── Send helper ───────────────────────────────────────────
function wbSend(payload) {
  if (payload.op && payload.op !== 'cursor') wbState.dirty = true;
  const ws = wbGetWs();
  if (!ws || ws.readyState !== WebSocket.OPEN) return;
  ws.send(JSON.stringify(payload));
}

// ══════════════════════════════════════════════════════════
//  Open / close
// ══════════════════════════════════════════════════════════
function openWhiteboard(boardName, sessionOwnerId, channelId) {
  // Chat's legacy launcher passes (boardName, channelId).
  if (channelId === undefined && Number.isInteger(Number(sessionOwnerId))) {
    channelId = Number(sessionOwnerId);
    sessionOwnerId = null;
  }
  const targetChannelId = channelId || window.ECOLLAB?.currentChannelId || window.__currentChannelId;
  if (targetChannelId && !window.ECOLLAB?.whiteboardStandalone) {
    window.location.href = `${window.ECOLLAB?.baseUrl || ''}/modules/whiteboard/index.php?channel_id=${encodeURIComponent(targetChannelId)}`;
    return;
  }
  const overlay = document.getElementById('wbOverlay');
  if (!overlay) return;

  wbState.boardName  = boardName || 'Whiteboard Session';
  wbState.sessionId  = sessionOwnerId || null;
  wbState.channelId  = channelId || window.__currentChannelId || null;
  wbState.dirty = false;

  const me           = wbGetCurrentUser();
  wbState.isOwner    = !sessionOwnerId || (sessionOwnerId === me.id);

  document.getElementById('wbBoardName').textContent = wbState.boardName;
  overlay.classList.add('wb-visible');
  wbState.open = true;

  setTimeout(() => {
    wbApi('state').then(data => { wbState.locked = !!data.whiteboard.locked; wbState.isOwner = !!data.whiteboard.is_host; wbApplyFullState(data.whiteboard.state_json); wbApplyLockState(); }).catch(() => {});
    wbInitCanvas();
    wbAnimateCursors();
    wbStartAutoSave();

    // Seed self into member list
    if (!wbState.members.find(m => m.user_id === me.id)) {
      const [c1, c2] = wbPickColor(0);
      wbState.members.push({
        user_id: me.id, username: me.name, role: me.role,
        color: c1, grad: `linear-gradient(135deg,${c1},${c2})`,
        initial: me.name.charAt(0).toUpperCase(),
        isYou: true, isOwner: wbState.isOwner,
      });
    }
    wbUpdateMemberList();
    wbRenderChat();
    wbLogActivity('🟢', `<strong>${me.name}</strong> joined the whiteboard`, 'rgba(34,197,94,.15)');

    // Join the whiteboard room over WebSocket
    if (wbState.channelId) {
      wbSend({ type: 'wb_join', channel_id: wbState.channelId });
    }
  }, 80);

  showToast('📋 ' + wbState.boardName + ' opened', 'info');
}

function wbRequestClose() {
  if (wbState.isOwner && wbState.open) {
    const modal = document.getElementById('wbLeaveModal');
    if (modal) { modal.style.display = 'flex'; return; }
  }
  closeWhiteboard();
}

function wbSaveAndEnd() {
  wbDismissLeaveModal();
  wbExportPdfToChat();
  _wbDoClose(true);
}

function wbEndWithoutSaving() {
  wbDismissLeaveModal();
  const me = wbGetCurrentUser();
  wbLogActivity('🔴', `<strong>${me.name}</strong> ended the session`, 'rgba(239,68,68,.15)');
  _wbDoClose(false);
}

function wbDismissLeaveModal() {
  const modal = document.getElementById('wbLeaveModal');
  if (modal) modal.style.display = 'none';
}

function _wbDoClose(saveState = true) {
  // Send final state snapshot then leave the room
  if (wbState.channelId) {
    const canvas = document.getElementById('wbCanvas');
    const stateJson = saveState ? JSON.stringify({
      paths: wbState.paths,
      savedAt: new Date().toISOString(),
    }) : '';

    wbSend({ type: 'wb_leave', channel_id: wbState.channelId, state_json: stateJson });
  }

  // Remove remote cursors
  Object.values(wbState.remoteCursors).forEach(c => c.el && c.el.remove());
  wbState.remoteCursors = {};
  wbState.remoteStrokes = {};
  wbState.members = [];
  wbState.paths = [];
  wbState.objects = {};
  wbState.channelId = null;

  document.getElementById('wbOverlay').classList.remove('wb-visible');
  wbState.open = false;
}

function wbApi(action, body = {}, method = 'GET') {
  const base = window.ECOLLAB?.baseUrl || '';
  const url = `${base}/API/chat/whiteboard-sync.php?channel_id=${encodeURIComponent(wbState.channelId)}${action === 'versions' || action === 'download' ? `&action=${action}` : ''}`;
  const opts = { method, credentials: 'same-origin', headers: { 'X-CSRF-Token': window.ECOLLAB?.csrfToken || '', 'Content-Type': 'application/json' } };
  if (method !== 'GET') opts.body = JSON.stringify({ ...body, action });
  return fetch(url, opts).then(async response => { const data = await response.json(); if (!response.ok) throw new Error(data.error || 'Whiteboard request failed'); return data; });
}

async function wbSaveVersion() {
  if (!wbState.channelId) return;
  const title = wbState.boardName || 'Whiteboard Session';
  const stateJson = JSON.stringify({ paths: wbState.paths, savedAt: new Date().toISOString() });
  try { await wbApi('save_version', { title, state_json: stateJson }, 'POST'); wbState.dirty = false; wbLoadVersions(); const label = document.getElementById('wbSaveLabel'); if (label) label.textContent = 'Saved just now'; }
  catch (error) { showToast(`Could not save version: ${error.message}`, 'error'); }
}
window.wbSaveVersion = wbSaveVersion;

async function wbLoadVersions() {
  if (!wbState.channelId) return;
  try { const data = await wbApi('versions'); const list = document.getElementById('wbVersionList'); if (list) list.innerHTML = data.versions.length ? data.versions.map(v => `<div class="wb-version-row"><span>Version ${v.version_no}</span><a href="${window.ECOLLAB.baseUrl}/API/chat/whiteboard-sync.php?channel_id=${wbState.channelId}&action=download&version_id=${v.id}">Download</a><button class="wb-page-btn" type="button" onclick="wbRestoreVersion(${v.id})">Revert</button></div>`).join('') : '<div class="wb-version-row">No saved versions</div>'; }
  catch (error) { const list = document.getElementById('wbVersionList'); if (list) list.textContent = error.message; }
}
window.wbLoadVersions = wbLoadVersions;
async function wbRestoreVersion(versionId) {
  if (!confirm('Revert the current whiteboard to this saved version?')) return;
  try {
    const data = await wbApi('restore_version', { version_id: versionId }, 'POST');
    wbApplyFullState(data.whiteboard.state_json);
    wbState.dirty = false;
    wbLoadVersions();
    showToast('Whiteboard reverted to saved version', 'success');
  } catch (error) {
    showToast(`Could not revert version: ${error.message}`, 'error');
  }
}
window.wbRestoreVersion = wbRestoreVersion;
function wbToggleVersions() { document.getElementById('wbVersionPanel')?.classList.toggle('open'); wbLoadVersions(); }
window.wbToggleVersions = wbToggleVersions;
async function wbToggleLock() {
  if (!wbState.isOwner) return;
  try { const data = await wbApi('lock', { locked: !wbState.locked }, 'POST'); wbState.locked = data.whiteboard.locked; wbApplyLockState(); }
  catch (error) { showToast(`Could not change lock: ${error.message}`, 'error'); }
}
window.wbToggleLock = wbToggleLock;
function wbApplyLockState() { document.body.classList.toggle('wb-locked', wbState.locked); const button=document.getElementById('wbLockButton'); if(button){button.hidden=!wbState.isOwner;button.textContent=wbState.locked?'Unlock':'Lock';} const label=document.getElementById('wbLockLabel'); if(label) label.textContent=wbState.locked?'Locked by host':''; }
window.wbApplyLockState = wbApplyLockState;

window.addEventListener('beforeunload', event => { if (wbState.open && wbState.dirty) { event.preventDefault(); event.returnValue = ''; } });

function closeWhiteboard() {
  if (wbState.isOwner && wbState.open) { wbRequestClose(); return; }
  _wbDoClose();
}

function joinSession(id) { openWhiteboard('Whiteboard Session'); }
function openWhiteboardFromVoice() {
  closeModal('vcInviteWhiteboardModal');
  openWhiteboard('Study Lounge Whiteboard', null, window.__currentChannelId);
}

// ══════════════════════════════════════════════════════════
//  WebSocket event handlers
// ══════════════════════════════════════════════════════════

function wbOnJoined(msg) {
  // Apply persisted state from server
  if (msg.state_json) {
    wbApplyFullState(msg.state_json);
  }
  // Replay buffered ops (from after last snapshot)
  if (msg.pending_ops && msg.pending_ops.length) {
    msg.pending_ops.forEach(op => wbApplyRemoteOp({ ...op, type: 'wb_op' }));
  }

  // Merge peers into member list
  const me = wbGetCurrentUser();
  (msg.peers || []).forEach(peer => {
    if (!wbState.members.find(m => m.user_id === peer.user_id)) {
      wbState.members.push({ ...peer, isYou: false, isOwner: false });
    }
  });
  wbUpdateMemberList();

  if (msg.peers && msg.peers.length > 0) {
    wbLogActivity('👥', `<strong>${msg.peers.length}</strong> other${msg.peers.length > 1 ? 's' : ''} already on this board`, 'rgba(99,102,241,.15)');
  }
}

function wbOnPeerJoined(peer) {
  if (!wbState.members.find(m => m.user_id === peer.user_id)) {
    wbState.members.push({ ...peer, isYou: false, isOwner: false });
    wbUpdateMemberList();
    wbLogActivity('🟢', `<strong>${peer.username}</strong> joined the whiteboard`, 'rgba(34,197,94,.15)');
    showToast(`👋 ${peer.username} joined`, 'info');
  }
}

function wbOnPeerLeft(userId, username) {
  wbState.members = wbState.members.filter(m => m.user_id !== userId);
  wbUpdateMemberList();

  // Remove their cursor
  if (wbState.remoteCursors[userId]) {
    wbState.remoteCursors[userId].el && wbState.remoteCursors[userId].el.remove();
    delete wbState.remoteCursors[userId];
  }
  wbLogActivity('🔴', `<strong>${username}</strong> left the whiteboard`, 'rgba(239,68,68,.15)');
}

// ══════════════════════════════════════════════════════════
//  Remote op application
// ══════════════════════════════════════════════════════════

function wbApplyRemoteOp(msg) {
  const canvas = document.getElementById('wbCanvas');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  const op  = msg.op;
  const uid = msg.user_id;

  switch (op) {
    case 'stroke_start': {
      wbState.remoteStrokes[msg.path_id] = {
        tool: msg.tool, color: msg.color, size: msg.size,
        points: [{ x: msg.x, y: msg.y }],
      };
      break;
    }
    case 'stroke_point': {
      const s = wbState.remoteStrokes[msg.path_id];
      if (!s) break;
      const prev = s.points[s.points.length - 1];
      s.points.push({ x: msg.x, y: msg.y });
      ctx.strokeStyle = s.color;
      ctx.lineWidth   = s.size;
      ctx.lineCap     = 'round';
      ctx.lineJoin    = 'round';
      ctx.beginPath();
      ctx.moveTo(prev.x, prev.y);
      ctx.lineTo(msg.x, msg.y);
      ctx.stroke();
      break;
    }
    case 'stroke_end': {
      const s = wbState.remoteStrokes[msg.path_id];
      if (!s) break;
      // Commit to path list for redraws
      wbState.paths.push({
        id: msg.path_id, tool: s.tool, color: s.color,
        size: s.size, points: s.points, owner: uid,
      });
      delete wbState.remoteStrokes[msg.path_id];
      break;
    }
    case 'erase': {
      ctx.clearRect(msg.x - msg.radius, msg.y - msg.radius, msg.radius * 2, msg.radius * 2);
      // Remove any paths whose points fall in erased area
      wbState.paths = wbState.paths.filter(p => {
        return !p.points.some(pt =>
          Math.abs(pt.x - msg.x) < msg.radius && Math.abs(pt.y - msg.y) < msg.radius
        );
      });
      break;
    }
    case 'sticky_add': {
      const el = _wbCreateStickyEl(msg.obj_id, msg.x, msg.y, msg.color, msg.text, false);
      document.getElementById('wbObjects').appendChild(el);
      wbMakeDraggable(el, msg.obj_id);
      wbState.objects[msg.obj_id] = el;
      break;
    }
    case 'sticky_move': {
      const el = wbState.objects[msg.obj_id];
      if (el) { el.style.left = msg.x + 'px'; el.style.top = msg.y + 'px'; }
      break;
    }
    case 'sticky_text': {
      const el = wbState.objects[msg.obj_id];
      if (el) {
        const ed = el.querySelector('[contenteditable]');
        if (ed) ed.textContent = msg.text;
      }
      break;
    }
    case 'text_add': {
      const el = _wbCreateTextEl(msg.obj_id, msg.x, msg.y, msg.color, msg.text, false);
      document.getElementById('wbObjects').appendChild(el);
      wbMakeDraggable(el, msg.obj_id);
      wbState.objects[msg.obj_id] = el;
      break;
    }
    case 'text_move': {
      const el = wbState.objects[msg.obj_id];
      if (el) { el.style.left = msg.x + 'px'; el.style.top = msg.y + 'px'; }
      break;
    }
    case 'text_edit': {
      const el = wbState.objects[msg.obj_id];
      if (el) el.textContent = msg.text;
      break;
    }
    case 'undo': {
      // Remove the path authored by that user
      const idx = wbState.paths.map(p => p.id).lastIndexOf(msg.path_id);
      if (idx !== -1) {
        wbState.paths.splice(idx, 1);
        wbRedraw(ctx, canvas);
      }
      break;
    }
    case 'clear': {
      wbState.paths = [];
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      wbLogActivity('🗑️', `<strong>${msg.username}</strong> cleared the board`, 'rgba(239,68,68,.1)');
      break;
    }
  }
}

// ══════════════════════════════════════════════════════════
//  Remote cursor rendering
// ══════════════════════════════════════════════════════════

function wbUpdateRemoteCursor(msg) {
  if (!wbState.open) return;
  const wrap = document.getElementById('wbCanvasWrap');
  if (!wrap) return;

  const uid = msg.user_id;
  if (!wbState.remoteCursors[uid]) {
    // Create cursor element
    const el = document.createElement('div');
    el.className = 'wb-remote-cursor';
    el.style.cssText = `
      position:absolute; pointer-events:none; z-index:50;
      display:flex; align-items:flex-end; gap:4px; transition:left .05s, top .05s;
    `;
    el.innerHTML = `
      <svg width="18" height="18" viewBox="0 0 24 24" style="filter:drop-shadow(0 1px 3px rgba(0,0,0,.5))">
        <path d="M4 0 L20 12 L12 14 L8 22 Z" fill="${msg.color}" stroke="white" stroke-width="1.5"/>
      </svg>
      <div class="wb-cursor-label" style="
        background:${msg.color}; color:#fff; font-size:10px; font-weight:700;
        padding:2px 6px; border-radius:8px; white-space:nowrap;
        box-shadow:0 1px 6px rgba(0,0,0,.35); line-height:1.4;
      ">${msg.username}</div>
    `;
    wrap.appendChild(el);
    wbState.remoteCursors[uid] = { el, color: msg.color, username: msg.username };
  }

  const c = wbState.remoteCursors[uid];
  c.el.style.left = (msg.x - 4) + 'px';
  c.el.style.top  = (msg.y - 4) + 'px';

  // Fade out after 4 s of inactivity
  clearTimeout(c._timeout);
  c.el.style.opacity = '1';
  c._timeout = setTimeout(() => { c.el.style.opacity = '0'; }, 4000);
}

// ══════════════════════════════════════════════════════════
//  Canvas init & drawing
// ══════════════════════════════════════════════════════════
function wbInitCanvas() {
  const canvas = document.getElementById('wbCanvas');
  const wrap   = document.getElementById('wbCanvasWrap');
  canvas.width  = wrap.clientWidth;
  canvas.height = wrap.clientHeight;
  const ctx = canvas.getContext('2d');
  ctx.lineCap = 'round'; ctx.lineJoin = 'round';
  wbRedraw(ctx, canvas);

  canvas.onmousedown  = e => wbDown(e, ctx, canvas);
  canvas.onmousemove  = e => { wbMove(e, ctx, canvas); wbSendCursor(e, canvas); };
  canvas.onmouseup    = e => wbUp(e, ctx, canvas);
  canvas.onmouseleave = e => wbUp(e, ctx, canvas);
  canvas.onwheel = e => { e.preventDefault(); wbWheelZoom(e); };

  canvas.addEventListener('touchstart',  e => { e.preventDefault(); wbDown(wbTouchEvt(e), ctx, canvas); }, {passive:false});
  canvas.addEventListener('touchmove',   e => { e.preventDefault(); wbMove(wbTouchEvt(e), ctx, canvas); wbSendCursor(wbTouchEvt(e), canvas); }, {passive:false});
  canvas.addEventListener('touchend',    e => { wbUp(wbTouchEvt(e), ctx, canvas); });

  if (!wbState._resizeObs) {
    wbState._resizeObs = new ResizeObserver(() => {
      if (!wbState.open) return;
      const saved = [...wbState.paths];
      canvas.width  = wrap.clientWidth;
      canvas.height = wrap.clientHeight;
      wbState.paths = saved;
      wbRedraw(canvas.getContext('2d'), canvas);
    });
    wbState._resizeObs.observe(wrap);
  }

  document.querySelectorAll('#wbObjects .wb-draggable').forEach(el => {
    const objId = el.dataset.objId;
    wbMakeDraggable(el, objId);
  });
}

function wbTouchEvt(e) {
  const t = e.touches[0] || e.changedTouches[0];
  return { clientX: t.clientX, clientY: t.clientY };
}

// ── Cursor broadcast (throttled to 30fps) ─────────────────
function wbSendCursor(e, canvas) {
  if (!wbState.channelId) return;
  const now = Date.now();
  if (now - wbState._lastCursorSend < 33) return;
  wbState._lastCursorSend = now;
  const r = canvas.getBoundingClientRect();
  wbSend({
    type:       'wb_cursor',
    channel_id: wbState.channelId,
    x: e.clientX - r.left,
    y: e.clientY - r.top,
  });
}

// ── Draggable objects ─────────────────────────────────────
function wbMakeDraggable(el, objId) {
  let dragging = false, ox = 0, oy = 0;

  function startDrag(cx, cy) {
    if (wbState.locked && !wbState.isOwner) return false;
    if (wbState.tool !== 'cursor') return false;
    dragging = true;
    const r = el.getBoundingClientRect();
    ox = cx - r.left; oy = cy - r.top;
    el.style.zIndex = 999;
    return true;
  }
  function moveDrag(cx, cy) {
    if (!dragging) return;
    const wr = document.getElementById('wbCanvasWrap').getBoundingClientRect();
    const nx = Math.max(0, cx - wr.left - ox);
    const ny = Math.max(0, cy - wr.top  - oy);
    el.style.left = nx + 'px';
    el.style.top  = ny + 'px';
  }
  function endDrag(cx, cy) {
    if (!dragging) return;
    dragging = false;
    el.style.zIndex = '';
    if (objId && wbState.channelId) {
      const wr = document.getElementById('wbCanvasWrap').getBoundingClientRect();
      const opType = el.classList.contains('wb-sticky') ? 'sticky_move' : 'text_move';
      wbSend({
        type: 'wb_op', channel_id: wbState.channelId,
        op: opType, obj_id: objId,
        x: parseFloat(el.style.left), y: parseFloat(el.style.top),
      });
    }
  }

  el.addEventListener('mousedown', e => { if (startDrag(e.clientX, e.clientY)) e.stopPropagation(); });
  document.addEventListener('mousemove', e => moveDrag(e.clientX, e.clientY));
  document.addEventListener('mouseup',   e => endDrag(e.clientX, e.clientY));

  el.addEventListener('touchstart', e => { const t = e.touches[0]; if (startDrag(t.clientX, t.clientY)) e.stopPropagation(); }, {passive:true});
  el.addEventListener('touchmove',  e => { const t = e.touches[0]; moveDrag(t.clientX, t.clientY); }, {passive:true});
  el.addEventListener('touchend',   e => { const t = e.changedTouches[0]; endDrag(t.clientX, t.clientY); });
}

// ── Drawing ───────────────────────────────────────────────
function wbPos(e, canvas) {
  const r = canvas.getBoundingClientRect();
  return { x: e.clientX - r.left, y: e.clientY - r.top };
}

function wbDown(e, ctx, canvas) {
  if (wbState.locked && !wbState.isOwner) return;
  wbState.dirty = true;
  const pos = wbPos(e, canvas);
  if (wbState.tool === 'pen' || wbState.tool === 'highlight' || wbState.tool === 'arrow') {
    const pathId = `${wbGetCurrentUser().id}_${Date.now()}_${wbState._pathSeq++}`;
    const color = wbState.tool === 'highlight'
      ? wbState.color + '55'
      : wbState.color;
    const size = wbState.tool === 'highlight'
      ? wbState.strokeSize * 4
      : wbState.strokeSize;

    wbState.drawing = true;
    wbState.currentPath = { id: pathId, tool: wbState.tool, color, size, points: [pos] };

    ctx.beginPath(); ctx.moveTo(pos.x, pos.y);

    if (wbState.channelId) {
      wbSend({
        type: 'wb_op', channel_id: wbState.channelId,
        op: 'stroke_start', path_id: pathId,
        tool: wbState.tool, color, size,
        x: pos.x, y: pos.y,
      });
    }

  } else if (wbState.tool === 'eraser') {
    wbState.drawing = true;

  } else if (wbState.tool === 'sticky') {
    wbAddSticky(pos.x, pos.y);
    wbPickToolByName('cursor');

  } else if (wbState.tool === 'text') {
    wbAddText(pos.x, pos.y);
    wbPickToolByName('cursor');
  }
}

function wbMove(e, ctx, canvas) {
  if (!wbState.drawing) return;
  const pos = wbPos(e, canvas);

  if (wbState.tool === 'pen' || wbState.tool === 'highlight') {
    wbState.currentPath.points.push(pos);
    ctx.strokeStyle = wbState.currentPath.color;
    ctx.lineWidth   = wbState.currentPath.size;
    ctx.lineTo(pos.x, pos.y); ctx.stroke();

    if (wbState.channelId) {
      wbSend({
        type: 'wb_op', channel_id: wbState.channelId,
        op: 'stroke_point', path_id: wbState.currentPath.id,
        x: pos.x, y: pos.y,
      });
    }

  } else if (wbState.tool === 'eraser') {
    const r = 14;
    ctx.clearRect(pos.x - r, pos.y - r, r * 2, r * 2);
    if (wbState.channelId) {
      wbSend({
        type: 'wb_op', channel_id: wbState.channelId,
        op: 'erase', x: pos.x, y: pos.y, radius: r,
      });
    }

  } else if (wbState.tool === 'arrow') {
    wbRedraw(ctx, canvas);
    const p0 = wbState.currentPath.points[0];
    wbDrawArrow(ctx, p0, pos, wbState.color, wbState.strokeSize);
  }
}

function wbUp(e, ctx, canvas) {
  if (!wbState.drawing) return;
  wbState.drawing = false;
  if (wbState.currentPath) {
    wbState.paths.push({ ...wbState.currentPath });
    if (wbState.channelId) {
      wbSend({
        type: 'wb_op', channel_id: wbState.channelId,
        op: 'stroke_end', path_id: wbState.currentPath.id,
      });
    }
    wbState.currentPath = null;
  }
}

function wbRedraw(ctx, canvas) {
  ctx.clearRect(0, 0, canvas.width, canvas.height);
  for (const p of wbState.paths) {
    if (!p.points || p.points.length < 2) continue;
    ctx.beginPath();
    ctx.strokeStyle = p.color; ctx.lineWidth = p.size;
    ctx.lineCap = 'round'; ctx.lineJoin = 'round';
    ctx.moveTo(p.points[0].x, p.points[0].y);
    for (let i = 1; i < p.points.length; i++) ctx.lineTo(p.points[i].x, p.points[i].y);
    ctx.stroke();
  }
}

function wbDrawArrow(ctx, from, to, color, size) {
  const h = 13, angle = Math.atan2(to.y - from.y, to.x - from.x);
  ctx.strokeStyle = color; ctx.lineWidth = size;
  ctx.beginPath(); ctx.moveTo(from.x, from.y); ctx.lineTo(to.x, to.y); ctx.stroke();
  ctx.fillStyle = color; ctx.beginPath();
  ctx.moveTo(to.x, to.y);
  ctx.lineTo(to.x - h*Math.cos(angle-Math.PI/7), to.y - h*Math.sin(angle-Math.PI/7));
  ctx.lineTo(to.x - h*Math.cos(angle+Math.PI/7), to.y - h*Math.sin(angle+Math.PI/7));
  ctx.closePath(); ctx.fill();
}

// ── Apply persisted full state ────────────────────────────
function wbApplyFullState(stateJson) {
  if (!stateJson) return;
  try {
    const state = JSON.parse(stateJson);
    if (Array.isArray(state.paths)) {
      wbState.paths = state.paths;
      const canvas = document.getElementById('wbCanvas');
      if (canvas) wbRedraw(canvas.getContext('2d'), canvas);
    }
  } catch (e) {
    console.warn('[WB] Could not parse state_json:', e);
  }
}

// ── Tool selection ─────────────────────────────────────────
function wbPickTool(btn) { wbPickToolByName(btn.dataset.tool); }
function wbPickToolByName(name) {
  wbState.tool = name;
  document.querySelectorAll('.wb-tbtn').forEach(b => b.classList.toggle('wb-active', b.dataset.tool === name));
  const cursors = { cursor:'default', pen:'crosshair', highlight:'cell', eraser:'grab', text:'text', arrow:'crosshair', sticky:'copy' };
  const wrap = document.getElementById('wbCanvasWrap');
  if (wrap) wrap.style.cursor = cursors[name] || 'crosshair';
}

// ── Color / stroke ─────────────────────────────────────────
function wbClr(el, color) {
  wbState.color = color;
  document.querySelectorAll('.wb-clr').forEach(d => d.classList.remove('sel'));
  el.classList.add('sel');
}
function wbStroke(el) {
  document.querySelectorAll('.wb-stroke').forEach(s => s.classList.remove('sel'));
  el.classList.add('sel');
}
function wbSzUp() {
  wbState.strokeSize = Math.min(20, wbState.strokeSize + 1);
  document.getElementById('wbSzLbl').textContent = wbState.strokeSize + 'px';
}
function wbSzDown() {
  wbState.strokeSize = Math.max(1, wbState.strokeSize - 1);
  document.getElementById('wbSzLbl').textContent = wbState.strokeSize + 'px';
}

// ── Zoom ───────────────────────────────────────────────────
function wbSetZoom(val) {
  wbState.zoom = parseInt(val) / 100;
  const objs = document.getElementById('wbObjects');
  objs.style.transform = `scale(${wbState.zoom})`;
  objs.style.transformOrigin = 'top left';
}
function wbFit() {
  document.getElementById('wbZoom').value = '100%';
  wbSetZoom('100');
  showToast('🖥️ Fit to screen','info');
}
function wbWheelZoom(e) {
  const sel = document.getElementById('wbZoom');
  const vals = ['50%','75%','100%','125%','150%','200%'];
  let idx = vals.indexOf(sel.value);
  idx = Math.max(0, Math.min(vals.length-1, idx + (e.deltaY > 0 ? -1 : 1)));
  sel.value = vals[idx];
  wbSetZoom(parseInt(vals[idx]));
}

// ── Undo ───────────────────────────────────────────────────
function wbUndo() {
  if (!wbState.paths.length) { showToast('Nothing to undo','info'); return; }
  const me = wbGetCurrentUser();
  // Find last path owned by this user
  for (let i = wbState.paths.length - 1; i >= 0; i--) {
    const p = wbState.paths[i];
    if (!p.owner || p.owner === me.id) {
      const pathId = p.id;
      wbState.paths.splice(i, 1);
      const canvas = document.getElementById('wbCanvas');
      wbRedraw(canvas.getContext('2d'), canvas);
      if (wbState.channelId) {
        wbSend({ type: 'wb_op', channel_id: wbState.channelId, op: 'undo', path_id: pathId });
      }
      showToast('↩️ Undo','info');
      return;
    }
  }
  showToast('Nothing to undo','info');
}
function wbRedo() { showToast('↪️ Redo not yet supported','info'); }

// ── Clear board ────────────────────────────────────────────
function wbClear() {
  wbState.paths = [];
  const canvas = document.getElementById('wbCanvas');
  if (canvas) canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
  if (wbState.channelId) {
    wbSend({ type: 'wb_op', channel_id: wbState.channelId, op: 'clear' });
  }
  showToast('🗑️ Board cleared','info');
}

// ── Add sticky ─────────────────────────────────────────────
const STICKY_COLORS = ['#f0abfc','#fde68a','#86efac','#93c5fd','#fca5a5'];

function _wbCreateStickyEl(objId, x, y, color, text, isLocal) {
  const el = document.createElement('div');
  el.className = 'wb-draggable wb-sticky';
  el.dataset.objId = objId;
  el.style.cssText = `left:${x}px;top:${y}px;background:${color};color:#1e1e2e;`;
  el.innerHTML = `<div contenteditable="${isLocal}" style="outline:none;min-height:60px;">${text || 'New sticky note'}</div><div class="wb-sticky-heart" onclick="wbLike(this)">🤍</div>`;
  if (isLocal) {
    const ed = el.querySelector('[contenteditable]');
    ed.addEventListener('input', () => {
      if (wbState.channelId) {
        wbSend({ type: 'wb_op', channel_id: wbState.channelId, op: 'sticky_text', obj_id: objId, text: ed.textContent });
      }
    });
  }
  return el;
}

function wbAddSticky(x, y) {
  const color  = STICKY_COLORS[Math.floor(Math.random() * STICKY_COLORS.length)];
  const objId  = `sticky_${wbGetCurrentUser().id}_${Date.now()}_${wbState._objSeq++}`;
  const el     = _wbCreateStickyEl(objId, x, y, color, 'New sticky note', true);
  document.getElementById('wbObjects').appendChild(el);
  wbMakeDraggable(el, objId);
  wbState.objects[objId] = el;

  if (wbState.channelId) {
    wbSend({
      type: 'wb_op', channel_id: wbState.channelId,
      op: 'sticky_add', obj_id: objId,
      x, y, color, text: 'New sticky note',
    });
  }
  const me = wbGetCurrentUser();
  wbLogActivity('📝', `<strong>${me.name}</strong> added a sticky note`, 'rgba(240,171,252,.15)');
  showToast('📝 Sticky note added','info');
}

function _wbCreateTextEl(objId, x, y, color, text, isLocal) {
  const el = document.createElement('div');
  el.className = 'wb-draggable';
  el.dataset.objId = objId;
  el.style.cssText = `left:${x}px;top:${y}px;font-size:16px;font-weight:700;color:${color};outline:none;cursor:move;min-width:60px;position:absolute;`;
  el.contentEditable = isLocal ? 'true' : 'false';
  el.textContent = text || 'Text';
  if (isLocal) {
    el.addEventListener('input', () => {
      if (wbState.channelId) {
        wbSend({ type: 'wb_op', channel_id: wbState.channelId, op: 'text_edit', obj_id: objId, text: el.textContent });
      }
    });
  }
  return el;
}

function wbAddText(x, y) {
  const objId = `text_${wbGetCurrentUser().id}_${Date.now()}_${wbState._objSeq++}`;
  const el    = _wbCreateTextEl(objId, x, y, wbState.color, 'Text', true);
  document.getElementById('wbObjects').appendChild(el);
  wbMakeDraggable(el, objId);
  wbState.objects[objId] = el;
  el.focus();

  if (wbState.channelId) {
    wbSend({
      type: 'wb_op', channel_id: wbState.channelId,
      op: 'text_add', obj_id: objId,
      x, y, color: wbState.color, text: 'Text',
    });
  }
}

function wbAddNew() {
  const t = ['sticky','text','arrow'][Math.floor(Math.random()*3)];
  wbPickToolByName(t);
  showToast(`➕ ${t} selected — click canvas to place`,'info');
}

// ── Like ───────────────────────────────────────────────────
function wbLike(id) {
  const btn = typeof id === 'string' ? document.getElementById(id) : id;
  btn.textContent = btn.textContent === '🤍' ? '❤️' : '🤍';
  if (btn.textContent === '❤️') showToast('❤️ Liked!','info');
}
function wbToggleTodo(row) {
  const chk = row.querySelector('.wb-chk');
  const txt = row.querySelector('.wb-todo-txt');
  const done = chk.classList.toggle('done');
  txt.style.textDecoration = done ? 'line-through' : 'none';
  txt.style.opacity = done ? '.5' : '1';
  showToast(done ? '✅ Done!' : '⬜ Undone','info');
}

// ── Tabs ───────────────────────────────────────────────────
function wbTab(el, panId) {
  document.querySelectorAll('.wb-tab').forEach(t => t.classList.remove('wb-tab-active'));
  el.classList.add('wb-tab-active');
  document.querySelectorAll('.wb-panel').forEach(p => p.classList.remove('wb-panel-active'));
  document.getElementById(panId).classList.add('wb-panel-active');
}

function wbToggleSidebar() {
  const sidebar = document.getElementById('wbRightSidebar');
  const btn     = document.getElementById('wbSidebarToggle');
  if (!sidebar) return;
  const open = sidebar.classList.toggle('wb-sidebar-open');
  if (btn) btn.textContent = open ? '✕' : '☰';
}

// ── Dynamic member list ────────────────────────────────────
function wbUpdateMemberList() {
  const list  = document.getElementById('wbMemberList');
  const label = document.getElementById('wbMembersLabel');
  const stack = document.getElementById('wbAvStack');
  if (!list) return;

  const members = wbState.members;
  if (label) label.textContent = `WHITEBOARD MEMBERS — ${members.length}`;

  list.innerHTML = members.map(m => `
    <div class="wb-member">
      <div style="width:34px;height:34px;border-radius:50%;flex-shrink:0;position:relative;
                  background:${m.grad};display:flex;align-items:center;justify-content:center;
                  font-size:13px;font-weight:700;color:#fff;">
        ${m.initial}
        <div class="online-dot"></div>
      </div>
      <div class="wb-m-info">
        <div class="wb-m-name">
          ${m.username}
          ${m.isYou   ? '<span class="wb-you">You</span>'        : ''}
          ${m.isOwner ? '<span class="wb-badge-crown">👑</span>' : ''}
        </div>
        <div class="wb-m-role">${m.role || 'Online'}</div>
      </div>
    </div>`).join('');

  if (stack) {
    const show = members.slice(0, 4);
    const rest = members.length - show.length;
    stack.innerHTML = show.map(m =>
      `<div class="wb-av" style="background:${m.grad};" title="${m.username}">${m.initial}</div>`
    ).join('') + (rest > 0 ? `<div class="wb-av-more">+${rest}</div>` : '');
  }
}

// ── Chat ───────────────────────────────────────────────────
function wbRenderChat() {
  const msgs = document.getElementById('wbChatMsgs');
  if (!msgs || wbState.chatMessages.length === 0) return;
  msgs.innerHTML = wbState.chatMessages.map(m => `
    <div class="wb-chat-msg" style="animation:toastIn .2s ease;">
      <div style="width:28px;height:28px;border-radius:50%;flex-shrink:0;
                  background:${m.color};display:flex;align-items:center;justify-content:center;
                  font-size:11px;font-weight:700;color:#fff;">${m.initial}</div>
      <div class="wb-msg-content">
        <div class="wb-msg-hdr">
          <span class="wb-msg-name" style="color:${m.nameColor};">${m.name}</span>
          ${m.isAI ? '<span style="font-size:9px;font-weight:700;padding:1px 5px;background:#3b82f6;color:#fff;border-radius:3px;">APP</span>' : ''}
          <span class="wb-msg-time">${m.time}</span>
        </div>
        <div class="wb-msg-text">${m.text}</div>
      </div>
    </div>`).join('');
  msgs.scrollTop = msgs.scrollHeight;
}

function wbSendChat() {
  const inp = document.getElementById('wbChatInp');
  const txt = inp.value.trim(); if (!txt) return;
  const msgs = document.getElementById('wbChatMsgs');
  const me   = wbGetCurrentUser();
  const [c1, c2] = wbPickColor(0);
  const time = (typeof getNow === 'function') ? getNow() : new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});

  const msg = document.createElement('div');
  msg.className = 'wb-chat-msg'; msg.style.animation = 'toastIn .2s ease';
  msg.innerHTML = `
    <div style="width:28px;height:28px;border-radius:50%;flex-shrink:0;
                background:linear-gradient(135deg,${c1},${c2});display:flex;align-items:center;
                justify-content:center;font-size:11px;font-weight:700;color:#fff;">
      ${me.name.charAt(0).toUpperCase()}
    </div>
    <div class="wb-msg-content">
      <div class="wb-msg-hdr">
        <span class="wb-msg-name" style="color:${c1};">${me.name}</span>
        <span class="wb-msg-time">${time}</span>
      </div>
      <div class="wb-msg-text">${txt.replace(/</g,'&lt;')}</div>
    </div>`;
  msgs.appendChild(msg); msgs.scrollTop = msgs.scrollHeight;
  inp.value = '';

  wbLogActivity('💬', `<strong>${me.name}</strong> sent a chat message`, 'rgba(249,115,22,.15)');

  if (/ai|help|explain|formula/i.test(txt)) {
    setTimeout(() => {
      const ai = document.createElement('div');
      ai.className = 'wb-chat-msg'; ai.style.animation = 'toastIn .2s ease';
      ai.innerHTML = `
        <div style="width:28px;height:28px;border-radius:50%;flex-shrink:0;
                    background:linear-gradient(135deg,#a855f7,#7c3aed);display:flex;align-items:center;
                    justify-content:center;font-size:12px;">🤖</div>
        <div class="wb-msg-content">
          <div class="wb-msg-hdr">
            <span class="wb-msg-name" style="color:#a855f7;">AI_Assistant</span>
            <span style="font-size:9px;font-weight:700;padding:1px 5px;background:#3b82f6;color:#fff;border-radius:3px;">APP</span>
            <span class="wb-msg-time">${time}</span>
          </div>
          <div class="wb-msg-text">Happy to help! Want me to add a worked example to the whiteboard?</div>
        </div>`;
      msgs.appendChild(ai); msgs.scrollTop = msgs.scrollHeight;
    }, 1200);
  }
}
function wbChatKey(e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); wbSendChat(); } }

// ── Activity log ───────────────────────────────────────────
function wbLogActivity(icon, text, bg) {
  const log = document.getElementById('wbActivityLog');
  if (!log) return;
  const time = (typeof getNow === 'function') ? getNow() : new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
  const item = document.createElement('div');
  item.className = 'wb-act-item'; item.style.animation = 'toastIn .2s ease';
  item.innerHTML = `
    <div class="wb-act-icon" style="background:${bg};">${icon}</div>
    <div class="wb-act-body">
      <div class="wb-act-text">${text}</div>
      <div class="wb-act-time">${time}</div>
    </div>`;
  log.insertBefore(item, log.firstChild);
}

// ── Live cursor animation (placeholder when no real peers yet) ─
function wbAnimateCursors() {
  // Real cursors come over WS. The demo cursors (wbCurF/wbCurS) remain
  // in place for when no peers are connected yet.
  if (!wbState.open) return;
  const curF = document.getElementById('wbCurF');
  const curS = document.getElementById('wbCurS');
  if (!curF && !curS) return;
  const pathF = [{x:520,y:175},{x:565,y:205},{x:595,y:240},{x:555,y:265},{x:520,y:245},{x:520,y:175}];
  const pathS = [{x:410,y:385},{x:455,y:408},{x:490,y:375},{x:452,y:355},{x:410,y:385}];
  let tf = 0, ts = 0;
  function tick() {
    if (!wbState.open) return;
    // Hide demo cursors if real peers are in the room
    const hasRealPeers = Object.keys(wbState.remoteCursors).length > 0;
    if (curF) curF.style.display = hasRealPeers ? 'none' : '';
    if (curS) curS.style.display = hasRealPeers ? 'none' : '';
    if (!hasRealPeers) {
      tf += .003; ts += .0045;
      const fi = Math.floor(tf % pathF.length), fn = pathF[(fi+1)%pathF.length], fp = pathF[fi], ff = tf % 1;
      const si = Math.floor(ts % pathS.length), sn = pathS[(si+1)%pathS.length], sp = pathS[si], sf = ts % 1;
      if (curF) { curF.style.left = (fp.x + (fn.x-fp.x)*ff) + 'px'; curF.style.top = (fp.y + (fn.y-fp.y)*ff) + 'px'; }
      if (curS) { curS.style.left = (sp.x + (sn.x-sp.x)*sf) + 'px'; curS.style.top = (sp.y + (sn.y-sp.y)*sf) + 'px'; }
    }
    requestAnimationFrame(tick);
  }
  requestAnimationFrame(tick);
}

// ── Auto-save (also sends periodic state to server) ───────
function wbStartAutoSave() {
  let s = 0;
  const lbl = document.getElementById('wbSaveLabel');
  const id = setInterval(() => {
    if (!wbState.open) { clearInterval(id); return; }
    s++;
    if (s % 30 === 0) {
      if (lbl) lbl.textContent = 'Saving…';
      // Persist full canvas state every 30 s
      if (wbState.channelId) {
        const canvas = document.getElementById('wbCanvas');
        const stateJson = JSON.stringify({ paths: wbState.paths, savedAt: new Date().toISOString() });
        wbSend({ type: 'wb_state_save', channel_id: wbState.channelId, state_json: stateJson });
      }
      setTimeout(() => { if (lbl) lbl.textContent = 'Saved just now'; }, 700);
    } else {
      const m = Math.floor(s / 60);
      if (lbl && lbl.textContent !== 'Saving…') lbl.textContent = m ? `Saved ${m}m ago` : 'Saved just now';
    }
  }, 1000);
}

// ── Export PNG ─────────────────────────────────────────────
function wbExportPdfToChat() {
  const canvas = document.getElementById('wbCanvas');
  if (!canvas) return;
  try {
    const imgData    = canvas.toDataURL('image/png');
    const boardName  = wbState.boardName;
    const timestamp  = (typeof getNow === 'function') ? getNow() : new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
    const me         = wbGetCurrentUser();
    _wbSendPdfMessage({ imgData, boardName, timestamp, senderName: me.name });
    showToast('📄 Whiteboard saved & sent to chat', 'success');
  } catch(e) {
    console.warn('WB PDF export failed:', e);
    showToast('⚠️ Could not export whiteboard', 'error');
  }
}

function _wbSendPdfMessage({ imgData, boardName, timestamp, senderName }) {
  const chatMsgsEl = document.getElementById('chatMessages') ||
                     document.querySelector('.chat-messages-container') ||
                     document.querySelector('[id$="Messages"]');
  const me = wbGetCurrentUser();
  const [c1, c2] = WB_COLORS[0];
  const msgHTML = `
    <div class="message-item wb-pdf-drop" style="animation:toastIn .3s ease;">
      <div class="avatar-placeholder" style="width:34px;height:34px;border-radius:50%;flex-shrink:0;
        background:linear-gradient(135deg,${c1},${c2});display:flex;align-items:center;justify-content:center;
        font-size:13px;font-weight:700;color:#fff;">${me.name.charAt(0).toUpperCase()}</div>
      <div class="message-content" style="flex:1;min-width:0;">
        <div class="message-header" style="display:flex;align-items:baseline;gap:8px;margin-bottom:4px;">
          <span class="message-author" style="font-size:13px;font-weight:700;color:#e2e8f0;">${senderName}</span>
          <span class="message-time" style="font-size:10px;color:#475569;">${timestamp}</span>
        </div>
        <div class="message-text" style="font-size:13px;color:#94a3b8;margin-bottom:8px;">
          📄 Whiteboard session ended — <strong style="color:#e2e8f0;">${boardName}</strong> snapshot
        </div>
        <div class="wb-pdf-preview" style="display:inline-block;border-radius:10px;overflow:hidden;
          border:1.5px solid rgba(168,85,247,.35);box-shadow:0 4px 20px rgba(0,0,0,.4);max-width:320px;cursor:pointer;"
          onclick="wbOpenExportedImage(this)">
          <img src="${imgData}" alt="Whiteboard snapshot" style="display:block;width:100%;max-width:320px;height:auto;" />
          <div style="padding:8px 12px;background:#1a2235;display:flex;align-items:center;gap:8px;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="#a855f7"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>
            <span style="font-size:12px;font-weight:600;color:#a855f7;flex:1;">${boardName}.png</span>
            <a href="${imgData}" download="${boardName}.png"
              style="font-size:11px;font-weight:600;color:#64748b;text-decoration:none;padding:2px 8px;border-radius:5px;border:1px solid rgba(255,255,255,.1);"
              onclick="event.stopPropagation()">Save</a>
          </div>
        </div>
      </div>
    </div>`;
  if (chatMsgsEl) { chatMsgsEl.insertAdjacentHTML('beforeend', msgHTML); chatMsgsEl.scrollTop = chatMsgsEl.scrollHeight; }
  wbLogActivity('📄', `<strong>${senderName}</strong> ended session — snapshot sent to chat`, 'rgba(168,85,247,.15)');
}

function wbOpenExportedImage(el) {
  const img = el.querySelector('img');
  if (!img) return;
  window.open(img.src, '_blank');
}

function wbExportPng() {
  const canvas = document.getElementById('wbCanvas');
  if (!canvas) return;
  const a = document.createElement('a');
  a.href = canvas.toDataURL('image/png');
  a.download = (wbState.boardName || 'whiteboard') + '.png';
  a.click();
  showToast('📤 Exported as PNG!','info');
}

// ── Share / Invite ─────────────────────────────────────────
function wbCopyInvite() {
  const url = window.location.href.split('?')[0];
  try { navigator.clipboard.writeText(url + '?wb=1'); } catch(e){}
  showToast('🔗 Invite link copied!','info');
}

// ── Shims ──────────────────────────────────────────────────
function wbSelectTool(el) {
  document.querySelectorAll('.wb-tool').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
}

window._wbOpen  = openWhiteboard;
window._wbClose = closeWhiteboard;
window.openWhiteboard  = openWhiteboard;
window.closeWhiteboard = closeWhiteboard;

// Expose real-time handler for chat.js WS dispatcher
window.wbHandleWsMessage = wbHandleWsMessage;

;(function() {
  const fns = {
    wbRequestClose, wbSaveAndEnd, wbEndWithoutSaving, wbDismissLeaveModal,
    closeWhiteboard, openWhiteboard, openWhiteboardFromVoice, joinSession,
    wbPickTool, wbClr, wbStroke, wbSzUp, wbSzDown, wbSetZoom, wbFit,
    wbUndo, wbRedo, wbClear, wbAddNew, wbLike, wbToggleTodo, wbTab,
    wbToggleSidebar, wbSendChat, wbChatKey, wbCopyInvite, wbExportPng,
    wbHandleWsMessage,
  };
  Object.entries(fns).forEach(([name, fn]) => {
    window['__real_' + name] = fn;
    window[name] = fn;
  });
})();
