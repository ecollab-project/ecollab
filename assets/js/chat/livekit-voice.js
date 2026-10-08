/**
 * LiveKit media adapter for eCollab.
 * Keeps eCollab's existing chat WebSocket for app events; LiveKit carries media.
 */
'use strict';

(() => {
  let room = null;
  let activeChannelId = null;
  let screenQuality = null;
  let mediaBusy = false;
  let countFetchBusy = false;

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
      full_name: escHtml(name),
      username: escHtml(metadata.username || name),
      role: escHtml(metadata.role || ''),
      avatar_color_gradient: '#3b82f6,#6366f1',
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
    if (activeChannelId && room) {
      const badge = document.querySelector('.voice-channel[data-channel-id="'+activeChannelId+'"] .vc-count');
      if (badge) badge.textContent = String(room.remoteParticipants.size + 1);
    }
    if (typeof _refreshVoiceLayout === 'function') _refreshVoiceLayout();
  }

  function syncParticipantState(participant) {
    const uid = participantId(participant);
    if (!uid || uid === Number(window.ECOLLAB?.userId || 0)) return;

    const muted = !participant.isMicrophoneEnabled;
    let card = participantCard(participant);

    // Both devices use the same publication state. Reattach the live video
    // after changing card layout; never stop another participant's track.
    if (!card || card.classList.contains('vc-listener-card') !== muted) {
      const focused = card?.classList.contains('vc-camera-focus');
      const camera = participant.getTrackPublication('camera');
      if (camera?.track) camera.track.detach().forEach(el => el.remove());
      card?.remove();
      addVcParticipant(participantUser(participant), !muted);
      card = participantCard(participant);
      card?.classList.toggle('vc-camera-focus', !!focused);
      if (camera?.track && !camera.isMuted) attach(camera.track, camera, participant);
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
      addVcParticipant(participantUser(participant), participant.isMicrophoneEnabled);
    }
    syncParticipantState(participant);
  }

  function removeParticipant(participant) {
    if(typeof _hideRemoteScreenShareSection === "function")_hideRemoteScreenShareSection(participantId(participant));
    participantCard(participant)?.remove();
    document.querySelectorAll(
      '.livekit-media-track[data-livekit-participant="' + participant.identity + '"]'
    ).forEach(el => el.remove());
    refreshParticipantCounts();
  }

  function attach(track, publication, participant) {
    // Repeated unmute/subscription events must not duplicate audio elements.
    track.detach().forEach(el => el.remove());
    const el = track.attach();
    el.autoplay = true;
    el.dataset.livekitParticipant = participant.identity;
    el.dataset.livekitSource = publication?.source || track.source || '';
    el.classList.add('livekit-media-track');
    if (track.kind === 'audio') {
      el.style.display = 'none';
      el.muted = vcDeafened;
      document.body.appendChild(el);
      window.EcollabMediaSettings?.playback(room);
    } else {
      const uid = participantId(participant);
      const source = publication?.source || track.source || '';
      const mediaTrack = track.mediaStreamTrack;

      // Reuse eCollab's existing in-card camera renderer instead of falling
      // back to the entire voice view (which made remote video fullscreen).
      if (source === 'camera' && mediaTrack) {
        // Keep the LiveKit RemoteVideoTrack attached to the actual visible
        // video element. Do not clone mediaStreamTrack into a new MediaStream:
        // doing so bypasses LiveKit's remote-track lifecycle and can freeze on
        // mobile browsers.
        track.detach(el);
        el.remove();
        if (typeof _removeRemoteCamera === 'function') _removeRemoteCamera(uid);
        const card = participantCard(participant);
        if (!card) return;
        let video = card.querySelector('.vc-cam-preview:not(.vc-screen-preview)');
        if (!video) {
          video = document.createElement('video');
          video.className = 'vc-cam-preview';
          video.autoplay = true;
          video.muted = true;
          video.playsInline = true;
          card.insertBefore(video, card.firstChild);
        }
        track.attach(video);
        card.classList.add('has-camera');
        decorateCamera(card, uid);
        video.play().catch(() => {});
        return;
      }

      if (source === 'screen_share' && mediaTrack) {
        track.detach(el);
        el.remove();
        const stream = new MediaStream([mediaTrack]);
        const username = participant.name || participant.identity || 'Participant';
        if (typeof _showRemoteScreenShareSection === 'function') {
          _showRemoteScreenShareSection(uid, username, stream);
        }

        // Ensure the screen-share card always has a visible Watch/Unwatch
        // control even when the card was created by the LiveKit path.
        requestAnimationFrame(() => {
          const card = document.getElementById('vcScreenGrid')?.querySelector('[data-screen-user="' + uid + '"]');
          if (!card) return;
          let wrap = card.querySelector('.vc-screen-card-watch');
          if (!wrap) {
            wrap = document.createElement('div');
            wrap.className = 'vc-screen-card-watch';
            card.appendChild(wrap);
          }
          let btn = wrap.querySelector('.vc-screen-watch-btn');
          if (!btn) {
            btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'vc-screen-watch-btn';
            wrap.appendChild(btn);
          }
          btn.onclick = event => {
            event.stopPropagation();
            if (typeof toggleScreenWatch === 'function') toggleScreenWatch(uid);
          };
          if (typeof _applyScreenWatchState === 'function') _applyScreenWatchState(uid);
          else btn.textContent = 'Watch';
        });
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
    const previous = room; room = null;
    try { await previous.disconnect(); } catch (_) {}
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
    await window.EcollabMediaSettings?.ready;
    room = new Room({
      // eCollab dynamically removes/recreates participant video elements.
      // Adaptive stream observes element visibility and may pause a remote
      // track while those elements are being moved, especially on mobile.
      adaptiveStream: false,
      dynacast: true,
      disconnectOnPageLeave: true,
      audioCaptureDefaults: window.EcollabMediaSettings?.audio(),
      videoCaptureDefaults: window.EcollabMediaSettings?.video(),
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
    room.on(RoomEvent.TrackMuted, (publication, participant) => {
      if (!participant) return;
      syncParticipantState(participant);

      const uid = participantId(participant);
      const source = publication?.source || publication?.track?.source || '';
      // LiveKit disables an existing camera by muting its publication; it does
      // not necessarily unsubscribe/unpublish it. Remove the stale remote
      // preview as soon as the remote camera publication becomes muted.
      if (uid && source === 'camera' && typeof _removeRemoteCamera === 'function') {
        _removeRemoteCamera(uid);
      }
      if (uid && source === 'screen_share') {
        if (typeof _hideRemoteScreenShareSection === 'function') _hideRemoteScreenShareSection(uid);
      }
    });
    room.on(RoomEvent.TrackUnmuted, (publication, participant) => {
      if (!participant) return;
      syncParticipantState(participant);

      // LiveKit commonly keeps the same subscribed publication when a camera
      // is toggled off/on. In that case TrackSubscribed will NOT fire again,
      // so reattach the existing live track on TrackUnmuted.
      const source = publication?.source || publication?.track?.source || '';
      const track = publication?.track;
      const mediaTrack = track?.mediaStreamTrack;
      if ((source === 'camera' || source === 'screen_share') && track && mediaTrack && mediaTrack.readyState === 'live') {
        attach(track, publication, participant);
      }
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
    room.on(RoomEvent.TrackStreamStateChanged, (publication, streamState, participant) => {
      if (!participant || !publication?.track) return;
      const source = publication.source || publication.track.source || '';
      if ((source === 'camera' || source === 'screen_share') && String(streamState).toLowerCase().includes('active')) {
        attach(publication.track, publication, participant);
      }
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
        if (typeof _hideRemoteScreenShareSection === 'function') _hideRemoteScreenShareSection(uid);
      }
      detach(track);
      refreshParticipantCounts();
    });
    room.on(RoomEvent.Disconnected, () => {
      document.querySelectorAll('.livekit-media-track').forEach(el => el.remove());
      activeChannelId = null;
      if (vcActive) { vcActive=false; disconnectVoice(); }
    });
    room.on(RoomEvent.LocalTrackUnpublished, publication => {
      if (publication.source === 'screen_share') { vcScreenOn=false; syncScreenControls(); const uid=Number(window.ECOLLAB?.userId); _removeRemoteScreenShare(uid); _hideRemoteScreenShareSection(uid); }
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
    try { await window.EcollabMediaSettings.initialize(room); } catch(e) { showToast(e.message,'info'); }

    return room;
  }

  async function setMic(enabled) {
    if (!room) throw new Error('Not connected to voice.');
    await room.localParticipant.setMicrophoneEnabled(Boolean(enabled), window.EcollabMediaSettings?.audio());
  }

  async function setCamera(enabled) {
    if (!room) throw new Error('Not connected to voice.');
    await room.localParticipant.setCameraEnabled(Boolean(enabled), window.EcollabMediaSettings?.video());

    const uid = Number(window.ECOLLAB?.userId || 0);
    if (!uid) return;

    if (!enabled) {
      if (typeof _removeRemoteCamera === 'function') _removeRemoteCamera(uid);
      return;
    }

    const publication = room.localParticipant.getTrackPublication?.('camera')
      || Array.from(room.localParticipant.trackPublications?.values?.() || [])
        .find(pub => (pub.source || pub.track?.source) === 'camera');
    if (publication?.track) attach(publication.track, publication, room.localParticipant);
  }

  function decorateCamera(card, uid) {
    card.dataset.local=String(uid===Number(window.ECOLLAB?.userId));
    let button=card.querySelector('.vc-camera-size');
    if(!button){button=document.createElement('button');button.type='button';button.className='vc-camera-size';card.appendChild(button);}
    const update=()=>{const expanded=card.classList.contains('vc-camera-focus');button.textContent=expanded?'Restore camera':'Expand camera';button.setAttribute('aria-pressed',String(expanded));};
    button.onclick=event=>{event.stopPropagation();card.classList.toggle('vc-camera-focus');update();};update();
  }

  async function setScreen(enabled) {
    if (!room) throw new Error('Not connected to voice.');
    const options = window.EcollabMediaSettings.screenOptions(screenQuality || window.EcollabMediaSettings.preferences.quality);
    await room.localParticipant.setScreenShareEnabled(Boolean(enabled), options.capture, options.publish);
    vcScreenOn = room.localParticipant.isScreenShareEnabled;
    syncScreenControls();

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
      mediaTrack.contentHint = 'detail';
      mediaTrack.addEventListener('ended', () => { vcScreenOn = false; syncScreenControls(); }, {once:true});
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
    // DM group calls use /API/dm/livekit-group-token.php. A group id is not a
    // server voice-channel id and must never reach /API/chat/livekit-token.php.
    if (window.__ecollabStartingDmGroupCall) {
      console.warn('[LiveKit] Ignored server voice join during DM group call flow.');
      return;
    }
    if (window.EcollabCalls?.busy()) { showToast('Leave your current call first.', 'info'); return; }
    if (vcActive && Number(vcChannelId) === Number(channelId)) { toggleVcMinimize(); return; }
    if (vcActive) await disconnectVoice();
    vcMicMuted = true; vcCamOn = false; vcScreenOn = false; vcDeafened = false;
    document.getElementById('vcSpeakingGrid')?.replaceChildren();
    document.getElementById('vcListeningGrid')?.replaceChildren();

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
      window.wsSend?.({type:'join_voice',channel_id:Number(channelId)});
      refreshParticipantCounts();
      showToast('🔊 Joined ' + vcRoomName, 'success');
    } catch (err) {
      console.error('[LiveKit] join failed', err);
      await disconnectVoice();
      vcActive = false;
      view?.classList.remove('active');
      document.body.classList.remove('vc-active');
      if (el) el.classList.remove('connected');
      showToast('Voice connection failed: ' + err.message, 'info');
    }
  };

  disconnectVoice = async function () {
    const leavingChannel = vcChannelId;
    vcActive = false;
    await disconnect();
    vcMinimized = false;
    vcCamOn = false;
    vcScreenOn = false;
    vcMicMuted = true;
    document.getElementById('voiceChannelView')?.classList.remove('active', 'vc-minimized');
    document.body.classList.remove('vc-active', 'vc-pip');
    document.querySelectorAll('.voice-channel').forEach(v => v.classList.remove('connected'));
    _updateConnectedBar(false);
    window.wsSend?.({type:'leave_voice',channel_id:Number(leavingChannel)});
    refreshSidebarCounts();
    if (typeof _reportVoiceStatus === 'function') _reportVoiceStatus('leave', leavingChannel);
    showToast('Disconnected from voice', 'info');
  };

  toggleVcMic = async function () {
    if (!room || mediaBusy) return;
    mediaBusy = true;
    const nextMuted = !vcMicMuted;
    try {
      await setMic(!nextMuted);
      vcMicMuted = nextMuted;
      document.getElementById('vcMicBtn')?.classList.toggle('muted-state', vcMicMuted);
      document.getElementById('vcMicBtn')?.classList.toggle('unmuted', !vcMicMuted);
      const localCard=participantCard(room.localParticipant);
      const focused=localCard?.classList.contains('vc-camera-focus');
      _moveUserCardOnMute(vcMicMuted);
      participantCard(room.localParticipant)?.classList.toggle('vc-camera-focus',!!focused);
      const camera=room.localParticipant.getTrackPublication('camera');
      if(camera?.track&&!camera.isMuted)attach(camera.track,camera,room.localParticipant);
      refreshParticipantCounts();
    } catch (err) { showToast('Microphone error: ' + err.message, 'info'); } finally { mediaBusy = false; }
  };

  toggleCamera = async function () {
    if (!room || mediaBusy) return;
    mediaBusy = true;
    try {
      await setCamera(!vcCamOn);
      vcCamOn = room.localParticipant.isCameraEnabled;
      document.getElementById('vcCamBtn')?.classList.toggle('active', vcCamOn);
      document.getElementById('vcQuickCam')?.classList.toggle('active', vcCamOn);
    } catch (err) { showToast('Camera error: ' + err.message, 'info'); } finally { mediaBusy = false; }
  };

  function syncScreenControls() {
    document.getElementById('vcScreenBtn')?.classList.toggle('active', vcScreenOn);
    document.getElementById('vcQuickScreen')?.classList.toggle('active', vcScreenOn);
    const start = document.getElementById('vcScreenStartBtn');
    if (start) start.textContent = vcScreenOn ? 'Stop Sharing' : 'Start Sharing';
  }
  toggleScreenShare = function () {
    if (!room) return;
    if (!navigator.mediaDevices?.getDisplayMedia) { showToast('Screen sharing is unavailable in this browser.', 'info'); return; }
    if (vcScreenOn) stopScreenShare(); else {
      const selected=screenQuality||window.EcollabMediaSettings.preferences.quality;
      document.querySelectorAll('.screen-quality-btn').forEach(button=>button.classList.toggle('active',button.getAttribute('onclick')?.includes("'"+selected+"'")));
      openModal('vcScreenModal');
    }
  };
  selectScreenQuality = function (button, quality) {
    screenQuality = ['720p','1080p','source'].includes(quality) ? quality : '1080p';
    document.querySelectorAll('.screen-quality-btn').forEach(el=>el.classList.toggle('active',el===button));
  };
  startStopScreenShare = async function () {
    if (!room || mediaBusy) return;
    mediaBusy = true;
    try { await setScreen(!vcScreenOn); closeModal('vcScreenModal'); }
    catch (e) { showToast('Screen share: '+e.message,'info'); }
    finally { mediaBusy = false; }
  };
  stopScreenShare = async function () {
    if (!room) return;
    try { await setScreen(false); } catch(e) { showToast(e.message,'info'); }
  };
  toggleVcDeafen = function () {
    vcDeafened = !vcDeafened;
    document.querySelectorAll('audio.livekit-media-track').forEach(el=>el.muted=vcDeafened);
    document.getElementById('vcDeafenBtn')?.classList.toggle('muted-state',vcDeafened);
  };
  toggleMute = function () { if(room) toggleVcMic(); };
  toggleDeafen = function () { if(room) toggleVcDeafen(); };
  openAudioSettings = function () { if(room) window.EcollabMediaSettings.open(room); };
  openNoiseCancelModal = openAudioSettings;
  // Keep deferred inline event stubs aligned with the media adapter.
  for (const name of ['joinVoice','disconnectVoice','toggleVcMic','toggleCamera','toggleScreenShare','startStopScreenShare','stopScreenShare','selectScreenQuality','toggleVcDeafen','toggleMute','toggleDeafen','openAudioSettings','openNoiseCancelModal']) window['__real_'+name]=window[name];

  async function refreshSidebarCounts() {
    if (countFetchBusy || document.hidden) return;
    const ids = [...document.querySelectorAll('.voice-channel[data-channel-id]')].map(el=>Number(el.dataset.channelId)).filter(Boolean).slice(0,100);
    if (!ids.length) return;
    countFetchBusy = true;
    try {
      const res = await fetch((window.ECOLLAB?.baseUrl||'')+'/API/chat/livekit-counts.php?channel_ids='+ids.join(','),{credentials:'same-origin'});
      if(!res.ok) return;
      const data = await res.json();
      for(const [id,count] of Object.entries(data.counts||{})) {
        const badge=document.querySelector('.voice-channel[data-channel-id="'+Number(id)+'"] .vc-count');
        if(badge)badge.textContent=String(Number(id)===activeChannelId&&room?room.remoteParticipants.size+1:count);
      }
    } catch (_) {} finally { countFetchBusy=false; }
  }
  window.addEventListener('ecollab-media-settings-changed',()=>{screenQuality=null;window.EcollabMediaSettings.playback(room);});
  setInterval(refreshSidebarCounts,10000);
  document.addEventListener('visibilitychange',refreshSidebarCounts);
  setTimeout(refreshSidebarCounts,1000);
})();

