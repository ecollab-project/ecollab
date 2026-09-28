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
let _dmCallRemoteStream = null;
let _dmCallPeerId      = null;
let _dmCallGroupId     = null;
let _dmCallLogId       = null;
let _dmCallIsVideo     = false; // local camera state
let _dmCallRemoteVideo = false; // peer camera state
let _dmCallPendingCandidates = [];
let _dmCallState       = 'idle'; // idle | ringing-out | ringing-in | active
let _dmCallTimeoutTimer = null;
let _dmCallStartedAt    = null;
let _dmRingAudio        = null;
let _dmCallDeafened     = false;
let _dmCallCameraBusy   = false;

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
    // Group Messages use their own LiveKit room/token flow. Mark this event so
    // legacy voice-channel UI cannot reinterpret the synthetic group id as a
    // server voice channel and call /API/chat/livekit-token.php.
    window.__ecollabStartingDmGroupCall = true;
    try {
      await startDmGroupLiveKitCall(groupId, !!video);
    } finally {
      window.__ecollabStartingDmGroupCall = false;
    }
    return;
  }

  _dmCallPeerId  = targetId;
  _dmCallGroupId = groupId;
  _dmCallIsVideo = !!video;
  _dmCallRemoteVideo = !!video;
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

let _dmGroupLiveKitRoom = null;

function _dmCallCsrf() {
  return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

async function startDmGroupLiveKitCall(groupId, video) {
  if (!window.LivekitClient?.Room) {
    showToast('LiveKit is not available. Refresh eCollab and try again.', 'error');
    return;
  }
  if (_dmGroupLiveKitRoom) {
    showToast('Already in a group call', 'info');
    return;
  }

  try {
    const response = await fetch((window.ECOLLAB?.baseUrl || '') + '/API/dm/livekit-group-token.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': _dmCallCsrf(),
      },
      body: JSON.stringify({ group_id: Number(groupId) }),
    });
    const data = await response.json();
    if (!response.ok || !data.success) throw new Error(data.error || 'Could not join group call');

    const { Room, RoomEvent } = window.LivekitClient;
    const room = new Room({ adaptiveStream: false, dynacast: true, disconnectOnPageLeave: true });
    _dmGroupLiveKitRoom = room;

    room.on(RoomEvent.TrackSubscribed, track => {
      const el = track.attach();
      el.dataset.dmGroupCall = '1';
      if (track.kind === 'audio') {
        el.autoplay = true;
        document.body.appendChild(el);
      } else {
        const stage = document.getElementById('dmGroupCallVideos');
        if (stage) stage.appendChild(el);
      }
    });
    room.on(RoomEvent.TrackUnsubscribed, track => track.detach().forEach(el => el.remove()));
    room.on(RoomEvent.ParticipantConnected, () => _renderDmGroupCallOverlay(data.room_name));
    room.on(RoomEvent.ParticipantDisconnected, () => _renderDmGroupCallOverlay(data.room_name));
    room.on(RoomEvent.Disconnected, () => {
      document.querySelectorAll('[data-dm-group-call="1"]').forEach(el => el.remove());
      document.getElementById('dmGroupCallOverlay')?.remove();
      _dmGroupLiveKitRoom = null;
    });

    // Ring group members as soon as authorization succeeds. Do not wait for
    // LiveKit media negotiation: a slow/blocked RTC connect must not suppress
    // the incoming Accept/Decline popup on the other members.
    if (!window.__joiningDmGroupInvite) {
      const invite = { type: 'dm_group_call_start', group_id: Number(groupId), is_video: !!video };
      let attempts = 0;
      const sendInvite = () => {
        attempts += 1;
        const sent = window.wsSend?.(invite) === true;
        console.log('[DM group call] invite attempt', attempts, sent ? 'sent' : 'not sent', invite);
        return sent;
      };
      if (!sendInvite()) {
        const retryInvite = setInterval(() => {
          if (sendInvite() || attempts >= 12) {
            clearInterval(retryInvite);
            if (attempts >= 12) console.error('[DM group call] invite signal could not be sent');
          }
        }, 250);
      }
    }

    await room.connect(data.url, data.token);
    await room.localParticipant.setMicrophoneEnabled(true);
    if (video) await room.localParticipant.setCameraEnabled(true);
    _renderDmGroupCallOverlay(data.room_name);
  } catch (e) {
    console.error('[DM group call] LiveKit join failed:', e);
    _dmGroupLiveKitRoom?.disconnect();
    _dmGroupLiveKitRoom = null;
    showToast(e.message || 'Could not establish group call', 'error');
  }
}

function _renderDmGroupCallOverlay(name) {
  if (!_dmGroupLiveKitRoom) return;
  let overlay = document.getElementById('dmGroupCallOverlay');
  if (!overlay) {
    overlay = document.createElement('div');
    overlay.id = 'dmGroupCallOverlay';
    overlay.style.cssText = 'position:fixed;bottom:0;right:376px;z-index:9002;width:360px;background:var(--bg-secondary);border:1px solid var(--border);border-radius:12px 12px 0 0;box-shadow:0 -4px 32px rgba(0,0,0,.5);overflow:hidden;';
    document.body.appendChild(overlay);
  }
  const count = 1 + _dmGroupLiveKitRoom.remoteParticipants.size;
  overlay.innerHTML = `
    <div id="dmGroupCallVideos" style="display:grid;grid-template-columns:repeat(2,1fr);gap:4px;background:#000;"></div>
    <div style="padding:12px;display:flex;align-items:center;gap:8px;">
      <div style="flex:1;min-width:0;">
        <div style="font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escHtml(name || 'Group Call')}</div>
        <div style="font-size:11px;color:#22c55e;">${count} connected · LiveKit</div>
      </div>
      <button type="button" onclick="_dmGroupToggleMic()" title="Mute/unmute">🎤</button>
      <button type="button" onclick="_dmGroupToggleCam()" title="Camera">📷</button>
      <button type="button" onclick="endDmGroupCall()" title="Leave" style="background:#ef4444;color:#fff;">☎</button>
    </div>`;

  _dmGroupLiveKitRoom.remoteParticipants.forEach(p => {
    p.trackPublications.forEach(pub => {
      if (pub.isSubscribed && pub.track?.kind === 'video') {
        const el = pub.track.attach();
        el.dataset.dmGroupCall = '1';
        el.autoplay = true;
        el.playsInline = true;
        el.style.cssText = 'width:100%;height:140px;object-fit:cover;';
        document.getElementById('dmGroupCallVideos')?.appendChild(el);
      }
    });
  });
}

async function _dmGroupToggleMic() {
  if (!_dmGroupLiveKitRoom) return;
  const p = _dmGroupLiveKitRoom.localParticipant;
  await p.setMicrophoneEnabled(!p.isMicrophoneEnabled);
}
window._dmGroupToggleMic = _dmGroupToggleMic;

async function _dmGroupToggleCam() {
  if (!_dmGroupLiveKitRoom) return;
  const p = _dmGroupLiveKitRoom.localParticipant;
  await p.setCameraEnabled(!p.isCameraEnabled);
  _renderDmGroupCallOverlay('Group Call');
}
window._dmGroupToggleCam = _dmGroupToggleCam;

function endDmGroupCall() {
  if (!_dmGroupLiveKitRoom) return;
  _dmGroupLiveKitRoom.disconnect();
}
window.endDmGroupCall = endDmGroupCall;
window.startDmGroupLiveKitCall = startDmGroupLiveKitCall;

let _dmGroupIncomingPopup = null;

function _closeDmGroupIncomingPopup() {
  document.getElementById('dmGroupIncomingCallPopup')?.remove();
  _dmGroupIncomingPopup = null;
}

window._onDmGroupCallStart = function (data) {
  const groupId = Number(data.group_id || 0);
  if (!groupId) return;

  const unavailable = _dmCallState !== 'idle' || !!_dmGroupLiveKitRoom || !!window.vcActive;
  if (unavailable) {
    window.wsSend?.({
      type: 'dm_group_call_busy',
      group_id: groupId,
      target_user_id: Number(data.from_user_id),
    });
    return;
  }

  _closeDmGroupIncomingPopup();
  const caller = data.from_username || 'Someone';
  const kind = data.is_video ? 'video' : 'voice';
  const popup = document.createElement('div');
  popup.id = 'dmGroupIncomingCallPopup';
  popup.style.cssText = 'position:fixed;top:18px;right:18px;z-index:10050;width:330px;padding:16px;background:var(--bg-secondary,#202225);border:1px solid var(--border,#3a3d42);border-radius:12px;box-shadow:0 12px 40px rgba(0,0,0,.45);';
  popup.innerHTML = `
    <div style="font-weight:800;font-size:14px;margin-bottom:4px;">${data.is_video ? '🎥' : '📞'} Incoming group ${kind} call</div>
    <div style="font-size:13px;color:var(--text-muted,#b5bac1);margin-bottom:14px;">${escHtml(caller)} started a group call</div>
    <div style="display:flex;gap:8px;justify-content:flex-end;">
      <button id="dmGroupDeclineCall" type="button" style="padding:8px 14px;border:0;border-radius:8px;background:#ef4444;color:#fff;font-weight:700;cursor:pointer;">Decline</button>
      <button id="dmGroupAcceptCall" type="button" style="padding:8px 14px;border:0;border-radius:8px;background:#22c55e;color:#fff;font-weight:700;cursor:pointer;">Accept</button>
    </div>`;
  document.body.appendChild(popup);
  _dmGroupIncomingPopup = popup;

  popup.querySelector('#dmGroupDeclineCall')?.addEventListener('click', _closeDmGroupIncomingPopup);
  popup.querySelector('#dmGroupAcceptCall')?.addEventListener('click', async () => {
    _closeDmGroupIncomingPopup();
    window.__joiningDmGroupInvite = true;
    try {
      await startDmGroupLiveKitCall(groupId, !!data.is_video);
    } finally {
      window.__joiningDmGroupInvite = false;
    }
  });
};

window._onDmGroupCallBusy = function (data) {
  const who = data.from_username || 'User';
  if (typeof showToast === 'function') {
    showToast(escHtml(who) + ' is busy', 'info');
  }
  const toast = document.querySelector('.toast:last-child, .toast-notification:last-child');
  if (toast) setTimeout(() => toast.remove(), 2000);
};


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
  _dmCallRemoteVideo = !!data.is_video;

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
    if (e.streams && e.streams[0]) {
      _dmCallRemoteStream = e.streams[0];
    } else {
      if (!_dmCallRemoteStream) _dmCallRemoteStream = new MediaStream();
      _dmCallRemoteStream.addTrack(e.track);
    }

    _attachDmRemoteMedia();
  };

  pc.onconnectionstatechange = () => {
    if (['disconnected', 'failed', 'closed'].includes(pc.connectionState) && _dmCallState !== 'idle') {
      _finishActiveCall('ended');
    }
  };

  return pc;
}

function _attachDmRemoteMedia() {
  if (!_dmCallRemoteStream) return;

  const remoteVideo = document.getElementById('dmCallRemoteVideo');
  const remoteAudio = document.getElementById('dmCallRemoteAudio');

  if (remoteVideo && remoteVideo.srcObject !== _dmCallRemoteStream) {
    remoteVideo.srcObject = _dmCallRemoteStream;
    remoteVideo.play().catch(() => {});
  }

  if (remoteAudio) {
    remoteAudio.muted = _dmCallDeafened;

    if (remoteAudio.srcObject !== _dmCallRemoteStream) {
      remoteAudio.srcObject = _dmCallRemoteStream;
      remoteAudio.play().catch(() => {});
    }
  }

  if (remoteVideo) {
    remoteVideo.muted = true;
  }
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
  _dmCallRemoteStream = null;
  _dmCallDeafened = false;
  _dmCallIsVideo = false;
  _dmCallRemoteVideo = false;
  _dmCallCameraBusy = false;
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

function _dmCallToggleDeafen() {
  _dmCallDeafened = !_dmCallDeafened;

  const remoteAudio = document.getElementById('dmCallRemoteAudio');
  const remoteVideo = document.getElementById('dmCallRemoteVideo');

  if (remoteAudio) remoteAudio.muted = _dmCallDeafened;

  // Remote video may also carry audio. Keep it muted because audio playback
  // is handled by dmCallRemoteAudio to avoid duplicate/echoed playback.
  if (remoteVideo) remoteVideo.muted = true;

  const btn = document.getElementById('dmCallDeafenBtn');
  if (btn) {
    btn.classList.toggle('muted-state', _dmCallDeafened);
    btn.textContent = _dmCallDeafened ? '🔇' : '🔊';
    btn.title = _dmCallDeafened ? 'Undeafen' : 'Deafen';
  }
}
window._dmCallToggleDeafen = _dmCallToggleDeafen;

async function _dmCallToggleCam() {
  if (!_dmCallPc || !_dmCallLocalStream || _dmCallState !== 'active' || _dmCallCameraBusy) return;

  _dmCallCameraBusy = true;

  try {
    const existingTrack = _dmCallLocalStream.getVideoTracks()[0];

    if (existingTrack && existingTrack.readyState === 'live') {
      // A retained video track can be toggled without renegotiating the call.
      // Check enabled FIRST: getVideoTracks()[0] returns the same live track
      // whether it is currently publishing frames or disabled.
      if (!existingTrack.enabled) {
        existingTrack.enabled = true;
        _dmCallIsVideo = true;
        _renderDmCallOverlay('active');
        return;
      }

      existingTrack.enabled = false;
      _dmCallIsVideo = false;
      _renderDmCallOverlay('active');
      return;
    }

    const camStream = await navigator.mediaDevices.getUserMedia({
      video: { width: { ideal: 640 }, height: { ideal: 480 } },
      audio: false,
    });

    const videoTrack = camStream.getVideoTracks()[0];
    if (!videoTrack) throw new Error('No camera track available');

    _dmCallLocalStream.addTrack(videoTrack);

    const reusableSender = _dmCallPc.getSenders().find(
      sender => !sender.track && sender.transceiver?.receiver?.track?.kind === 'video'
    );

    if (reusableSender) {
      await reusableSender.replaceTrack(videoTrack);
    } else {
      _dmCallPc.addTrack(videoTrack, _dmCallLocalStream);
    }

    _dmCallIsVideo = true;
    _renderDmCallOverlay('active');

    await _dmCallRenegotiate();
  } catch (e) {
    console.error('[DM call] camera toggle failed:', e);
    showToast('Could not change camera', 'error');
  } finally {
    _dmCallCameraBusy = false;
  }
}
window._dmCallToggleCam = _dmCallToggleCam;

async function _dmCallRenegotiate() {
  if (!_dmCallPc || !_dmCallPeerId) return;

  const offer = await _dmCallPc.createOffer();
  await _dmCallPc.setLocalDescription(offer);

  window.wsSend({
    type: 'dm_call_renegotiate',
    target_user_id: _dmCallPeerId,
    group_id: _dmCallGroupId,
    log_id: _dmCallLogId,
    is_video: _dmCallIsVideo,
    sdp: _dmCallPc.localDescription,
  });
}

window._onDmCallRenegotiate = async function(data) {
  if (!_dmCallPc || _dmCallState !== 'active' || !data.sdp) return;

  try {
    await _dmCallPc.setRemoteDescription(new RTCSessionDescription(data.sdp));

    const answer = await _dmCallPc.createAnswer();
    await _dmCallPc.setLocalDescription(answer);

    window.wsSend({
      type: 'dm_call_renegotiate_answer',
      target_user_id: _dmCallPeerId,
      group_id: _dmCallGroupId,
      log_id: _dmCallLogId,
      is_video: !!data.is_video,
      sdp: _dmCallPc.localDescription,
    });

    // Keep the peer camera state separate from this user's local camera.
    _dmCallRemoteVideo = !!data.is_video;
    _renderDmCallOverlay('active');
  } catch (e) {
    console.error('[DM call] renegotiation offer failed:', e);
  }
};

window._onDmCallRenegotiateAnswer = async function(data) {
  if (!_dmCallPc || _dmCallState !== 'active' || !data.sdp) return;

  try {
    await _dmCallPc.setRemoteDescription(new RTCSessionDescription(data.sdp));
  } catch (e) {
    console.error('[DM call] renegotiation answer failed:', e);
  }
};

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
      <audio id="dmCallRemoteAudio" autoplay playsinline></audio>
      <div style="position:relative;background:#000;height:${(_dmCallIsVideo || _dmCallRemoteVideo) ? '220px' : '0'};">
        ${_dmCallRemoteVideo ? `<video id="dmCallRemoteVideo" autoplay playsinline muted style="width:100%;height:100%;object-fit:cover;"></video>` : ''}
        ${_dmCallIsVideo ? `<video id="dmCallLocalVideo" autoplay playsinline muted style="position:absolute;bottom:8px;right:8px;width:70px;height:52px;border-radius:6px;object-fit:cover;transform:scaleX(-1);border:1px solid rgba(255,255,255,0.3);"></video>` : ''}
      </div>
      <div style="padding:12px;display:flex;align-items:center;gap:10px;">
        <div style="flex:1;min-width:0;">
          <div style="font-size:13px;font-weight:700;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escHtml(partnerName)}</div>
          <div style="font-size:11px;color:#22c55e;">Connected</div>
        </div>
        <button id="dmCallMicBtn" onclick="_dmCallToggleMic()" title="Mute" style="width:32px;height:32px;border-radius:50%;background:var(--bg-tertiary);border:none;color:var(--text-primary);cursor:pointer;">🎤</button>
        <button id="dmCallDeafenBtn" onclick="_dmCallToggleDeafen()" title="${_dmCallDeafened ? 'Undeafen' : 'Deafen'}" class="${_dmCallDeafened ? 'muted-state' : ''}" style="width:32px;height:32px;border-radius:50%;background:var(--bg-tertiary);border:none;color:var(--text-primary);cursor:pointer;">${_dmCallDeafened ? '🔇' : '🔊'}</button>
        <button id="dmCallCamBtn" onclick="_dmCallToggleCam()" title="${_dmCallIsVideo ? 'Turn camera off' : 'Turn camera on'}" class="${_dmCallIsVideo ? '' : 'muted-state'}" style="width:32px;height:32px;border-radius:50%;background:var(--bg-tertiary);border:none;color:var(--text-primary);cursor:pointer;">📷</button>
        <button onclick="endDmCall()" title="End call" style="width:32px;height:32px;border-radius:50%;background:#ef4444;border:none;color:#fff;cursor:pointer;">☎</button>
      </div>`;

    if (_dmCallIsVideo && _dmCallLocalStream) {
      const localVid = document.getElementById('dmCallLocalVideo');
      if (localVid) {
        localVid.srcObject = _dmCallLocalStream;
        localVid.play().catch(() => {});
      }
    }

    _attachDmRemoteMedia();
  }
}
