/**
 * voice.js — Voice channel & WebRTC management for Ecollab Chat
 * Discord-style: voice runs in background while you use text channels.
 * The voice panel is a floating overlay that can be minimized to a pip.
 */

'use strict';

// ── State ──────────────────────────────────────────────────────────────────
let vcMicMuted   = true;
let vcDeafened   = false;
let vcCamOn      = false;
let vcScreenOn   = false;
let vcMinimized  = false;
let vcActive     = false;       // true while connected to a voice channel
let vcRoomName   = '';
let vcChannelId  = null;        // current voice channel id
let localStream  = null;

// WebRTC state
let peerConnections = {};       // user_id => RTCPeerConnection
let remoteStreams    = {};       // user_id => MediaStream

// ICE servers — works on localhost (no STUN needed for same-machine), with STUN for LAN
const ICE_SERVERS = [
  { urls: 'stun:stun.l.google.com:19302' },
  { urls: 'stun:stun1.l.google.com:19302' },
];


// ── Join voice channel ─────────────────────────────────────────────────────
// Does NOT hide chatMain — voice floats on top as an overlay.
function joinVoice(channelSlug, el, channelId) {
  // Mark sidebar item
  document.querySelectorAll('.voice-channel').forEach(v => v.classList.remove('connected'));
  if (el) el.classList.add('connected');

  vcActive     = true;
  vcMinimized  = false;
  vcChannelId  = channelId;
  vcRoomName   = el?.textContent?.trim()?.replace(/\d+/g, '').trim() || 'Voice Channel';

  // Show the floating panel (full-screen by default)
  const vcView = document.getElementById('voiceChannelView');
  if (vcView) {
    vcView.classList.remove('vc-minimized');
    vcView.classList.add('active');
    document.body.classList.add('vc-active');
  }

  _setVcLabels();
  _updateConnectedBar(true);
  renderVcUser();
  _ensureMinimizeBtn();

  // Acquire mic first, then notify server (order matters for WebRTC)
  _acquireMic().then(() => {
    // Notify via WebSocket — server will send back voice_peers list
    if (window.chatSocket && window.chatSocket.readyState === WebSocket.OPEN) {
      window.chatSocket.send(JSON.stringify({
        type: 'join_voice',
        channel_id: channelId,
        channel_slug: channelSlug,
      }));
    }
    // Also update via HTTP so active-now sees it immediately
    _reportVoiceStatus('join', channelId);
  });

  showToast('🔊 Joined ' + vcRoomName, 'success');
}

// ── Acquire microphone + enumerate devices ────────────────────────────────
// Works with built-in mics, USB mics, virtual mic apps (WO Mic, VB-Cable etc.)
async function _acquireMic() {
  try {
    const preferredInput = window._vcPreferredInput || '';
    const constraints = {
      audio: preferredInput
        ? { deviceId: { ideal: preferredInput }, echoCancellation: true, noiseSuppression: true, autoGainControl: true }
        : { echoCancellation: true, noiseSuppression: true, autoGainControl: true }
    };

    localStream = await navigator.mediaDevices.getUserMedia(constraints);
    localStream.getAudioTracks().forEach(t => { t.enabled = !vcMicMuted; });

    await _populateDeviceSelects();
    _startVoiceActivityDetection();

    const btn = document.getElementById('vcMicBtn');
    if (btn) btn.classList.toggle('muted-state', vcMicMuted);

    showToast('🎤 Microphone connected', 'success');
  } catch (err) {
    if (err.name === 'NotFoundError') {
      showToast('🎤 No microphone found. Check your device or WO Mic connection.', 'info');
    } else if (err.name === 'NotAllowedError') {
      showToast('🎤 Microphone permission denied. Please allow access in your browser.', 'info');
    } else {
      showToast('🎤 Microphone error: ' + err.message, 'info');
    }
  }
}

// Populate all device selects across VC audio settings and mic test modal
async function _populateDeviceSelects() {
  try {
    const devices = await navigator.mediaDevices.enumerateDevices();
    const inputs  = devices.filter(d => d.kind === 'audioinput');
    const outputs = devices.filter(d => d.kind === 'audiooutput');

    // Selects to populate: audioInputSelect, audioOutputSelect (VC settings modal)
    //                       micTestInputSelect (mic test modal)
    //                       voiceRecordInputSelect (settings panel)
    const inputSelects  = ['audioInputSelect', 'micTestInputSelect', 'voiceRecordInputSelect'];
    const outputSelects = ['audioOutputSelect', 'voiceRecordOutputSelect'];

    inputSelects.forEach(id => {
      const sel = document.getElementById(id);
      if (!sel) return;
      const cur = sel.value;
      sel.innerHTML = inputs.map(d =>
        `<option value="${d.deviceId}" ${d.deviceId === cur ? 'selected' : ''}>
          ${d.label || 'Microphone ' + (inputs.indexOf(d) + 1)}
        </option>`
      ).join('') || '<option value="">No microphone found</option>';
    });

    outputSelects.forEach(id => {
      const sel = document.getElementById(id);
      if (!sel) return;
      const cur = sel.value;
      sel.innerHTML = outputs.map(d =>
        `<option value="${d.deviceId}" ${d.deviceId === cur ? 'selected' : ''}>
          ${d.label || 'Speaker ' + (outputs.indexOf(d) + 1)}
        </option>`
      ).join('') || '<option value="">Default Speaker</option>';
    });
  } catch (e) {
    console.warn('Device enumeration failed:', e);
  }
}

// Listen for device changes (plug/unplug USB mic, WO Mic connect etc.)
navigator.mediaDevices?.addEventListener('devicechange', async () => {
  await _populateDeviceSelects();
  if (vcActive) showToast('🎤 Audio devices updated', 'info');
});

// ── Voice Activity Detection (speaks = animate wave bars) ────────────────
let _vadCtx = null, _vadAnalyser = null, _vadRaf = null;

function _startVoiceActivityDetection() {
  if (!localStream) return;
  try {
    _vadCtx     = new (window.AudioContext || window.webkitAudioContext)();
    const src   = _vadCtx.createMediaStreamSource(localStream);
    _vadAnalyser = _vadCtx.createAnalyser();
    _vadAnalyser.fftSize = 256;
    src.connect(_vadAnalyser);
    _vadLoop();
  } catch (e) { console.warn('VAD init failed:', e); }
}

function _vadLoop() {
  if (!_vadAnalyser || !vcActive) return;
  _vadRaf = requestAnimationFrame(_vadLoop);
  const data = new Uint8Array(_vadAnalyser.frequencyBinCount);
  _vadAnalyser.getByteFrequencyData(data);
  const avg = data.reduce((a, b) => a + b, 0) / data.length;
  const isSpeaking = avg > 12 && !vcMicMuted;

  // Animate the wave bars on user's speaker card
  const card = document.querySelector('.vc-speaker-card[data-user-id]');
  if (card) {
    card.classList.toggle('speaking', isSpeaking);
    const bars = card.querySelectorAll('.sc-wave-bar');
    if (isSpeaking) {
      bars.forEach(bar => {
        bar.style.height = (4 + Math.random() * 20) + 'px';
        bar.style.background = '#22c55e';
      });
    } else {
      bars.forEach((bar, i) => {
        bar.style.height = [14, 8, 20, 10, 16][i] + 'px';
        bar.style.background = '';
      });
    }
  }
}

function _stopVAD() {
  cancelAnimationFrame(_vadRaf);
  _vadRaf = null;
  if (_vadCtx) { _vadCtx.close(); _vadCtx = null; }
  _vadAnalyser = null;
}

function _setVcLabels() {
  const els = {
    vcRoomTitle:     vcRoomName,
    vcRoomSubtitle:  '1 participant',
    vcRoomTitleLarge: vcRoomName,
    vcRoomSubLarge:  'Voice Channel',
  };
  Object.entries(els).forEach(([id, text]) => {
    const el = document.getElementById(id);
    if (el) el.textContent = text;
  });
}

// ── Sidebar connected bar ──────────────────────────────────────────────────
function _updateConnectedBar(visible) {
  const bar = document.getElementById('vcConnectedBar');
  if (!bar) return;
  bar.style.display = visible ? '' : 'none';
  const roomEl = document.getElementById('vcbRoomName');
  if (roomEl) roomEl.textContent = vcRoomName;
}

// ── Minimize button injection ──────────────────────────────────────────────
function _ensureMinimizeBtn() {
  const header = document.querySelector('.vc-header-right');
  if (!header || header.querySelector('.vc-minimize-btn')) return;
  const btn = document.createElement('div');
  btn.className = 'vc-minimize-btn';
  btn.title = 'Minimize';
  btn.onclick = toggleVcMinimize;
  btn.innerHTML = `<svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24">
    <path d="M19 13H5v-2h14v2z"/>
  </svg>`;
  // Insert before the first child (leave button area)
  header.insertBefore(btn, header.firstChild);
}

// ── Minimize / Expand panel ────────────────────────────────────────────────
function toggleVcMinimize() {
  const vcView = document.getElementById('voiceChannelView');
  if (!vcView || !vcActive) return;
  vcMinimized = !vcMinimized;
  vcView.classList.toggle('vc-minimized', vcMinimized);
  document.body.classList.toggle('vc-pip', vcMinimized);

  // Update minimize btn icon
  const btn = vcView.querySelector('.vc-minimize-btn');
  if (btn) {
    btn.title = vcMinimized ? 'Expand' : 'Minimize';
    btn.innerHTML = vcMinimized
      ? `<svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24">
           <path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/>
         </svg>`
      : `<svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24">
           <path d="M19 13H5v-2h14v2z"/>
         </svg>`;
  }
}

// Called from the connected bar "open panel" button
function toggleVcPanelFromBar() {
  if (!vcActive) return;
  const vcView = document.getElementById('voiceChannelView');
  if (!vcView) return;

  if (!vcView.classList.contains('active')) {
    // Panel was closed (shouldn't happen) — re-open
    vcView.classList.add('active');
    vcMinimized = false;
    vcView.classList.remove('vc-minimized');
    document.body.classList.remove('vc-pip');
  } else if (vcMinimized) {
    // Expand from pip
    vcMinimized = false;
    vcView.classList.remove('vc-minimized');
    document.body.classList.remove('vc-pip');
  } else {
    // Minimize
    vcMinimized = true;
    vcView.classList.add('vc-minimized');
    document.body.classList.add('vc-pip');
  }

  const btn = vcView.querySelector('.vc-minimize-btn');
  if (btn) btn.title = vcMinimized ? 'Expand' : 'Minimize';
}

// ── Render current user card ───────────────────────────────────────────────
function renderVcUser() {
  const speakingGrid  = document.getElementById('vcSpeakingGrid');
  const listeningGrid = document.getElementById('vcListeningGrid');
  if (!speakingGrid || !listeningGrid) return;

  const user = window.ECOLLAB || {};
  const grad = user.avatarGradient || '#a855f7,#ec4899';
  const [c1, c2] = grad.split(',');
  const init = user.initials || '?';

  speakingGrid.innerHTML = `
    <div class="vc-speaker-card speaking" data-user-id="${user.userId || 0}">
      <div class="sc-top">
        <div class="sc-avatar" style="background:linear-gradient(135deg,${c1},${c2});">
          ${init}
          <div class="sc-av-ring"></div>
        </div>
        <div class="sc-wave">
          <div class="sc-wave-bar" style="height:14px"></div>
          <div class="sc-wave-bar" style="height:8px"></div>
          <div class="sc-wave-bar" style="height:20px"></div>
          <div class="sc-wave-bar" style="height:10px"></div>
          <div class="sc-wave-bar" style="height:16px"></div>
        </div>
      </div>
      <div class="sc-name">${user.fullName || user.username || 'You'}</div>
      <div class="sc-role">You · Joined now</div>
      <div class="sc-quality">
        <div class="sc-q-bar on" style="height:6px"></div>
        <div class="sc-q-bar on" style="height:10px"></div>
        <div class="sc-q-bar on" style="height:14px"></div>
        <div class="sc-q-bar on" style="height:10px"></div>
      </div>
      <div class="sc-mic-btn" onclick="toggleVcMic()">
        <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24">
          <path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/>
          <path d="M19 10v2a7 7 0 0 1-14 0v-2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
      </div>
    </div>
  `;

  listeningGrid.innerHTML = '';
  updateVcCounts(1, 0);
}

function updateVcCounts(speaking, listening) {
  const total = speaking + listening;
  [
    ['vcSpeakingSection', speaking],
    ['vcListeningSection', listening],
    ['vcMemberCount', total],
  ].forEach(([id, val]) => {
    const el = document.getElementById(id);
    if (el) el.textContent = val;
  });
  const subtitleEl = document.getElementById('vcRoomSubtitle');
  if (subtitleEl) subtitleEl.textContent = total + ' participant' + (total !== 1 ? 's' : '');
}

// ── Add remote participant ─────────────────────────────────────────────────
function addVcParticipant(user, isSpeaking = false) {
  const grad = user.avatar_color_gradient || '#3b82f6,#6366f1';
  const [c1, c2] = grad.split(',');
  const init = (user.full_name || user.username || '?').charAt(0).toUpperCase();

  if (isSpeaking) {
    const grid = document.getElementById('vcSpeakingGrid');
    if (!grid) return;
    const card = document.createElement('div');
    card.className = 'vc-speaker-card';
    card.dataset.userId = user.id;
    card.innerHTML = `
      <div class="sc-top">
        <div class="sc-avatar" style="background:linear-gradient(135deg,${c1},${c2});">${init}</div>
      </div>
      <div class="sc-name">${user.full_name || user.username}</div>
      <div class="sc-role">${user.role || 'Student'}</div>
    `;
    grid.appendChild(card);
  } else {
    const grid = document.getElementById('vcListeningGrid');
    if (!grid) return;
    const card = document.createElement('div');
    card.className = 'vc-listener-card';
    card.dataset.userId = user.id;
    card.innerHTML = `
      <div class="lc-avatar" style="background:linear-gradient(135deg,${c1},${c2});">${init}<div class="lc-online"></div></div>
      <div class="lc-name">${user.full_name || user.username}</div>
      <div class="lc-sub">${user.role || 'Student'}</div>
      <div class="lc-vol">
        <svg width="12" height="12" fill="currentColor" viewBox="0 0 24 24"><path d="M3 9v6h4l5 5V4L7 9H3z"/></svg>
      </div>
    `;
    grid.appendChild(card);
  }

  const speaking  = document.querySelectorAll('.vc-speaker-card').length;
  const listening = document.querySelectorAll('.vc-listener-card').length;
  updateVcCounts(speaking, listening);
}

// ── Leave voice ────────────────────────────────────────────────────────────
function leaveVoice() {
  // If in full panel, show confirm modal; if from bar, confirm inline
  openModal('vcLeaveModal');
}

function confirmLeaveCall() {
  closeModal('vcLeaveModal');
  disconnectVoice();
}

function disconnectVoice() {
  vcActive     = false;
  vcMinimized  = false;

  const vcView = document.getElementById('voiceChannelView');
  if (vcView) {
    vcView.classList.remove('active', 'vc-minimized');
    document.body.classList.remove('vc-active');
    document.body.classList.remove('vc-pip');
  }

  document.querySelectorAll('.voice-channel').forEach(v => v.classList.remove('connected'));
  _updateConnectedBar(false);
  _stopVAD();

  if (localStream) {
    localStream.getTracks().forEach(t => t.stop());
    localStream = null;
  }

  // Close all WebRTC peer connections
  Object.entries(peerConnections).forEach(([uid, pc]) => {
    try { pc.close(); } catch {}
    // Remove remote audio
    const audio = document.getElementById(`remote-audio-${uid}`);
    if (audio) audio.remove();
  });
  peerConnections = {};
  remoteStreams = {};

  if (window.chatSocket && window.chatSocket.readyState === WebSocket.OPEN) {
    window.chatSocket.send(JSON.stringify({ type: 'leave_voice' }));
  }

  _reportVoiceStatus('leave', vcChannelId);
  vcChannelId = null;

  showToast('📵 Left voice channel', 'info');
}

// ── Mic toggle ────────────────────────────────────────────────────────────
function toggleVcMic() {
  vcMicMuted = !vcMicMuted;

  // Update bottom bar mic button
  const btn = document.getElementById('vcMicBtn');
  if (btn) {
    btn.classList.toggle('muted-state', vcMicMuted);
    btn.classList.toggle('unmuted', !vcMicMuted);
    const tooltip = btn.querySelector('.vc-ctrl-tooltip');
    if (tooltip) tooltip.textContent = vcMicMuted ? 'Unmute' : 'Mute';
  }

  // Mute actual audio track
  if (localStream) {
    localStream.getAudioTracks().forEach(t => { t.enabled = !vcMicMuted; });
  }

  // Move user card between Speaking ↔ Listening sections
  _moveUserCardOnMute(vcMicMuted);

  // Notify via WebSocket
  if (window.chatSocket && window.chatSocket.readyState === WebSocket.OPEN) {
    window.chatSocket.send(JSON.stringify({ type: 'presence', muted: vcMicMuted }));
  }
}

// Move current user's card between Speaking and Listening grids
function _moveUserCardOnMute(isMuted) {
  const userId   = window.ECOLLAB?.userId || 0;
  const user     = window.ECOLLAB || {};
  const grad     = user.avatarGradient || '#a855f7,#ec4899';
  const [c1, c2] = grad.split(',');
  const init     = user.initials || '?';
  const name     = user.fullName || user.username || 'You';
  const role     = user.role || 'Student';

  const speakingGrid  = document.getElementById('vcSpeakingGrid');
  const listeningGrid = document.getElementById('vcListeningGrid');
  if (!speakingGrid || !listeningGrid) return;

  // Preserve live camera stream before removing old card (from either card type)
  const existingCam = document.querySelector('.vc-cam-preview');
  const savedCamStream = (existingCam && existingCam.srcObject) ? existingCam.srcObject : null;

  // Remove existing user card from both grids
  const existing = document.querySelector(`.vc-speaker-card[data-user-id="${userId}"], .vc-listener-card[data-user-id="${userId}"]`);
  if (existing) existing.remove();

  if (isMuted) {
    // Add to Listening grid as a compact listener card
    const card = document.createElement('div');
    card.className = 'vc-listener-card';
    card.dataset.userId = userId;
    card.innerHTML = `
      <div class="lc-avatar" style="background:linear-gradient(135deg,${c1},${c2});">
        ${init}
        <div class="lc-online"></div>
      </div>
      <div class="lc-name">${name}</div>
      <div class="lc-sub">You · Muted</div>
      <div class="lc-vol" title="Muted">
        <svg width="12" height="12" fill="currentColor" viewBox="0 0 24 24">
          <path d="M16.5 12A4.5 4.5 0 0 0 12 7.5v2.17l4.45 4.45c.03-.2.05-.41.05-.62zM19 12c0 .94-.2 1.82-.54 2.64l1.51 1.51A9.9 9.9 0 0 0 21 12c0-5.52-3.99-10.1-9.28-10.82v2.02C15.89 3.99 19 7.6 19 12zm-8.5-9.98v2.02A7.003 7.003 0 0 1 17 12c0 .77-.12 1.51-.34 2.22l1.52 1.52C18.69 14.35 19 13.21 19 12c0-4.97-3.5-9.12-8.28-9.84l-.22-.14zM3 4.27l2.55 2.55A9.9 9.9 0 0 0 3 12c0 5.52 3.99 10.1 9.28 10.82v-2.02A8.002 8.002 0 0 1 5 12c0-1.83.62-3.52 1.65-4.87L3 3.73 1.27 4l1.73 1.73L3 4.27zM12 20.98v-2.02a5.002 5.002 0 0 1-4.5-5l-2-2A7 7 0 0 0 12 20.98z"/>
        </svg>
      </div>
    `;
    listeningGrid.appendChild(card);

    // Re-attach camera into listener card if camera was on
    if (vcCamOn && savedCamStream) {
      let vid = card.querySelector('.vc-cam-preview');
      if (!vid) {
        vid = document.createElement('video');
        vid.className = 'vc-cam-preview';
        vid.autoplay = true;
        vid.muted = true;
        vid.playsInline = true;
        card.insertBefore(vid, card.firstChild);
      }
      vid.srcObject = savedCamStream;
      card.classList.add('has-camera');
    }

    showToast('🔇 You are now in listening mode', 'info');
  } else {
    // Rebuild full speaking card
    const card = document.createElement('div');
    card.className = 'vc-speaker-card speaking';
    card.dataset.userId = userId;
    card.innerHTML = `
      <div class="sc-top">
        <div class="sc-avatar" style="background:linear-gradient(135deg,${c1},${c2});">
          ${init}
          <div class="sc-av-ring"></div>
        </div>
        <div class="sc-wave">
          <div class="sc-wave-bar" style="height:14px"></div>
          <div class="sc-wave-bar" style="height:8px"></div>
          <div class="sc-wave-bar" style="height:20px"></div>
          <div class="sc-wave-bar" style="height:10px"></div>
          <div class="sc-wave-bar" style="height:16px"></div>
        </div>
      </div>
      <div class="sc-name">${name}</div>
      <div class="sc-role">You · Unmuted</div>
      <div class="sc-mic-btn" onclick="toggleVcMic()">
        <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24">
          <path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/>
          <path d="M19 10v2a7 7 0 0 1-14 0v-2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
      </div>
    `;
    speakingGrid.appendChild(card);

    // Re-attach camera stream if camera was on before mute
    if (vcCamOn && savedCamStream) {
      let vid = card.querySelector('.vc-cam-preview');
      if (!vid) {
        vid = document.createElement('video');
        vid.className = 'vc-cam-preview';
        vid.autoplay = true;
        vid.muted = true;
        vid.playsInline = true;
        const scTop = card.querySelector('.sc-top');
        card.insertBefore(vid, scTop || card.firstChild);
      }
      vid.srcObject = savedCamStream;
      card.classList.add('has-camera');
    }

    showToast('🎤 Microphone on — you are speaking', 'success');
  }

  // Update counts
  const speakingCount  = document.querySelectorAll('.vc-speaker-card').length;
  const listeningCount = document.querySelectorAll('.vc-listener-card').length;
  updateVcCounts(speakingCount, listeningCount);
}

// ── Deafen toggle ─────────────────────────────────────────────────────────
function toggleVcDeafen() {
  vcDeafened = !vcDeafened;
  const btn = document.getElementById('vcDeafBtn');
  if (btn) btn.classList.toggle('deafened', vcDeafened);
  if (localStream) {
    localStream.getAudioTracks().forEach(t => { if (!vcMicMuted) t.enabled = !vcDeafened; });
  }
  showToast(vcDeafened ? '🔇 Deafened' : '🔊 Undeafened', 'info');
}

// ── Screen share ──────────────────────────────────────────────────────────
function toggleScreenShare() {
  if (!vcScreenOn) openModal('vcScreenModal');
  else stopScreenShare();
}

function closeScreenShare() { closeModal('vcScreenModal'); }
function selectScreenQuality(btn, quality) {
  document.querySelectorAll('.screen-quality-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
}

// ── Whiteboard ────────────────────────────────────────────────────────────
function openWhiteboard() {
  if (!vcMinimized) toggleVcMinimize();
  if (window.openWhiteboardView) window.openWhiteboardView();
  else showToast('📋 Whiteboard feature requires a whiteboard channel', 'info');
}

// ── Noise / Audio settings helpers ────────────────────────────────────────
function selectNoiseMode(el, mode) {
  document.querySelectorAll('.noise-option').forEach(o => o.classList.remove('active'));
  el.classList.add('active');
}
function saveNoiseMode() {
  const active = document.querySelector('.noise-option.active');
  const mode   = active?.querySelector('.no-name')?.textContent || 'Standard';
  showToast('🎙️ Noise cancellation: ' + mode, 'success');
  closeModal('vcNoiseCancelModal');
}

// ── Sidebar footer mic/deafen (not tied to voice channel) ────────────────
function toggleMute(e) {
  if (e) e.stopPropagation();
  const btn = document.getElementById('muteBtn');
  if (btn) btn.classList.toggle('muted');
  showToast(btn?.classList.contains('muted') ? '🔇 Muted' : '🎤 Unmuted', 'info');
}
function toggleDeafen(e) {
  if (e) e.stopPropagation();
  const btn = document.getElementById('deafenBtn');
  if (btn) btn.classList.toggle('deafened');
  showToast(btn?.classList.contains('deafened') ? '🔕 Deafened' : '🔔 Undeafened', 'info');
}

// ── Voice recording ───────────────────────────────────────────────────────
let mediaRecorder   = null;
let recordedChunks  = [];
let recordTimer     = null;
let recordSeconds   = 0;
let recordingActive = false;

function toggleVoiceRecord() {
  if (!recordingActive) startVoiceRecording();
  else stopVoiceRecording();
}

async function startVoiceRecording() {
  try {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    mediaRecorder   = new MediaRecorder(stream);
    recordedChunks  = [];
    recordingActive = true;
    recordSeconds   = 0;

    mediaRecorder.ondataavailable = e => { if (e.data.size > 0) recordedChunks.push(e.data); };
    mediaRecorder.onstop          = handleRecordingStop;
    mediaRecorder.start(100);

    const micBtn = document.getElementById('micBtn');
    if (micBtn) micBtn.classList.add('active');

    recordTimer = setInterval(() => {
      recordSeconds++;
      const min = String(Math.floor(recordSeconds / 60)).padStart(2, '0');
      const sec = String(recordSeconds % 60).padStart(2, '0');
      showToast(`🎤 Recording ${min}:${sec}`, 'info');
    }, 1000);

    showToast('🎤 Recording started — click mic to stop', 'info');
  } catch {
    showToast('🎤 Microphone access denied', 'info');
  }
}

function stopVoiceRecording() {
  if (mediaRecorder && mediaRecorder.state !== 'inactive') {
    mediaRecorder.stop();
    mediaRecorder.stream.getTracks().forEach(t => t.stop());
  }
  clearInterval(recordTimer);
  recordingActive = false;
  const micBtn = document.getElementById('micBtn');
  if (micBtn) micBtn.classList.remove('active');
}

function handleRecordingStop() {
  const blob = new Blob(recordedChunks, { type: 'audio/webm' });
  showToast('🎤 Recording ready — ' + recordSeconds + 's', 'success');
  uploadAudioBlob(blob);
}

async function uploadAudioBlob(blob) {
  if (!window.ECOLLAB?.currentChannelId) return;
  const fd = new FormData();
  fd.append('file', blob, 'voice-message.webm');
  try {
    const resp = await fetch((window.ECOLLAB?.baseUrl||'') + '/API/chat/upload-file.php', {
      method: 'POST',
      headers: { 'X-CSRF-Token': window.ECOLLAB?.csrfToken || '' },
      body: fd,
    });
    const data = await resp.json();
    if (data.success) {
      await apiFetch((window.ECOLLAB?.baseUrl||'') + '/API/chat/send-message.php', {
        method: 'POST',
        body: JSON.stringify({
          channel_id: window.ECOLLAB.currentChannelId,
          content: '🎤 Voice message',
          content_type: 'file',
          attachment_path: data.file_path,
          attachment_name: data.file_name,
          attachment_size: data.file_size,
          attachment_mime: data.mime_type,
        }),
      });
    }
  } catch (err) {
    console.error('Voice upload failed:', err);
  }
}

// ── MIC TEST (real-time, private — others cannot hear) ────────────────────
let micTestStream     = null;
let micTestAnalyser   = null;
let micTestCtx        = null;
let micTestRaf        = null;
let micTestLoopback   = false;
let micTestLoopbackEl = null; // AudioContext destination node for loopback
let micTestAudioCtx   = null;
let micTestActive     = false;

async function openMicTest() {
  // Populate device list
  const sel = document.getElementById('micTestInputSelect');
  if (sel) {
    sel.innerHTML = '';
    try {
      const devices = await navigator.mediaDevices.enumerateDevices();
      devices.filter(d => d.kind === 'audioinput').forEach(d => {
        const opt = document.createElement('option');
        opt.value = d.deviceId;
        opt.textContent = d.label || 'Microphone ' + (sel.options.length + 1);
        sel.appendChild(opt);
      });
    } catch { /* permissions not yet granted */ }
    if (!sel.options.length) sel.innerHTML = '<option value="">Default Microphone</option>';
  }
  openModal('vcMicTestModal');
}

async function toggleMicTest() {
  if (!micTestActive) await startMicTest();
  else stopMicTest();
}

async function startMicTest() {
  const btn = document.getElementById('micTestStartBtn');
  const status = document.getElementById('micTestStatus');
  if (btn) btn.textContent = '⏹ Stop Test';
  if (status) status.textContent = '🎙️ Mic active — speak to see your levels. Others cannot hear you.';
  micTestActive = true;

  try {
    const deviceId = document.getElementById('micTestInputSelect')?.value;
    const constraints = { audio: deviceId ? { deviceId: { exact: deviceId } } : true };
    micTestStream = await navigator.mediaDevices.getUserMedia(constraints);

    micTestAudioCtx = new (window.AudioContext || window.webkitAudioContext)();
    const source    = micTestAudioCtx.createMediaStreamSource(micTestStream);
    micTestAnalyser = micTestAudioCtx.createAnalyser();
    micTestAnalyser.fftSize = 256;
    source.connect(micTestAnalyser);

    // Loopback node (connect to destination only when loopback is on)
    micTestLoopbackEl = micTestAudioCtx.createGain();
    micTestLoopbackEl.gain.value = micTestLoopback ? 1 : 0;
    source.connect(micTestLoopbackEl);
    micTestLoopbackEl.connect(micTestAudioCtx.destination);

    _micTestDraw();
  } catch (e) {
    if (status) status.textContent = '❌ Microphone access denied. Please allow mic permissions.';
    micTestActive = false;
    if (btn) btn.textContent = '▶ Start Test';
  }
}

function stopMicTest() {
  micTestActive = false;
  const btn = document.getElementById('micTestStartBtn');
  const status = document.getElementById('micTestStatus');
  if (btn) btn.textContent = '▶ Start Test';
  if (status) status.textContent = 'Test stopped.';

  cancelAnimationFrame(micTestRaf);
  micTestRaf = null;

  if (micTestStream) { micTestStream.getTracks().forEach(t => t.stop()); micTestStream = null; }
  if (micTestAudioCtx) { micTestAudioCtx.close(); micTestAudioCtx = null; }
  micTestAnalyser = null;

  // Clear canvas and bar
  const canvas = document.getElementById('micTestCanvas');
  if (canvas) { const ctx = canvas.getContext('2d'); ctx.clearRect(0, 0, canvas.width, canvas.height); }
  const vol = document.getElementById('micTestVolBar');
  if (vol) vol.style.width = '0%';
}

function closeMicTest() {
  stopMicTest();
  closeModal('vcMicTestModal');
  // Reset loopback state UI
  micTestLoopback = false;
  _updateLoopbackUI();
}

function toggleMicLoopback() {
  micTestLoopback = !micTestLoopback;
  if (micTestLoopbackEl) micTestLoopbackEl.gain.value = micTestLoopback ? 1 : 0;
  _updateLoopbackUI();
}

function _updateLoopbackUI() {
  const toggle = document.getElementById('micTestLoopbackToggle');
  const knob   = document.getElementById('micTestLoopbackKnob');
  if (toggle) toggle.style.background = micTestLoopback ? '#a855f7' : 'var(--bg-card)';
  if (knob)   { knob.style.left = micTestLoopback ? '21px' : '3px'; knob.style.background = micTestLoopback ? '#fff' : '#64748b'; }
}

function _micTestDraw() {
  const canvas = document.getElementById('micTestCanvas');
  if (!canvas || !micTestAnalyser) return;

  const wrap = document.getElementById('micTestWaveWrap');
  canvas.width  = wrap ? wrap.clientWidth - 24 : 300;
  canvas.height = 36;
  const ctx = canvas.getContext('2d');
  const data = new Uint8Array(micTestAnalyser.frequencyBinCount);

  function draw() {
    if (!micTestActive || !micTestAnalyser) return;
    micTestRaf = requestAnimationFrame(draw);
    micTestAnalyser.getByteFrequencyData(data);

    ctx.clearRect(0, 0, canvas.width, canvas.height);

    // Draw frequency bars
    const barW = Math.max(2, canvas.width / data.length * 2);
    const gap   = 1;
    let x = 0;
    const grad = ctx.createLinearGradient(0, 0, canvas.width, 0);
    grad.addColorStop(0,   '#22c55e');
    grad.addColorStop(0.6, '#a855f7');
    grad.addColorStop(1,   '#ef4444');
    ctx.fillStyle = grad;

    for (let i = 0; i < data.length / 2; i++) {
      const h = (data[i] / 255) * canvas.height;
      ctx.fillRect(x, canvas.height - h, barW, h);
      x += barW + gap;
      if (x > canvas.width) break;
    }

    // Volume bar
    const avg = data.reduce((a, b) => a + b, 0) / data.length;
    const pct = Math.min(100, (avg / 255) * 100 * 2.5);
    const vol = document.getElementById('micTestVolBar');
    if (vol) vol.style.width = pct + '%';
  }

  draw();
}

// ── Camera toggle with live preview in speaker card ───────────────────────
async function toggleCamera() {
  vcCamOn = !vcCamOn;
  const btn = document.getElementById('vcCamBtn');
  if (btn) btn.classList.toggle('active', vcCamOn);

  if (vcCamOn) {
    try {
      const camStream = await navigator.mediaDevices.getUserMedia({ video: true });
      if (!localStream) localStream = camStream;
      else camStream.getVideoTracks().forEach(t => localStream.addTrack(t));

      // Inject live video preview into the local speaker card
      const card = document.querySelector('.vc-speaker-card[data-user-id]');
      if (card) {
        let vid = card.querySelector('.vc-cam-preview');
        if (!vid) {
          vid = document.createElement('video');
          vid.className = 'vc-cam-preview';
          vid.autoplay = true;
          vid.muted = true;
          vid.playsInline = true;
          // Insert before sc-top so the video sits at the top of the card
          const scTop = card.querySelector('.sc-top');
          card.insertBefore(vid, scTop || card.firstChild);
        }
        vid.srcObject = camStream;
        // Mark the card so CSS can reflow the layout properly
        card.classList.add('has-camera');
      }
      showToast('📷 Camera on', 'success');
    } catch {
      showToast('📷 Camera access denied', 'info');
      vcCamOn = false;
      if (btn) btn.classList.remove('active');
    }
  } else {
    // Remove preview, stop cam tracks, and restore card layout
    document.querySelectorAll('.vc-cam-preview').forEach(v => { v.srcObject = null; v.remove(); });
    document.querySelectorAll('.vc-speaker-card.has-camera').forEach(c => c.classList.remove('has-camera'));
    if (localStream) localStream.getVideoTracks().forEach(t => { t.stop(); try { localStream.removeTrack(t); } catch {} });
    showToast('📷 Camera off', 'info');
  }
}

// ── Screen share with live preview in modal ───────────────────────────────
let _screenStream = null;

async function startStopScreenShare() {
  if (!vcScreenOn) {
    try {
      _screenStream = await navigator.mediaDevices.getDisplayMedia({ video: true, audio: true });
      vcScreenOn = true;

      const btn = document.getElementById('vcScreenBtn');
      if (btn) btn.classList.add('active');
      const startBtn = document.getElementById('vcScreenStartBtn');
      if (startBtn) startBtn.textContent = 'Stop Sharing';
      const liveBadge = document.getElementById('vcScreenLiveBadge');
      if (liveBadge) liveBadge.style.display = 'block';

      // Live preview in modal
      const preview = document.getElementById('vcScreenPreview');
      if (preview) {
        preview.innerHTML = '';
        const vid = document.createElement('video');
        vid.autoplay = true;
        vid.muted = true;
        vid.playsInline = true;
        vid.style.cssText = 'width:100%;height:100%;object-fit:contain;border-radius:8px;background:#000;';
        vid.srcObject = _screenStream;
        preview.appendChild(vid);
      }

      _screenStream.getVideoTracks()[0].addEventListener('ended', stopScreenShare);

      // Show screen share section — small thumbnail like Discord
      const screenSection = document.getElementById('vcScreenSection');
      if (screenSection) screenSection.style.display = '';
      const screenGrid = document.getElementById('vcScreenGrid');
      if (screenGrid) {
        screenGrid.innerHTML = '';
        const sc = document.createElement('div');
        sc.id = 'vcScreenCard';
        sc.className = 'vc-screen-card';
        sc.innerHTML = `
          <div class="vc-screen-card-inner">
            <video id="vcScreenCardVid" autoplay muted playsinline style="width:100%;height:100%;object-fit:cover;background:#000;"></video>
          </div>
          <div class="vc-screen-card-live">LIVE</div>
          <div class="vc-screen-card-label">🖥️ Your screen</div>
          <div class="vc-screen-card-watch">
            <button class="vc-screen-watch-btn" onclick="event.stopPropagation(); toggleScreenExpand()">Watch</button>
          </div>`;
        screenGrid.appendChild(sc);
        const vid = document.getElementById('vcScreenCardVid');
        if (vid) vid.srcObject = _screenStream;
        const cnt = document.getElementById('vcScreenSectionCount');
        if (cnt) cnt.textContent = '1';
      }

      showToast('🖥️ Screen sharing started', 'success');
      closeModal('vcScreenModal');
    } catch {
      showToast('🖥️ Screen share cancelled', 'info');
      closeModal('vcScreenModal');
    }
  } else {
    stopScreenShare();
  }
}

function stopScreenShare() {
  vcScreenOn = false;
  const btn = document.getElementById('vcScreenBtn');
  if (btn) btn.classList.remove('active');
  const startBtn = document.getElementById('vcScreenStartBtn');
  if (startBtn) startBtn.textContent = 'Start Sharing';
  const liveBadge = document.getElementById('vcScreenLiveBadge');
  if (liveBadge) liveBadge.style.display = 'none';
  // Clear preview
  const preview = document.getElementById('vcScreenPreview');
  if (preview) preview.innerHTML = 'Preview will appear here';
  if (_screenStream) { _screenStream.getTracks().forEach(t => t.stop()); _screenStream = null; }

  // Hide screen share section
  const screenSection = document.getElementById('vcScreenSection');
  if (screenSection) screenSection.style.display = 'none';
  const screenGrid = document.getElementById('vcScreenGrid');
  if (screenGrid) screenGrid.innerHTML = '';
  const cnt = document.getElementById('vcScreenSectionCount');
  if (cnt) cnt.textContent = '0';

  closeModal('vcScreenModal');
  showToast('🖥️ Screen sharing stopped', 'info');
}

// ── HTTP voice status reporting (for active-now polling) ──────────────────
function _reportVoiceStatus(action, channelId) {
  const base = window.ECOLLAB?.baseUrl || '';
  const fd = new FormData();
  fd.append('action', action === 'join' ? 'join_voice' : 'leave_voice');
  if (channelId) fd.append('channel_id', channelId);
  fd.append('server_id', window.ECOLLAB?.currentServerId || '');
  fetch(`${base}/API/chat/active-now.php`, { method: 'POST', body: fd }).catch(() => {});
}

// ── WebRTC: create peer connection for a remote user ─────────────────────
function _createPeerConnection(remoteUserId, remoteUsername) {
  if (peerConnections[remoteUserId]) {
    peerConnections[remoteUserId].close();
  }

  const pc = new RTCPeerConnection({ iceServers: ICE_SERVERS });
  peerConnections[remoteUserId] = pc;

  // Add local tracks
  if (localStream) {
    localStream.getTracks().forEach(track => pc.addTrack(track, localStream));
  }

  // Relay ICE candidates via WebSocket
  pc.onicecandidate = ({ candidate }) => {
    if (!candidate) return;
    if (window.chatSocket && window.chatSocket.readyState === WebSocket.OPEN) {
      window.chatSocket.send(JSON.stringify({
        type: 'webrtc_candidate',
        target_user_id: remoteUserId,
        candidate: candidate,
      }));
    }
  };

  // On receiving remote audio stream
  pc.ontrack = ({ streams }) => {
    const stream = streams[0];
    remoteStreams[remoteUserId] = stream;
    _attachRemoteAudio(remoteUserId, remoteUsername, stream);
    // Update speaking indicator for remote participant
    _startRemoteVAD(remoteUserId, stream);
  };

  pc.onconnectionstatechange = () => {
    console.log(`[WebRTC] Peer ${remoteUsername} state: ${pc.connectionState}`);
    if (pc.connectionState === 'failed' || pc.connectionState === 'disconnected') {
      pc.close();
      delete peerConnections[remoteUserId];
    }
  };

  return pc;
}

// ── Attach remote audio to the page ──────────────────────────────────────
function _attachRemoteAudio(userId, username, stream) {
  let audio = document.getElementById(`remote-audio-${userId}`);
  if (!audio) {
    audio = document.createElement('audio');
    audio.id = `remote-audio-${userId}`;
    audio.autoplay = true;
    audio.style.display = 'none';
    document.body.appendChild(audio);
  }
  audio.srcObject = stream;
  // Apply deafen state
  audio.muted = vcDeafened;
  showToast(`🔊 ${username} is now in voice`, 'info');
}

// ── Remote voice activity detection ──────────────────────────────────────
function _startRemoteVAD(userId, stream) {
  try {
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    const src = ctx.createMediaStreamSource(stream);
    const analyser = ctx.createAnalyser();
    analyser.fftSize = 256;
    src.connect(analyser);

    function loop() {
      if (!peerConnections[userId]) { ctx.close(); return; }
      requestAnimationFrame(loop);
      const data = new Uint8Array(analyser.frequencyBinCount);
      analyser.getByteFrequencyData(data);
      const avg = data.reduce((a, b) => a + b, 0) / data.length;
      const isSpeaking = avg > 10;
      const card = document.querySelector(`.vc-speaker-card[data-user-id="${userId}"], .vc-listener-card[data-user-id="${userId}"]`);
      if (card) card.classList.toggle('speaking', isSpeaking);
    }
    loop();
  } catch {}
}

// ── WebRTC: initiate offer to a peer ─────────────────────────────────────
async function _initiateWebRtcOffer(remoteUserId, remoteUsername) {
  const pc = _createPeerConnection(remoteUserId, remoteUsername);
  try {
    const offer = await pc.createOffer();
    await pc.setLocalDescription(offer);

    if (window.chatSocket && window.chatSocket.readyState === WebSocket.OPEN) {
      window.chatSocket.send(JSON.stringify({
        type: 'webrtc_offer',
        target_user_id: remoteUserId,
        sdp: pc.localDescription,
      }));
    }
    console.log(`[WebRTC] Offer sent to ${remoteUsername}`);
  } catch (err) {
    console.error('[WebRTC] Offer error:', err);
  }
}

// ── WebRTC: handle incoming offer ─────────────────────────────────────────
async function _handleWebRtcOffer(fromUserId, fromUsername, sdp) {
  const pc = _createPeerConnection(fromUserId, fromUsername);
  try {
    await pc.setRemoteDescription(new RTCSessionDescription(sdp));
    const answer = await pc.createAnswer();
    await pc.setLocalDescription(answer);

    if (window.chatSocket && window.chatSocket.readyState === WebSocket.OPEN) {
      window.chatSocket.send(JSON.stringify({
        type: 'webrtc_answer',
        target_user_id: fromUserId,
        sdp: pc.localDescription,
      }));
    }
    console.log(`[WebRTC] Answer sent to ${fromUsername}`);
  } catch (err) {
    console.error('[WebRTC] Answer error:', err);
  }
}

// ── WebRTC: handle incoming answer ────────────────────────────────────────
async function _handleWebRtcAnswer(fromUserId, sdp) {
  const pc = peerConnections[fromUserId];
  if (!pc) return;
  try {
    await pc.setRemoteDescription(new RTCSessionDescription(sdp));
  } catch (err) {
    console.error('[WebRTC] setRemoteDescription error:', err);
  }
}

// ── WebRTC: handle ICE candidate ──────────────────────────────────────────
async function _handleWebRtcCandidate(fromUserId, candidate) {
  const pc = peerConnections[fromUserId];
  if (!pc || !candidate) return;
  try {
    await pc.addIceCandidate(new RTCIceCandidate(candidate));
  } catch (err) {
    console.error('[WebRTC] ICE candidate error:', err);
  }
}

// ── Handle voice_peers from server (list of existing participants) ─────────
function handleVoicePeers(data) {
  const peers = data.peers || [];
  peers.forEach(peer => {
    if (parseInt(peer.user_id) === parseInt(window.ECOLLAB?.userId)) return;
    // Initiate WebRTC offer to each existing peer
    setTimeout(() => _initiateWebRtcOffer(peer.user_id, peer.username), 100);
  });
}

// Expose so socket.js can call it
window.handleVoicePeers = handleVoicePeers;
window._handleWebRtcOffer = _handleWebRtcOffer;
window._handleWebRtcAnswer = _handleWebRtcAnswer;
window._handleWebRtcCandidate = _handleWebRtcCandidate;

// ── Expose globals ────────────────────────────────────────────────────────
window.joinVoice             = joinVoice;
window.leaveVoice            = leaveVoice;
window.confirmLeaveCall      = confirmLeaveCall;
window.disconnectVoice       = disconnectVoice;
window.toggleVcMinimize      = toggleVcMinimize;
window.toggleVcPanelFromBar  = toggleVcPanelFromBar;
window.toggleVcMic           = toggleVcMic;
window.toggleVcDeafen        = toggleVcDeafen;
function toggleScreenExpand() {
  const card = document.getElementById('vcScreenCard');
  if (!card) return;
  const isExpanded = card.classList.toggle('vc-screen-expanded');
  const vid = document.getElementById('vcScreenCardVid');
  if (vid) vid.style.objectFit = isExpanded ? 'contain' : 'cover';
}
window.toggleScreenExpand = toggleScreenExpand;
window.toggleCamera          = toggleCamera;
window.toggleScreenShare     = toggleScreenShare;
window.startStopScreenShare  = startStopScreenShare;
window.stopScreenShare       = stopScreenShare;
window.closeScreenShare      = closeScreenShare;
window.selectScreenQuality   = selectScreenQuality;
window.openWhiteboard        = openWhiteboard;
window.selectNoiseMode       = selectNoiseMode;
window.saveNoiseMode         = saveNoiseMode;
window.toggleMute            = toggleMute;
window.toggleDeafen          = toggleDeafen;
window.toggleVoiceRecord     = toggleVoiceRecord;
window.addVcParticipant      = addVcParticipant;
window.openMicTest           = openMicTest;
window.toggleMicTest         = toggleMicTest;
window.closeMicTest          = closeMicTest;
window.toggleMicLoopback     = toggleMicLoopback;

// ── Save preferred input device ────────────────────────────────────────────
async function saveAudioSettings() {
  const inputSel  = document.getElementById('audioInputSelect');
  const outputSel = document.getElementById('audioOutputSelect');

  if (inputSel?.value)  window._vcPreferredInput  = inputSel.value;
  if (outputSel?.value) window._vcPreferredOutput = outputSel.value;

  // If currently in a voice channel, re-acquire mic with new device
  if (vcActive && localStream) {
    _stopVAD();
    localStream.getTracks().forEach(t => t.stop());
    localStream = null;
    await _acquireMic();
  }

  closeModal('vcAudioSettingsModal');
  showToast('🔊 Audio settings saved', 'success');
}

// Populate devices when audio settings modal is opened
async function openAudioSettings() {
  await _populateDeviceSelects();
  openModal('vcAudioSettingsModal');
}

window._acquireMic            = _acquireMic;
window._populateDeviceSelects = _populateDeviceSelects;
window.saveAudioSettings      = saveAudioSettings;
window.openAudioSettings      = openAudioSettings;

// ── Draggable pip ─────────────────────────────────────────────────────────
(function initPipDrag() {
  let dragging = false, startX, startY, startRight, startBottom;

  document.addEventListener('mousedown', e => {
    const vcView = document.getElementById('voiceChannelView');
    if (!vcView?.classList.contains('vc-minimized')) return;
    const header = vcView.querySelector('.vc-header');
    if (!header?.contains(e.target)) return;
    dragging = true;
    startX = e.clientX;
    startY = e.clientY;
    const s = vcView.style;
    startRight  = parseInt(s.right  || '16') || 16;
    startBottom = parseInt(s.bottom || '80') || 80;
    e.preventDefault();
  });

  document.addEventListener('mousemove', e => {
    if (!dragging) return;
    const vcView = document.getElementById('voiceChannelView');
    if (!vcView) return;
    const dx = e.clientX - startX;
    const dy = e.clientY - startY;
    const newRight  = Math.max(0, startRight  - dx);
    const newBottom = Math.max(0, startBottom - dy);
    vcView.style.right  = newRight  + 'px';
    vcView.style.bottom = newBottom + 'px';
  });

  document.addEventListener('mouseup', () => { dragging = false; });
})();

// Register voice.js functions as __real_* for the stub system
(function() {
  var fns = [
    'joinVoice','leaveVoice','confirmLeaveCall','disconnectVoice',
    'toggleVcMinimize','toggleVcPanelFromBar','toggleVcMic','toggleVcDeafen',
    'toggleCamera','toggleScreenShare','startStopScreenShare','stopScreenShare',
    'closeScreenShare','openWhiteboard','openMicTest','toggleMicTest',
    'closeMicTest','toggleMicLoopback','saveAudioSettings','openAudioSettings',
    'toggleMute','toggleDeafen','toggleVoiceRecord','addVcParticipant',
  ];
  fns.forEach(function(name) {
    if (typeof window[name] === 'function') window['__real_' + name] = window[name];
  });
})();
