/**
 * chat-features.js — Full-featured chat functionality for Ecollab
 * Adds: emoji picker, GIF picker, voice recording, whiteboard, nav views,
 * active now modal, members modal, matches modal, poll creation, search overlay,
 * platform settings, voice channel interactions, mini profile, and more.
 * Based on chat-sample4.html feature set, integrated with Ecollab backend.
 */

'use strict';

// ═══════════════════════════════════════════════════════
// GIF PICKER
// ═══════════════════════════════════════════════════════
const gifEmojis = ['🎉', '🔥', '😂', '😍', '🤔', '👏', '💯', '🙌', '🤣', '😭', '🎊', '✨', '💪', '🎯', '⚡', '🌟', '🚀', '💥', '🎵', '🎶'];

function populateGifPicker() {
  const grid = document.getElementById('gifGrid');
  if (!grid || grid.children.length) return;
  gifEmojis.forEach(emoji => {
    const item = document.createElement('div');
    item.className = 'gif-item';
    item.textContent = emoji;
    item.title = 'Insert GIF';
    item.onclick = () => { insertEmoji(emoji); closeGifPicker(); };
    grid.appendChild(item);
  });
}

function toggleGifPicker(e) {
  if (e) e.stopPropagation();
  if (window.closeEmojiPicker) window.closeEmojiPicker();
  closeAttachMenu();
  closeExtrasMenu();
  const picker = document.getElementById('gifPicker');
  if (!picker) return;
  picker.classList.toggle('open');
  if (picker.classList.contains('open')) populateGifPicker();
  const btn = document.getElementById('gifBtn');
  if (btn) btn.classList.toggle('active', picker.classList.contains('open'));
}

function closeGifPicker() {
  const picker = document.getElementById('gifPicker');
  if (picker) picker.classList.remove('open');
  const btn = document.getElementById('gifBtn');
  if (btn) btn.classList.remove('active');
}

function filterGifs(query) {
  const grid = document.getElementById('gifGrid');
  if (!grid) return;
  grid.innerHTML = '';
  const filtered = query ? ['🔍', '✨', '🎯', '⚡', '💡', '🔥', '😂', '🎉'] : gifEmojis;
  filtered.forEach(emoji => {
    const item = document.createElement('div');
    item.className = 'gif-item';
    item.textContent = emoji;
    item.onclick = () => { insertEmoji(emoji); closeGifPicker(); };
    grid.appendChild(item);
  });
}

function insertEmoji(emoji) {
  const input = document.getElementById('chatInputField');
  if (!input) return;
  const pos = input.selectionStart ?? input.value.length;
  input.value = input.value.substring(0, pos) + emoji + input.value.substring(pos);
  input.focus();
  input.selectionStart = input.selectionEnd = pos + [...emoji].length;
}

// ═══════════════════════════════════════════════════════
// VOICE RECORDING
// ═══════════════════════════════════════════════════════
let recState = 'idle';
let recInterval = null;
let recSeconds = 0;
let recWaveInterval = null;
let recDuration = 0;
let previewInterval = null;
let previewCurrentSec = 0;
let previewPlaying = false;

function toggleVoiceRecord() {
  if (recState === 'idle') startRecording();
  else if (recState === 'recording') stopRecordingToPreview();
}

function startRecording() {
  recState = 'recording';
  recSeconds = 0;
  recDuration = 0;
  const recordBar = document.getElementById('voiceRecordBar');
  const previewBar = document.getElementById('voicePreviewBar');
  if (recordBar) recordBar.style.display = 'flex';
  if (previewBar) previewBar.style.display = 'none';
  const micBtn = document.getElementById('micBtn');
  if (micBtn) { micBtn.style.color = '#ef4444'; micBtn.title = 'Stop recording'; }
  const timerEl = document.getElementById('recTimer');
  if (timerEl) timerEl.textContent = '0:00';
  recInterval = setInterval(() => {
    recSeconds++;
    if (timerEl) timerEl.textContent = _fmtDur(recSeconds);
  }, 1000);
  const wf = document.getElementById('recWaveform');
  if (wf) {
    wf.innerHTML = '';
    for (let i = 0; i < 32; i++) {
      const b = document.createElement('div');
      b.style.cssText = 'width:3px;border-radius:2px;background:#ef4444;opacity:0.75;flex-shrink:0;transition:height 0.1s ease;height:4px;';
      wf.appendChild(b);
    }
    recWaveInterval = setInterval(() => {
      wf.querySelectorAll('div').forEach(b => {
        b.style.height = (Math.random() * 20 + 2) + 'px';
        b.style.opacity = (Math.random() * 0.4 + 0.6).toFixed(2);
      });
    }, 100);
  }
  if (window.showToast) showToast('🔴 Recording…', 'info');
}

function stopRecordingToPreview() {
  if (recState !== 'recording') return;
  recDuration = recSeconds;
  clearInterval(recInterval); clearInterval(recWaveInterval);
  recInterval = null; recWaveInterval = null;
  recState = 'preview';
  const recordBar = document.getElementById('voiceRecordBar');
  const previewBar = document.getElementById('voicePreviewBar');
  if (recordBar) recordBar.style.display = 'none';
  if (previewBar) previewBar.style.display = 'flex';
  const micBtn = document.getElementById('micBtn');
  if (micBtn) { micBtn.style.color = ''; micBtn.title = 'Voice message'; }
  const durEl = document.getElementById('previewDuration');
  if (durEl) durEl.textContent = _fmtDur(recDuration);
  document.getElementById('previewCurrentTime') && (document.getElementById('previewCurrentTime').textContent = '0:00');
  document.getElementById('previewProgress') && (document.getElementById('previewProgress').style.width = '0%');
  _drawPreviewWave();
  previewCurrentSec = 0; previewPlaying = false; _setPlayIcon(false);
  if (window.showToast) showToast('✅ Recording stopped — preview before sending', 'info');
}

function togglePreviewPlay() {
  if (!previewPlaying) _startPreviewPlay(); else _pausePreviewPlay();
}

function _startPreviewPlay() {
  if (previewCurrentSec >= recDuration) previewCurrentSec = 0;
  previewPlaying = true; _setPlayIcon(true);
  previewInterval = setInterval(() => {
    previewCurrentSec += 0.1;
    if (previewCurrentSec >= recDuration) {
      previewCurrentSec = recDuration; _pausePreviewPlay(); return;
    }
    const pct = (previewCurrentSec / recDuration) * 100;
    const prog = document.getElementById('previewProgress');
    if (prog) prog.style.width = pct + '%';
    const ct = document.getElementById('previewCurrentTime');
    if (ct) ct.textContent = _fmtDur(Math.floor(previewCurrentSec));
  }, 100);
}

function _pausePreviewPlay() {
  previewPlaying = false; clearInterval(previewInterval); _setPlayIcon(false);
}

function scrubPreview(e, el) {
  const rect = el.getBoundingClientRect();
  const pct = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
  previewCurrentSec = pct * recDuration;
  const prog = document.getElementById('previewProgress');
  if (prog) prog.style.width = (pct * 100) + '%';
  const ct = document.getElementById('previewCurrentTime');
  if (ct) ct.textContent = _fmtDur(Math.floor(previewCurrentSec));
  if (previewPlaying) { clearInterval(previewInterval); _startPreviewPlay(); }
}

function _setPlayIcon(playing) {
  const icon = document.getElementById('previewPlayIcon');
  if (!icon) return;
  icon.innerHTML = playing
    ? '<rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/>'
    : '<path d="M8 5v14l11-7z"/>';
}

function _drawPreviewWave() {
  const wf = document.getElementById('previewWaveStatic');
  if (!wf) return;
  wf.innerHTML = '';
  for (let i = 0; i < 48; i++) {
    const b = document.createElement('div');
    const h = Math.random() * 18 + 3;
    b.style.cssText = `width:3px;border-radius:2px;background:rgba(168,85,247,0.35);flex-shrink:0;height:${h}px;`;
    wf.appendChild(b);
  }
}

function discardRecording() {
  _pausePreviewPlay(); _fullResetRec();
  if (window.showToast) showToast('🗑️ Recording discarded', 'info');
}

function reRecord() {
  _pausePreviewPlay(); _fullResetRec(); startRecording();
}

function cancelRecording() {
  clearInterval(recInterval); clearInterval(recWaveInterval);
  _fullResetRec();
  if (window.showToast) showToast('❌ Recording cancelled', 'info');
}

function sendRecording() {
  _pausePreviewPlay();
  const duration = recDuration || recSeconds;
  _fullResetRec();
  const area = document.getElementById('messagesArea');
  const typing = document.getElementById('typingIndicator');
  if (!area) return;
  const msgEl = document.createElement('div');
  msgEl.className = 'message-group';
  const user = window.ECOLLAB || {};
  const grad = user.avatarGradient || '#a855f7,#ec4899';
  const [c1, c2] = grad.split(',');
  const init = (user.username || 'Me')[0].toUpperCase();
  msgEl.innerHTML = `
    <div class="msg-avatar">
      <div class="avatar-placeholder" style="width:36px;height:36px;font-size:14px;border-radius:50%;background:linear-gradient(135deg,${c1},${c2});display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;position:relative;">${init}<div class="online-dot"></div></div>
    </div>
    <div class="msg-content">
      <div class="msg-header">
        <span class="msg-username student2-name">${escHtml ? escHtml(user.username || 'Me') : (user.username || 'Me')}</span>
        <span class="msg-timestamp">${_getNow()}</span>
      </div>
      <div style="display:inline-flex;align-items:center;gap:10px;background:var(--bg-card);border:1px solid var(--border);border-radius:99px;padding:8px 16px;margin-top:4px;min-width:200px;max-width:320px;">
        <button onclick="_toggleChatVoicePlay(this)" style="width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,#a855f7,#ec4899);border:none;color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 2px 8px rgba(168,85,247,0.35);">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
        </button>
        <div style="flex:1;position:relative;height:20px;">
          <div style="position:absolute;top:50%;left:0;right:0;height:3px;background:rgba(255,255,255,0.1);border-radius:99px;transform:translateY(-50%);">
            <div class="chat-voice-progress" style="height:100%;width:0%;background:linear-gradient(135deg,#a855f7,#ec4899);border-radius:99px;transition:width 0.25s linear;"></div>
          </div>
        </div>
        <span style="font-size:11px;color:var(--text-muted);font-weight:500;flex-shrink:0;">${_fmtDur(duration)}</span>
        <span style="font-size:13px;">🎤</span>
      </div>
      <div class="reactions" style="margin-top:6px;"><div class="reaction-add" onclick="showEmojiForMsgBar(event,this)">😊</div></div>
    </div>`;
  if (typing && typing.parentNode === area) area.insertBefore(msgEl, typing);
  else area.appendChild(msgEl);
  area.scrollTop = area.scrollHeight;
  if (window.showToast) showToast('🎤 Voice message sent!', 'info');
}

function _toggleChatVoicePlay(btn) {
  const bubble = btn.closest('div[style*="inline-flex"]');
  const progress = bubble.querySelector('.chat-voice-progress');
  const svg = btn.querySelector('svg');
  const playing = btn.dataset.playing === '1';
  if (playing) {
    btn.dataset.playing = '0'; svg.innerHTML = '<path d="M8 5v14l11-7z"/>';
    if (btn._interval) clearInterval(btn._interval);
  } else {
    btn.dataset.playing = '1'; svg.innerHTML = '<rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/>';
    let pct = parseFloat(progress?.style.width) || 0;
    if (pct >= 100) pct = 0;
    btn._interval = setInterval(() => {
      pct += 2;
      if (progress) progress.style.width = Math.min(pct, 100) + '%';
      if (pct >= 100) { clearInterval(btn._interval); btn.dataset.playing = '0'; svg.innerHTML = '<path d="M8 5v14l11-7z"/>'; }
    }, 80);
  }
}

function _fullResetRec() {
  recState = 'idle'; recSeconds = 0; recDuration = 0; previewCurrentSec = 0; previewPlaying = false;
  clearInterval(previewInterval); clearInterval(recInterval); clearInterval(recWaveInterval);
  previewInterval = null; recInterval = null; recWaveInterval = null;
  const rb = document.getElementById('voiceRecordBar'); if (rb) rb.style.display = 'none';
  const pb = document.getElementById('voicePreviewBar'); if (pb) pb.style.display = 'none';
  const mb = document.getElementById('micBtn'); if (mb) { mb.style.color = ''; mb.title = 'Voice message'; }
  const rt = document.getElementById('recTimer'); if (rt) rt.textContent = '0:00';
}

function _fmtDur(secs) {
  const m = Math.floor(secs / 60);
  const s = String(Math.floor(secs % 60)).padStart(2, '0');
  return `${m}:${s}`;
}

function _getNow() {
  const d = new Date();
  let h = d.getHours(), m = d.getMinutes();
  const ap = h >= 12 ? 'PM' : 'AM';
  h = h % 12 || 12;
  return `${h}:${m.toString().padStart(2, '0')} ${ap}`;
}

// ═══════════════════════════════════════════════════════
// ═══════════════════════════════════════════════════════
// REPORT MESSAGE
// ═══════════════════════════════════════════════════════
function openReportModal(msgId) {
  const el = document.getElementById('reportMsgId');
  if (el) el.value = msgId;
  // Reset form
  const radios = document.querySelectorAll('input[name="reportReason"]');
  radios.forEach(r => { r.checked = r.value === 'other'; });
  const desc = document.getElementById('reportDescription');
  if (desc) desc.value = '';
  if (window.openModal) openModal('reportModal');
}

async function submitReport() {
  const msgId = parseInt(document.getElementById('reportMsgId')?.value);
  if (!msgId) return;
  const reason = document.querySelector('input[name="reportReason"]:checked')?.value || 'other';
  const description = document.getElementById('reportDescription')?.value?.trim() || '';

  try {
    const data = await window.apiFetch(`${(window.ECOLLAB?.baseUrl || '')}/API/chat/report-message.php`, {
      method: 'POST',
      body: JSON.stringify({ message_id: msgId, reason, description }),
    });
    if (window.closeModal) closeModal('reportModal');
    showToast('🚩 Report submitted. Our team will review it.', 'success');
  } catch (err) {
    showToast(err.message || 'Failed to submit report', 'info');
  }
}

// POLL CREATION
// ═══════════════════════════════════════════════════════
function addPollOption() {
  const container = document.getElementById('pollOptions');
  if (!container) return;
  const count = container.querySelectorAll('.poll-opt').length + 1;
  if (count > 6) { if (window.showToast) showToast('Maximum 6 options allowed', 'info'); return; }
  const inp = document.createElement('input');
  inp.type = 'text'; inp.className = 'poll-opt';
  inp.placeholder = `Option ${count}`;
  inp.style.cssText = "width:100%;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:6px;padding:8px 12px;font-size:14px;color:var(--text-primary);outline:none;font-family:'Inter',sans-serif;";
  container.appendChild(inp);
}

async function submitPoll() {
  const q = document.getElementById('pollQuestion')?.value?.trim();
  if (!q) { if (window.showToast) showToast('Please enter a poll question', 'info'); return; }
  const opts = Array.from(document.querySelectorAll('.poll-opt')).map(i => i.value.trim()).filter(Boolean);
  if (opts.length < 2) { if (window.showToast) showToast('Please add at least 2 options', 'info'); return; }
  if (opts.length > 6) { if (window.showToast) showToast('Maximum 6 options allowed', 'info'); return; }

  const channelId = window.ECOLLAB?.currentChannelId;
  if (!channelId) { showToast('No channel selected', 'info'); return; }

  if (window.closeModal) closeModal('pollModal');

  try {
    const data = await window.apiFetch(`${(window.ECOLLAB?.baseUrl || '')}/API/chat/send-message.php`, {
      method: 'POST',
      body: JSON.stringify({
        channel_id: channelId,
        content: q,
        content_type: 'poll',
        poll_question: q,
        poll_options: opts,
      }),
    });
    if (data.success) {
      // appendMessageToUI handles rendering via buildMessageElement
      if (window.appendMessageToUI) window.appendMessageToUI(data.message);
      showToast('📊 Poll posted!', 'success');
    }
  } catch (err) {
    showToast(err.message || 'Failed to post poll', 'info');
  }

  document.getElementById('pollQuestion') && (document.getElementById('pollQuestion').value = '');
  const po = document.getElementById('pollOptions');
  if (po) po.innerHTML = `
    <input type="text" class="poll-opt" placeholder="Option 1" style="width:100%;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:6px;padding:8px 12px;font-size:14px;color:var(--text-primary);outline:none;font-family:'Inter',sans-serif;">
    <input type="text" class="poll-opt" placeholder="Option 2" style="width:100%;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:6px;padding:8px 12px;font-size:14px;color:var(--text-primary);outline:none;font-family:'Inter',sans-serif;">`;
}

// Re-render poll bars from live data (called after voting)
function updatePollUI(msgId, pollData) {
  const msgEl = document.querySelector(`.message-group[data-msg-id="${msgId}"]`);
  if (!msgEl) return;
  const pollEl = msgEl.querySelector('.poll-widget');
  if (!pollEl) return;
  const total = pollData.total_votes || 0;
  pollEl.querySelectorAll('.poll-option-row').forEach(row => {
    const optId = parseInt(row.dataset.optionId);
    const opt = pollData.options.find(o => o.id === optId);
    if (!opt) return;
    const pct = total > 0 ? Math.round((opt.vote_count / total) * 100) : 0;
    row.querySelector('.poll-bar-fill').style.width = pct + '%';
    row.querySelector('.poll-pct').textContent = pct + '%';
    const isMyVote = pollData.my_vote === optId;
    row.classList.toggle('my-vote', isMyVote);
  });
  const footer = pollEl.querySelector('.poll-footer');
  if (footer) footer.textContent = `${total} vote${total !== 1 ? 's' : ''} • Click to vote`;
}

async function castPollVote(msgId, pollId, optionId) {
  try {
    const data = await window.apiFetch(`${(window.ECOLLAB?.baseUrl || '')}/API/chat/vote-poll.php`, {
      method: 'POST',
      body: JSON.stringify({ poll_id: pollId, option_id: optionId }),
    });
    if (data.success) updatePollUI(msgId, data);
  } catch (err) {
    showToast(err.message || 'Vote failed', 'info');
  }
}

// ═══════════════════════════════════════════════════════
// SEARCH OVERLAY
// ═══════════════════════════════════════════════════════
function openSearchModal() {
  if (window.openModal) openModal('searchOverlay');
  setTimeout(() => document.getElementById('searchInput')?.focus(), 100);
}

function closeSearchOverlay(event) {
  if (event.target === document.getElementById('searchOverlay')) {
    if (window.closeModal) closeModal('searchOverlay');
  }
}

function searchMessages(query) {
  const resultsEl = document.getElementById('searchResults');
  if (!resultsEl) return;
  if (!query.trim()) {
    resultsEl.innerHTML = '<div style="text-align:center;color:var(--text-muted);font-size:13px;padding:20px 0;">Type to search messages in this channel...</div>';
    return;
  }
  // Search visible messages in the DOM
  const q = query.toLowerCase();
  const msgs = [];
  document.querySelectorAll('.message-group').forEach(mg => {
    const textEl = mg.querySelector('.msg-text');
    const authorEl = mg.querySelector('.msg-username');
    const timeEl = mg.querySelector('.msg-timestamp');
    if (!textEl) return;
    const text = textEl.textContent || '';
    const author = authorEl?.textContent || '';
    if (text.toLowerCase().includes(q) || author.toLowerCase().includes(q)) {
      msgs.push({ text, author, time: timeEl?.textContent || '' });
    }
  });
  if (!msgs.length) {
    resultsEl.innerHTML = '<div style="text-align:center;color:var(--text-muted);font-size:13px;padding:20px 0;">No messages found.</div>';
    return;
  }
  resultsEl.innerHTML = msgs.map(m => `
    <div style="padding:10px;background:var(--bg-tertiary);border-radius:8px;margin-bottom:8px;cursor:pointer;border:1px solid var(--border);">
      <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px;">${m.author} · ${m.time}</div>
      <div style="font-size:13px;color:var(--text-secondary);">${m.text.replace(new RegExp(query, 'gi'), '<mark style="background:rgba(168,85,247,0.3);color:#fff;border-radius:2px;padding:0 1px;">$&</mark>')}</div>
    </div>`).join('');
}

// ═══════════════════════════════════════════════════════
// ACTIVE NOW MODAL
// ═══════════════════════════════════════════════════════
let _activeTab = 'all';
let _activeSearch = '';
let _activeMembersData = [];
let _activeNowPollTimer = null;

// ── Fetch real online users from server ───────────────────────────────────
async function _fetchActiveNow() {
  const serverId = window.ECOLLAB?.currentServerId;
  if (!serverId) return;
  try {
    const base = window.ECOLLAB?.baseUrl || '';
    const res = await fetch(`${base}/API/chat/active-now.php?server_id=${serverId}&_=${Date.now()}`);
    if (!res.ok) return;
    const data = await res.json();
    if (data.success && Array.isArray(data.users)) {
      _activeMembersData = data.users;

      // Update count badge
      const badge = document.getElementById('activeNowCountBadge');
      if (badge) badge.textContent = data.count;

      // Render sidebar mini-list (show top 5)
      const sideList = document.getElementById('activeMembersList');
      if (sideList) {
        const top = _activeMembersData.slice(0, 5);
        sideList.innerHTML = top.map(u => `
          <div onclick="openMiniProfile(event,'${_esc(u.name)}','${_esc(u.role || '')}','${_esc(u.grad || '')}','${_esc(String(u.name[0]).toUpperCase())}',${u.id || 0})"
            style="display:flex;align-items:center;gap:8px;padding:5px 6px;border-radius:7px;cursor:pointer;transition:background 0.1s;"
            onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background=''">
            <div style="position:relative;flex-shrink:0;">
              <div style="width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,${u.grad || '#3b82f6,#6366f1'});display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#fff;">${u.name[0].toUpperCase()}</div>
              <div style="position:absolute;bottom:0;right:0;width:9px;height:9px;border-radius:50%;border:2px solid var(--bg-secondary);background:${u.status === 'voice' ? '#22c55e' : u.status === 'idle' ? '#f59e0b' : '#22c55e'};"></div>
            </div>
            <div style="flex:1;min-width:0;">
              <div style="font-size:12px;font-weight:600;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${_esc(u.name)}</div>
              <div style="font-size:10px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${u.status === 'voice' ? '🎙 Voice' : u.status === 'idle' ? 'Idle' : 'Active'}</div>
            </div>
          </div>`).join('') || '<div style="color:var(--text-muted);font-size:12px;padding:8px 4px;">No one active yet</div>';
      }

      // Re-render modal if open
      if (document.getElementById('activeNowModal')?.style.display !== 'none') {
        _renderActiveNowList();
      }

      // Update sidebar online dots
      _activeMembersData.forEach(u => {
        const dots = document.querySelectorAll(`[data-user-id="${u.id}"] .online-dot`);
        dots.forEach(d => {
          d.style.background = u.status === 'voice' ? '#22c55e' :
            u.online ? '#22c55e' : '#64748b';
        });
      });
    }
  } catch { }
}

function _startActiveNowPolling() {
  if (_activeNowPollTimer) return;
  _fetchActiveNow();
  _activeNowPollTimer = setInterval(_fetchActiveNow, 8000); // poll every 8 seconds

  // Heartbeat to keep self online
  setInterval(() => {
    const serverId = window.ECOLLAB?.currentServerId;
    if (!serverId) return;
    const base = window.ECOLLAB?.baseUrl || '';
    fetch(`${base}/API/chat/active-now.php?action=heartbeat&server_id=${serverId}`, { method: 'GET' }).catch(() => { });
  }, 30000);
}

// Start polling when page loads
setTimeout(_startActiveNowPolling, 1500);

function openActiveNowModal() {
  _activeTab = 'all'; _activeSearch = '';
  const inp = document.getElementById('activeNowSearch');
  if (inp) inp.value = '';
  document.querySelectorAll('#activeNowTabs .filter-tab').forEach((t, i) => t.classList.toggle('active', i === 0));
  _fetchActiveNow().then(() => _renderActiveNowList());
  if (window.openModal) openModal('activeNowModal');
}

function _renderActiveNowList() {
  const container = document.getElementById('activeNowList');
  if (!container) return;
  const source = _activeMembersData.length ? _activeMembersData : _getPlaceholderActiveUsers();
  let data = source;
  if (_activeTab !== 'all') data = data.filter(u => u.status === _activeTab);
  if (_activeSearch) data = data.filter(u => u.name.toLowerCase().includes(_activeSearch) || (u.role || '').toLowerCase().includes(_activeSearch));
  if (!data.length) { container.innerHTML = '<div style="text-align:center;color:var(--text-muted);padding:24px;font-size:13px;">No members found</div>'; return; }
  container.innerHTML = data.map(u => `
    <div onclick="openMiniProfile(event,'${_esc(u.name)}','${_esc(u.role || '')}','${_esc(u.grad || '')}','${_esc(String(u.name[0]).toUpperCase())}',${u.id || 0})"
      style="display:flex;align-items:center;gap:12px;padding:9px 10px;border-radius:9px;cursor:pointer;transition:background 0.12s;${u.is_me ? 'opacity:0.7;' : ''}"
      onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background=''">
      <div style="position:relative;flex-shrink:0;">
        <div style="width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,${u.grad || '#3b82f6,#6366f1'});display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:700;color:#fff;">${u.name[0].toUpperCase()}</div>
        <div style="position:absolute;bottom:0;right:0;width:11px;height:11px;border-radius:50%;border:2px solid var(--bg-secondary);background:${u.status === 'voice' ? '#22c55e' : u.status === 'idle' ? '#f59e0b' : '#22c55e'};"></div>
      </div>
      <div style="flex:1;min-width:0;">
        <div style="font-size:13px;font-weight:600;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${_esc(u.name)}${u.is_me ? ' <span style="font-size:10px;opacity:0.5;">(you)</span>' : ''}</div>
        <div style="font-size:11px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${_esc(u.role || '')}</div>
      </div>
      <div style="font-size:11px;font-weight:600;color:${u.status === 'voice' ? '#22c55e' : u.status === 'idle' ? '#f59e0b' : '#a855f7'};background:${u.status === 'voice' ? 'rgba(34,197,94,0.1)' : u.status === 'idle' ? 'rgba(245,158,11,0.1)' : 'rgba(168,85,247,0.1)'};padding:3px 8px;border-radius:99px;white-space:nowrap;">
        ${u.status === 'voice' ? '🎙 Voice' : u.status === 'idle' ? '🟡 Idle' : '📚 Study'}
      </div>
    </div>`).join('');
}

function switchActiveTab(btn, tab) {
  _activeTab = tab;
  document.querySelectorAll('#activeNowTabs .filter-tab').forEach(t => t.classList.remove('active'));
  btn.classList.add('active');
  _renderActiveNowList();
}

function filterActiveNow(val) {
  _activeSearch = val.toLowerCase();
  _renderActiveNowList();
}

function _getPlaceholderActiveUsers() {
  return [
    { name: 'John Doe', role: 'In Study Lounge', status: 'study', online: true, grad: '#3b82f6,#6366f1' },
    { name: 'Sara Kim', role: 'In Code Review Room', status: 'study', online: true, grad: '#0d9488,#06b6d4' },
    { name: 'Fatima_Student', role: 'In #ai-study-partners', status: 'study', online: true, grad: '#ec4899,#f43f5e' },
    { name: 'Dr. Emily Carter', role: 'In Voice • Data Science', status: 'voice', online: true, grad: '#9333ea,#6366f1' },
    { name: 'David Wilson', role: 'In Voice • Robotics', status: 'voice', online: true, grad: '#4f46e5,#6366f1' },
    { name: 'Alex Park', role: 'Idle • Last seen 5m ago', status: 'idle', online: false, grad: '#ca8a04,#d97706' },
  ];
}

// ═══════════════════════════════════════════════════════
// MEMBERS MODAL (full)
// ═══════════════════════════════════════════════════════
let _membersTab = 'all';
let _membersSearch = '';

function openMembersPanel() {
  _membersTab = 'all'; _membersSearch = '';
  const inp = document.getElementById('membersSearch');
  if (inp) inp.value = '';
  document.querySelectorAll('#membersStatusTabs .filter-tab').forEach((t, i) => t.classList.toggle('active', i === 0));
  _renderMembersModal();
  if (window.openModal) openModal('membersModal');
}

function _renderMembersModal() {
  const container = document.getElementById('membersModalList');
  if (!container) return;
  // Try to use real member data from sidebar
  const source = _getModalMembersSource();
  let data = source;
  if (_membersTab !== 'all') data = data.filter(m => m.status === _membersTab);
  if (_membersSearch) data = data.filter(m => m.name.toLowerCase().includes(_membersSearch) || (m.role || '').toLowerCase().includes(_membersSearch));
  if (!data.length) { container.innerHTML = '<div style="text-align:center;color:var(--text-muted);padding:24px;font-size:13px;">No members found</div>'; return; }
  const order = ['online', 'idle', 'offline'];
  const groups = { online: '🟢 Online', idle: '🟡 Idle', offline: '⚫ Offline' };
  let html = '';
  order.forEach(status => {
    const group = data.filter(m => m.status === status);
    if (!group.length) return;
    if (_membersTab === 'all') html += `<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:var(--text-muted);padding:10px 10px 4px;">${groups[status]} — ${group.length}</div>`;
    group.forEach(m => {
      const dotColor = status === 'online' ? '#22c55e' : status === 'idle' ? '#f59e0b' : '#475569';
      html += `<div onclick="openMiniProfile(event,'${_esc(m.name)}','${_esc(m.role || 'Member')}','','${_esc(m.name[0].toUpperCase())}')"
        style="display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:8px;cursor:pointer;transition:background 0.12s;"
        onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background=''">
        <div style="position:relative;flex-shrink:0;">
          <div style="width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,${m.grad || '#3b82f6,#6366f1'});display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#fff;">${m.name[0].toUpperCase()}</div>
          <div style="position:absolute;bottom:0;right:0;width:10px;height:10px;border-radius:50%;border:2px solid var(--bg-secondary);background:${dotColor};"></div>
        </div>
        <div style="flex:1;min-width:0;">
          <div style="font-size:13px;font-weight:600;color:var(--text-primary);">${_esc(m.name)}</div>
          <div style="font-size:11px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${_esc(m.role || 'Member')}</div>
        </div>
        <button onclick="event.stopPropagation();sendConnectionRequest(this,${m.id || 0})"
          style="font-size:11px;padding:4px 10px;border-radius:6px;background:rgba(168,85,247,0.1);border:1px solid rgba(168,85,247,0.25);color:#c084fc;cursor:pointer;font-family:inherit;font-weight:600;"
          onmouseover="this.style.background='rgba(168,85,247,0.2)'" onmouseout="this.style.background='rgba(168,85,247,0.1)'">Connect</button>
      </div>`;
    });
  });
  container.innerHTML = html;
}

function _getModalMembersSource() {
  // Build from real sidebar member list if available, else placeholder
  const memberItems = document.querySelectorAll('#membersList .member-item');
  if (memberItems.length > 0) {
    const result = [];
    memberItems.forEach(item => {
      const name = item.querySelector('.member-name')?.textContent?.trim().replace(/👑/g, '').trim() || '';
      const role = item.querySelector('.member-sub')?.textContent?.trim() || '';
      const isOnline = item.querySelector('.online-dot:not(.offline)') !== null;
      const isOffline = item.querySelector('.online-dot.offline') !== null;
      const id = parseInt(item.dataset.userId || 0);
      const grad = item.dataset.userGrad || '#3b82f6,#6366f1';
      result.push({ id, name, role, status: isOffline ? 'offline' : isOnline ? 'online' : 'idle', grad });
    });
    return result;
  }
  return [];
}

function switchMembersTab(btn, tab) {
  _membersTab = tab;
  document.querySelectorAll('#membersStatusTabs .filter-tab').forEach(t => t.classList.remove('active'));
  btn.classList.add('active');
  _renderMembersModal();
}

function filterMembersModal(val) {
  _membersSearch = val.toLowerCase();
  _renderMembersModal();
}

// ═══════════════════════════════════════════════════════
// FULL MATCHES MODAL
// ═══════════════════════════════════════════════════════
const _allMatches = []; // populated from API on load

function openFullMatchesModal() {
  _populateFullMatches('all');
  if (window.openModal) openModal('matchesModal');
}

function _populateFullMatches(filter) {
  const container = document.getElementById('fullMatchesList');
  if (!container) return;
  const filtered = filter === 'all' ? _allMatches : _allMatches.filter(m => m.type === filter);
  container.innerHTML = filtered.map(m => `
    <div style="display:flex;align-items:center;gap:14px;padding:14px 0;border-bottom:1px solid var(--border);cursor:pointer;border-radius:8px;transition:background 0.12s;margin:0 -6px;padding-left:6px;padding-right:6px;"
      onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background=''"
      onclick="openMiniProfile(event,'${_esc(m.name)}','${_esc(m.detail)}','','${_esc(m.name[0].toUpperCase())}',${m.id || 0})">
      <div style="width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,${m.grad});display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:700;color:#fff;flex-shrink:0;">${m.name[0]}</div>
      <div style="flex:1;min-width:0;">
        <div style="font-size:14px;font-weight:700;color:var(--text-primary);margin-bottom:2px;">${_esc(m.name)}</div>
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:6px;">${_esc(m.detail)}</div>
        <div style="display:flex;gap:6px;flex-wrap:wrap;">${m.tags.map(t => `<span style="font-size:11px;padding:2px 8px;border-radius:99px;background:rgba(168,85,247,0.1);border:1px solid rgba(168,85,247,0.25);color:#c084fc;">${_esc(t)}</span>`).join('')}</div>
      </div>
      <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;flex-shrink:0;">
        <div style="font-size:18px;font-weight:800;color:#a855f7;">${m.pct}%</div>
        <button onclick="event.stopPropagation();sendConnectionRequest(this,${m.id || 0})" style="font-size:12px;padding:6px 14px;border-radius:8px;background:rgba(168,85,247,0.1);border:1px solid rgba(168,85,247,0.3);color:#c084fc;cursor:pointer;font-weight:600;font-family:inherit;transition:0.12s;" onmouseover="this.style.background='rgba(168,85,247,0.2)'" onmouseout="this.style.background='rgba(168,85,247,0.1)'">Connect</button>
      </div>
    </div>`).join('');
}

function filterMatchTab(el, filter) {
  document.querySelectorAll('#matchesModal .filter-tab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  _populateFullMatches(filter);
}

async function refreshMatches(btn) {
  btn.classList.add('spinning');
  btn.disabled = true;
  try {
    const data = await apiFetch((window.ECOLLAB?.baseUrl || '') + '/API/chat/get-matches.php');
    if (data.matches && data.matches.length) {
      // Update the live _allMatches array and re-render
      _allMatches.length = 0;
      data.matches.forEach(m => _allMatches.push(m));
      // Re-render sidebar mini list
      const miniList = document.getElementById('matchesList');
      if (miniList) {
        miniList.innerHTML = data.matches.slice(0, 3).map(m => `
          <div class="match-item" style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border);">
            <div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,${m.grad || '#a855f7,#ec4899'});display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#fff;flex-shrink:0;">${(m.name || '?')[0]}</div>
            <div style="flex:1;min-width:0;">
              <div style="font-size:12px;font-weight:600;color:var(--text-primary);">${_esc(m.name)}</div>
              <div style="font-size:11px;color:var(--text-muted);">${_esc(m.detail || '')}</div>
              <div style="font-size:11px;color:#a855f7;font-weight:600;">${m.pct}% match</div>
            </div>
            <button onclick="event.stopPropagation();sendConnectionRequest(this,${m.id || 0})"
              style="font-size:11px;padding:4px 10px;border-radius:6px;background:rgba(168,85,247,0.1);border:1px solid rgba(168,85,247,0.25);color:#c084fc;cursor:pointer;font-family:inherit;">Connect</button>
          </div>`).join('');
      }
      if (window.showToast) showToast(`✨ Found ${data.matches.length} matches!`, 'success');
    } else {
      if (window.showToast) showToast('No new matches found right now', 'info');
    }
  } catch (err) {
    if (window.showToast) showToast('Could not refresh matches', 'info');
  } finally {
    btn.classList.remove('spinning');
    btn.disabled = false;
  }
}

// Auto-load matches on page load (no button click needed)
async function _autoLoadMatches() {
  try {
    const data = await apiFetch((window.ECOLLAB?.baseUrl || '') + '/API/chat/get-matches.php');
    if (!data.matches || !data.matches.length) return;
    _allMatches.length = 0;
    data.matches.forEach(m => _allMatches.push(m));
    const miniList = document.getElementById('matchesList');
    if (miniList) {
      miniList.innerHTML = data.matches.slice(0, 3).map(m => `
        <div class="match-item" style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border);">
          <div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,${m.grad || '#a855f7,#ec4899'});display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#fff;flex-shrink:0;">${(m.name || '?')[0]}</div>
          <div style="flex:1;min-width:0;">
            <div style="font-size:12px;font-weight:600;color:var(--text-primary);">${_esc(m.name)}</div>
            <div style="font-size:11px;color:var(--text-muted);">${_esc(m.detail || '')}</div>
            <div style="font-size:11px;color:#a855f7;font-weight:600;">${m.pct}% match</div>
          </div>
          <button onclick="event.stopPropagation();sendConnectionRequest(this,${m.id || 0})"
            style="font-size:11px;padding:4px 10px;border-radius:6px;background:rgba(168,85,247,0.1);border:1px solid rgba(168,85,247,0.25);color:#c084fc;cursor:pointer;font-family:inherit;font-weight:600;">Connect</button>
        </div>`).join('');
    }
  } catch (_) { }
}
document.addEventListener('DOMContentLoaded', _autoLoadMatches);

// ═══════════════════════════════════════════════════════
// NAV VIEWS (Mentions, Bookmarks, Threads, Drafts)
// ═══════════════════════════════════════════════════════
let _currentView = 'home';

const _navViewConfigs = {
  mentions: { icon: '@', label: 'Mentions', desc: 'Messages that mentioned you', color: '#a855f7' },
  bookmarks: { icon: '📌', label: 'Pinned', desc: 'All pinned messages across this server', color: '#f59e0b' },
  threads: { icon: '💬', label: 'Threads', desc: 'Direct messages with server members', color: '#22c55e' },
  drafts: { icon: '📝', label: 'Drafts', desc: 'Unsent messages', color: '#3b82f6' },
};

// Real data — populated from API when view is opened
const _mentionMessages = [];
const _bookmarkedMessages = [];
const _threadMessages = [];
const _draftMessages = [];

// Load nav view data from localStorage (set by chat.js interactions)
async function _fetchNavViewData(viewName) {
  if (viewName === 'mentions') {
    const data = window._mentions || JSON.parse(localStorage.getItem('ec_mentions') || '[]');
    _mentionMessages.length = 0;
    data.forEach(i => _mentionMessages.push(i));
    // Mark all as read when viewing
    data.forEach(i => i.read = true);
    localStorage.setItem('ec_mentions', JSON.stringify(data));
    if (window._updateMentionBadge) window._updateMentionBadge();
  }
  if (viewName === 'bookmarks') {
    // Fetch server-wide pinned messages from the API
    try {
      const apiBase = window.API_BASE || '/API/chat';
      const resp = await fetch(`${apiBase}/nav-view-data.php?view=bookmarks`, { credentials: 'same-origin' });
      const json = await resp.json();
      _bookmarkedMessages.length = 0;
      (json.items || []).forEach(i => _bookmarkedMessages.push(i));
    } catch (e) {
      // Fall back to localStorage bookmarks if API fails
      const data = window._bookmarks || JSON.parse(localStorage.getItem('ec_bookmarks') || '[]');
      _bookmarkedMessages.length = 0;
      data.forEach(i => _bookmarkedMessages.push(i));
    }
  }
  if (viewName === 'threads') {
    try {
      // Try multiple sources for the current server ID
      const servId = window.ECOLLAB?.currentServerId
        || parseInt(document.querySelector('.workspace-icon.active')?.dataset?.serverId || '0')
        || parseInt(document.querySelector('[data-server-id]')?.dataset?.serverId || '0')
        || 0;

      if (!servId) {
        console.warn('[threads] No server ID found - cannot fetch members');
        return;
      }

      const base = window.ECOLLAB?.baseUrl || '';
      const res = await fetch(`${base}/API/threads/get-server-members.php?server_id=${servId}`, {
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': window.ECOLLAB?.csrfToken || '',
        }
      });

      if (!res.ok) {
        console.warn('[threads] API returned', res.status, await res.text());
        return;
      }

      const d = await res.json();
      _threadMessages.length = 0;
      if (d.success && d.members) {
        d.members.forEach(m => _threadMessages.push(m));
        console.log('[threads] Loaded', d.members.length, 'server members');
      } else {
        console.warn('[threads] API error:', d.error || 'unknown');
      }
    } catch (e) {
      console.warn('[threads] Failed to load server members', e);
    }
  }
  if (viewName === 'drafts') {
    const raw = window._drafts || JSON.parse(localStorage.getItem('ec_drafts') || '{}');
    _draftMessages.length = 0;
    Object.entries(raw).forEach(([channelId, d]) => _draftMessages.push({ ...d, channelId }));
  }
}

// Let chat.js trigger a re-render of drafts view when drafts change
window._notifyDraftChange = function () {
  const overlay = document.getElementById('navViewOverlay');
  if (overlay && overlay.style.display !== 'none' && window._currentNavView === 'drafts') {
    _fetchNavViewData('drafts').then(() => _renderNavView('drafts', overlay));
  }
};

function switchView(viewName, el) {
  document.querySelectorAll('.sidebar-nav-item').forEach(n => n.classList.remove('active'));
  if (el) el.classList.add('active');
  _currentView = viewName;
  window._currentNavView = viewName;

  // If voice is active fullscreen, minimize it to PiP so the page is visible
  if (document.body.classList.contains('vc-active') && !document.body.classList.contains('vc-pip')) {
    if (typeof toggleVcMinimize === 'function') toggleVcMinimize();
  }

  const chatMain = document.querySelector('.chat-main');
  let overlay = document.getElementById('navViewOverlay');
  if (viewName === 'home') {
    if (overlay) overlay.style.display = 'none';
    if (chatMain) chatMain.style.display = '';
    return;
  }
  if (chatMain) chatMain.style.display = 'none';
  if (!overlay) {
    overlay = document.createElement('div');
    overlay.id = 'navViewOverlay';
    overlay.style.cssText = 'flex:1;display:flex;flex-direction:column;height:100vh;overflow:hidden;background:var(--bg-primary);';
    if (chatMain) chatMain.parentNode.insertBefore(overlay, chatMain.nextSibling);
  } else {
    overlay.style.display = 'flex';
  }
  // Show loading spinner while fetching
  if (viewName === 'threads') {
    overlay.innerHTML = `
      <div style="height:56px;flex-shrink:0;display:flex;align-items:center;gap:12px;padding:0 20px;border-bottom:1px solid var(--border);background:var(--bg-secondary);">
        <span style="font-size:20px;color:#22c55e;">💬</span>
        <div>
          <div style="font-size:15px;font-weight:700;color:var(--text-primary);">Threads</div>
          <div style="font-size:11px;color:var(--text-muted);">Direct messages with server members</div>
        </div>
        <button onclick="switchView('home', document.querySelector('.sidebar-nav-item'))" style="margin-left:auto;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:8px;padding:7px 14px;font-size:12px;color:var(--text-secondary);cursor:pointer;font-family:'Inter',sans-serif;">← Back to chat</button>
      </div>
      <div style="flex:1;display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:13px;gap:8px;">
        <div style="width:16px;height:16px;border:2px solid var(--text-muted);border-top-color:#a855f7;border-radius:50%;animation:spin 0.8s linear infinite;"></div>
        Loading server members…
      </div>`;
  }
  // Fetch real data then render
  _fetchNavViewData(viewName).then(() => _renderNavView(viewName, overlay));
}

function _renderNavView(viewName, overlay) {
  const cfg = _navViewConfigs[viewName];
  if (!cfg) return;
  let bodyHTML = '';
  const cardStyle = 'background:var(--bg-tertiary);border:1px solid var(--border);border-radius:10px;padding:12px 14px;margin-bottom:8px;cursor:pointer;transition:background 0.12s;';
  if (viewName === 'mentions') {
    bodyHTML = _mentionMessages.map((m, i) => `
      <div style="${cardStyle}" onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background='var(--bg-tertiary)'">
        <div style="display:flex;align-items:center;gap:6px;margin-bottom:8px;font-size:11px;color:var(--text-muted);">
          <span style="background:rgba(168,85,247,0.15);color:#c084fc;border-radius:4px;padding:1px 6px;font-weight:700;">#${_esc(m.channel)}</span>
          <span>${_esc(m.time)}</span>
        </div>
        <div style="display:flex;gap:10px;">
          <div style="width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#6366f1);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#fff;flex-shrink:0;">${m.letter}</div>
          <div><div style="font-size:13px;font-weight:600;color:var(--text-primary);margin-bottom:2px;">${_esc(m.author)}</div><div style="font-size:12px;color:var(--text-secondary);">${_esc(m.text)}</div></div>
        </div>
        <div style="display:flex;gap:8px;margin-top:8px;">
          <button onclick="event.stopPropagation()" style="font-size:11px;padding:4px 10px;border-radius:6px;background:rgba(168,85,247,0.1);border:1px solid rgba(168,85,247,0.2);color:#c084fc;cursor:pointer;font-family:inherit;">↩ Reply</button>
          <button onclick="event.stopPropagation();this.closest('div[style]').style.opacity='0.4'" style="font-size:11px;padding:4px 10px;border-radius:6px;background:var(--bg-card);border:1px solid var(--border);color:var(--text-muted);cursor:pointer;font-family:inherit;">✓ Mark read</button>
        </div>
      </div>`).join('') || _nvEmpty('No mentions yet.');
  } else if (viewName === 'bookmarks') {
    bodyHTML = _bookmarkedMessages.map((m, i) => `
      <div style="${cardStyle}" onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background='var(--bg-tertiary)'">
        <div style="display:flex;align-items:center;gap:6px;margin-bottom:8px;font-size:11px;color:var(--text-muted);">
          ${m.server ? `<span style="background:rgba(99,102,241,0.15);color:#818cf8;border-radius:4px;padding:1px 6px;font-weight:700;">${_esc(m.server)}</span>` : ''}
          <span style="background:rgba(245,158,11,0.12);color:#fbbf24;border-radius:4px;padding:1px 6px;font-weight:700;">#${_esc(m.channel || '')}</span>
          <span style="margin-left:auto;">${_esc(m.time || '')}</span>
        </div>
        <div style="display:flex;gap:10px;">
          <div style="width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,${_esc(m.grad || '#a855f7,#ec4899')});display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#fff;flex-shrink:0;">${_esc(m.letter || '?')}</div>
          <div style="flex:1;min-width:0;">
            <div style="font-size:13px;font-weight:600;color:var(--text-primary);margin-bottom:3px;">${_esc(m.author)}</div>
            <div style="font-size:12px;color:var(--text-secondary);overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">${_esc(m.text)}</div>
          </div>
        </div>
        <div style="display:flex;gap:8px;margin-top:8px;">
          ${m.channelId ? `<button onclick="event.stopPropagation();_jumpToBookmark('${m.channelId}','${m.id}')" style="font-size:11px;padding:4px 10px;border-radius:6px;background:rgba(168,85,247,0.1);border:1px solid rgba(168,85,247,0.2);color:#c084fc;cursor:pointer;font-family:inherit;">↗ Jump to</button>` : ''}
        </div>
      </div>`).join('') || _nvEmpty('No pinned messages in this server yet.');
  } else if (viewName === 'threads') {
    // Threads = server-wide DM directory
    bodyHTML = `<div style="font-size:11px;color:var(--text-muted);margin-bottom:12px;padding:6px 10px;background:rgba(168,85,247,0.06);border-radius:8px;border:1px solid rgba(168,85,247,0.12);">
      💬 <strong style="color:var(--text-secondary);">Threads</strong> — private one-on-one chats with anyone in this server.
    </div>` + (_threadMessages.map((m) => {
      const grad = m.grad || '#a855f7,#ec4899';
      const [c1, c2] = grad.split(',');
      const init = (m.full_name || m.username || '?').charAt(0).toUpperCase();
      const displayName = _esc(m.nickname || m.full_name || m.username);
      const lastMsg = m.last_message ? _esc(m.last_message) : '<span style="color:var(--text-muted);font-style:italic;">No messages yet</span>';
      const unread = parseInt(m.unread_count) || 0;
      const online = m.is_online == 1;
      const timeStr = m.last_msg_at ? _relTime(m.last_msg_at) : '';
      return `
      <div data-thread-user="${m.id}" style="${cardStyle}" onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background='var(--bg-tertiary)'" onclick="openThreadDM(${m.id},'${displayName.replace(/'/g,"\\'")}')">
        <div style="display:flex;gap:10px;align-items:center;">
          <div style="position:relative;flex-shrink:0;">
            <div style="width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,${c1},${c2});display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;color:#fff;">${init}</div>
            <div style="position:absolute;bottom:0;right:0;width:10px;height:10px;border-radius:50%;background:${online ? '#22c55e' : 'var(--text-muted)'};border:2px solid var(--bg-secondary);"></div>
          </div>
          <div style="flex:1;min-width:0;">
            <div style="display:flex;align-items:center;gap:6px;margin-bottom:2px;">
              <span style="font-size:13px;font-weight:700;color:var(--text-primary);">${displayName}</span>
              ${m.server_role === 'owner' ? '<span style="font-size:9px;background:rgba(245,158,11,0.15);color:#fbbf24;border-radius:3px;padding:1px 5px;">👑 Owner</span>' : ''}
              ${timeStr ? `<span style="margin-left:auto;font-size:10px;color:var(--text-muted);white-space:nowrap;">${timeStr}</span>` : ''}
            </div>
            <div style="font-size:11px;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${lastMsg}</div>
          </div>
          ${unread > 0 ? `<span style="min-width:18px;height:18px;border-radius:9px;background:#ef4444;color:#fff;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;padding:0 4px;flex-shrink:0;">${unread}</span>` : ''}
        </div>
      </div>`;
    }).join('') || _nvEmpty('No server members found.'));
  } else if (viewName === 'drafts') {
    bodyHTML = _draftMessages.map((m, i) => `
      <div style="background:rgba(245,158,11,0.05);border:1px solid rgba(245,158,11,0.15);border-radius:10px;padding:14px;margin-bottom:8px;">
        <div style="display:flex;align-items:center;gap:6px;margin-bottom:8px;font-size:11px;color:var(--text-muted);">
          <span style="font-size:14px;">📝</span>
          <span>#${m.channelId ? _esc(m.channel || m.channelId) : 'unknown'} · Saved ${_esc(m.saved || '')}</span>
        </div>
        <div style="font-size:13px;color:var(--text-secondary);margin-bottom:10px;">${_esc(m.text)}</div>
        <div style="display:flex;gap:8px;">
          <button onclick="event.stopPropagation();_editDraftInChannel('${m.channelId}','${m.text}')" style="font-size:11px;padding:4px 10px;border-radius:6px;background:rgba(168,85,247,0.1);border:1px solid rgba(168,85,247,0.2);color:#c084fc;cursor:pointer;font-family:inherit;">✏️ Edit in channel</button>
          <button onclick="event.stopPropagation();_deleteDraftById('${m.channelId}');this.closest('div[style]').remove()" style="font-size:11px;padding:4px 10px;border-radius:6px;background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.2);color:#f87171;cursor:pointer;font-family:inherit;">🗑 Delete</button>
        </div>
      </div>`).join('') || _nvEmpty('No drafts saved.');
  }

  overlay.innerHTML = `
    <div style="height:56px;flex-shrink:0;display:flex;align-items:center;gap:12px;padding:0 20px;border-bottom:1px solid var(--border);background:var(--bg-secondary);">
      <span style="font-size:20px;color:${cfg.color};">${cfg.icon}</span>
      <div>
        <div style="font-size:15px;font-weight:700;color:var(--text-primary);">${cfg.label}</div>
        <div style="font-size:11px;color:var(--text-muted);">${cfg.desc}</div>
      </div>
      <button onclick="switchView('home', document.querySelector('.sidebar-nav-item'))" style="margin-left:auto;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:8px;padding:7px 14px;font-size:12px;color:var(--text-secondary);cursor:pointer;font-family:'Inter',sans-serif;">← Back to chat</button>
    </div>
    <div style="flex:1;overflow-y:auto;padding:16px 20px;">${bodyHTML}</div>`;
}

function _nvEmpty(msg) {
  return `<div style="text-align:center;color:var(--text-muted);font-size:14px;padding:60px 20px;">${msg}</div>`;
}

// ═══════════════════════════════════════════════════════
// USER SETTINGS / PLATFORM SETTINGS
// ═══════════════════════════════════════════════════════
function openUserSettings() {
  if (window.openModal) openModal('platformSettingsModal');
  // Populate audio devices as soon as settings opens
  if (window._populateDeviceSelects) {
    window._populateDeviceSelects();
  } else {
    // Fallback: enumerate directly without prior permission
    navigator.mediaDevices?.enumerateDevices().then(devices => {
      const inputs = devices.filter(d => d.kind === 'audioinput');
      const outputs = devices.filter(d => d.kind === 'audiooutput');
      ['voiceRecordInputSelect', 'audioInputSelect', 'micTestInputSelect'].forEach(id => {
        const sel = document.getElementById(id);
        if (!sel || sel.options.length > 1) return;
        if (inputs.length) {
          sel.innerHTML = inputs.map((d, i) =>
            `<option value="${d.deviceId}">${d.label || 'Microphone ' + (i + 1)}</option>`
          ).join('');
        }
      });
      ['voiceRecordOutputSelect', 'audioOutputSelect'].forEach(id => {
        const sel = document.getElementById(id);
        if (!sel || sel.options.length > 1) return;
        if (outputs.length) {
          sel.innerHTML = outputs.map((d, i) =>
            `<option value="${d.deviceId}">${d.label || 'Speaker ' + (i + 1)}</option>`
          ).join('');
        }
      });
    }).catch(() => { });
  }
}

function closePlatformSettingsOverlay(event) {
  if (event.target === document.getElementById('platformSettingsModal')) {
    if (window.closeModal) closeModal('platformSettingsModal');
  }
}

function switchSettingsTab(tabId, navEl) {
  const modal = document.getElementById('platformSettingsModal');
  if (!modal) return;
  modal.querySelectorAll('.ps-tab').forEach(t => t.classList.remove('active-tab'));
  modal.querySelectorAll('.ps-nav-item').forEach(n => n.classList.remove('active'));
  const tab = document.getElementById('ps-' + tabId);
  if (tab) tab.classList.add('active-tab');
  if (navEl) navEl.classList.add('active');
}

function handleLogout() {
  if (window.closeModal) closeModal('platformSettingsModal');
  if (window.showToast) showToast('👋 Logging out…', 'info');
  setTimeout(() => { window.location.href = (window.ECOLLAB?.baseUrl || window.ECOLLAB_BASE || '') + '/modules/auth/logout.php'; }, 800);
}

function goToDashboard() {
  const base = window.ECOLLAB?.baseUrl || window.ECOLLAB_BASE || '';
  const role = window.ECOLLAB?.role || 'student';
  let url;
  if (['admin', 'super_admin', 'moderator'].includes(role)) {
    url = base + '/modules/admin/dashboard.php';
  } else if (role === 'facilitator') {
    url = base + '/modules/facilitator/dashboard.php';
  } else {
    url = base + '/modules/student/dashboard.php';
  }
  window.location.href = url;
}

function applyTheme(theme, el) {
  document.querySelectorAll('#ps-appearance > div:nth-child(2) > div').forEach(d => d.style.borderColor = 'var(--border)');
  el.style.borderColor = 'var(--accent-purple)';
  const themes = {
    dark: { '--bg-deepest': '#060a14', '--bg-primary': '#0b0f1a', '--bg-secondary': '#111827' },
    darker: { '--bg-deepest': '#02040a', '--bg-primary': '#060a12', '--bg-secondary': '#0d1117' },
    midnight: { '--bg-deepest': '#080820', '--bg-primary': '#0d0d2b', '--bg-secondary': '#111135' }
  };
  if (themes[theme]) Object.entries(themes[theme]).forEach(([k, v]) => document.documentElement.style.setProperty(k, v));
  if (window.showToast) showToast('🎨 Theme: ' + theme, 'info');
}

function applyFontSize(val) {
  document.body.style.fontSize = val + 'px';
  if (window.showToast) showToast('🔤 Font size: ' + val + 'px', 'info');
}

function saveProfileSettings() {
  const name = document.getElementById('psEditName')?.value?.trim() || (window.ECOLLAB?.username || 'User');
  document.querySelector('.user-name') && (document.querySelector('.user-name').textContent = name);
  if (window.showToast) showToast('✅ Profile saved!', 'info');
}

// ═══════════════════════════════════════════════════════
// VOICE CHANNEL CONTROLS (extended)
// — Real implementations live in voice.js which loads first.
//   These are no-ops kept for safety in case voice.js hasn't loaded.
// ═══════════════════════════════════════════════════════
function _vcNoop(name) { if (typeof window[name] === 'function' && window[name] !== window._vcNoop) return; }

// openWhiteboard and closeWhiteboard are implemented in whiteboard.js
// These shims ensure backward-compatibility if whiteboard.js hasn't loaded yet
function openWhiteboard(boardName, channelId) {
  const targetChannelId = channelId || window.ECOLLAB?.currentChannelId || window.__currentChannelId;
  if (targetChannelId && !window.ECOLLAB?.whiteboardStandalone) {
    window.location.href = `${window.ECOLLAB?.baseUrl || ''}/modules/whiteboard/index.php?channel_id=${encodeURIComponent(targetChannelId)}`;
    return;
  }
  if (window._wbOpen) { window._wbOpen(boardName, channelId); return; }
  const overlay = document.getElementById('wbOverlay');
  if (overlay) { overlay.classList.add('wb-visible'); }
}
function closeWhiteboard() {
  if (window._wbClose) { window._wbClose(); return; }
  const overlay = document.getElementById('wbOverlay');
  if (overlay) overlay.classList.remove('wb-visible');
}

// ═══════════════════════════════════════════════════════
// MINI PROFILE (enhanced)
// ═══════════════════════════════════════════════════════
function openMiniProfile(event, name, role, avatarGrad, initials, userId) {
  event.stopPropagation();
  // Store for full profile card
  _miniProfileUserId = userId ? parseInt(userId) : 0;
  _miniProfileUsername = name;
  _miniProfileGradient = avatarGrad || '';

  const mp = document.getElementById('miniProfile');
  if (!mp) return;
  const av = document.getElementById('mpAvatar') || document.getElementById('miniAvatar');
  const nm = document.getElementById('mpName') || document.getElementById('miniName');
  const rl = document.getElementById('mpRole') || document.getElementById('miniRole');
  if (av) {
    av.textContent = initials || name.charAt(0).toUpperCase();
    // Apply gradient to avatar circle if provided
    if (avatarGrad && avatarGrad.includes(',')) {
      av.style.background = `linear-gradient(135deg, ${avatarGrad})`;
    }
  }
  // Apply gradient to the banner header of the mini profile card
  const banner = mp.querySelector('.mp-banner') || mp.querySelector('[class*="banner"]') || mp.querySelector('div[style*="linear-gradient"]');
  if (banner && avatarGrad && avatarGrad.includes(',')) {
    banner.style.background = `linear-gradient(135deg, ${avatarGrad})`;
  }
  if (nm) nm.textContent = name;
  if (rl) rl.textContent = role;
  // Show logout row only for own profile
  const logoutRow = document.getElementById('mpLogoutRow');
  if (logoutRow) {
    const isMe = name === (window.ECOLLAB?.username || '') || name === (window.ECOLLAB?.fullName || '');
    logoutRow.style.display = isMe ? 'block' : 'none';
  }
  // Stats
  const s1 = document.getElementById('miniStat1'); if (s1) s1.textContent = Math.floor(Math.random() * 200 + 10);
  const s2 = document.getElementById('miniStat2'); if (s2) s2.textContent = Math.floor(Math.random() * 80 + 5);
  const s3 = document.getElementById('miniStat3'); if (s3) s3.textContent = '4.5 ⭐';
  const ab = document.getElementById('miniAbout'); if (ab) ab.textContent = 'Ecollab member.';
  const cb = document.getElementById('miniConnectBtn');
  if (cb) { cb.textContent = 'Connect'; cb.style.background = 'var(--accent-purple)'; cb.style.color = '#fff'; }
  // Position near click — smart placement inside modals
  mp.style.display = 'block';
  const rect = (event.currentTarget || event.target).getBoundingClientRect();
  const popW = 280, popH = 340;
  // Prefer right of element, fallback left; prefer below, fallback above
  let left = rect.right + 10;
  if (left + popW > window.innerWidth - 10) left = rect.left - popW - 10;
  if (left < 10) left = Math.max(10, (window.innerWidth - popW) / 2);
  let top = rect.top;
  if (top + popH > window.innerHeight - 10) top = window.innerHeight - popH - 10;
  if (top < 10) top = 10;
  mp.style.top = top + 'px';
  mp.style.left = left + 'px';
  mp.classList.add('open');
}

function closeMiniProfile() {
  const mp = document.getElementById('miniProfile');
  if (mp) { mp.style.display = 'none'; mp.classList.remove('open'); }
}

// ═══════════════════════════════════════════════════════
// CONNECTION REQUESTS — real API + notification dropdown
// ═══════════════════════════════════════════════════════

// Current user we have a mini profile open for (set by openMiniProfile)
let _miniProfileUserId = 0;
let _miniProfileUsername = '';
let _miniProfileGradient = '';

async function sendConnectionRequest(btn, nameOrId) {
  if (btn.classList.contains('connected')) {
    if (window.showToast) showToast(`✅ Already connected`, 'info');
    return;
  }
  if (btn.classList.contains('pending')) {
    if (window.showToast) showToast('⏳ Request already pending', 'info');
    return;
  }

  btn.textContent = '⏳ Pending...';
  btn.classList.add('pending');
  btn.disabled = true;

  try {
    const payload = typeof nameOrId === 'number' || /^\d+$/.test(String(nameOrId))
      ? { addressee_id: parseInt(nameOrId) }
      : { addressee_name: nameOrId };

    const data = await apiFetch((window.ECOLLAB?.baseUrl || '') + '/API/friendship/send-request.php', {
      method: 'POST',
      body: JSON.stringify(payload),
    });

    if (data.status === 'accepted') {
      btn.textContent = 'Connected ✓';
      btn.classList.remove('pending');
      btn.classList.add('connected');
      btn.disabled = false;
      if (window.showToast) showToast(`✅ Already connected`, 'info');
    } else if (data.status === 'pending') {
      if (window.showToast) showToast('📨 Connection request sent!', 'info');
      // Relay to recipient via WebSocket — only they will see the Accept/Decline banner
      if (data.request_id && window.wsSend) {
        window.wsSend({
          type: 'connection_request',
          request_id: data.request_id,
          addressee_id: data.addressee_id,
          requester: data.requester,
        });
      }
    }
  } catch (err) {
    btn.textContent = 'Connect';
    btn.classList.remove('pending');
    btn.disabled = false;
    if (window.showToast) showToast(err.message || 'Failed to send request', 'info');
  }
}

/**
 * Injects a connection request into the existing notification dropdown.
 * Called on the recipient's client via WebSocket (connection_request event).
 */
function _addConnectionRequestNotif(data) {
  const reqId = data.request_id;
  const from = data.requester || {};
  const name = from.fullName || from.username || 'Someone';
  const grad = from.gradient || '#a855f7,#ec4899';
  const [c1, c2] = grad.split(',');
  const init = name.charAt(0).toUpperCase();
  const time = 'Just now';

  // ── Inject into #notifList ──
  const list = document.getElementById('notifList');
  if (list) {
    const item = document.createElement('div');
    item.className = 'notif-item unread';
    item.id = `connReq_${reqId}`;
    item.innerHTML = `
      <div class="notif-dot"></div>
      <div class="notif-content" style="flex:1;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
          <div style="width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,${c1},${c2});display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;color:#fff;flex-shrink:0;">${init}</div>
          <div>
            <div class="notif-text"><strong>${_esc(name)}</strong> wants to connect with you</div>
            <div class="notif-time">${time}</div>
          </div>
        </div>
        <div style="display:flex;gap:6px;">
          <button onclick="_respondConnReq(${reqId},'accept',this)" style="flex:1;padding:5px;border-radius:6px;background:rgba(34,197,94,0.12);border:1px solid rgba(34,197,94,0.3);color:#22c55e;font-size:11px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;transition:0.12s;" onmouseover="this.style.background='rgba(34,197,94,0.22)'" onmouseout="this.style.background='rgba(34,197,94,0.12)'">✓ Accept</button>
          <button onclick="_respondConnReq(${reqId},'decline',this)" style="flex:1;padding:5px;border-radius:6px;background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.2);color:#f87171;font-size:11px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;transition:0.12s;" onmouseover="this.style.background='rgba(239,68,68,0.18)'" onmouseout="this.style.background='rgba(239,68,68,0.08)'">✕ Decline</button>
        </div>
      </div>`;
    // Prepend so it appears at the top
    list.insertBefore(item, list.firstChild);
  }

  // ── Bump the notification badge ──
  const badge = document.getElementById('notifBadge');
  if (badge) {
    const current = parseInt(badge.textContent) || 0;
    badge.textContent = current + 1;
    badge.style.display = 'block';
  }

  // ── Open the dropdown so the user sees it immediately ──
  const dropdown = document.getElementById('notifDropdown');
  if (dropdown) {
    dropdown.style.display = 'block';
    dropdown.classList.add('open');
  }

  // ── Toast to draw attention ──
  if (window.showToast) showToast(`🤝 ${name} wants to connect!`, 'info');
}

// Keep _showConnectionRequestBanner as an alias for backward compatibility
function _showConnectionRequestBanner(data) {
  _addConnectionRequestNotif(data);
}

async function _respondConnReq(reqId, action, btn) {
  const banner = document.getElementById(`connReq_${reqId}`);
  if (banner) banner.style.opacity = '0.6';
  try {
    const data = await apiFetch((window.ECOLLAB?.baseUrl || '') + '/API/friendship/respond-request.php', {
      method: 'POST',
      body: JSON.stringify({ request_id: reqId, action }),
    });
    if (data.success) {
      if (action === 'accept') {
        if (window.showToast) showToast('🤝 Connection accepted!', 'success');
        // Update any pending Connect buttons in the UI
        document.querySelectorAll('.pending').forEach(b => {
          b.textContent = 'Connected ✓';
          b.classList.remove('pending');
          b.classList.add('connected');
          b.disabled = false;
        });
      } else {
        if (window.showToast) showToast('Connection declined', 'info');
      }
    }
  } catch (err) {
    if (window.showToast) showToast('Could not respond — try again', 'info');
  } finally {
    _dismissConnReqBanner(reqId);
  }
}

function _dismissConnReqBanner(reqId) {
  const banner = document.getElementById(`connReq_${reqId}`);
  if (!banner) return;
  banner.style.transition = 'opacity 0.3s,transform 0.3s';
  banner.style.opacity = '0';
  banner.style.transform = 'translateX(20px)';
  setTimeout(() => banner.remove(), 320);
}

// Add slide-in keyframe once
(function _addConnReqStyles() {
  if (document.getElementById('_connReqStyles')) return;
  const s = document.createElement('style');
  s.id = '_connReqStyles';
  s.textContent = `@keyframes slideInRight{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:translateX(0)}}`;
  document.head.appendChild(s);
})();

// ═══════════════════════════════════════════════════════
// FULL PROFILE CARD (Discord-style)
// ═══════════════════════════════════════════════════════
let _profileCardData = null; // last loaded profile

async function openFullProfileCard(userIdOrName) {
  // If called from mini profile with no arg, use the stored user
  const target = userIdOrName || _miniProfileUserId || _miniProfileUsername;
  if (!target) return;

  closeMiniProfile();
  if (window.openModal) openModal('profileCardModal');

  // Show loading state
  _resetProfileCard();
  document.getElementById('pcFullName').textContent = 'Loading…';

  try {
    const param = typeof target === 'number' || /^\d+$/.test(String(target))
      ? `user_id=${target}`
      : `name=${encodeURIComponent(target)}`;

    const data = await apiFetch(`${window.ECOLLAB?.baseUrl || ''}/API/profile/get-profile.php?${param}`);
    if (!data.success) throw new Error('Not found');

    _profileCardData = data.profile;
    _populateProfileCard(data.profile);
  } catch (err) {
    document.getElementById('pcFullName').textContent = 'Profile unavailable';
    document.getElementById('pcUsername').textContent = err.message || '';
  }
}

function _resetProfileCard() {
  ['pcFullName', 'pcUsername', 'pcRoleBadge', 'pcYearProgram', 'pcBio'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.textContent = '';
  });
  ['pcStreak', 'pcHours', 'pcCompat'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.textContent = '—';
  });
  ['pcInterests', 'pcMutual'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.innerHTML = '';
  });
  const styleEl = document.querySelector('#pcStudyWrap .pc-style-val') || document.querySelector('.pc-style-val');
  if (styleEl) styleEl.textContent = '';
  const goalEl = document.querySelector('#pcGoal .pc-goal-val') || document.querySelector('.pc-goal-val');
  if (goalEl) goalEl.textContent = '';
  const av = document.getElementById('pcAvatar');
  if (av) { av.textContent = '?'; av.style.background = 'linear-gradient(135deg,#a855f7,#ec4899)'; }
  const cb = document.getElementById('pcConnectBtn');
  if (cb) { cb.textContent = 'Connect'; cb.classList.remove('pending', 'connected'); cb.disabled = false; }
}

function _populateProfileCard(p) {
  // Avatar
  const av = document.getElementById('pcAvatar');
  if (av) {
    const grad = p.avatar_color_gradient || '#a855f7,#ec4899';
    av.style.background = `linear-gradient(135deg,${grad})`;
    av.textContent = (p.full_name || p.username || '?').charAt(0).toUpperCase();
  }

  // Banner gradient
  const banner = document.getElementById('pcBanner');
  if (banner) {
    const g = p.avatar_color_gradient || '#a855f7,#ec4899';
    banner.style.background = `linear-gradient(135deg,${g})`;
  }

  // Name / username / role badge / year+program
  const setT = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val || ''; };
  setT('pcFullName', p.full_name || p.username);
  setT('pcUsername', p.username ? `@${p.username}` : '');
  setT('pcRoleBadge', p.role ? p.role.charAt(0).toUpperCase() + p.role.slice(1) : '');
  const yearProg = [p.year_level, p.academic_program].filter(Boolean).join(' · ');
  setT('pcYearProgram', yearProg);

  // Bio
  const bioWrap = document.getElementById('pcBioWrap');
  const bioEl = document.getElementById('pcBio');
  if (bioEl) {
    const bio = (p.bio || '').trim();
    bioEl.textContent = bio || 'No bio yet.';
    if (bioWrap) bioWrap.style.display = '';
  }

  // Interests & hobbies tags
  const intEl = document.getElementById('pcInterests');
  if (intEl) {
    const tags = [
      ...(p.interests ? p.interests.split(',').map(s => s.trim()).filter(Boolean) : []),
      ...(p.hobbies ? p.hobbies.split(',').map(s => s.trim()).filter(Boolean) : []),
    ];
    intEl.innerHTML = tags.length
      ? tags.map(t => `<span style="font-size:11px;padding:3px 9px;border-radius:99px;background:rgba(168,85,247,0.1);border:1px solid rgba(168,85,247,0.2);color:#c084fc;white-space:nowrap;">${_esc(t)}</span>`).join('')
      : '<span style="font-size:12px;color:var(--text-muted);">None listed</span>';
    const wrap = document.getElementById('pcInterestsWrap');
    if (wrap) wrap.style.display = '';
  }

  // Study style / goal
  const styleEl = document.querySelector('.pc-style-val');
  if (styleEl) styleEl.textContent = p.study_style || '—';
  const goalEl = document.querySelector('.pc-goal-val');
  if (goalEl) goalEl.textContent = p.goals || '—';

  // Stats
  setT('pcStreak', p.streak_days ? `${p.streak_days}d` : '—');
  setT('pcHours', p.study_hours ? `${p.study_hours}h` : '—');
  const compatWrap = document.getElementById('pcCompatWrap');
  const compatEl = document.getElementById('pcCompat');
  if (compatEl) {
    if (p.compatibility_score !== null && p.compatibility_score !== undefined) {
      compatEl.textContent = `${p.compatibility_score}%`;
      if (compatWrap) compatWrap.style.display = '';
    } else {
      if (compatWrap) compatWrap.style.display = 'none';
    }
  }

  // Mutual servers
  const mutEl = document.getElementById('pcMutual');
  const mutWrap = document.getElementById('pcMutualWrap');
  if (mutEl) {
    const servers = Array.isArray(p.mutual_servers) ? p.mutual_servers : [];
    if (servers.length) {
      mutEl.innerHTML = servers.map(s => `<span style="font-size:11px;padding:3px 9px;border-radius:6px;background:var(--bg-tertiary);border:1px solid var(--border);color:var(--text-secondary);">⭐ ${_esc(s)}</span>`).join('');
      if (mutWrap) mutWrap.style.display = '';
    } else {
      mutEl.innerHTML = '<span style="font-size:12px;color:var(--text-muted);">No mutual servers</span>';
    }
  }

  // Connect button state
  const cb = document.getElementById('pcConnectBtn');
  if (cb) {
    if (p.id === (window.ECOLLAB?.userId || 0)) {
      cb.style.display = 'none'; // own profile
    } else if (p.connection_status === 'accepted') {
      cb.textContent = 'Connected ✓';
      cb.classList.add('connected');
      cb.disabled = false;
      cb.style.display = '';
    } else if (p.connection_status === 'pending') {
      cb.textContent = '⏳ Pending…';
      cb.classList.add('pending');
      cb.style.display = '';
    } else {
      cb.textContent = 'Connect';
      cb.dataset.addresseeId = p.id;
      cb.style.display = '';
    }
  }
}

function profileCardConnect() {
  const cb = document.getElementById('pcConnectBtn');
  if (!cb || !_profileCardData) return;
  sendConnectionRequest(cb, _profileCardData.id);
}

// ═══════════════════════════════════════════════════════
// NOTIFICATIONS
// ═══════════════════════════════════════════════════════
function toggleNotifications() {
  const dd = document.getElementById('notifDropdown');
  if (!dd) return;
  const isOpen = dd.classList.contains('open') || dd.style.display === 'block';
  dd.classList.toggle('open', !isOpen);
  dd.style.display = isOpen ? 'none' : 'block';
}

function closeNotifications() {
  const dd = document.getElementById('notifDropdown');
  if (dd) { dd.classList.remove('open'); dd.style.display = 'none'; }
}

function markAllRead(event) {
  if (event) event.stopPropagation();
  document.querySelectorAll('.notif-dot').forEach(d => d.classList.add('read'));
  const badge = document.getElementById('notifBadge');
  if (badge) badge.style.display = 'none';
  if (window.showToast) showToast('✓ All notifications marked as read', 'info');
}

// ═══════════════════════════════════════════════════════
// ADD SERVER MODAL
// ═══════════════════════════════════════════════════════
let _selectedTemplate = 'custom';

function selectServerTemplate(template) {
  _selectedTemplate = template;
  const emojis = { 'study-group': '📚', gaming: '🎮', research: '🔬', custom: '⚙️' };
  const names = { 'study-group': 'My Study Group', gaming: 'My Gaming Server', research: 'My Research Lab', custom: 'My Server' };
  const fe = document.getElementById('serverFormEmoji'); if (fe) fe.textContent = emojis[template] || '⚙️';
  const nn = document.getElementById('newServerName'); if (nn) nn.value = names[template] || '';
  const choices = document.getElementById('addServerChoices'); if (choices) choices.style.display = 'none';
  const form = document.getElementById('addServerForm'); if (form) form.style.display = 'block';
}

function backToServerChoices() {
  const choices = document.getElementById('addServerChoices'); if (choices) choices.style.display = 'block';
  const form = document.getElementById('addServerForm'); if (form) form.style.display = 'none';
}

async function createServer() {
  const name = document.getElementById('newServerName')?.value?.trim();
  if (!name) { if (window.showToast) showToast('Please enter a server name', 'info'); return; }
  const btn = document.getElementById('createServerBtn');
  if (btn) { btn.textContent = 'Creating…'; btn.disabled = true; }
  try {
    const data = await apiFetch((window.ECOLLAB?.baseUrl || '') + '/API/server/create-server.php', {
      method: 'POST',
      body: JSON.stringify({ name, template: _selectedTemplate }),
    });
    if (data.success) {
      if (window.closeModal) closeModal('addServerModal');
      backToServerChoices();
      if (window.showToast) showToast(`✨ "${name}" server created!`, 'success');
      // Reload page so new server appears in sidebar
      setTimeout(() => window.location.reload(), 800);
    }
  } catch (err) {
    if (window.showToast) showToast(err.message || 'Failed to create server', 'info');
  } finally {
    if (btn) { btn.textContent = 'Create Server'; btn.disabled = false; }
  }
}

async function joinByInvite() {
  const val = document.getElementById('inviteInput')?.value?.trim();
  if (!val) { if (window.showToast) showToast('Please paste an invite link', 'info'); return; }
  try {
    const data = await apiFetch((window.ECOLLAB?.baseUrl || '') + '/API/server/join-server.php', {
      method: 'POST',
      body: JSON.stringify({ invite_code: val }),
    });
    if (data.success) {
      if (window.closeModal) closeModal('addServerModal');
      if (window.showToast) showToast('🔗 Joined server!', 'success');
      setTimeout(() => window.location.reload(), 800);
    }
  } catch (err) {
    if (window.showToast) showToast(err.message || 'Invalid invite link', 'info');
  }
}

// ═══════════════════════════════════════════════════════
// CHANNEL CREATE (enhanced)
// ═══════════════════════════════════════════════════════
let _channelPrivate = false;

function togglePrivate(el) {
  _channelPrivate = !_channelPrivate;
  el.classList.toggle('on', _channelPrivate);
}

function formatChannelName(input) {
  input.value = input.value.toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9\-]/g, '');
}

// ═══════════════════════════════════════════════════════
// EXTRAS MENU ACTIONS (enhanced)
// ═══════════════════════════════════════════════════════
function openExtrasAction(action) {
  if (typeof closeExtrasMenu === 'function') closeExtrasMenu();
  if (action === 'poll') { if (window.openModal) openModal('pollModal'); return; }
  const map = {
    event: '📅 Schedule Event coming soon!', quiz: '🧠 Quiz creator coming soon!',
    link: '🔗 Paste your link in the chat!', resource: '📚 Resource sharing coming soon!',
    code: '💻 Code snippet editor coming soon!'
  };
  if (window.showToast) showToast(map[action] || '✨ Coming soon!', 'info');
}

// ═══════════════════════════════════════════════════════
// EMOJI QUICK PICKER FOR MSG ACTION BAR
// ═══════════════════════════════════════════════════════
function showEmojiForMsgBar(event, btn) {
  event.stopPropagation();
  const old = document.getElementById('_quickEmojiMenu');
  if (old) old.remove();
  const emojis = ['❤️', '🔥', '🎉', '👏', '😂', '🤔', '💡', '⚡', '😮', '👍', '👎', '😢'];
  const menu = document.createElement('div');
  menu.id = '_quickEmojiMenu';
  menu.style.cssText = 'position:fixed;background:var(--bg-secondary);border:1px solid var(--border);border-radius:12px;padding:8px 10px;display:flex;flex-wrap:wrap;gap:4px;width:196px;z-index:2000;box-shadow:0 8px 32px rgba(0,0,0,0.5);';
  const rect = btn.getBoundingClientRect();
  menu.style.top = (rect.top - 60) + 'px';
  menu.style.left = Math.min(rect.left - 80, window.innerWidth - 210) + 'px';
  emojis.forEach(emoji => {
    const b = document.createElement('button');
    b.textContent = emoji;
    b.style.cssText = 'background:none;border:none;font-size:20px;cursor:pointer;padding:3px;border-radius:6px;transition:transform 0.1s,background 0.1s;width:34px;height:34px;';
    b.onmouseover = () => { b.style.transform = 'scale(1.3)'; b.style.background = 'rgba(255,255,255,0.1)'; };
    b.onmouseout = () => { b.style.transform = 'scale(1)'; b.style.background = ''; };
    b.onclick = (e) => {
      e.stopPropagation();
      const msgGroup = btn.closest('.message-group');
      const reactions = msgGroup?.querySelector('.reactions');
      if (reactions) {
        let found = false;
        reactions.querySelectorAll('.reaction').forEach(r => {
          if (r.querySelector('span')?.textContent === emoji) {
            const cnt = r.querySelector('.reaction-count');
            if (cnt) cnt.textContent = parseInt(cnt.textContent || 0) + 1;
            r.classList.add('reacted'); found = true;
          }
        });
        if (!found) {
          const r = document.createElement('div');
          r.className = 'reaction reacted';
          r.onclick = () => {
            r.classList.toggle('reacted');
            const c = r.querySelector('.reaction-count');
            if (c) c.textContent = Math.max(0, parseInt(c.textContent || 1) + (r.classList.contains('reacted') ? 1 : -1));
          };
          r.innerHTML = `<span>${emoji}</span><span class="reaction-count">1</span>`;
          const addBtn = reactions.querySelector('.reaction-add');
          reactions.insertBefore(r, addBtn);
        }
      }
      menu.remove();
    };
    menu.appendChild(b);
  });
  document.body.appendChild(menu);
  setTimeout(() => {
    document.addEventListener('click', function rm(e) {
      if (!menu.contains(e.target)) { menu.remove(); document.removeEventListener('click', rm); }
    });
  }, 50);
}

// ═══════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════
function _esc(str) {
  return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

// ═══════════════════════════════════════════════════════
// WINDOW EXPORTS
// ═══════════════════════════════════════════════════════
// ── Nav view action helpers ──────────────────────────────────────────────
function _jumpToBookmark(channelId, msgId) {
  if (!channelId) return;
  switchView('home', document.querySelector('.sidebar-nav-item'));
  // Switch to the channel that contains the bookmark
  const channelEl = document.querySelector(`.channel-item[data-channel-id="${channelId}"]`);
  if (channelEl && window.switchChannel) {
    window.switchChannel(channelEl, parseInt(channelId));
    // Highlight the message after load
    setTimeout(() => {
      const msgEl = document.querySelector(`[data-msg-id="${msgId}"]`);
      if (msgEl) {
        msgEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        msgEl.style.transition = 'background 0.4s';
        msgEl.style.background = 'rgba(245,158,11,0.15)';
        setTimeout(() => { msgEl.style.background = ''; }, 2500);
      }
    }, 800);
  } else {
    showToast('Channel not found in sidebar', 'info');
  }
}
function _removeBookmark(msgId) {
  const bm = window._bookmarks;
  if (!bm) return;
  const idx = bm.findIndex(b => b.id == msgId);
  if (idx !== -1) bm.splice(idx, 1);
  localStorage.setItem('ec_bookmarks', JSON.stringify(bm));
}
function _deleteDraftById(channelId) {
  if (window._deleteDraft) window._deleteDraft(channelId);
}
function _editDraftInChannel(channelId, text) {
  switchView('home', document.querySelector('.sidebar-nav-item'));
  const channelEl = document.querySelector(`.channel-item[data-channel-id="${channelId}"]`);
  if (channelEl && window.switchChannel) {
    window.switchChannel(channelEl, parseInt(channelId));
    setTimeout(() => {
      const inputEl = document.getElementById('chatInputField');
      if (inputEl) { inputEl.value = text; inputEl.focus(); }
    }, 600);
  }
}
window._jumpToBookmark = _jumpToBookmark;
window._removeBookmark = _removeBookmark;
window._deleteDraftById = _deleteDraftById;
window._editDraftInChannel = _editDraftInChannel;

Object.assign(window, {
  // GIF
  toggleGifPicker, closeGifPicker, filterGifs, insertEmoji, populateGifPicker,
  // Voice Recording (chat input mic button)
  toggleVoiceRecord, startRecording, stopRecordingToPreview,
  togglePreviewPlay, scrubPreview, discardRecording, reRecord, cancelRecording, sendRecording,
  _toggleChatVoicePlay, _fmtDur,
  // Poll
  addPollOption, submitPoll, castPollVote, updatePollUI,
  openReportModal, submitReport,
  // Search
  openSearchModal, closeSearchOverlay, searchMessages,
  // Active Now
  openActiveNowModal, switchActiveTab, filterActiveNow, _fetchActiveNow,
  // Members Modal
  openMembersPanel, switchMembersTab, filterMembersModal,
  // Matches
  openFullMatchesModal, filterMatchTab, refreshMatches, sendConnectionRequest,
  // Nav Views
  switchView,
  // User Settings
  openUserSettings, closePlatformSettingsOverlay, switchSettingsTab, handleLogout, goToDashboard,
  applyTheme, applyFontSize, saveProfileSettings,
  // Whiteboard (chat-features fallback)
  openWhiteboard, closeWhiteboard,
  // Mini Profile — override chat.js stub with the richer version
  openMiniProfile, closeMiniProfile,
  _chatFeaturesOpenMiniProfile: openMiniProfile,
  // Full Profile Card
  openFullProfileCard, profileCardConnect,
  // Connection request banners
  _respondConnReq, _dismissConnReqBanner, _addConnectionRequestNotif, _showConnectionRequestBanner,
  // Notifications
  toggleNotifications, closeNotifications, markAllRead,
  // Add Server
  selectServerTemplate, backToServerChoices, createServer, joinByInvite,
  // Channel
  togglePrivate, formatChannelName,
  // Extras
  openExtrasAction,
  // Emoji for msg bar
  showEmojiForMsgBar,
});

// Register all functions under __real_* so the chat.php stubs can forward to them
// once defer scripts have finished loading.
(function () {
  var realFns = [
    'openExtrasAction', 'switchActiveTab', 'filterActiveNow', 'openActiveNowModal',
    '_fetchActiveNow', 'openMembersPanel', 'switchMembersTab', 'filterMembersModal',
    'openFullMatchesModal', 'filterMatchTab', 'refreshMatches', 'sendConnectionRequest',
    'switchView', 'openSearchModal', 'closeSearchOverlay', 'searchMessages',
    'openUserSettings', 'closePlatformSettingsOverlay', 'switchSettingsTab',
    'handleLogout', 'goToDashboard', 'applyTheme', 'applyFontSize', 'saveProfileSettings',
    'openMiniProfile', 'closeMiniProfile', 'toggleNotifications', 'markAllRead',
    'filterSidebar', 'generateAIReply', 'addPollOption', 'submitPoll', 'castPollVote', 'updatePollUI',
    'openReportModal', 'submitReport',
    'selectServerTemplate', 'backToServerChoices', 'createServer', 'joinByInvite',
    'togglePrivate', 'formatChannelName', 'showEmojiForMsgBar',
  ];
  realFns.forEach(function (name) {
    if (typeof window[name] === 'function') {
      window['__real_' + name] = window[name];
    }
  });
  // Fix for openExtrasAction infinite recursion in chat.js stub
  if (typeof openExtrasAction === 'function') {
    window.__openExtrasAction = openExtrasAction;
  }
})();
// ── Threads: helper + openThreadDM ──────────────────────────────────────────

function _relTime(dateStr) {
  if (!dateStr) return '';
  const d = new Date(dateStr);
  const now = new Date();
  const diff = (now - d) / 1000;
  if (diff < 60) return 'now';
  if (diff < 3600) return Math.floor(diff / 60) + 'm';
  if (diff < 86400) return Math.floor(diff / 3600) + 'h';
  return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
}

/**
 * Opens a DM conversation with `userId` via the DM system,
 * then switches the main panel to show that DM.
 * Called when user clicks a member in the Threads view.
 */
async function openThreadDM(userId, displayName) {
  try {
    const base = window.ECOLLAB?.baseUrl || '';
    const res = await fetch(`${base}/API/dm/open-conversation.php?partner_id=${userId}`, {
      headers: { 'X-CSRF-Token': window.ECOLLAB?.csrfToken || '' }
    });
    const data = await res.json();
    if (!data.success) { showToast('Could not open conversation', 'info'); return; }

    // Switch back to home view (chat main)
    switchView('home', document.querySelector('.sidebar-nav-item'));

    // Delegate to DM panel if available (dm-notifications.js / chat-features DM panel)
    if (typeof window.openDMConversation === 'function') {
      window.openDMConversation(data);
    } else {
      // Fallback: show a DM overlay panel
      _showInlineDMPanel(data, displayName);
    }
  } catch (e) {
    showToast('Failed to open thread', 'info');
    console.error(e);
  }
}
window.openThreadDM = openThreadDM;

/**
 * Called from miniProfile "Thread" button.
 * Reads the current mini-profile user ID and opens a DM.
 */
function openThreadDMFromMiniProfile() {
  // Use _miniProfileUserId set by openMiniProfile, or fallback to dataset
  const mp = document.getElementById('miniProfile');
  const userId = (typeof _miniProfileUserId !== 'undefined' && _miniProfileUserId)
    ? _miniProfileUserId
    : (mp ? parseInt(mp.dataset.userId || '0') : 0);
  const name = document.getElementById('mpName')?.textContent || 'User';
  closeMiniProfile();
  if (userId) openThreadDM(userId, name);
  else showToast('Could not identify user', 'info');
}
window.openThreadDMFromMiniProfile = openThreadDMFromMiniProfile;

/**
 * Inline DM panel — shown in the chat-main area when a Thread DM is opened
 * and no dedicated DM panel component is present.
 */
function _showInlineDMPanel(convData, displayName) {
  const base = window.ECOLLAB?.baseUrl || '';
  const meId = window.ECOLLAB?.userId || 0;
  const partner = convData.partner || {};
  const grad = partner.avatar_color_gradient || '#a855f7,#ec4899';
  const [c1, c2] = grad.split(',');
  const init = (partner.full_name || partner.username || displayName || '?').charAt(0).toUpperCase();
  const convId = convData.conversation_id;

  let panel = document.getElementById('inlineDMPanel');
  if (!panel) {
    panel = document.createElement('div');
    panel.id = 'inlineDMPanel';
    const chatMain = document.querySelector('.chat-main');
    if (chatMain) chatMain.parentNode.insertBefore(panel, chatMain.nextSibling);
  }

  const renderMessages = (msgs) => msgs.map(msg => {
    const isMe = parseInt(msg.sender_id) === parseInt(meId);
    const mGrad = msg.sender_gradient || '#3b82f6,#6366f1';
    const [mg1, mg2] = mGrad.split(',');
    const mInit = (msg.sender_name || msg.sender_username || '?').charAt(0).toUpperCase();
    return `
    <div style="display:flex;gap:10px;align-items:flex-start;${isMe ? 'flex-direction:row-reverse;' : ''}">
      <div style="width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,${mg1},${mg2});display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:#fff;flex-shrink:0;">${mInit}</div>
      <div style="max-width:70%;">
        <div style="font-size:10px;color:var(--text-muted);margin-bottom:2px;${isMe?'text-align:right;':''}">${_esc(msg.sender_name||msg.sender_username)} · ${_relTime(msg.created_at)}</div>
        <div style="background:${isMe?'var(--gradient-main)':'var(--bg-tertiary)'};border-radius:10px;padding:8px 12px;font-size:13px;color:${isMe?'#fff':'var(--text-primary)'};word-break:break-word;">${_esc(msg.body)}</div>
      </div>
    </div>`;
  }).join('');

  panel.style.cssText = 'flex:1;display:flex;flex-direction:column;height:100vh;overflow:hidden;background:var(--bg-primary);';
  panel.innerHTML = `
    <div style="height:56px;flex-shrink:0;display:flex;align-items:center;gap:12px;padding:0 20px;border-bottom:1px solid var(--border);background:var(--bg-secondary);">
      <button onclick="document.getElementById('inlineDMPanel').style.display='none';document.querySelector('.chat-main').style.display=''" style="background:var(--bg-tertiary);border:1px solid var(--border);border-radius:8px;padding:6px 12px;font-size:12px;color:var(--text-secondary);cursor:pointer;font-family:inherit;">← Back</button>
      <div style="width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,${c1},${c2});display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;color:#fff;">${init}</div>
      <div>
        <div style="font-size:14px;font-weight:700;color:var(--text-primary);">${_esc(partner.full_name || partner.username || displayName)}</div>
        <div style="font-size:11px;color:var(--text-muted);">Thread — private message</div>
      </div>
    </div>
    <div id="dmMsgArea" style="flex:1;overflow-y:auto;padding:16px 20px;display:flex;flex-direction:column;gap:12px;">
      ${renderMessages(convData.messages || [])}
      ${(convData.messages||[]).length === 0 ? `<div style="text-align:center;color:var(--text-muted);padding:40px 0;font-size:13px;">No messages yet.<br>Start the conversation!</div>` : ''}
    </div>
    <div style="padding:12px 16px;border-top:1px solid var(--border);background:var(--bg-secondary);display:flex;gap:8px;align-items:flex-end;">
      <textarea id="dmInput" placeholder="Message ${_esc(partner.full_name||partner.username||displayName)}…"
        style="flex:1;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:10px;padding:10px 14px;font-size:13px;color:var(--text-primary);outline:none;resize:none;font-family:'Inter',sans-serif;min-height:40px;max-height:120px;line-height:1.4;"
        onkeydown="if((event.ctrlKey||event.metaKey)&&event.key==='Enter'){sendDMFromPanel(${convId});event.preventDefault();}"
        oninput="this.style.height='auto';this.style.height=this.scrollHeight+'px';"></textarea>
      <button onclick="sendDMFromPanel(${convId})"
        style="padding:10px 18px;background:var(--gradient-main);border:none;border-radius:10px;color:#fff;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;flex-shrink:0;">
        Send
      </button>
    </div>`;

  // Scroll to bottom
  const area = panel.querySelector('#dmMsgArea');
  if (area) area.scrollTop = area.scrollHeight;
}

async function sendDMFromPanel(convId) {
  const input = document.getElementById('dmInput');
  const text = input?.value?.trim();
  if (!text || !convId) return;
  input.value = '';
  input.style.height = 'auto';

  try {
    const base = window.ECOLLAB?.baseUrl || '';
    const data = await apiFetch(`${base}/API/dm/send-message.php`, {
      method: 'POST',
      body: JSON.stringify({ conversation_id: convId, body: text }),
    });
    if (data.success) {
      // Append message to panel
      const area = document.getElementById('dmMsgArea');
      if (area) {
        const meId = window.ECOLLAB?.userId || 0;
        const meGrad = window.ECOLLAB?.avatarGradient || '#3b82f6,#6366f1';
        const [mg1, mg2] = meGrad.split(',');
        const meName = window.ECOLLAB?.fullName || window.ECOLLAB?.username || 'Me';
        const mInit = meName.charAt(0).toUpperCase();
        const msgEl = document.createElement('div');
        msgEl.style.cssText = 'display:flex;gap:10px;align-items:flex-start;flex-direction:row-reverse;';
        msgEl.innerHTML = `
          <div style="width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,${mg1},${mg2});display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:#fff;flex-shrink:0;">${mInit}</div>
          <div style="max-width:70%;">
            <div style="font-size:10px;color:var(--text-muted);margin-bottom:2px;text-align:right;">${_esc(meName)} · now</div>
            <div style="background:var(--gradient-main);border-radius:10px;padding:8px 12px;font-size:13px;color:#fff;word-break:break-word;">${_esc(text)}</div>
          </div>`;
        // Remove empty state placeholder if present
        area.querySelector('div[style*="No messages yet"]')?.remove();
        area.appendChild(msgEl);
        area.scrollTop = area.scrollHeight;
      }
    }
  } catch (e) {
    showToast('Failed to send message', 'info');
  }
}
window.sendDMFromPanel = sendDMFromPanel;

// ── Private channel member selector ─────────────────────────────────────────

function togglePrivateMembersSection() {
  const toggle = document.getElementById('privateChannelToggle');
  const section = document.getElementById('privateMembersSection');
  if (!section) return;
  const isOn = toggle?.classList.contains('on');
  section.style.display = isOn ? 'block' : 'none';
  if (isOn) _loadPrivateMemberSelector();
}
window.togglePrivateMembersSection = togglePrivateMembersSection;

// Store selected user IDs for the new private channel
window._privateChannelSelectedUsers = new Set();

async function _loadPrivateMemberSelector() {
  const loadingEl = document.getElementById('privateMembersLoading');
  const listEl = document.getElementById('privateMembersList');
  if (!listEl) return;
  window._privateChannelSelectedUsers = new Set();
  if (loadingEl) loadingEl.style.display = 'block';
  listEl.innerHTML = '';

  try {
    const base = window.ECOLLAB?.baseUrl || '';
    const servId = window.ECOLLAB?.currentServerId || 0;
    const res = await fetch(`${base}/API/active-now.php?server_id=${servId}&action=get_all_members`, {
      headers: { 'X-CSRF-Token': window.ECOLLAB?.csrfToken || '' }
    });
    // active-now doesn't have get_all_members — use get-channels to infer, but really
    // we should use the threads endpoint which lists all server members
    const res2 = await fetch(`${base}/API/threads/get-server-members.php?server_id=${servId}`, {
      headers: { 'X-CSRF-Token': window.ECOLLAB?.csrfToken || '' }
    });
    const d = await res2.json();
    if (loadingEl) loadingEl.style.display = 'none';
    if (!d.success || !d.members?.length) {
      listEl.innerHTML = '<div style="color:var(--text-muted);font-size:12px;padding:8px 0;">No other members found.</div>';
      return;
    }
    d.members.forEach(m => {
      const grad = m.grad || '#a855f7,#ec4899';
      const [c1, c2] = grad.split(',');
      const init = (m.full_name || m.username || '?').charAt(0).toUpperCase();
      const name = _esc(m.nickname || m.full_name || m.username);
      const el = document.createElement('div');
      el.dataset.userId = m.id;
      el.style.cssText = 'display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:8px;cursor:pointer;border:1px solid var(--border);background:var(--bg-tertiary);transition:0.12s;';
      el.innerHTML = `
        <div style="width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,${c1},${c2});display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:#fff;flex-shrink:0;">${init}</div>
        <div style="flex:1;font-size:12px;font-weight:600;color:var(--text-primary);">${name}</div>
        <div class="priv-lock-icon" style="flex-shrink:0;display:flex;align-items:center;">
          <svg class="lock-closed" width="14" height="14" viewBox="0 0 24 24" fill="var(--text-muted)"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg>
          <svg class="lock-open" width="14" height="14" viewBox="0 0 24 24" fill="#22c55e" style="display:none;"><path d="M12 1C9.24 1 7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2H9V6c0-1.66 1.34-3 3-3 1.19 0 2.22.7 2.73 1.72l1.73-1C15.84 2.03 14.06 1 12 1zm0 13c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2z"/></svg>
        </div>`;
      el.addEventListener('click', () => {
        const uid = parseInt(el.dataset.userId);
        if (window._privateChannelSelectedUsers.has(uid)) {
          window._privateChannelSelectedUsers.delete(uid);
          el.style.borderColor = 'var(--border)';
          el.style.background = 'var(--bg-tertiary)';
          el.querySelector('.lock-closed').style.display = '';
          el.querySelector('.lock-open').style.display = 'none';
        } else {
          window._privateChannelSelectedUsers.add(uid);
          el.style.borderColor = 'rgba(34,197,94,0.4)';
          el.style.background = 'rgba(34,197,94,0.06)';
          el.querySelector('.lock-closed').style.display = 'none';
          el.querySelector('.lock-open').style.display = '';
        }
      });
      listEl.appendChild(el);
    });
  } catch (e) {
    if (loadingEl) loadingEl.style.display = 'none';
    listEl.innerHTML = '<div style="color:var(--text-muted);font-size:12px;">Failed to load members.</div>';
  }
}

// ── Expose openMiniProfile with userId stored on the element ─────────────────
// Override so Thread button can read mpUserId
const _origOpenMiniProfile = window.openMiniProfile;
window.openMiniProfile = function(event, name, role, avatarClass, initials, userId) {
  if (_origOpenMiniProfile) _origOpenMiniProfile(event, name, role, avatarClass, initials, userId);
  // Store userId on panel for Thread button
  const mp = document.getElementById('miniProfile');
  if (mp) mp.dataset.userId = userId || '';
};

// Ensure new functions are registered as __real_* stubs
(function () {
  ['openThreadDM', 'openThreadDMFromMiniProfile', 'sendDMFromPanel', 'togglePrivateMembersSection'].forEach(n => {
    if (typeof window[n] === 'function') window['__real_' + n] = window[n];
  });
})();

// ═══════════════════════════════════════════════════════════════════════════
// PRIVATE CHANNEL MANAGER
// ═══════════════════════════════════════════════════════════════════════════

let _pcmChannelId  = 0;
let _pcmActiveTab  = 'members';

function openPrivateChannelManager() {
  const meta = window._currentChannelMeta;
  if (!meta || !meta.id) { showToast('No channel selected', 'info'); return; }
  _pcmChannelId = meta.id;
  _pcmActiveTab = 'members';

  const modal = document.getElementById('privateChannelManagerModal');
  const nameEl = document.getElementById('pcmChannelName');
  if (nameEl) nameEl.textContent = '#' + meta.name;
  if (modal) modal.style.display = 'flex';

  pcmSwitchTab('members');
}
window.openPrivateChannelManager = openPrivateChannelManager;

function closePrivateChannelManager() {
  const modal = document.getElementById('privateChannelManagerModal');
  if (modal) modal.style.display = 'none';
}
window.closePrivateChannelManager = closePrivateChannelManager;

function pcmSwitchTab(tab) {
  _pcmActiveTab = tab;
  const tabs = { members: document.getElementById('pcmTabMembers'), requests: document.getElementById('pcmTabRequests') };
  Object.entries(tabs).forEach(([k, btn]) => {
    if (!btn) return;
    btn.style.borderBottomColor = k === tab ? '#a855f7' : 'transparent';
    btn.style.color             = k === tab ? '#a855f7' : 'var(--text-muted)';
  });
  if (tab === 'members')  _pcmLoadMembers();
  if (tab === 'requests') _pcmLoadRequests();
}
window.pcmSwitchTab = pcmSwitchTab;

async function _pcmLoadMembers() {
  const content = document.getElementById('pcmContent');
  if (!content) return;
  content.innerHTML = '<div style="text-align:center;color:var(--text-muted);padding:32px;font-size:13px;">Loading…</div>';

  try {
    const base = window.ECOLLAB?.baseUrl || '';
    const res  = await fetch(`${base}/API/chat/channel-members.php?channel_id=${_pcmChannelId}`, {
      credentials: 'same-origin',
      headers: { 'X-CSRF-Token': window.ECOLLAB?.csrfToken || '' }
    });
    const d = await res.json();
    if (!d.success) { content.innerHTML = `<div style="color:#f87171;padding:20px;">${_esc(d.error || 'Error')}</div>`; return; }

    const members = d.members || [];
    if (!members.length) {
      content.innerHTML = '<div style="text-align:center;color:var(--text-muted);padding:32px;font-size:13px;">No other server members found.</div>';
      return;
    }

    content.innerHTML = `
      <div style="font-size:11px;color:var(--text-muted);margin-bottom:10px;">
        Toggle access for each server member. <span style="color:#22c55e;">🔓 Unlocked</span> = can see this channel. <span style="color:var(--text-muted);">🔒 Locked</span> = no access.
      </div>
      <div style="display:flex;flex-direction:column;gap:6px;">
        ${members.map(m => {
          const grad   = m.grad || '#a855f7,#ec4899';
          const [c1,c2]= grad.split(',');
          const init   = (m.full_name || m.username || '?').charAt(0).toUpperCase();
          const name   = _esc(m.nickname || m.full_name || m.username);
          const access = m.has_access == 1;
          return `
          <div data-member-id="${m.id}" data-has-access="${access ? '1':'0'}" style="display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:10px;border:1px solid ${access ? 'rgba(34,197,94,0.3)' : 'var(--border)'};background:${access ? 'rgba(34,197,94,0.04)' : 'var(--bg-tertiary)'};transition:0.15s;">
            <div style="width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,${c1},${c2});display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;color:#fff;flex-shrink:0;">${init}</div>
            <div style="flex:1;min-width:0;">
              <div style="font-size:13px;font-weight:600;color:var(--text-primary);">${name}</div>
              <div style="font-size:11px;color:var(--text-muted);">${m.server_role || 'member'}</div>
            </div>
            <button onclick="_pcmToggleAccess(this,${m.id},${access ? '1':'0'})"
              style="padding:5px 12px;border-radius:8px;border:1px solid ${access ? 'rgba(34,197,94,0.4)' : 'var(--border)'};background:${access ? 'rgba(34,197,94,0.1)' : 'var(--bg-card)'};color:${access ? '#22c55e' : 'var(--text-muted)'};font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;transition:0.12s;display:flex;align-items:center;gap:5px;white-space:nowrap;">
              ${access
                ? `<svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M12 1C9.24 1 7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2H9V6c0-1.66 1.34-3 3-3 1.19 0 2.22.7 2.73 1.72l1.73-1C15.84 2.03 14.06 1 12 1zm0 13c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2z"/></svg> Unlocked`
                : `<svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg> Locked`
              }
            </button>
          </div>`;
        }).join('')}
      </div>`;
  } catch (e) {
    content.innerHTML = `<div style="color:#f87171;padding:20px;">Failed to load members.</div>`;
  }
}

async function _pcmToggleAccess(btn, userId, currentAccess) {
  const action = currentAccess == 1 ? 'remove' : 'add';
  btn.disabled = true;
  btn.style.opacity = '0.5';
  try {
    const base = window.ECOLLAB?.baseUrl || '';
    const d = await apiFetch(`${base}/API/chat/channel-members.php`, {
      method: 'POST',
      body: JSON.stringify({ action, channel_id: _pcmChannelId, user_id: userId }),
    });
    if (d.success) {
      showToast(action === 'add' ? '🔓 Access granted' : '🔒 Access removed', 'success');
      _pcmLoadMembers(); // refresh
    }
  } catch (e) {
    showToast('Failed to update access', 'info');
    btn.disabled = false;
    btn.style.opacity = '';
  }
}
window._pcmToggleAccess = _pcmToggleAccess;

async function _pcmLoadRequests() {
  const content = document.getElementById('pcmContent');
  if (!content) return;
  content.innerHTML = '<div style="text-align:center;color:var(--text-muted);padding:32px;font-size:13px;">Loading requests…</div>';

  try {
    const base = window.ECOLLAB?.baseUrl || '';
    const res  = await fetch(`${base}/API/chat/channel-access-request.php?channel_id=${_pcmChannelId}`, {
      credentials: 'same-origin',
      headers: { 'X-CSRF-Token': window.ECOLLAB?.csrfToken || '' }
    });
    const d = await res.json();
    if (!d.success) { content.innerHTML = `<div style="color:#f87171;padding:20px;">${_esc(d.error || 'Error')}</div>`; return; }

    // Update badge
    const badge = document.getElementById('pcmRequestBadge');
    if (badge) {
      badge.textContent = d.count;
      badge.style.display = d.count > 0 ? '' : 'none';
    }

    if (!d.requests?.length) {
      content.innerHTML = '<div style="text-align:center;color:var(--text-muted);padding:40px 20px;font-size:13px;">📭 No pending access requests.</div>';
      return;
    }

    content.innerHTML = `
      <div style="font-size:11px;color:var(--text-muted);margin-bottom:10px;">
        ${d.requests.length} pending request${d.requests.length !== 1 ? 's' : ''}. Accept to grant channel access, or decline to reject.
      </div>
      <div style="display:flex;flex-direction:column;gap:8px;">
        ${d.requests.map(r => {
          const grad   = r.grad || '#a855f7,#ec4899';
          const [c1,c2]= grad.split(',');
          const init   = (r.full_name || r.username || '?').charAt(0).toUpperCase();
          const name   = _esc(r.full_name || r.username);
          const time   = _relTime(r.requested_at);
          return `
          <div id="pcmReq_${r.user_id}" style="display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;border:1px solid rgba(245,158,11,0.2);background:rgba(245,158,11,0.04);">
            <div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,${c1},${c2});display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;color:#fff;flex-shrink:0;">${init}</div>
            <div style="flex:1;min-width:0;">
              <div style="font-size:13px;font-weight:700;color:var(--text-primary);">${name}</div>
              <div style="font-size:11px;color:var(--text-muted);">Requested ${time} · ${_esc(r.server_role || 'member')}</div>
            </div>
            <div style="display:flex;gap:6px;flex-shrink:0;">
              <button onclick="_pcmRespondRequest(${r.user_id},'accept',this)" style="padding:6px 14px;border-radius:8px;background:rgba(34,197,94,0.12);border:1px solid rgba(34,197,94,0.3);color:#22c55e;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;transition:0.12s;" onmouseover="this.style.background='rgba(34,197,94,0.22)'" onmouseout="this.style.background='rgba(34,197,94,0.12)'">✓ Accept</button>
              <button onclick="_pcmRespondRequest(${r.user_id},'decline',this)" style="padding:6px 14px;border-radius:8px;background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.2);color:#f87171;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;transition:0.12s;" onmouseover="this.style.background='rgba(239,68,68,0.18)'" onmouseout="this.style.background='rgba(239,68,68,0.08)'">✕ Decline</button>
            </div>
          </div>`;
        }).join('')}
      </div>`;
  } catch (e) {
    content.innerHTML = `<div style="color:#f87171;padding:20px;">Failed to load requests.</div>`;
  }
}

async function _pcmRespondRequest(userId, action, btn) {
  btn.disabled = true;
  const row = document.getElementById(`pcmReq_${userId}`);
  try {
    const base = window.ECOLLAB?.baseUrl || '';
    const d = await apiFetch(`${base}/API/chat/channel-access-request.php`, {
      method: 'POST',
      body: JSON.stringify({ action, channel_id: _pcmChannelId, user_id: userId }),
    });
    if (d.success) {
      if (row) {
        row.style.transition = 'opacity 0.3s';
        row.style.opacity = '0';
        setTimeout(() => row.remove(), 300);
      }
      showToast(action === 'accept' ? '✅ Access granted!' : '✕ Request declined', action === 'accept' ? 'success' : 'info');
      // Refresh request count badge
      setTimeout(_pcmLoadRequests, 350);
    }
  } catch (e) {
    showToast('Failed to respond', 'info');
    btn.disabled = false;
  }
}
window._pcmRespondRequest = _pcmRespondRequest;

// ── Request Access (for non-members trying to enter a private channel) ─────

async function requestChannelAccess() {
  const meta = window._currentChannelMeta;
  if (!meta?.id) return;

  const btn    = document.getElementById('accessBannerBtn');
  const status = document.getElementById('accessBannerStatus');
  if (btn) { btn.disabled = true; btn.textContent = 'Sending…'; }

  try {
    const base = window.ECOLLAB?.baseUrl || '';
    const d = await apiFetch(`${base}/API/chat/channel-access-request.php`, {
      method: 'POST',
      body: JSON.stringify({ action: 'request', channel_id: meta.id }),
    });
    if (d.success) {
      if (status) {
        status.style.cssText = 'display:block;background:rgba(34,197,94,0.1);color:#22c55e;border:1px solid rgba(34,197,94,0.2);border-radius:8px;padding:6px 12px;font-size:12px;margin-bottom:12px;';
        status.textContent = '✓ Request sent! The channel owner will review it.';
      }
      if (btn) { btn.disabled = true; btn.textContent = 'Request Sent'; }
    } else {
      if (status) {
        status.style.cssText = 'display:block;background:rgba(239,68,68,0.08);color:#f87171;border:1px solid rgba(239,68,68,0.15);border-radius:8px;padding:6px 12px;font-size:12px;margin-bottom:12px;';
        status.textContent = d.error || 'Failed to send request.';
      }
      if (btn) { btn.disabled = false; btn.textContent = 'Request Access'; }
    }
  } catch (e) {
    if (btn) { btn.disabled = false; btn.textContent = 'Request Access'; }
    showToast('Failed to send request', 'info');
  }
}
window.requestChannelAccess = requestChannelAccess;

// ── Auto-poll pending request count for current private channel ─────────────
async function _pollPrivateChannelRequests() {
  try {
    const meta = window._currentChannelMeta;
    if (!meta?.isPrivate || !meta?.canManage || !meta?.id) return;
    const base = window.ECOLLAB?.baseUrl || '';
    const res  = await fetch(`${base}/API/chat/channel-access-request.php?channel_id=${meta.id}`, {
      credentials: 'same-origin',
      headers: { 'X-CSRF-Token': window.ECOLLAB?.csrfToken || '' }
    });
    const d = await res.json();
    if (!d.success) return;
    const count = d.count || 0;
    const manageBtn = document.getElementById('manageChannelBtn');
    // Show dot on manage button when there are pending requests
    if (manageBtn) {
      let dot = manageBtn.querySelector('.req-dot');
      if (count > 0) {
        if (!dot) {
          dot = document.createElement('span');
          dot.className = 'req-dot';
          dot.style.cssText = 'position:absolute;top:4px;right:4px;width:8px;height:8px;border-radius:50%;background:#ef4444;border:1px solid var(--bg-secondary);';
          manageBtn.style.position = 'relative';
          manageBtn.appendChild(dot);
        }
        dot.title = `${count} pending request${count !== 1 ? 's' : ''}`;
      } else if (dot) {
        dot.remove();
      }
    }
    // Also update tabs badge if modal is open
    const badge = document.getElementById('pcmRequestBadge');
    if (badge) { badge.textContent = count; badge.style.display = count > 0 ? '' : 'none'; }
  } catch (_) {}
}

// Poll every 30s when page is visible
setInterval(() => {
  if (!document.hidden) _pollPrivateChannelRequests();
}, 30000);
