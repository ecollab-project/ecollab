/**
 * LiveKit media adapter for eCollab.
 * Keeps eCollab's existing chat WebSocket for app events; LiveKit carries media.
 */
'use strict';

(() => {
  let room = null;
  let activeChannelId = null;

  function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
  }

  async function tokenFor(channelId) {
    const res = await fetch((window.ECOLLAB?.baseUrl || '') + '/API/chat/livekit-token.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrf(),
      },
      body: JSON.stringify({ channel_id: Number(channelId) }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.success) throw new Error(data.error || 'Unable to join voice channel');
    return data;
  }

  function mediaContainer() {
    return document.getElementById('voiceChannelView') || document.body;
  }

  function participantId(participant) {
    const m = (() => { try { return JSON.parse(participant.metadata || '{}'); } catch { return {}; } })();
    return Number(m.ecollab_user_id || String(participant.identity || '').replace(/^user-/, '')) || 0;
  }

  function attach(track, publication, participant) {
    const el = track.attach();
    el.autoplay = true;
    el.dataset.livekitParticipant = participant.identity;
    el.dataset.livekitSource = publication?.source || track.source || '';
    el.classList.add('livekit-media-track');
    if (track.kind === 'audio') {
      el.style.display = 'none';
      document.body.appendChild(el);
    } else {
      const uid = participantId(participant);
      const target = document.querySelector('[data-user-id="' + uid + '"] .vc-video-wrap, [data-user-id="' + uid + '"] .vc-video') || mediaContainer();
      target.appendChild(el);
    }
  }

  function detach(track) {
    track.detach().forEach(el => el.remove());
  }

  async function disconnect() {
    if (!room) return;
    try { await room.disconnect(); } catch (_) {}
    document.querySelectorAll('.livekit-media-track').forEach(el => el.remove());
    room = null;
    activeChannelId = null;
  }

  async function connect(channelId) {
    if (!window.LivekitClient) throw new Error('LiveKit client library did not load.');
    if (room && activeChannelId === Number(channelId)) return room;
    await disconnect();

    const auth = await tokenFor(channelId);
    const { Room, RoomEvent, Track } = window.LivekitClient;
    room = new Room({
      adaptiveStream: true,
      dynacast: true,
      disconnectOnPageLeave: true,
    });

    room.on(RoomEvent.TrackSubscribed, attach);
    room.on(RoomEvent.TrackUnsubscribed, detach);
    room.on(RoomEvent.Disconnected, () => {
      document.querySelectorAll('.livekit-media-track').forEach(el => el.remove());
      activeChannelId = null;
    });

    await room.connect(auth.url, auth.token);
    activeChannelId = Number(channelId);

    // eCollab joins muted by default.
    await room.localParticipant.setMicrophoneEnabled(false);
    return room;
  }

  async function setMic(enabled) {
    if (!room) throw new Error('Not connected to voice.');
    await room.localParticipant.setMicrophoneEnabled(Boolean(enabled));
  }

  async function setCamera(enabled) {
    if (!room) throw new Error('Not connected to voice.');
    await room.localParticipant.setCameraEnabled(Boolean(enabled));
  }

  async function setScreen(enabled) {
    if (!room) throw new Error('Not connected to voice.');
    await room.localParticipant.setScreenShareEnabled(Boolean(enabled));
  }

  window.EcollabLiveKit = {
    connect,
    disconnect,
    setMic,
    setCamera,
    setScreen,
    get room() { return room; },
    get channelId() { return activeChannelId; },
  };

  // Replace only the media-facing voice-channel entry points. The existing
  // eCollab overlay/layout helpers remain in use.
  joinVoice = async function (channelSlug, el, channelId, roomNameOverride) {
    if (vcActive && Number(vcChannelId) !== Number(channelId)) await disconnect();

    document.querySelectorAll('.voice-channel').forEach(v => v.classList.remove('connected'));
    if (el) el.classList.add('connected');

    vcActive = true;
    vcMinimized = false;
    vcChannelId = Number(channelId);
    vcRoomName = roomNameOverride || el?.textContent?.trim()?.replace(/\\d+/g, '').trim() || 'Voice Channel';

    const view = document.getElementById('voiceChannelView');
    if (view) {
      view.classList.remove('vc-minimized');
      view.classList.add('active');
      document.body.classList.add('vc-active');
    }
    _setVcLabels();
    _updateConnectedBar(true);
    renderVcUser();
    _ensureMinimizeBtn();
    _ensureVoiceQuickActions();
    _refreshVoiceLayout();

    try {
      await connect(channelId);
      if (typeof _reportVoiceStatus === 'function') _reportVoiceStatus('join', channelId);
      if (typeof _bumpSidebarVcCount === 'function') _bumpSidebarVcCount(channelId, 1);
      showToast('🔊 Joined ' + vcRoomName, 'success');
    } catch (err) {
      console.error('[LiveKit] join failed', err);
      vcActive = false;
      view?.classList.remove('active');
      document.body.classList.remove('vc-active');
      if (el) el.classList.remove('connected');
      showToast('Voice connection failed: ' + err.message, 'info');
    }
  };

  disconnectVoice = async function () {
    const leavingChannel = vcChannelId;
    await disconnect();
    vcActive = false;
    vcMinimized = false;
    vcCamOn = false;
    vcScreenOn = false;
    vcMicMuted = true;
    document.getElementById('voiceChannelView')?.classList.remove('active', 'vc-minimized');
    document.body.classList.remove('vc-active', 'vc-pip');
    document.querySelectorAll('.voice-channel').forEach(v => v.classList.remove('connected'));
    _updateConnectedBar(false);
    if (typeof _bumpSidebarVcCount === 'function') _bumpSidebarVcCount(leavingChannel, -1);
    if (typeof _reportVoiceStatus === 'function') _reportVoiceStatus('leave', leavingChannel);
    showToast('Disconnected from voice', 'info');
  };

  toggleVcMic = async function () {
    if (!room) return;
    const nextMuted = !vcMicMuted;
    try {
      await setMic(!nextMuted);
      vcMicMuted = nextMuted;
      document.getElementById('vcMicBtn')?.classList.toggle('muted-state', vcMicMuted);
      document.getElementById('vcMicBtn')?.classList.toggle('unmuted', !vcMicMuted);
      _moveUserCardOnMute(vcMicMuted);
    } catch (err) { showToast('Microphone error: ' + err.message, 'info'); }
  };

  toggleCamera = async function () {
    if (!room) return;
    try {
      await setCamera(!vcCamOn);
      vcCamOn = !vcCamOn;
      document.getElementById('vcCamBtn')?.classList.toggle('active', vcCamOn);
      document.getElementById('vcQuickCam')?.classList.toggle('active', vcCamOn);
    } catch (err) { showToast('Camera error: ' + err.message, 'info'); }
  };

  toggleScreenShare = async function () {
    if (!room) return;
    try {
      await setScreen(!vcScreenOn);
      vcScreenOn = !vcScreenOn;
      document.getElementById('vcScreenBtn')?.classList.toggle('active', vcScreenOn);
      document.getElementById('vcQuickScreen')?.classList.toggle('active', vcScreenOn);
    } catch (err) { showToast('Screen share error: ' + err.message, 'info'); }
  };
})();
