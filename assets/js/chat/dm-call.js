/**
 * dm-call.js — Direct voice/video calls from a DM conversation.
 *
 * Reuses the ICE_SERVERS config already defined in voice.js. Signaling goes
 * through dm_call_offer / dm_call_answer / dm_call_candidate / dm_call_end /
 * dm_call_decline, relayed server-side by DmHandler::handleDmCallSignal,
 * which verifies a real DM (or shared group) relationship before relaying,
 * and logs every call attempt to dm_call_history for the chat-log entries
 * this file renders (missed/answered/declined/ended, with duration).
 *
 * Group calls are intentionally not supported (mesh WebRTC for 3+ people is
 * a meaningfully bigger feature) — attempting one shows a clear message
 * rather than silently failing.
 */

const DM_CALL_TIMEOUT_MS = 45000; // ring for 45s before giving up

let _dmCallPc          = null;
let _dmCallLocalStream = null;
let _dmCallPeerId      = null;
let _dmCallGroupId     = null;
let _dmCallLogId       = null;
let _dmCallIsVideo     = false;
let _dmCallPendingCandidates = [];
let _dmCallState       = 'idle'; // idle | ringing-out | ringing-in | active
let _dmCallTimeoutTimer = null;
let _dmCallStartedAt    = null;
let _dmRingAudio        = null;

function _dmIceServers() {
  return (typeof ICE_SERVERS !== 'undefined') ? ICE_SERVERS : [{ urls: 'stun:stun.l.google.com:19302' }];
}

// ── Ringtone playback ───────────────────────────────────────────────────────
function _dmPlayRing(which) {
  _dmStopRing();
  const base = window.ECOLLAB?.baseUrl || '';
  const src = which === 'out' ? `${base}/assets/sounds/ringback.mp3` : `${base}/assets/sounds/incoming-ring.mp3`;
  _dmRingAudio = new Audio(src);
  _dmRingAudio.loop = true;
  _dmRingAudio.volume = 0.6;
  _dmRingAudio.play().catch(() => {}); // blocked until a user gesture on some browsers — call buttons are themselves a gesture, so outgoing works; incoming may need the accept click to unlock, degrades to silent ring which is fine
}

function _dmStopRing() {
  if (_dmRingAudio) {
    _dmRingAudio.pause();
    _dmRingAudio.currentTime = 0;
    _dmRingAudio = null;
  }
}

// ── Call history chat-log entries ──────────────────────────────────────────
function _appendCallLogEntry({ status, isVideo, isOutgoing, durationSeconds }) {
  const area = document.getElementById('dmMessagesArea');
  if (!area) return;

  const icon = isVideo ? '🎥' : '📞';
  let label;
  if (status === 'answered' || status === 'ended') {
    const mins = Math.floor((durationSeconds || 0) / 60);
    const secs = (durationSeconds || 0) % 60;
    label = `${isVideo ? 'Video' : 'Voice'} call · ${mins}:${String(secs).padStart(2, '0')}`;
  } else if (status === 'missed') {
    label = isOutgoing ? 'No answer' : `Missed ${isVideo ? 'video' : 'voice'} call`;
  } else if (status === 'declined') {
    label = isOutgoing ? 'Call declined' : `You declined a ${isVideo ? 'video' : 'voice'} call`;
  } else {
    label = `${isVideo ? 'Video' : 'Voice'} call`;
  }

  const el = document.createElement('div');
  el.style.cssText = 'display:flex;align-items:center;justify-content:center;gap:6px;margin:6px 0;font-size:11px;color:var(--text-muted);';
  el.innerHTML = `<span>${icon}</span><span>${escHtml(label)}</span><span style="opacity:0.6;">· ${new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</span>`;
  area.appendChild(el);
  area.scrollTop = area.scrollHeight;
}

function _clearDmCallTimeout() {
  if (_dmCallTimeoutTimer) { clearTimeout(_dmCallTimeoutTimer); _dmCallTimeoutTimer = null; }
}

// ── Starting a call (outgoing) ─────────────────────────────────────────────
async function startDmCall(video) {
  if (_dmCallState !== 'idle') { showToast('Already in a call', 'info'); return; }
  const targetId = DM.activeGroupId ? null : DM.activePartnerId;
  const groupId  = DM.activeGroupId || null;
  if (!targetId && !groupId) return;

  if (groupId) {
    showToast('Group calls aren\'t supported yet — start a 1:1 call instead', 'info');
    return;
  }

  _dmCallPeerId  = targetId;
  _dmCallGroupId = groupId;
  _dmCallIsVideo = !!video;
  _dmCallState   = 'ringing-out';

  try {
    _dmCallLocalStream = await navigator.mediaDevices.getUserMedia({
      audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
      video: video ? { width: { ideal: 640 }, height: { ideal: 480 } } : false,
    });
  } catch (e) {
    showToast('Could not access ' + (video ? 'camera/microphone' : 'microphone'), 'error');
    _dmCallState = 'idle';
    return;
  }

  _dmCallPc = _buildDmPeerConnection();
  _dmCallLocalStream.getTracks().forEach(t => _dmCallPc.addTrack(t, _dmCallLocalStream));

  const offer = await _dmCallPc.createOffer();
  await _dmCallPc.setLocalDescription(offer);

  window.wsSend({
    type: 'dm_call_offer',
    target_user_id: targetId,
    group_id: groupId,
    is_video: _dmCallIsVideo,
    sdp: offer,
  });

  _dmPlayRing('out');
  _renderDmCallOverlay('ringing-out');

  _dmCallTimeoutTimer = setTimeout(() => {
    if (_dmCallState === 'ringing-out') {
      showToast('No answer', 'info');
      _sendCallEnd();
      _appendCallLogEntry({ status: 'missed', isVideo: _dmCallIsVideo, isOutgoing: true });
      _resetDmCallState();
    }
  }, DM_CALL_TIMEOUT_MS);
}
window.startDmCall = startDmCall;

window._onDmCallOfferSent = function (data) {
  if (data.log_id) _dmCallLogId = data.log_id;
};

// ── Receiving a call (incoming) ────────────────────────────────────────────
let _incomingCallData = null;

window._onDmCallOffer = function (data) {
  if (_dmCallState !== 'idle') {
    window.wsSend({ type: 'dm_call_decline', target_user_id: data.from_user_id, group_id: data.group_id, log_id: data.log_id });
    return;
  }
  _incomingCallData = data;
  _dmCallLogId = data.log_id || null;
  _dmCallState = 'ringing-in';
  _dmPlayRing('in');
  _renderDmCallOverlay('ringing-in');

  _dmCallTimeoutTimer = setTimeout(() => {
    if (_dmCallState === 'ringing-in') {
      _appendCallLogEntry({ status: 'missed', isVideo: data.is_video, isOutgoing: false });
      _resetDmCallState(); // let it simply time out — the caller's own timer sends the actual dm_call_end
    }
  }, DM_CALL_TIMEOUT_MS);
};

async function _acceptDmCall() {
  const data = _incomingCallData;
  if (!data) return;
  _clearDmCallTimeout();
  _dmStopRing();

  _dmCallPeerId  = data.from_user_id;
  _dmCallGroupId = data.group_id || null;
  _dmCallIsVideo = !!data.is_video;

  try {
    _dmCallLocalStream = await navigator.mediaDevices.getUserMedia({
      audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
      video: _dmCallIsVideo ? { width: { ideal: 640 }, height: { ideal: 480 } } : false,
    });
  } catch (e) {
    showToast('Could not access ' + (_dmCallIsVideo ? 'camera/microphone' : 'microphone'), 'error');
    _declineDmCall();
    return;
  }

  _dmCallPc = _buildDmPeerConnection();
  _dmCallLocalStream.getTracks().forEach(t => _dmCallPc.addTrack(t, _dmCallLocalStream));

  await _dmCallPc.setRemoteDescription(new RTCSessionDescription(data.sdp));
  _dmCallPendingCandidates.forEach(c => _dmCallPc.addIceCandidate(new RTCIceCandidate(c)).catch(() => {}));
  _dmCallPendingCandidates = [];

  const answer = await _dmCallPc.createAnswer();
  await _dmCallPc.setLocalDescription(answer);

  window.wsSend({
    type: 'dm_call_answer',
    target_user_id: _dmCallPeerId,
    group_id: _dmCallGroupId,
    log_id: _dmCallLogId,
    sdp: answer,
  });

  _dmCallState = 'active';
  _dmCallStartedAt = Date.now();
  _renderDmCallOverlay('active');
}
window._acceptDmCall = _acceptDmCall;

function _declineDmCall() {
  _clearDmCallTimeout();
  _dmStopRing();
  if (_incomingCallData) {
    window.wsSend({ type: 'dm_call_decline', target_user_id: _incomingCallData.from_user_id, group_id: _incomingCallData.group_id, log_id: _dmCallLogId });
    _appendCallLogEntry({ status: 'declined', isVideo: _incomingCallData.is_video, isOutgoing: false });
  }
  _resetDmCallState();
}
window._declineDmCall = _declineDmCall;

// ── Shared peer connection setup ───────────────────────────────────────────
function _buildDmPeerConnection() {
  const pc = new RTCPeerConnection({ iceServers: _dmIceServers() });

  pc.onicecandidate = (e) => {
    if (e.candidate) {
      window.wsSend({
        type: 'dm_call_candidate',
        target_user_id: _dmCallPeerId,
        group_id: _dmCallGroupId,
        log_id: _dmCallLogId,
        candidate: e.candidate,
      });
    }
  };

  pc.ontrack = (e) => {
    const remoteVideo = document.getElementById('dmCallRemoteVideo');
    if (remoteVideo) remoteVideo.srcObject = e.streams[0];
  };

  pc.onconnectionstatechange = () => {
    if (['disconnected', 'failed', 'closed'].includes(pc.connectionState) && _dmCallState !== 'idle') {
      _finishActiveCall('ended');
    }
  };

  return pc;
}

// ── WS signal handlers ─────────────────────────────────────────────────────
window._onDmCallAnswer = async function (data) {
  if (!_dmCallPc || _dmCallState !== 'ringing-out') return;
  _clearDmCallTimeout();
  _dmStopRing();
  await _dmCallPc.setRemoteDescription(new RTCSessionDescription(data.sdp));
  _dmCallPendingCandidates.forEach(c => _dmCallPc.addIceCandidate(new RTCIceCandidate(c)).catch(() => {}));
  _dmCallPendingCandidates = [];
  _dmCallState = 'active';
  _dmCallStartedAt = Date.now();
  _renderDmCallOverlay('active');
};

window._onDmCallCandidate = function (data) {
  if (!data.candidate) return;
  if (_dmCallPc && _dmCallPc.remoteDescription) {
    _dmCallPc.addIceCandidate(new RTCIceCandidate(data.candidate)).catch(() => {});
  } else {
    _dmCallPendingCandidates.push(data.candidate);
  }
};

window._onDmCallDecline = function () {
  _clearDmCallTimeout();
  _dmStopRing();
  showToast('Call declined', 'info');
  _appendCallLogEntry({ status: 'declined', isVideo: _dmCallIsVideo, isOutgoing: true });
  _resetDmCallState();
};

window._onDmCallEnd = function () {
  if (_dmCallState === 'idle') return;
  const wasActive = _dmCallState === 'active';
  _dmStopRing();
  showToast(wasActive ? 'Call ended' : 'Missed call', 'info');
  _appendCallLogEntry({
    status: wasActive ? 'ended' : 'missed',
    isVideo: _dmCallIsVideo,
    isOutgoing: false,
    durationSeconds: wasActive && _dmCallStartedAt ? Math.round((Date.now() - _dmCallStartedAt) / 1000) : 0,
  });
  _resetDmCallState();
};

function _sendCallEnd() {
  if (_dmCallPeerId) {
    window.wsSend({ type: 'dm_call_end', target_user_id: _dmCallPeerId, group_id: _dmCallGroupId, log_id: _dmCallLogId });
  }
}

function _finishActiveCall(reason) {
  const durationSeconds = _dmCallStartedAt ? Math.round((Date.now() - _dmCallStartedAt) / 1000) : 0;
  _sendCallEnd();
  _appendCallLogEntry({ status: reason, isVideo: _dmCallIsVideo, isOutgoing: true, durationSeconds });
  _resetDmCallState();
}

function endDmCall() {
  _clearDmCallTimeout();
  _dmStopRing();
  if (_dmCallState === 'active') {
    _finishActiveCall('ended');
  } else {
    _sendCallEnd();
    _resetDmCallState();
  }
}
window.endDmCall = endDmCall;

function _resetDmCallState() {
  _clearDmCallTimeout();
  _dmStopRing();
  if (_dmCallPc) {
    // Detach before closing — closing a peer connection can re-fire
    // onconnectionstatechange synchronously on some browsers, which would
    // otherwise re-enter this same cleanup mid-execution and double-send
    // dm_call_end / double-append the call-log entry.
    _dmCallPc.onconnectionstatechange = null;
    try { _dmCallPc.close(); } catch (e) {}
  }
  if (_dmCallLocalStream) { _dmCallLocalStream.getTracks().forEach(t => t.stop()); }
  _dmCallPc = null;
  _dmCallLocalStream = null;
  _dmCallPeerId = null;
  _dmCallGroupId = null;
  _dmCallLogId = null;
  _dmCallStartedAt = null;
  _incomingCallData = null;
  _dmCallPendingCandidates = [];
  _dmCallState = 'idle';
  const overlay = document.getElementById('dmCallOverlay');
  if (overlay) overlay.remove();
}

// ── Call UI ─────────────────────────────────────────────────────────────────
function _dmCallToggleMic() {
  if (!_dmCallLocalStream) return;
  const track = _dmCallLocalStream.getAudioTracks()[0];
  if (!track) return;
  track.enabled = !track.enabled;
  const btn = document.getElementById('dmCallMicBtn');
  if (btn) btn.classList.toggle('muted-state', !track.enabled);
}
window._dmCallToggleMic = _dmCallToggleMic;

function _dmCallToggleCam() {
  if (!_dmCallLocalStream) return;
  const track = _dmCallLocalStream.getVideoTracks()[0];
  if (!track) return;
  track.enabled = !track.enabled;
  const btn = document.getElementById('dmCallCamBtn');
  if (btn) btn.classList.toggle('muted-state', !track.enabled);
}
window._dmCallToggleCam = _dmCallToggleCam;

function _renderDmCallOverlay(state) {
  let overlay = document.getElementById('dmCallOverlay');
  if (!overlay) {
    overlay = document.createElement('div');
    overlay.id = 'dmCallOverlay';
    overlay.style.cssText = 'position:fixed;bottom:0;right:376px;z-index:9001;width:300px;background:var(--bg-secondary);border:1px solid var(--border);border-radius:12px 12px 0 0;box-shadow:0 -4px 32px rgba(0,0,0,0.5);overflow:hidden;';
    document.body.appendChild(overlay);
  }

  const partnerName = DM.activePartnerName || _incomingCallData?.from_username || 'User';

  if (state === 'ringing-out') {
    overlay.innerHTML = `
      <div style="padding:20px;text-align:center;">
        <div style="font-size:13px;color:var(--text-muted);margin-bottom:6px;">Calling…</div>
        <div style="font-size:16px;font-weight:700;color:var(--text-primary);margin-bottom:16px;">${escHtml(partnerName)}</div>
        <button onclick="endDmCall()" style="width:44px;height:44px;border-radius:50%;background:#ef4444;border:none;color:#fff;font-size:18px;cursor:pointer;">☎</button>
      </div>`;
  } else if (state === 'ringing-in') {
    const isVideo = _incomingCallData?.is_video;
    overlay.innerHTML = `
      <div style="padding:20px;text-align:center;">
        <div style="font-size:13px;color:var(--text-muted);margin-bottom:6px;">Incoming ${isVideo ? 'video' : 'voice'} call</div>
        <div style="font-size:16px;font-weight:700;color:var(--text-primary);margin-bottom:16px;">${escHtml(partnerName)}</div>
        <div style="display:flex;gap:16px;justify-content:center;">
          <button onclick="_declineDmCall()" style="width:44px;height:44px;border-radius:50%;background:#ef4444;border:none;color:#fff;font-size:18px;cursor:pointer;">✕</button>
          <button onclick="_acceptDmCall()" style="width:44px;height:44px;border-radius:50%;background:#22c55e;border:none;color:#fff;font-size:18px;cursor:pointer;">✓</button>
        </div>
      </div>`;
  } else if (state === 'active') {
    overlay.innerHTML = `
      <div style="position:relative;background:#000;height:${_dmCallIsVideo ? '220px' : '0'};">
        ${_dmCallIsVideo ? `
          <video id="dmCallRemoteVideo" autoplay playsinline style="width:100%;height:100%;object-fit:cover;"></video>
          <video id="dmCallLocalVideo" autoplay playsinline muted style="position:absolute;bottom:8px;right:8px;width:70px;height:52px;border-radius:6px;object-fit:cover;transform:scaleX(-1);border:1px solid rgba(255,255,255,0.3);"></video>
        ` : ''}
      </div>
      <div style="padding:12px;display:flex;align-items:center;gap:10px;">
        <div style="flex:1;min-width:0;">
          <div style="font-size:13px;font-weight:700;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escHtml(partnerName)}</div>
          <div style="font-size:11px;color:#22c55e;">Connected</div>
        </div>
        <button id="dmCallMicBtn" onclick="_dmCallToggleMic()" title="Mute" style="width:32px;height:32px;border-radius:50%;background:var(--bg-tertiary);border:none;color:var(--text-primary);cursor:pointer;">🎤</button>
        ${_dmCallIsVideo ? `<button id="dmCallCamBtn" onclick="_dmCallToggleCam()" title="Camera" style="width:32px;height:32px;border-radius:50%;background:var(--bg-tertiary);border:none;color:var(--text-primary);cursor:pointer;">📷</button>` : ''}
        <button onclick="endDmCall()" title="End call" style="width:32px;height:32px;border-radius:50%;background:#ef4444;border:none;color:#fff;cursor:pointer;">☎</button>
      </div>`;

    if (_dmCallIsVideo && _dmCallLocalStream) {
      const localVid = document.getElementById('dmCallLocalVideo');
      if (localVid) localVid.srcObject = _dmCallLocalStream;
    }
  }
}
