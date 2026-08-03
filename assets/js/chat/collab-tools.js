/**
 * collab-tools.js — 6 real-time collaboration tools for Ecollab
 *
 * Tools:
 *   1. Shared Notes   — live markdown editor, optimistic saves, conflict detection
 *   2. Task Board     — drag-and-drop Kanban with priorities and assignments
 *   3. Code Sandbox   — shared code editor with language switcher and run log
 *   4. Study Timer    — synchronized Pomodoro for all channel members
 *   5. Quiz Builder   — create, publish, and auto-grade channel quizzes
 *   6. Group Calendar — shared events with RSVP and month/week views
 *
 * All tools:
 *   - Communicate via /API/collab/collab.php
 *   - Receive live pushes via the ws_relay WebSocket channel
 *   - Are scoped to the currently open channel (window.ECOLLAB.currentChannelId)
 */

'use strict';

const COLLAB_API = (window.ECOLLAB?.baseUrl || '') + '/API/collab/collab.php';

// ── Utility ──────────────────────────────────────────────────────────────────
async function collabFetch(tool, action, body = {}, method = 'POST') {
  const channelId = window.ECOLLAB?.currentChannelId;
  if (!channelId) { showToast('Open a channel first', 'info'); throw new Error('No channel'); }
  const url = `${COLLAB_API}?tool=${tool}&action=${action}&channel_id=${channelId}`;
  const opts = { method, credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.ECOLLAB?.csrfToken || '' } };
  if (method !== 'GET') opts.body = JSON.stringify({ ...body, channel_id: channelId });
  const res = await fetch(url, opts);
  const data = await res.json();
  if (!data.ok) throw new Error(data.error || 'Request failed');
  return data;
}

function escH(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// ─────────────────────────────────────────────────────────────────────────────
// COLLAB HUB — toolbar button + panel switcher
// ─────────────────────────────────────────────────────────────────────────────
let _collabOpen = false;
let _collabActive = 'notes';

function openCollabHub(tool = _collabActive) {
  _collabActive = tool;
  _collabOpen   = true;
  const panel = document.getElementById('collabHub');
  if (!panel) return;
  panel.style.display = 'flex';
  requestAnimationFrame(() => panel.classList.add('collab-open'));
  _switchCollabTool(tool);
}
window.openCollabHub = openCollabHub;

function closeCollabHub() {
  _collabOpen = false;
  const panel = document.getElementById('collabHub');
  if (!panel) return;
  panel.classList.remove('collab-open');
  setTimeout(() => { if (!_collabOpen) panel.style.display = 'none'; }, 260);
}
window.closeCollabHub = closeCollabHub;

function _switchCollabTool(tool) {
  _collabActive = tool;
  document.querySelectorAll('.collab-tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tool === tool));
  document.querySelectorAll('.collab-pane').forEach(p => p.style.display = p.id === `collabPane_${tool}` ? 'flex' : 'none');
  _loadCollabTool(tool);
}
window._switchCollabTool = _switchCollabTool;

function _loadCollabTool(tool) {
  const channelId = window.ECOLLAB?.currentChannelId;
  if (!channelId) return;
  switch (tool) {
    case 'notes':    loadNotes();    break;
    case 'tasks':    loadBoard();    break;
    case 'code':     loadCode();     break;
    case 'timer':    loadTimer();    break;
    case 'quiz':     loadQuizList(); break;
    case 'calendar': loadCalendar(); break;
    // Extra tools — handled by collab-extra.js (loaded after this file)
    default:
      if (window._loadExtraTool) window._loadExtraTool(tool);
      break;
  }
}

// Reload active tool when channel changes
document.addEventListener('channelSwitched', () => { if (_collabOpen) _loadCollabTool(_collabActive); });

// ─────────────────────────────────────────────────────────────────────────────
// 1. SHARED NOTES
// ─────────────────────────────────────────────────────────────────────────────
let _noteVersion = 0;
let _noteSaveTimer = null;

async function loadNotes() {
  const pane = document.getElementById('collabPane_notes');
  if (!pane) return;
  pane.innerHTML = `<div class="collab-loading"><div class="collab-spinner"></div></div>`;
  try {
    const { note } = await collabFetch('notes', 'get', {}, 'GET');
    _noteVersion = parseInt(note.version) || 0;
    pane.innerHTML = `
      <div class="notes-toolbar">
        <input id="noteTitle" class="notes-title-input" value="${escH(note.title || 'Untitled Note')}" placeholder="Note title…" />
        <button class="collab-btn-sm" onclick="saveNote(true)">💾 Save</button>
      </div>
      <textarea id="noteBody" class="notes-body" placeholder="Start writing… Markdown supported."
        oninput="_scheduleNoteSave()">${escH(note.content || '')}</textarea>
      <div id="noteSaveStatus" class="notes-status">All saved</div>`;
  } catch (e) { pane.innerHTML = `<div class="collab-err">⚠ ${escH(e.message)}</div>`; }
}

function _scheduleNoteSave() {
  const statusEl = document.getElementById('noteSaveStatus');
  if (statusEl) statusEl.textContent = 'Unsaved changes…';
  clearTimeout(_noteSaveTimer);
  _noteSaveTimer = setTimeout(() => saveNote(false), 1800);
}

async function saveNote(manual = false) {
  clearTimeout(_noteSaveTimer);
  const title   = document.getElementById('noteTitle')?.value?.trim() || 'Untitled Note';
  const content = document.getElementById('noteBody')?.value || '';
  const statusEl = document.getElementById('noteSaveStatus');
  try {
    const res = await collabFetch('notes', 'save', { title, content, version: _noteVersion });
    _noteVersion = res.version;
    if (statusEl) statusEl.textContent = 'Saved ' + new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
    if (manual && window.showToast) showToast('📝 Note saved', 'success');
  } catch (e) {
    if (statusEl) statusEl.textContent = '⚠ ' + e.message;
    if (window.showToast) showToast('⚠ ' + e.message, 'info');
  }
}
window.saveNote = saveNote;

// WS live update — another user saved the note
window._collabNotesOnUpdate = (data) => {
  if (parseInt(data.channel_id) !== parseInt(window.ECOLLAB?.currentChannelId)) return;
  if (_collabActive !== 'notes' || !_collabOpen) return;
  const bodyEl  = document.getElementById('noteBody');
  const titleEl = document.getElementById('noteTitle');
  const status  = document.getElementById('noteSaveStatus');
  if (bodyEl && bodyEl.value !== data.content) {
    if (!bodyEl.matches(':focus')) { bodyEl.value = data.content; }
    else if (status) status.textContent = `⚠ ${data.editor} saved a newer version — refresh to sync`;
  }
  if (titleEl && !titleEl.matches(':focus')) titleEl.value = data.title;
  _noteVersion = data.version;
};

// ─────────────────────────────────────────────────────────────────────────────
// 2. TASK BOARD (Kanban)
// ─────────────────────────────────────────────────────────────────────────────
let _boardData = null;

async function loadBoard() {
  const pane = document.getElementById('collabPane_tasks');
  if (!pane) return;
  pane.innerHTML = `<div class="collab-loading"><div class="collab-spinner"></div></div>`;
  try {
    const res = await collabFetch('tasks', 'get_board', {}, 'GET');
    _boardData = res;
    renderBoard(pane, res);
  } catch (e) { pane.innerHTML = `<div class="collab-err">⚠ ${escH(e.message)}</div>`; }
}

function renderBoard(pane, { board, columns }) {
  const PRIORITY_COLORS = { low:'#22c55e', medium:'#f59e0b', high:'#ef4444', urgent:'#a855f7' };
  pane.innerHTML = `
    <div class="board-header">
      <span class="board-title">📋 ${escH(board.name)}</span>
    </div>
    <div class="board-columns" id="boardColumns">
      ${columns.map(col => `
        <div class="board-col" data-col-id="${col.id}">
          <div class="board-col-header" style="border-top:3px solid ${escH(col.color)}">
            <span class="board-col-title">${escH(col.title)}</span>
            <span class="board-col-count">${col.tasks.length}</span>
          </div>
          <div class="board-tasks" id="colTasks_${col.id}"
            ondragover="event.preventDefault()" ondrop="_taskDrop(event,${col.id})">
            ${col.tasks.map(t => `
              <div class="board-task ${t.done == 1 ? 'task-done':''}"
                data-task-id="${t.id}" draggable="true"
                ondragstart="_taskDragStart(event,${t.id})"
                onclick="_openTaskDetail(${t.id},${col.id})">
                <div class="task-priority-dot" style="background:${PRIORITY_COLORS[t.priority]||'#a855f7'}"
                  title="${escH(t.priority)}"></div>
                <div class="task-title">${escH(t.title)}</div>
                ${t.due_date ? `<div class="task-due">📅 ${escH(t.due_date)}</div>` : ''}
                ${t.assignee_name ? `<div class="task-assignee">👤 ${escH(t.assignee_name)}</div>` : ''}
              </div>`).join('')}
          </div>
          <button class="board-add-task" onclick="_openAddTask(${col.id})">+ Add task</button>
        </div>`).join('')}
    </div>`;
}

let _draggingTaskId = null;
function _taskDragStart(e, taskId) { _draggingTaskId = taskId; e.dataTransfer.effectAllowed = 'move'; }
async function _taskDrop(e, toColId) {
  e.preventDefault();
  if (!_draggingTaskId) return;
  try {
    await collabFetch('tasks', 'move_task', { task_id: _draggingTaskId, to_column: toColId, position: 0 });
    loadBoard();
  } catch(err) { if (window.showToast) showToast('⚠ ' + err.message, 'info'); }
  _draggingTaskId = null;
}
window._taskDragStart = _taskDragStart;
window._taskDrop = _taskDrop;

function _openAddTask(colId) {
  const title = prompt('Task title:');
  if (!title?.trim()) return;
  collabFetch('tasks', 'add_task', { column_id: colId, title: title.trim() })
    .then(() => loadBoard())
    .catch(e => { if (window.showToast) showToast('⚠ ' + e.message, 'info'); });
}
window._openAddTask = _openAddTask;

function _openTaskDetail(taskId, colId) {
  if (!_boardData) return;
  const col  = _boardData.columns.find(c => c.id == colId);
  const task = col?.tasks.find(t => t.id == taskId);
  if (!task) return;
  const modal = document.getElementById('taskDetailModal');
  if (!modal) return;
  document.getElementById('tdTitle').value       = task.title;
  document.getElementById('tdDesc').value        = task.description || '';
  document.getElementById('tdPriority').value    = task.priority;
  document.getElementById('tdDue').value         = task.due_date || '';
  document.getElementById('tdDone').checked      = task.done == 1;
  modal.dataset.taskId = taskId;
  modal.style.display  = 'flex';
  requestAnimationFrame(() => modal.classList.add('modal-open'));
}
window._openTaskDetail = _openTaskDetail;

async function saveTaskDetail() {
  const modal  = document.getElementById('taskDetailModal');
  const taskId = parseInt(modal?.dataset.taskId);
  if (!taskId) return;
  try {
    await collabFetch('tasks', 'update_task', {
      task_id:     taskId,
      title:       document.getElementById('tdTitle').value.trim(),
      description: document.getElementById('tdDesc').value.trim(),
      priority:    document.getElementById('tdPriority').value,
      due_date:    document.getElementById('tdDue').value || null,
      done:        document.getElementById('tdDone').checked ? 1 : 0,
    });
    closeTaskDetail();
    loadBoard();
    if (window.showToast) showToast('✅ Task updated', 'success');
  } catch(e) { if (window.showToast) showToast('⚠ ' + e.message, 'info'); }
}
window.saveTaskDetail = saveTaskDetail;

async function deleteTask() {
  const modal  = document.getElementById('taskDetailModal');
  const taskId = parseInt(modal?.dataset.taskId);
  if (!taskId || !confirm('Delete this task?')) return;
  await collabFetch('tasks', 'delete_task', { task_id: taskId });
  closeTaskDetail();
  loadBoard();
}
window.deleteTask = deleteTask;

function closeTaskDetail() {
  const modal = document.getElementById('taskDetailModal');
  if (modal) { modal.classList.remove('modal-open'); setTimeout(() => modal.style.display = 'none', 220); }
}
window.closeTaskDetail = closeTaskDetail;

window._collabBoardOnUpdate = (data) => {
  if (parseInt(data.channel_id) !== parseInt(window.ECOLLAB?.currentChannelId)) return;
  if (_collabActive === 'tasks' && _collabOpen) loadBoard();
  if (window.showToast && data.actor !== window.ECOLLAB?.username)
    showToast(`📋 ${escH(data.actor)} updated the board`, 'info');
};

// ─────────────────────────────────────────────────────────────────────────────
// 3. CODE SANDBOX
// ─────────────────────────────────────────────────────────────────────────────
let _codeVersion  = 0;
let _codeSaveTimer = null;
const CODE_LANGS = ['javascript','python','php','html','css','sql','bash','json','markdown','typescript'];

async function loadCode() {
  const pane = document.getElementById('collabPane_code');
  if (!pane) return;
  pane.innerHTML = `<div class="collab-loading"><div class="collab-spinner"></div></div>`;
  try {
    const { snippet } = await collabFetch('code', 'get', {}, 'GET');
    _codeVersion = parseInt(snippet.version) || 0;
    pane.innerHTML = `
      <div class="code-toolbar">
        <input id="codeTitle" class="code-title-input" value="${escH(snippet.title || 'Untitled')}" placeholder="Snippet title…" />
        <select id="codeLang" onchange="_scheduleCodeSave()" class="code-lang-select">
          ${CODE_LANGS.map(l => `<option value="${l}" ${l === (snippet.language||'javascript') ? 'selected':''}>${l}</option>`).join('')}
        </select>
        <button class="collab-btn-sm" onclick="saveCode(true)">💾 Save</button>
        <button class="collab-btn-sm collab-btn-run" onclick="runCode()">▶ Run</button>
      </div>
      <textarea id="codeEditor" class="code-editor" spellcheck="false"
        oninput="_scheduleCodeSave()">${escH(snippet.code || '')}</textarea>
      <div class="code-output-header">
        <span>Output</span>
        <button class="collab-btn-xs" onclick="loadRunHistory()">📜 History</button>
        <span id="codeSaveStatus" class="notes-status">All saved</span>
      </div>
      <div id="codeOutput" class="code-output">— Run code to see output —</div>`;
  } catch (e) { pane.innerHTML = `<div class="collab-err">⚠ ${escH(e.message)}</div>`; }
}

function _scheduleCodeSave() {
  const s = document.getElementById('codeSaveStatus');
  if (s) s.textContent = 'Unsaved…';
  clearTimeout(_codeSaveTimer);
  _codeSaveTimer = setTimeout(() => saveCode(false), 2000);
}
window._scheduleCodeSave = _scheduleCodeSave;

async function saveCode(manual = false) {
  clearTimeout(_codeSaveTimer);
  const title = document.getElementById('codeTitle')?.value?.trim() || 'Untitled';
  const lang  = document.getElementById('codeLang')?.value || 'javascript';
  const code  = document.getElementById('codeEditor')?.value || '';
  const s     = document.getElementById('codeSaveStatus');
  try {
    const res = await collabFetch('code', 'save', { title, language: lang, code, version: _codeVersion });
    _codeVersion = res.version;
    if (s) s.textContent = 'Saved ' + new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
    if (manual && window.showToast) showToast('💾 Snippet saved', 'success');
  } catch (e) {
    if (s) s.textContent = '⚠ ' + e.message;
    if (window.showToast) showToast('⚠ ' + e.message, 'info');
  }
}
window.saveCode = saveCode;

async function runCode() {
  const lang   = document.getElementById('codeLang')?.value || 'javascript';
  const code   = document.getElementById('codeEditor')?.value || '';
  const outEl  = document.getElementById('codeOutput');
  if (outEl) outEl.textContent = '⏳ Running…';
  const t0 = Date.now();
  let output = '', error = '';
  try {
    if (lang === 'javascript') {
      // Client-side JS execution in isolated scope
      const lines = [];
      const fakeConsole = { log: (...a) => lines.push(a.map(String).join(' ')),
                            error: (...a) => lines.push('ERROR: ' + a.join(' ')),
                            warn: (...a) => lines.push('WARN: ' + a.join(' ')) };
      try {
        const fn = new Function('console', code);
        fn(fakeConsole);
        output = lines.join('\n') || '(no output)';
      } catch (e) { error = e.message; }
    } else {
      output = `▶ ${lang} execution runs server-side.\nSave the snippet and use your dev environment.`;
    }
  } catch (e) { error = e.message; }

  const dur = Date.now() - t0;
  if (outEl) {
    outEl.textContent = error ? `⚠ Error:\n${error}` : output;
    outEl.style.color = error ? '#ef4444' : '#22c55e';
  }

  // Log the run via API for the run history panel
  const snippetId = parseInt(document.querySelector('[data-snippet-id]')?.dataset.snippetId || '0');
  if (snippetId) {
    await collabFetch('code', 'log_run', { snippet_id: snippetId, output, error, duration_ms: dur }).catch(() => {});
  }
}
window.runCode = runCode;

async function loadRunHistory() {
  try {
    const { runs } = await collabFetch('code', 'run_history', {}, 'GET');
    const outEl = document.getElementById('codeOutput');
    if (!outEl) return;
    if (!runs.length) { outEl.textContent = 'No runs yet.'; return; }
    outEl.innerHTML = runs.map(r => `
      <div style="padding:4px 0;border-bottom:1px solid var(--app-border)">
        <span style="color:var(--text-muted);font-size:11px">${escH(r.username)} — ${escH(r.ran_at)}</span><br>
        <span style="color:${r.error?'#ef4444':'#22c55e'}">${escH((r.error || r.output || '').substring(0,200))}</span>
      </div>`).join('');
  } catch(e) { if (window.showToast) showToast('⚠ ' + e.message,'info'); }
}
window.loadRunHistory = loadRunHistory;

window._collabCodeOnUpdate = (data) => {
  if (parseInt(data.channel_id) !== parseInt(window.ECOLLAB?.currentChannelId)) return;
  if (_collabActive !== 'code' || !_collabOpen) return;
  if (data.editor === window.ECOLLAB?.username) return; // own save
  const editor = document.getElementById('codeEditor');
  const status = document.getElementById('codeSaveStatus');
  if (editor && !editor.matches(':focus')) {
    editor.value = data.code;
    const langSel = document.getElementById('codeLang');
    if (langSel) langSel.value = data.language;
    _codeVersion = data.version;
    if (status) status.textContent = `Updated by ${data.editor}`;
  } else if (status) {
    status.textContent = `⚠ ${data.editor} saved changes — reload to sync`;
  }
};
window._collabCodeOnRun = (data) => {
  if (parseInt(data.channel_id) !== parseInt(window.ECOLLAB?.currentChannelId)) return;
  if (data.runner !== window.ECOLLAB?.username && window.showToast)
    showToast(`▶ ${data.runner} ran the code${data.has_error ? ' (with errors)' : ''}`, 'info');
};

// ─────────────────────────────────────────────────────────────────────────────
// 4. STUDY TIMER (Pomodoro)
// ─────────────────────────────────────────────────────────────────────────────
let _timerInterval = null;
let _timerState    = null;

async function loadTimer() {
  try {
    const { timer } = await collabFetch('timer', 'get', {}, 'GET');
    _timerState = timer;
    renderTimer(timer);
  } catch (e) {
    const p = document.getElementById('collabPane_timer');
    if (p) p.innerHTML = `<div class="collab-err">⚠ ${escH(e.message)}</div>`;
  }
}

function renderTimer(t) {
  const pane = document.getElementById('collabPane_timer');
  if (!pane) return;
  const total   = (t.duration_min || 25) * 60;
  const elapsed = parseInt(t.elapsed_sec || 0) + (t.state === 'running' ? Math.floor((Date.now() - new Date(t.started_at + ' UTC').getTime()) / 1000) : 0);
  const remain  = Math.max(0, total - elapsed);
  const pct     = Math.min(100, (elapsed / total) * 100);
  const modeLabel = { focus: '🎯 Focus', short_break: '☕ Short Break', long_break: '🛋 Long Break' }[t.mode] || '🎯 Focus';
  const roundLabel = `Round ${t.round || 1} of ${t.total_rounds || 4}`;

  pane.innerHTML = `
    <div class="timer-wrap">
      <div class="timer-mode-row">
        <button class="timer-mode-btn ${t.mode==='focus'?'active':''}" onclick="_setTimerMode('focus',25)">Focus 25m</button>
        <button class="timer-mode-btn ${t.mode==='short_break'?'active':''}" onclick="_setTimerMode('short_break',5)">Short 5m</button>
        <button class="timer-mode-btn ${t.mode==='long_break'?'active':''}" onclick="_setTimerMode('long_break',15)">Long 15m</button>
      </div>
      <div class="timer-ring-wrap">
        <svg class="timer-ring" viewBox="0 0 120 120">
          <circle cx="60" cy="60" r="52" fill="none" stroke="var(--bg-tertiary)" stroke-width="8"/>
          <circle cx="60" cy="60" r="52" fill="none" stroke="var(--accent-purple)" stroke-width="8"
            stroke-dasharray="${2*Math.PI*52}" stroke-dashoffset="${2*Math.PI*52*(1-pct/100)}"
            stroke-linecap="round" transform="rotate(-90 60 60)" style="transition:stroke-dashoffset 1s linear"/>
        </svg>
        <div class="timer-display">
          <div class="timer-time" id="timerDisplay">${_fmtTimerSec(remain)}</div>
          <div class="timer-mode-label">${modeLabel}</div>
          <div class="timer-round-label">${roundLabel}</div>
        </div>
      </div>
      <div class="timer-controls">
        ${t.state === 'idle' || t.state === 'done'
          ? `<button class="timer-start-btn" onclick="timerStart()">▶ Start</button>`
          : t.state === 'running'
            ? `<button class="timer-ctrl-btn" onclick="timerPause()">⏸ Pause</button>`
            : `<button class="timer-ctrl-btn" onclick="timerResume()">▶ Resume</button>`}
        <button class="timer-reset-btn" onclick="timerReset()">↺ Reset</button>
      </div>
      <div class="timer-participants" id="timerParticipants">
        <span style="color:var(--text-muted);font-size:12px">Channel members study together</span>
      </div>
    </div>`;

  clearInterval(_timerInterval);
  if (t.state === 'running') {
    let localElapsed = elapsed;
    _timerInterval = setInterval(() => {
      localElapsed++;
      const rem = Math.max(0, total - localElapsed);
      const disp = document.getElementById('timerDisplay');
      if (disp) disp.textContent = _fmtTimerSec(rem);
      // Update ring
      const ring = pane.querySelector('.timer-ring circle:last-child');
      if (ring) {
        const p2 = Math.min(100, (localElapsed / total) * 100);
        ring.setAttribute('stroke-dashoffset', String(2*Math.PI*52*(1-p2/100)));
      }
      if (rem === 0) {
        clearInterval(_timerInterval);
        collabFetch('timer', 'complete', {}).then(() => loadTimer()).catch(() => {});
        if (window.showToast) showToast('⏰ Time\'s up!', 'success');
        // Browser notification
        if (Notification.permission === 'granted') new Notification('⏰ Ecollab Timer', { body: `${modeLabel} session complete!` });
      }
    }, 1000);
  }
}

function _fmtTimerSec(sec) {
  const m = Math.floor(sec / 60), s = sec % 60;
  return `${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
}

let _pendingTimerMode = null;
function _setTimerMode(mode, min) {
  _pendingTimerMode = { mode, min };
  document.querySelectorAll('.timer-mode-btn').forEach(b => b.classList.toggle('active', b.textContent.toLowerCase().includes(mode.replace('_',' '))));
}
window._setTimerMode = _setTimerMode;

async function timerStart() {
  const m = _pendingTimerMode || { mode: 'focus', min: 25 };
  try { await collabFetch('timer', 'start', { mode: m.mode, duration_min: m.min }); }
  catch (e) { if (window.showToast) showToast('⚠ ' + e.message, 'info'); }
}
window.timerStart  = timerStart;

async function timerPause()  { await collabFetch('timer','pause',{}).catch(e => showToast('⚠ '+e.message,'info')); }
window.timerPause  = timerPause;

async function timerResume() { await collabFetch('timer','resume',{}).catch(e => showToast('⚠ '+e.message,'info')); }
window.timerResume = timerResume;

async function timerReset() {
  clearInterval(_timerInterval);
  await collabFetch('timer','reset',{}).catch(e => showToast('⚠ '+e.message,'info'));
}
window.timerReset = timerReset;

window._collabTimerOnUpdate = (data) => {
  if (parseInt(data.channel_id) !== parseInt(window.ECOLLAB?.currentChannelId)) return;
  if (_collabActive === 'timer' && _collabOpen) loadTimer();
  if (window.showToast) {
    const labels = { collab_timer_start: `▶ ${data.actor} started the timer`,
                     collab_timer_pause: `⏸ ${data.actor} paused`, collab_timer_resume: `▶ ${data.actor} resumed`,
                     collab_timer_reset: `↺ ${data.actor} reset the timer`, collab_timer_done: '⏰ Time\'s up!' };
    if (labels[data.type]) showToast(labels[data.type], 'info');
  }
};

// Request notification permission on first load
if ('Notification' in window && Notification.permission === 'default') {
  document.addEventListener('click', () => Notification.requestPermission(), { once: true });
}

// ─────────────────────────────────────────────────────────────────────────────
// 5. QUIZ BUILDER
// ─────────────────────────────────────────────────────────────────────────────
let _activeQuizId = null;

async function loadQuizList() {
  const pane = document.getElementById('collabPane_quiz');
  if (!pane) return;
  pane.innerHTML = `<div class="collab-loading"><div class="collab-spinner"></div></div>`;
  try {
    const { quizzes } = await collabFetch('quiz', 'list', {}, 'GET');
    pane.innerHTML = `
      <div class="quiz-header">
        <span class="collab-section-title">📝 Quizzes</span>
        <button class="collab-btn-sm" onclick="openCreateQuiz()">+ Create Quiz</button>
      </div>
      <div class="quiz-list" id="quizList">
        ${quizzes.length ? quizzes.map(q => `
          <div class="quiz-card">
            <div class="quiz-card-left">
              <div class="quiz-card-title">${escH(q.title)}</div>
              <div class="quiz-card-meta">by ${escH(q.creator)} · <span class="quiz-state-badge quiz-state-${q.state}">${q.state}</span></div>
            </div>
            <div class="quiz-card-actions">
              ${q.state === 'draft' ? `<button class="collab-btn-xs" onclick="publishQuiz(${q.id})">Publish</button>` : ''}
              ${q.state === 'live'  ? `<button class="collab-btn-xs collab-btn-run" onclick="openTakeQuiz(${q.id})">Take</button>
                                       <button class="collab-btn-xs" onclick="closeQuiz(${q.id})">Close</button>` : ''}
              ${q.state === 'closed' ? `<button class="collab-btn-xs" onclick="viewResults(${q.id})">Results</button>` : ''}
            </div>
          </div>`).join('') : '<div class="collab-empty">No quizzes yet. Create one!</div>'}
      </div>`;
  } catch (e) { pane.innerHTML = `<div class="collab-err">⚠ ${escH(e.message)}</div>`; }
}

function openCreateQuiz() {
  const modal = document.getElementById('createQuizModal');
  if (modal) { modal.style.display = 'flex'; requestAnimationFrame(() => modal.classList.add('modal-open')); }
}
window.openCreateQuiz = openCreateQuiz;

function closeCreateQuiz() {
  const modal = document.getElementById('createQuizModal');
  if (modal) { modal.classList.remove('modal-open'); setTimeout(() => modal.style.display = 'none', 220); }
}
window.closeCreateQuiz = closeCreateQuiz;

function addQuizQuestion() {
  const container = document.getElementById('quizQuestionsContainer');
  if (!container) return;
  const idx = container.children.length;
  const div = document.createElement('div');
  div.className = 'quiz-question-row';
  div.innerHTML = `
    <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px">
      <span class="quiz-q-num">Q${idx+1}</span>
      <input class="collab-input" placeholder="Question…" data-field="question" style="flex:1"/>
      <select class="code-lang-select" data-field="type" onchange="_toggleQuizOptions(this)">
        <option value="mcq">MCQ</option><option value="true_false">True/False</option>
        <option value="short_answer">Short Answer</option>
      </select>
      <button class="collab-btn-xs" onclick="this.closest('.quiz-question-row').remove()" style="color:#ef4444">✕</button>
    </div>
    <div class="quiz-options-wrap" data-options-for="${idx}">
      <input class="collab-input" placeholder="Option A" data-opt="0" style="margin-bottom:4px"/>
      <input class="collab-input" placeholder="Option B" data-opt="1" style="margin-bottom:4px"/>
      <input class="collab-input" placeholder="Option C" data-opt="2" style="margin-bottom:4px"/>
      <input class="collab-input" placeholder="Option D" data-opt="3" style="margin-bottom:4px"/>
      <input class="collab-input" placeholder="Correct answer (exact text)" data-field="correct"/>
    </div>`;
  container.appendChild(div);
}
window.addQuizQuestion = addQuizQuestion;

function _toggleQuizOptions(sel) {
  const wrap = sel.closest('.quiz-question-row')?.querySelector('.quiz-options-wrap');
  if (!wrap) return;
  wrap.style.display = sel.value === 'short_answer' ? 'block' : 'grid';
}
window._toggleQuizOptions = _toggleQuizOptions;

async function submitCreateQuiz() {
  const title = document.getElementById('quizTitleInput')?.value?.trim();
  if (!title) { if (window.showToast) showToast('Quiz title required', 'info'); return; }
  const questions = [];
  document.querySelectorAll('.quiz-question-row').forEach(row => {
    const q = { question: row.querySelector('[data-field="question"]')?.value?.trim() || '',
                type: row.querySelector('[data-field="type"]')?.value || 'mcq',
                correct: row.querySelector('[data-field="correct"]')?.value?.trim() || '',
                points: 1, options: [] };
    row.querySelectorAll('[data-opt]').forEach(o => { if (o.value.trim()) q.options.push(o.value.trim()); });
    if (q.question) questions.push(q);
  });
  if (!questions.length) { if (window.showToast) showToast('Add at least one question', 'info'); return; }
  try {
    await collabFetch('quiz', 'create', { title, questions });
    closeCreateQuiz();
    loadQuizList();
    if (window.showToast) showToast('📝 Quiz created!', 'success');
  } catch(e) { if (window.showToast) showToast('⚠ ' + e.message, 'info'); }
}
window.submitCreateQuiz = submitCreateQuiz;

async function publishQuiz(qid) {
  await collabFetch('quiz','set_state',{quiz_id:qid,state:'live'});
  loadQuizList();
  if (window.showToast) showToast('🟢 Quiz is now live!','success');
}
window.publishQuiz = publishQuiz;

async function closeQuiz(qid) {
  await collabFetch('quiz','set_state',{quiz_id:qid,state:'closed'});
  loadQuizList();
}
window.closeQuiz = closeQuiz;

async function openTakeQuiz(qid) {
  const { quiz } = await collabFetch('quiz','get',{quiz_id:qid},'GET');
  _activeQuizId = qid;
  const modal = document.getElementById('takeQuizModal');
  if (!modal) return;
  modal.innerHTML = `
    <div class="modal-box" style="max-width:600px;width:92%">
      <div class="modal-header"><span>📝 ${escH(quiz.title)}</span>
        <button class="modal-close" onclick="closeTakeQuiz()">✕</button></div>
      <div class="modal-body" style="max-height:60vh;overflow-y:auto">
        ${quiz.questions.map((q,i) => `
          <div class="quiz-take-q">
            <div class="quiz-take-qtext"><strong>Q${i+1}.</strong> ${escH(q.question)}</div>
            ${q.type === 'mcq' && q.options ? JSON.parse(q.options).map(opt => `
              <label class="quiz-option-label">
                <input type="radio" name="q_${q.id}" value="${escH(opt)}"> ${escH(opt)}
              </label>`).join('') :
            q.type === 'true_false' ? `
              <label class="quiz-option-label"><input type="radio" name="q_${q.id}" value="true"> True</label>
              <label class="quiz-option-label"><input type="radio" name="q_${q.id}" value="false"> False</label>` :
            `<input class="collab-input" id="sa_${q.id}" placeholder="Your answer…">`}
          </div>`).join('')}
      </div>
      <div class="modal-footer">
        <button class="timer-start-btn" onclick="submitTakeQuiz(${JSON.stringify(quiz.questions.map(q=>q.id))})">Submit Quiz</button>
      </div>
    </div>`;
  modal.style.display = 'flex';
  requestAnimationFrame(() => modal.classList.add('modal-open'));
}
window.openTakeQuiz = openTakeQuiz;

function closeTakeQuiz() {
  const modal = document.getElementById('takeQuizModal');
  if (modal) { modal.classList.remove('modal-open'); setTimeout(() => modal.style.display='none',220); }
}
window.closeTakeQuiz = closeTakeQuiz;

async function submitTakeQuiz(questionIds) {
  const answers = {};
  questionIds.forEach(qid => {
    const radio = document.querySelector(`input[name="q_${qid}"]:checked`);
    const text  = document.getElementById(`sa_${qid}`);
    answers[qid] = radio ? radio.value : (text ? text.value.trim() : '');
  });
  try {
    const { score, max_score } = await collabFetch('quiz','submit',{quiz_id:_activeQuizId,answers});
    closeTakeQuiz();
    if (window.showToast) showToast(`✅ Score: ${score}/${max_score}`, 'success');
    loadQuizList();
  } catch(e) { if (window.showToast) showToast('⚠ ' + e.message,'info'); }
}
window.submitTakeQuiz = submitTakeQuiz;

async function viewResults(qid) {
  const { results } = await collabFetch('quiz','results',{quiz_id:qid},'GET');
  const modal = document.getElementById('takeQuizModal');
  if (!modal) return;
  modal.innerHTML = `
    <div class="modal-box" style="max-width:500px;width:92%">
      <div class="modal-header"><span>🏆 Quiz Results</span><button class="modal-close" onclick="closeTakeQuiz()">✕</button></div>
      <div class="modal-body">
        ${results.length ? results.map((r,i) => `
          <div style="display:flex;justify-content:space-between;padding:8px;border-bottom:1px solid var(--app-border)">
            <span>${i===0?'🥇':i===1?'🥈':i===2?'🥉':'  '} ${escH(r.username)}</span>
            <span style="color:var(--accent-purple);font-weight:600">${r.score}/${r.max_score}</span>
          </div>`).join('') : '<div class="collab-empty">No submissions yet.</div>'}
      </div>
      <div class="modal-footer"><button class="timer-reset-btn" onclick="closeTakeQuiz()">Close</button></div>
    </div>`;
  modal.style.display = 'flex'; requestAnimationFrame(() => modal.classList.add('modal-open'));
}
window.viewResults = viewResults;

window._collabQuizOnUpdate = (data) => {
  if (parseInt(data.channel_id) !== parseInt(window.ECOLLAB?.currentChannelId)) return;
  if (_collabActive === 'quiz' && _collabOpen) loadQuizList();
  if (window.showToast) {
    if (data.type==='collab_quiz_created') showToast(`📝 ${data.actor} created a quiz: ${data.title}`, 'info');
    if (data.type==='collab_quiz_state' && data.state==='live') showToast(`🟢 Quiz is now live — take it!`, 'info');
    if (data.type==='collab_quiz_submission') showToast(`✅ ${data.actor} submitted: ${data.score}/${data.max}`, 'info');
  }
};

// ─────────────────────────────────────────────────────────────────────────────
// 6. GROUP CALENDAR
// ─────────────────────────────────────────────────────────────────────────────
let _calYear  = new Date().getFullYear();
let _calMonth = new Date().getMonth(); // 0-indexed
let _calEvents = [];

async function loadCalendar() {
  const from = `${_calYear}-${String(_calMonth+1).padStart(2,'0')}-01`;
  const lastDay = new Date(_calYear, _calMonth+1, 0).getDate();
  const to = `${_calYear}-${String(_calMonth+1).padStart(2,'0')}-${String(lastDay).padStart(2,'0')}`;
  const pane = document.getElementById('collabPane_calendar');
  if (!pane) return;
  try {
    const { events } = await collabFetch(`calendar`, `list&from=${from}&to=${to}`, {}, 'GET');
    _calEvents = events;
    renderCalendar(pane, events);
  } catch (e) { pane.innerHTML = `<div class="collab-err">⚠ ${escH(e.message)}</div>`; }
}

function renderCalendar(pane, events) {
  const TYPE_COLORS = { study:'#3b82f6',deadline:'#ef4444',meeting:'#a855f7',exam:'#f59e0b',social:'#22c55e',other:'#64748b' };
  const monthName   = new Date(_calYear, _calMonth, 1).toLocaleString('default', { month:'long', year:'numeric' });
  const firstDow    = new Date(_calYear, _calMonth, 1).getDay();
  const daysInMonth = new Date(_calYear, _calMonth+1, 0).getDate();

  const byDay = {};
  events.forEach(e => {
    const d = new Date(e.start_time).getDate();
    if (!byDay[d]) byDay[d] = [];
    byDay[d].push(e);
  });

  let cells = '';
  for (let i = 0; i < firstDow; i++) cells += `<div class="cal-cell cal-empty"></div>`;
  for (let d = 1; d <= daysInMonth; d++) {
    const isToday = new Date().getDate() === d && new Date().getMonth() === _calMonth && new Date().getFullYear() === _calYear;
    const dayEvents = byDay[d] || [];
    cells += `
      <div class="cal-cell ${isToday?'cal-today':''}" onclick="_openDayView(${d})">
        <span class="cal-day-num">${d}</span>
        ${dayEvents.slice(0,3).map(e => `
          <div class="cal-event-chip" style="background:${TYPE_COLORS[e.type]||'#a855f7'}20;border-left:2px solid ${TYPE_COLORS[e.type]||'#a855f7'}"
            title="${escH(e.title)}">${escH(e.title.substring(0,18))}</div>`).join('')}
        ${dayEvents.length > 3 ? `<div class="cal-more">+${dayEvents.length-3} more</div>` : ''}
      </div>`;
  }

  pane.innerHTML = `
    <div class="cal-toolbar">
      <button class="collab-btn-xs" onclick="_calNav(-1)">‹</button>
      <span class="cal-month-label">${monthName}</span>
      <button class="collab-btn-xs" onclick="_calNav(1)">›</button>
      <button class="collab-btn-sm" onclick="openCreateEvent()" style="margin-left:auto">+ Event</button>
    </div>
    <div class="cal-grid">
      ${['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].map(d=>`<div class="cal-dow">${d}</div>`).join('')}
      ${cells}
    </div>`;
}

function _calNav(dir) {
  _calMonth += dir;
  if (_calMonth > 11) { _calMonth = 0; _calYear++; }
  if (_calMonth < 0)  { _calMonth = 11; _calYear--; }
  loadCalendar();
}
window._calNav = _calNav;

function _openDayView(day) {
  const dayEvents = _calEvents.filter(e => new Date(e.start_time).getDate() === day);
  if (!dayEvents.length) { openCreateEventForDay(day); return; }
  const modal = document.getElementById('calEventModal');
  if (!modal) return;
  const TYPE_COLORS = { study:'#3b82f6',deadline:'#ef4444',meeting:'#a855f7',exam:'#f59e0b',social:'#22c55e',other:'#64748b' };
  modal.innerHTML = `
    <div class="modal-box" style="max-width:480px;width:92%">
      <div class="modal-header">
        <span>📅 ${new Date(_calYear,_calMonth,day).toDateString()}</span>
        <button class="modal-close" onclick="closeCalModal()">✕</button>
      </div>
      <div class="modal-body">
        ${dayEvents.map(e => `
          <div class="cal-event-detail" style="border-left:3px solid ${TYPE_COLORS[e.type]||'#a855f7'}">
            <div class="cal-event-title">${escH(e.title)}</div>
            <div class="cal-event-meta">${escH(new Date(e.start_time).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'}))} — ${escH(e.creator)} · ${e.going_count} going</div>
            ${e.description ? `<div class="cal-event-desc">${escH(e.description)}</div>` : ''}
            <div style="margin-top:6px;display:flex;gap:6px">
              <button class="collab-btn-xs collab-btn-run" onclick="rsvpEvent(${e.id},'going')">✓ Going</button>
              <button class="collab-btn-xs" onclick="rsvpEvent(${e.id},'maybe')">? Maybe</button>
              <button class="collab-btn-xs" style="color:#ef4444" onclick="rsvpEvent(${e.id},'not_going')">✕ Can't</button>
            </div>
          </div>`).join('')}
      </div>
      <div class="modal-footer">
        <button class="collab-btn-sm" onclick="openCreateEventForDay(${day})">+ Add Event</button>
        <button class="timer-reset-btn" onclick="closeCalModal()">Close</button>
      </div>
    </div>`;
  modal.style.display = 'flex'; requestAnimationFrame(() => modal.classList.add('modal-open'));
}
window._openDayView = _openDayView;

function openCreateEvent() { openCreateEventForDay(new Date().getDate()); }
window.openCreateEvent = openCreateEvent;

function openCreateEventForDay(day) {
  const modal = document.getElementById('calEventModal');
  if (!modal) return;
  const dateStr = `${_calYear}-${String(_calMonth+1).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
  modal.innerHTML = `
    <div class="modal-box" style="max-width:440px;width:92%">
      <div class="modal-header"><span>📅 New Event</span><button class="modal-close" onclick="closeCalModal()">✕</button></div>
      <div class="modal-body">
        <input class="collab-input" id="evTitle" placeholder="Event title*" style="margin-bottom:8px"/>
        <select class="code-lang-select" id="evType" style="margin-bottom:8px;width:100%">
          <option value="study">📚 Study Session</option><option value="deadline">⚠ Deadline</option>
          <option value="meeting">👥 Meeting</option><option value="exam">📋 Exam</option>
          <option value="social">🎉 Social</option><option value="other">📌 Other</option>
        </select>
        <input class="collab-input" id="evStart" type="datetime-local" value="${dateStr}T09:00" style="margin-bottom:8px"/>
        <input class="collab-input" id="evEnd"   type="datetime-local" value="${dateStr}T10:00" style="margin-bottom:8px"/>
        <textarea class="collab-input" id="evDesc" placeholder="Description (optional)" rows="3" style="resize:vertical"></textarea>
      </div>
      <div class="modal-footer">
        <button class="timer-start-btn" onclick="submitCreateEvent()">Create Event</button>
        <button class="timer-reset-btn" onclick="closeCalModal()">Cancel</button>
      </div>
    </div>`;
  modal.style.display = 'flex'; requestAnimationFrame(() => modal.classList.add('modal-open'));
}
window.openCreateEventForDay = openCreateEventForDay;

async function submitCreateEvent() {
  const title = document.getElementById('evTitle')?.value?.trim();
  if (!title) { if (window.showToast) showToast('Title required','info'); return; }
  try {
    await collabFetch('calendar','create',{
      title, type: document.getElementById('evType')?.value || 'study',
      start_time: document.getElementById('evStart')?.value?.replace('T',' ') || '',
      end_time:   document.getElementById('evEnd')?.value?.replace('T',' ') || '',
      description: document.getElementById('evDesc')?.value?.trim() || '',
    });
    closeCalModal();
    loadCalendar();
    if (window.showToast) showToast('📅 Event created!','success');
  } catch(e) { if (window.showToast) showToast('⚠ '+e.message,'info'); }
}
window.submitCreateEvent = submitCreateEvent;

async function rsvpEvent(eventId, status) {
  await collabFetch('calendar','rsvp',{event_id:eventId, status});
  closeCalModal();
  loadCalendar();
  const labels = {going:'✓ You\'re going!', maybe:'? Marked as maybe', not_going:'✕ Can\'t make it'};
  if (window.showToast) showToast(labels[status],'success');
}
window.rsvpEvent = rsvpEvent;

function closeCalModal() {
  const modal = document.getElementById('calEventModal');
  if (modal) { modal.classList.remove('modal-open'); setTimeout(()=>modal.style.display='none',220); }
}
window.closeCalModal = closeCalModal;

window._collabCalendarOnUpdate = (data) => {
  if (parseInt(data.channel_id) !== parseInt(window.ECOLLAB?.currentChannelId)) return;
  if (_collabActive === 'calendar' && _collabOpen) loadCalendar();
  if (window.showToast && data.actor !== window.ECOLLAB?.username) {
    if (data.type==='collab_event_created') showToast(`📅 ${data.actor} added: ${data.title}`,'info');
  }
};
