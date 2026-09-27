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

  function participantUser(participant) {
    let metadata = {};
    try { metadata = JSON.parse(participant.metadata || '{}'); } catch (_) {}

    const id = participantId(participant);
    const name = participant.name || metadata.full_name || metadata.username || participant.identity || 'Participant';

    return {
      id,
      user_id: id,
      full_name: name,
      username: metadata.username || name,
      role: metadata.role || '',
      avatar_color_gradient: metadata.avatar_color_gradient || '#3b82f6,#6366f1',
      muted: !participant.isMicrophoneEnabled,
    };
  }

  function participantCard(participant) {
    const uid = participantId(participant);
    return document.querySelector(
      '.vc-speaker-card[data-user-id="' + uid + '"], .vc-listener-card[data-user-id="' + uid + '"]'
    );
  }

  function refreshParticipantCounts() {
    const speaking = document.querySelectorAll('.vc-speaker-card').length;
    const listening = document.querySelectorAll('.vc-listener-card').length;
    updateVcCounts(speaking, listening);
    if (typeof _refreshVoiceLayout === 'function') _refreshVoiceLayout();
  }

  function syncParticipantState(participant) {
    const uid = participantId(participant);
    if (!uid || uid === Number(window.ECOLLAB?.userId || 0)) return;

    const muted = !participant.isMicrophoneEnabled;
    let card = participantCard(participant);

    // Muting is a microphone state, not a listening-role change. Normal voice
    // participants stay in the Speaking section even while their mic is muted.
    if (card?.classList.contains('vc-listener-card')) {
      card.remove();
      addVcParticipant({ ...participantUser(participant), muted }, true);
      card = participantCard(participant);
    }

    if (card) {
      card.classList.toggle('speaking', participant.isSpeaking === true && !muted);
      const mic = card.querySelector('.sc-mic-btn');
      if (mic) {
        mic.classList.toggle('muted-state', muted);
        mic.title = muted ? 'Muted' : 'Speaking';
      }
    }

    refreshParticipantCounts();
  }

  function syncParticipant(participant) {
    const uid = participantId(participant);
    if (!uid || uid === Number(window.ECOLLAB?.userId || 0)) return;

    if (!participantCard(participant)) {
      // A normal LiveKit room participant is a voice participant regardless of
      // whether their microphone is currently muted.
      addVcParticipant(participantUser(participant), true);
    }
    syncParticipantState(participant);
  }

  function removeParticipant(participant) {
    participantCard(participant)?.remove();
    document.querySelectorAll(
      '.livekit-media-track[data-livekit-participant="' + participant.identity + '"]'
    ).forEach(el => el.remove());
    refreshParticipantCounts();
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
      const source = publication?.source || track.source || '';
      const mediaTrack = track.mediaStreamTrack;

      // Reuse eCollab's existing in-card camera renderer instead of falling
      // back to the entire voice view (which made remote video fullscreen).
      if (source === 'camera' && mediaTrack && typeof _attachRemoteCamera === 'function') {
        el.remove();
        _attachRemoteCamera(uid, new MediaStream([mediaTrack]));
        return;
      }

      if (source === 'screen_share' && mediaTrack) {
        el.remove();
        const stream = new MediaStream([mediaTrack]);
        const username = participant.name || participant.identity || 'Participant';
        if (typeof _attachRemoteScreenShare === 'function') {
          _attachRemoteScreenShare(uid, username, stream);
        }
        if (typeof _showRemoteScreenShareSection === 'function') {
          _showRemoteScreenShareSection(uid, username, stream);
        }
        return;
      }

      const target = document.querySelector(
        '[data-user-id="' + uid + '"] .vc-video-wrap, [data-user-id="' + uid + '"] .vc-video'
      );
      if (target) {
        target.appendChild(el);
      } else {
        // Never append a participant camera directly to voiceChannelView.
        // Keep the track attached but hidden until a proper target exists.
        el.style.display = 'none';
        document.body.appendChild(el);
      }
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

    room.on(RoomEvent.ParticipantConnected, participant => {
      console.log('[LiveKit] participant connected:', participant.identity);
      syncParticipant(participant);
    });
    room.on(RoomEvent.ParticipantDisconnected, participant => {
      console.log('[LiveKit] participant disconnected:', participant.identity);
      removeParticipant(participant);
    });
    room.on(RoomEvent.ParticipantMetadataChanged, (_metadata, participant) => {
      if (participant) syncParticipant(participant);
    });
    room.on(RoomEvent.TrackMuted, (_publication, participant) => {
      if (participant) syncParticipantState(participant);
    });
    room.on(RoomEvent.TrackUnmuted, (_publication, participant) => {
      if (participant) syncParticipantState(participant);
    });
    room.on(RoomEvent.ActiveSpeakersChanged, participants => {
      const active = new Set(participants.map(participant => participant.identity));
      room.remoteParticipants.forEach(participant => {
        const card = participantCard(participant);
        if (card) card.classList.toggle(
          'speaking',
          active.has(participant.identity) && participant.isMicrophoneEnabled
        );
      });
    });
    room.on(RoomEvent.TrackSubscribed, (track, publication, participant) => {
      syncParticipant(participant);
      attach(track, publication, participant);
      refreshParticipantCounts();
    });
    room.on(RoomEvent.TrackUnsubscribed, (track, publication, participant) => {
      const uid = participant ? participantId(participant) : 0;
      const source = publication?.source || track?.source || '';
      if (uid && source === 'camera' && typeof _removeRemoteCamera === 'function') {
        _removeRemoteCamera(uid);
      }
      if (uid && source === 'screen_share') {
        if (typeof _removeRemoteScreenShare === 'function') _removeRemoteScreenShare(uid);
        if (typeof _hideRemoteScreenShareSection === 'function') _hideRemoteScreenShareSection(uid);
      }
      detach(track);
      refreshParticipantCounts();
    });
    room.on(RoomEvent.Disconnected, () => {
      document.querySelectorAll('.livekit-media-track').forEach(el => el.remove());
      activeChannelId = null;
    });

    await room.connect(auth.url, auth.token);
    activeChannelId = Number(channelId);

    // eCollab joins muted by default.
    await room.localParticipant.setMicrophoneEnabled(false);

    // ParticipantConnected only fires for later arrivals. Synchronize users
    // who were already present when this browser joined the LiveKit room.
    room.remoteParticipants.forEach(participant => {
      syncParticipant(participant);
      participant.trackPublications.forEach(publication => {
        if (publication.track && publication.isSubscribed) {
          attach(publication.track, publication, participant);
        }
      });
    });
    refreshParticipantCounts();

    return room;
  }

  async function setMic(enabled) {
    if (!room) throw new Error('Not connected to voice.');
    await room.localParticipant.setMicrophoneEnabled(Boolean(enabled));
  }

  async function setCamera(enabled) {
    if (!room) throw new Error('Not connected to voice.');
    await room.localParticipant.setCameraEnabled(Boolean(enabled));

    const uid = Number(window.ECOLLAB?.userId || 0);
    if (!uid) return;

    if (!enabled) {
      if (typeof _removeRemoteCamera === 'function') _removeRemoteCamera(uid);
      return;
    }

    const publication = room.localParticipant.getTrackPublication?.('camera')
      || Array.from(room.localParticipant.trackPublications?.values?.() || [])
        .find(pub => (pub.source || pub.track?.source) === 'camera');
    const mediaTrack = publication?.track?.mediaStreamTrack;
    if (mediaTrack && typeof _attachRemoteCamera === 'function') {
      _attachRemoteCamera(uid, new MediaStream([mediaTrack]));
    }
  }

  async function setScreen(enabled) {
    if (!room) throw new Error('Not connected to voice.');
    await room.localParticipant.setScreenShareEnabled(Boolean(enabled));

    const uid = Number(window.ECOLLAB?.userId || 0);
    if (!uid) return;

    if (!enabled) {
      if (typeof _removeRemoteScreenShare === 'function') _removeRemoteScreenShare(uid);
      if (typeof _hideRemoteScreenShareSection === 'function') _hideRemoteScreenShareSection(uid);
      return;
    }

    const publication = Array.from(room.localParticipant.trackPublications?.values?.() || [])
      .find(pub => (pub.source || pub.track?.source) === 'screen_share');
    const mediaTrack = publication?.track?.mediaStreamTrack;
    if (mediaTrack) {
      const stream = new MediaStream([mediaTrack]);
      const username = window.ECOLLAB?.fullName || window.ECOLLAB?.username || 'You';
      if (typeof _attachRemoteScreenShare === 'function') _attachRemoteScreenShare(uid, username, stream);
      if (typeof _showRemoteScreenShareSection === 'function') _showRemoteScreenShareSection(uid, username, stream);
    }
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
