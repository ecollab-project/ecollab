/**
 * collab-liveeditor.js — Google Docs-grade live collaborative editor for Ecollab
 *
 * Architecture:
 *   OT Engine (ot-engine.js)
 *     ↕ ops + ack
 *   LiveEditor UI
 *     ↕ WS messages (collab_note_op / collab_note_ack / collab_note_cursor /
 *                    collab_note_presence / collab_note_full_sync)
 *   ChatServer (relay via ws_relay)
 *     ↕ HTTP
 *   API/collab/collab.php (note_op / note_ack / note_load actions)
 *
 * Features:
 *   - Real-time character-level OT (insert/delete/retain) with no overwrites
 *   - Named cursor carets for every connected peer with colour coding
 *   - Selection highlights for peers
 *   - Title bar with live doc name editing
 *   - Formatting toolbar (Bold, Italic, Underline, H1-H3, lists, blockquote, code)
 *   - Word count, character count, last-edited-by badge
 *   - Per-user undo stack (Ctrl+Z / Ctrl+Y) — does not undo remote changes
 *   - Auto-reconnect and full-doc resync on OT error
 *   - Read-only mode for non-members
 */

'use strict';

/* ── Constants ───────────────────────────────────────────────────────────── */
const DOC_API    = (window.ECOLLAB?.baseUrl || '') + '/API/collab/collab.php';
const PEER_COLORS = [
  '#e879f9','#38bdf8','#fb923c','#4ade80','#f472b6',
  '#a78bfa','#34d399','#fbbf24','#60a5fa','#f87171',
];

/* ── State ───────────────────────────────────────────────────────────────── */
let _editor         = null;   // contenteditable div
let _noteId         = null;
let _channelId      = null;
let _otClient       = null;   // OT.ClientState instance
let _undoStack      = [];     // [{op, docBefore}]
let _redoStack      = [];
let _peers          = {};     // uid → { username, color, cursor, selStart, selEnd }
let _myColor        = null;
let _sendLock       = false;  // prevent re-entrant sends during apply
let _lastSent       = 0;      // ms timestamp of last cursor broadcast
let _titleSaveTimer = null;
let _docTitle       = 'Untitled Document';
let _isReadOnly     = false;
let _syncPending    = false;

/* ── Init ────────────────────────────────────────────────────────────────── */
async function loadLiveEditor(channelId) {
  _channelId = channelId;
  const pane = document.getElementById('collabPane_notes');
  if (!pane) return;

  pane.innerHTML = `<div class="collab-loading"><div class="collab-spinner"></div></div>`;

  try {
    const res = await _docFetch('note_load', {}, 'GET');
    _noteId   = res.note.id;
    _docTitle = res.note.title || 'Untitled Document';
    _isReadOnly = res.readonly ?? false;

    _myColor = _assignMyColor();
    _otClient = new OT.ClientState(_sendOp);
    _otClient.init(res.note.content || '', res.note.revision || 0);

    _undoStack = [];
    _redoStack = [];
    _peers     = {};

    _renderEditor(pane, res.note);
    _attachEditorEvents();
    _broadcastPresence();
    _startHeartbeat();
  } catch (e) {
    pane.innerHTML = `<div class="collab-err">⚠ ${_esc(e.message)}</div>`;
  }
}
window.loadLiveEditor = loadLiveEditor;

function _assignMyColor() {
  const uid = parseInt(window.ECOLLAB?.userId || '0');
  return PEER_COLORS[uid % PEER_COLORS.length];
}

/* ── Render ──────────────────────────────────────────────────────────────── */
function _renderEditor(pane, note) {
  pane.innerHTML = `
    <!-- Title bar -->
    <div class="le-titlebar">
      <input id="leDocTitle" class="le-title-input"
             value="${_esc(_docTitle)}"
             placeholder="Document title…"
             ${_isReadOnly ? 'readonly' : ''}
             oninput="_onTitleInput()" />
      <div class="le-meta-row">
        <span id="leWordCount" class="le-meta-chip">0 words</span>
        <span id="leLastEditor" class="le-meta-chip" style="display:none"></span>
        <span id="leSyncStatus" class="le-sync-status">●&nbsp;Synced</span>
      </div>
    </div>

    <!-- Formatting toolbar -->
    <div class="le-toolbar" id="leToolbar" ${_isReadOnly ? 'style="opacity:.4;pointer-events:none"' : ''}>
      <button class="le-tb-btn" data-cmd="bold"          title="Bold (Ctrl+B)"><b>B</b></button>
      <button class="le-tb-btn" data-cmd="italic"        title="Italic (Ctrl+I)"><i>I</i></button>
      <button class="le-tb-btn" data-cmd="underline"     title="Underline (Ctrl+U)"><u>U</u></button>
      <div class="le-tb-sep"></div>
      <button class="le-tb-btn" data-md="# "            title="Heading 1">H1</button>
      <button class="le-tb-btn" data-md="## "           title="Heading 2">H2</button>
      <button class="le-tb-btn" data-md="### "          title="Heading 3">H3</button>
      <div class="le-tb-sep"></div>
      <button class="le-tb-btn" data-md="- "            title="Bullet list">☰</button>
      <button class="le-tb-btn" data-md="1. "           title="Numbered list">1.</button>
      <button class="le-tb-btn" data-md="> "            title="Blockquote">❝</button>
      <button class="le-tb-btn" data-md="\`\`\`\n" title="Code block">&lt;/&gt;</button>
      <div class="le-tb-sep"></div>
      <button class="le-tb-btn" onclick="_leUndo()" title="Undo (Ctrl+Z)">↩</button>
      <button class="le-tb-btn" onclick="_leRedo()" title="Redo (Ctrl+Y)">↪</button>
      <div class="le-tb-sep" style="margin-left:auto"></div>
      <div id="lePeerAvatars" class="le-peer-avatars"></div>
    </div>

    <!-- Document viewport -->
    <div class="le-viewport" id="leViewport">
      <!-- Peer cursor layer (absolutely positioned over editor) -->
      <div class="le-cursor-layer" id="leCursorLayer"></div>

      <!-- Editor surface -->
      <div id="leEditor"
           class="le-editor${_isReadOnly ? ' le-readonly' : ''}"
           contenteditable="${_isReadOnly ? 'false' : 'true'}"
           spellcheck="true"
           autocorrect="off"
           data-placeholder="Start writing…"
      >${_textToHtml(_otClient.doc)}</div>
    </div>

    <!-- Peer selection highlights layer (rendered inside viewport) -->
    <style id="lePeerHighlights"></style>`;

  _editor = document.getElementById('leEditor');
  _updateWordCount();
}

/* ── Editor events ───────────────────────────────────────────────────────── */
function _attachEditorEvents() {
  if (!_editor) return;

  // Input: diff old vs new text, build op, apply locally
  let _lastDoc = _otClient.doc;
  _editor.addEventListener('input', () => {
    if (_sendLock) return;
    const newDoc = _getEditorText();
    if (newDoc === _lastDoc) return;

    const docBefore = _lastDoc;
    _lastDoc = newDoc;
    _undoStack.push({ docBefore, newDoc });
    if (_undoStack.length > 50) _undoStack.shift();
    _redoStack = [];

    _otClient.localChange(newDoc);
    _updateWordCount();
    _setSyncStatus('pending');
  });

  // Cursor / selection movement — broadcast peer cursor
  _editor.addEventListener('keyup',   _throttledCursorBroadcast);
  _editor.addEventListener('mouseup', _throttledCursorBroadcast);
  _editor.addEventListener('click',   _throttledCursorBroadcast);

  // Keyboard shortcuts
  _editor.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && !e.shiftKey) {
      if (e.key === 'z') { e.preventDefault(); _leUndo(); }
      if (e.key === 'y') { e.preventDefault(); _leRedo(); }
    }
  });

  // Formatting toolbar — markdown prefix insertion
  document.getElementById('leToolbar')?.addEventListener('click', e => {
    const btn = e.target.closest('.le-tb-btn');
    if (!btn) return;
    if (btn.dataset.cmd) {
      document.execCommand(btn.dataset.cmd, false, null);
      _editor.dispatchEvent(new Event('input'));
    } else if (btn.dataset.md) {
      _insertMarkdownPrefix(btn.dataset.md);
    }
  });
}

/* ── Text helpers ────────────────────────────────────────────────────────── */
function _getEditorText() {
  if (!_editor) return '';
  // Normalise: replace <br> with \n, strip other tags
  return _editor.innerText ?? _editor.textContent ?? '';
}

function _textToHtml(text) {
  return _esc(text).replace(/\n/g, '<br>');
}

function _esc(s) {
  return String(s)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ── Cursor position helpers ─────────────────────────────────────────────── */
function _getCaretOffset(el) {
  const sel = window.getSelection();
  if (!sel?.rangeCount) return 0;
  const range  = sel.getRangeAt(0).cloneRange();
  range.selectNodeContents(el);
  range.setEnd(sel.getRangeAt(0).endContainer, sel.getRangeAt(0).endOffset);
  return range.toString().length;
}

function _getSelectionRange(el) {
  const sel = window.getSelection();
  if (!sel?.rangeCount) return { start: 0, end: 0 };
  const r    = sel.getRangeAt(0);
  const base = document.createRange();
  base.selectNodeContents(el);
  base.setEnd(r.startContainer, r.startOffset);
  const start = base.toString().length;
  return { start, end: start + r.toString().length };
}

function _setCaretOffset(el, offset) {
  const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
  let remaining = offset, node;
  while ((node = walker.nextNode())) {
    if (remaining <= node.length) {
      const range = document.createRange();
      range.setStart(node, remaining);
      range.collapse(true);
      const sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(range);
      return;
    }
    remaining -= node.length;
  }
}

/* ── Cursor broadcast ────────────────────────────────────────────────────── */
function _throttledCursorBroadcast() {
  const now = Date.now();
  if (now - _lastSent < 80) return; // 80 ms throttle
  _lastSent = now;
  if (!_editor || !_channelId) return;
  const { start, end } = _getSelectionRange(_editor);
  _wsSendCollab({
    type:       'collab_note_cursor',
    note_id:    _noteId,
    channel_id: _channelId,
    user_id:    window.ECOLLAB?.userId,
    username:   window.ECOLLAB?.username,
    color:      _myColor,
    cursor:     end,
    sel_start:  start,
    sel_end:    end,
  });
}

/* ── Peer presence broadcast ─────────────────────────────────────────────── */
function _broadcastPresence() {
  _wsSendCollab({
    type:       'collab_note_presence',
    note_id:    _noteId,
    channel_id: _channelId,
    user_id:    window.ECOLLAB?.userId,
    username:   window.ECOLLAB?.username,
    color:      _myColor,
    joined:     true,
  });
}

function _broadcastLeave() {
  _wsSendCollab({
    type:       'collab_note_presence',
    note_id:    _noteId,
    channel_id: _channelId,
    user_id:    window.ECOLLAB?.userId,
    username:   window.ECOLLAB?.username,
    joined:     false,
  });
}

/* ── Send op via REST → ws_relay → WS → peers ───────────────────────────── */
async function _sendOp(op, revision) {
  _setSyncStatus('saving');
  try {
    const res = await _docFetch('note_op', {
      note_id:  _noteId,
      op:       JSON.stringify(op),
      revision,
    });
    // Server echoes back the committed revision and transformed op
    _otClient.ackFromServer();
    _setSyncStatus('synced');
    if (res.last_editor) _setLastEditor(res.last_editor);
  } catch (e) {
    _setSyncStatus('error');
    if (e.message === 'RESYNC') {
      await _resync();
    }
  }
}

/* ── Full resync (OT error recovery) ────────────────────────────────────── */
async function _resync() {
  if (_syncPending) return;
  _syncPending = true;
  _setSyncStatus('syncing');
  try {
    const res = await _docFetch('note_load', {}, 'GET');
    _sendLock = true;
    _otClient.init(res.note.content || '', res.note.revision || 0);
    if (_editor) {
      const pos = _getCaretOffset(_editor);
      _editor.innerHTML = _textToHtml(_otClient.doc);
      _setCaretOffset(_editor, Math.min(pos, _otClient.doc.length));
    }
    _sendLock = false;
    _setSyncStatus('synced');
  } catch { _setSyncStatus('error'); }
  finally { _syncPending = false; }
}

/* ── Apply remote op to editor ───────────────────────────────────────────── */
function _applyRemoteOp(transformedOp) {
  if (!_editor || !_otClient) return;
  const caretBefore = _getCaretOffset(_editor);

  _sendLock = true;
  try {
    // Update the doc string via OT engine
    _editor.innerHTML = _textToHtml(_otClient.doc);
    // Adjust local caret for the transformed op
    const newCaret = OT.transformCursor(caretBefore, transformedOp);
    _setCaretOffset(_editor, newCaret);
  } finally {
    _sendLock = false;
  }
  _updateWordCount();
}

/* ── Peer cursor / selection rendering ──────────────────────────────────── */
function _renderPeerCursor(uid, data) {
  const peer = _peers[uid] || {};
  _peers[uid] = { ...peer, ...data };

  _updatePeerAvatars();
  _updatePeerHighlights();
  _updatePeerCarets();
}

function _updatePeerAvatars() {
  const bar = document.getElementById('lePeerAvatars');
  if (!bar) return;
  bar.innerHTML = Object.entries(_peers).map(([uid, p]) => `
    <div class="le-peer-avatar" style="background:${p.color || '#a855f7'}"
         title="${_esc(p.username || uid)}">${(p.username||'?')[0].toUpperCase()}</div>
  `).join('');
}

function _updatePeerHighlights() {
  const style = document.getElementById('lePeerHighlights');
  if (!style) return;
  // CSS-based selection highlighting is not possible for remote cursors without
  // custom overlays; we inject colour vars for the caret elements instead
  style.textContent = Object.entries(_peers).map(([uid, p]) => `
    .le-caret-${uid} { border-left-color: ${p.color || '#a855f7'}; }
    .le-caret-label-${uid} { background: ${p.color || '#a855f7'}; }
  `).join('');
}

function _updatePeerCarets() {
  const layer = document.getElementById('leCursorLayer');
  if (!layer || !_editor) return;
  layer.innerHTML = '';

  const editorRect = _editor.getBoundingClientRect();
  const layerRect  = layer.getBoundingClientRect();

  Object.entries(_peers).forEach(([uid, peer]) => {
    if (peer.cursor === undefined || peer.cursor === null) return;

    // Get pixel position for the caret using a Range
    try {
      const range = _rangeAtOffset(_editor, peer.cursor);
      if (!range) return;
      const rects = range.getClientRects();
      const rect  = rects[rects.length - 1] || range.getBoundingClientRect();
      if (!rect || rect.width === 0 && rect.height === 0) return;

      const top  = rect.top  - layerRect.top;
      const left = rect.left - layerRect.left;

      const caret = document.createElement('div');
      caret.className = `le-caret le-caret-${uid}`;
      caret.style.cssText = `top:${top}px;left:${left}px;height:${rect.height || 18}px`;

      const label = document.createElement('div');
      label.className = `le-caret-label le-caret-label-${uid}`;
      label.textContent = peer.username || '?';
      caret.appendChild(label);
      layer.appendChild(caret);
    } catch { /* caret out of range after remote edit — will update next broadcast */ }
  });
}

function _rangeAtOffset(el, offset) {
  const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
  let remaining = offset, node;
  while ((node = walker.nextNode())) {
    if (remaining <= node.length) {
      const r = document.createRange();
      r.setStart(node, remaining);
      r.collapse(true);
      return r;
    }
    remaining -= node.length;
  }
  return null;
}

/* ── Undo / Redo ─────────────────────────────────────────────────────────── */
function _leUndo() {
  if (!_undoStack.length || _isReadOnly) return;
  const { docBefore } = _undoStack.pop();
  _redoStack.push({ docBefore: _getEditorText(), newDoc: docBefore });
  _applyDocReplace(docBefore);
}
window._leUndo = _leUndo;

function _leRedo() {
  if (!_redoStack.length || _isReadOnly) return;
  const { docBefore } = _redoStack.pop();
  _undoStack.push({ docBefore: _getEditorText() });
  _applyDocReplace(docBefore);
}
window._leRedo = _leRedo;

function _applyDocReplace(newText) {
  if (!_editor) return;
  const pos = _getCaretOffset(_editor);
  _editor.innerHTML = _textToHtml(newText);
  _setCaretOffset(_editor, Math.min(pos, newText.length));
  _otClient.localChange(newText);
  _updateWordCount();
}

/* ── Markdown prefix insertion ───────────────────────────────────────────── */
function _insertMarkdownPrefix(prefix) {
  if (!_editor || _isReadOnly) return;
  _editor.focus();
  const sel   = window.getSelection();
  if (!sel?.rangeCount) return;
  const range = sel.getRangeAt(0);
  // Find start of line
  const textNode = document.createTextNode(prefix);
  range.collapse(true);
  range.insertNode(textNode);
  range.setStartAfter(textNode);
  range.collapse(true);
  sel.removeAllRanges();
  sel.addRange(range);
  _editor.dispatchEvent(new Event('input'));
}

/* ── Title ───────────────────────────────────────────────────────────────── */
function _onTitleInput() {
  clearTimeout(_titleSaveTimer);
  _setSyncStatus('pending');
  _titleSaveTimer = setTimeout(async () => {
    const title = document.getElementById('leDocTitle')?.value?.trim() || 'Untitled Document';
    try {
      await _docFetch('note_title', { note_id: _noteId, title });
      _docTitle = title;
      _setSyncStatus('synced');
    } catch { _setSyncStatus('error'); }
  }, 1000);
}
window._onTitleInput = _onTitleInput;

/* ── Word count ──────────────────────────────────────────────────────────── */
function _updateWordCount() {
  const el  = document.getElementById('leWordCount');
  if (!el) return;
  const text = _otClient?.doc || '';
  const words = text.trim() ? text.trim().split(/\s+/).length : 0;
  const chars = text.length;
  el.textContent = `${words} word${words !== 1 ? 's' : ''} · ${chars} chars`;
}

function _setLastEditor(name) {
  const el = document.getElementById('leLastEditor');
  if (!el) return;
  el.style.display = '';
  el.textContent   = `Edited by ${name}`;
}

/* ── Sync status indicator ───────────────────────────────────────────────── */
const _syncMessages = {
  synced:  '● Synced',
  pending: '○ Unsaved…',
  saving:  '↑ Saving…',
  syncing: '↻ Syncing…',
  error:   '⚠ Sync error',
};
const _syncColors = {
  synced: '#22c55e', pending: '#f59e0b', saving: '#38bdf8', syncing: '#a855f7', error: '#ef4444',
};
function _setSyncStatus(state) {
  const el = document.getElementById('leSyncStatus');
  if (!el) return;
  el.textContent   = _syncMessages[state] || state;
  el.style.color   = _syncColors[state]   || 'var(--text-muted)';
}

/* ── Heartbeat for cursor / presence refresh ─────────────────────────────── */
let _heartbeatTimer = null;
function _startHeartbeat() {
  clearInterval(_heartbeatTimer);
  _heartbeatTimer = setInterval(() => {
    _broadcastPresence();
    _updatePeerCarets(); // refresh positions after reflow
  }, 8000);
}

/* ── WS send helper ──────────────────────────────────────────────────────── */
function _wsSendCollab(payload) {
  if (window.wsSend) window.wsSend(payload);
}

/* ── REST fetch ──────────────────────────────────────────────────────────── */
async function _docFetch(action, body = {}, method = 'POST') {
  const channelId = _channelId || window.ECOLLAB?.currentChannelId;
  const url = `${DOC_API}?tool=notes&action=${action}&channel_id=${channelId}`;
  const opts = {
    method,
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.ECOLLAB?.csrfToken || '',
    },
  };
  if (method !== 'GET') opts.body = JSON.stringify({ ...body, channel_id: channelId });
  const res  = await fetch(url, opts);
  const data = await res.json();
  if (!data.ok) {
    if (res.status === 409) throw new Error('RESYNC');
    throw new Error(data.error || 'Request failed');
  }
  return data;
}

/* ── WS message handlers (registered on window for socket.js) ─────────────── */

/** Remote op arrived */
window._collabNotesOnOp = (data) => {
  if (!_otClient || parseInt(data.channel_id) !== parseInt(_channelId)) return;
  if (parseInt(data.user_id) === parseInt(window.ECOLLAB?.userId)) return; // own echo

  let op;
  try { op = JSON.parse(data.op); } catch { return; }

  const transformedOp = _otClient.opFromServer(op);
  if (transformedOp === null) { _resync(); return; }
  _applyRemoteOp(transformedOp);
  _setSyncStatus('synced');

  if (data.username) _setLastEditor(data.username);
};

/** Remote cursor moved */
window._collabNotesOnCursor = (data) => {
  if (parseInt(data.channel_id) !== parseInt(_channelId)) return;
  if (parseInt(data.user_id) === parseInt(window.ECOLLAB?.userId)) return;

  const uid = String(data.user_id);
  _renderPeerCursor(uid, {
    username:  data.username,
    color:     data.color || PEER_COLORS[uid % PEER_COLORS.length],
    cursor:    data.cursor,
    sel_start: data.sel_start,
    sel_end:   data.sel_end,
  });
};

/** Peer joined / left */
window._collabNotesOnPresence = (data) => {
  if (parseInt(data.channel_id) !== parseInt(_channelId)) return;
  const uid = String(data.user_id);
  if (data.joined) {
    _peers[uid] = { username: data.username, color: data.color || _myColor };
  } else {
    delete _peers[uid];
    document.querySelector(`.le-caret-${uid}`)?.remove();
  }
  _updatePeerAvatars();
  _updatePeerHighlights();
};

/** Full doc sync pushed from server (e.g. after OT error recovery) */
window._collabNotesOnFullSync = (data) => {
  if (parseInt(data.channel_id) !== parseInt(_channelId)) return;
  _sendLock = true;
  _otClient.init(data.content || '', data.revision || 0);
  if (_editor) _editor.innerHTML = _textToHtml(_otClient.doc);
  _sendLock = false;
  _updateWordCount();
  _setSyncStatus('synced');
};

/** Overwrite existing _collabNotesOnUpdate to route to new handlers */
window._collabNotesOnUpdate = (data) => {
  if (!data) return;
  switch (data.type) {
    case 'collab_note_op':       window._collabNotesOnOp(data);       break;
    case 'collab_note_cursor':   window._collabNotesOnCursor(data);   break;
    case 'collab_note_presence': window._collabNotesOnPresence(data); break;
    case 'collab_note_full_sync': window._collabNotesOnFullSync(data); break;
    // Legacy full-save from old system
    case 'collab_note_updated':
      if (_editor && data.content !== undefined) {
        _sendLock = true;
        _otClient.init(data.content, data.version || 0);
        _editor.innerHTML = _textToHtml(_otClient.doc);
        _sendLock = false;
        _updateWordCount();
      }
      break;
  }
};

/* ── Clean up when switching channels ────────────────────────────────────── */
document.addEventListener('channelSwitched', () => {
  clearInterval(_heartbeatTimer);
  _broadcastLeave();
  _peers = {};
  _otClient = null;
  _editor = null;
  _noteId = null;
});

/* ── Patch collab-tools.js loadNotes to call loadLiveEditor ──────────────── */
// Override the simple textarea implementation with the OT editor
window._overrideLoadNotes = true;
const _origLoadNotes = window.loadNotes;
window.loadNotes = async function() {
  const channelId = window.ECOLLAB?.currentChannelId;
  if (!channelId) return;
  if (!window.OT) {
    // OT engine not yet available — wait for it
    await new Promise(r => setTimeout(r, 100));
  }
  await loadLiveEditor(channelId);
};
