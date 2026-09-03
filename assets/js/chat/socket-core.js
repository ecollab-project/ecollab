/**
 * socket.js — WebSocket client for Ecollab Chat
 *
 * Responsibilities:
 *   1. Fetch a server-issued ws_token before connecting (prevents user_id spoofing)
 *   2. Connect to Ratchet WS server with exponential-backoff reconnect
 *   3. Dispatch every incoming event type to the correct UI handler
 *   4. Graceful polling fallback when WS is unavailable
 *   5. Typing throttle, presence updates, draft/thread/mention sync over WS
 */

'use strict';

// ── State ────────────────────────────────────────────────────────────────────
let chatSocket             = null;
let socketReconnectTimer   = null;
let socketReconnectDelay   = 2000;
let socketReconnectAttempts = 0;
const MAX_RECONNECT_ATTEMPTS = 5;
const typingUsers          = new Set();
let _authed                = false;
let _wsToken               = null;        // server-issued token, fetched before connect

// ── Token fetch + connect entry point ────────────────────────────────────────
async function connectWebSocket() {
  try {
    const base = window.ECOLLAB?.baseUrl || '';
    const res  = await fetch(`${base}/API/auth/ws-token.php`, { credentials: 'same-origin' });
    if (!res.ok) throw new Error('ws-token fetch failed: ' + res.status);
    const data = await res.json();
    if (!data.token) throw new Error('No token in ws-token response');
    _wsToken = data.token;
  } catch (err) {
    console.warn('[WS] Could not fetch ws_token — falling back to polling.', err.message);
    startPollingFallback();
    return;
  }
  initWebSocket();
}

// ── Core WebSocket init ───────────────────────────────────────────────────────
function initWebSocket() {
  const wsUrl = window.ECOLLAB?.wsUrl || 'ws://localhost:8080';

  if (chatSocket && (chatSocket.readyState === WebSocket.CONNECTING || chatSocket.readyState === WebSocket.OPEN)) {
    return;
  }

  try {
    const socket = new WebSocket(wsUrl);
    chatSocket = socket;
    window.chatSocket = socket;

    socket.onopen = () => {
      if (chatSocket !== socket || socket.readyState !== WebSocket.OPEN) return;
      console.log('[WS] Connected');
      socketReconnectDelay    = 2000;
      socketReconnectAttempts = 0;
      _authed                 = false;

      // Authenticate this exact socket; a reconnect may have replaced the global.
      socket.send(JSON.stringify({ type: 'auth', ws_token: _wsToken }));
    };

    socket.onmessage = (event) => {
      if (chatSocket !== socket) return;
      let data;
      try { data = JSON.parse(event.data); } catch { return; }
      handleSocketMessage(data);
    };

    socket.onclose = (event) => {
      if (chatSocket !== socket) return;
      chatSocket = null;
      _authed = false;
      console.info(`[WS] Closed (code ${event.code})`);
      if (event.code === 1006 && socketReconnectAttempts >= MAX_RECONNECT_ATTEMPTS) {
        console.info('[WS] Server unreachable — switching to polling mode');
        startPollingFallback();
        return;
      }
      if (socketReconnectAttempts < MAX_RECONNECT_ATTEMPTS) {
        scheduleReconnect();
      } else {
        console.info('[WS] Max reconnect attempts — polling mode');
        startPollingFallback();
      }
    };

    socket.onerror = () => { /* onclose fires immediately after */ };
  } catch (err) {
    console.warn('[WS] WebSocket constructor threw — polling mode.');
    startPollingFallback();
    return;
  }
}

function scheduleReconnect() {
  clearTimeout(socketReconnectTimer);
  socketReconnectAttempts++;
  // Fetch a fresh token before each reconnect attempt
  socketReconnectTimer = setTimeout(async () => {
    console.log('[WS] Reconnecting… attempt', socketReconnectAttempts);
    try {
      const base = window.ECOLLAB?.baseUrl || '';
      const res  = await fetch(`${base}/API/auth/ws-token.php`, { credentials: 'same-origin' });
      const data = await res.json();
      if (data.token) _wsToken = data.token;
    } catch { /* use last token; server will reject if expired and we'll retry */ }
    initWebSocket();
  }, socketReconnectDelay);
  socketReconnectDelay = Math.min(socketReconnectDelay * 1.5, 30000);
}

// ── Safe send helper ─────────────────────────────────────────────────────────
function wsSend(payload) {
  if (chatSocket && chatSocket.readyState === WebSocket.OPEN && _authed) {
    chatSocket.send(JSON.stringify(payload));
    return true;
  }
  return false;
}
window.wsSend = wsSend;

// ── Message dispatcher ───────────────────────────────────────────────────────
function handleSocketMessage(data) {
  switch (data.type) {

    // ── Auth ──
    case 'auth_ok':
      _authed = true;
      console.log('[WS] Authenticated as user', data.user_id);
      // Join current channel if any
      if (window.ECOLLAB?.currentChannelId) {
        chatSocket.send(JSON.stringify({
          type: 'join_channel',
          channel_id: window.ECOLLAB.currentChannelId,
        }));
      }
      break;

    // ── Messages ──
    case 'message':
      handleIncomingMessage(data.message);
      break;
    case 'message_edited':
      handleMessageEdited(data.message);
      break;
    case 'message_deleted':
      handleMessageDeleted(data.message_id);
      break;
    case 'message_pinned':
      handleMessagePinned(data);
      break;

    // ── Reactions ──
    case 'reaction':
      handleReactionUpdate(data);
      break;

    // ── Typing ──
    case 'typing':
      handleTypingEvent(data);
      break;

    // ── Presence / Active status ──
    case 'presence':
      handlePresenceUpdate(data);
      break;

    // ── Channel events ──
    case 'channel_created':
      handleChannelCreated(data.channel);
      break;
    case 'channel_seen':
      handleChannelSeen(data);
      break;

    // ── Drafts (cross-tab sync) ──
    case 'draft_saved':
      handleDraftSync(data);
      break;

    // ── Threads ──
    case 'thread_reply':
      handleThreadReply(data);
      break;

    // ── Mentions ──
    case 'mention':
      handleMentionEvent(data);
      break;

    // ── Voice ──
    case 'voice_join':
      handleVoiceJoin(data);
      break;
    case 'voice_leave':
      handleVoiceLeave(data);
      break;
    case 'voice_peers':
      if (window.handleVoicePeers) window.handleVoicePeers(data);
      break;

    // ── WebRTC signaling ──
    case 'screen_share_notify':
      handleScreenShareNotify(data);
      break;
    case 'webrtc_offer':
      if (window._handleWebRtcOffer)
        window._handleWebRtcOffer(data.from_user_id, data.from_username, data.sdp, !!data.is_screen_offer);
      break;
    case 'webrtc_answer':
      if (window._handleWebRtcAnswer)
        window._handleWebRtcAnswer(data.from_user_id, data.sdp);
      break;
    case 'webrtc_candidate':
      if (window._handleWebRtcCandidate)
        window._handleWebRtcCandidate(data.from_user_id, data.candidate);
      break;

    // ── Whiteboard ──
    case 'whiteboard_sync':
      if (window.handleRemoteWhiteboardSync) window.handleRemoteWhiteboardSync(data);
      break;
    case 'wb_joined':
    case 'wb_peer_joined':
    case 'wb_peer_left':
    case 'wb_op':
    case 'wb_cursor':
    case 'wb_state':
    case 'wb_state_saved':
    case 'wb_lock_changed':
    case 'wb_version_saved':
    case 'wb_state_reverted':
      if (window.wbHandleWsMessage) window.wbHandleWsMessage(data);
      break;

    // ── Social ──
    case 'connection_request':
      if (window._addConnectionRequestNotif) window._addConnectionRequestNotif(data);
      break;

    // ── Collaboration tools (relay from ws_relay table) ──
    case 'collab_note_op':
    case 'collab_note_cursor':
    case 'collab_note_presence':
    case 'collab_note_full_sync':
    case 'collab_note_updated':
      if (window._collabNotesOnUpdate) window._collabNotesOnUpdate(data);
      break;
    case 'collab_task_added':
    case 'collab_task_moved':
    case 'collab_task_updated':
    case 'collab_task_deleted':
      if (window._collabBoardOnUpdate) window._collabBoardOnUpdate(data);
      break;
    case 'collab_code_updated':
      if (window._collabCodeOnUpdate) window._collabCodeOnUpdate(data);
      break;
    case 'collab_code_run':
      if (window._collabCodeOnRun) window._collabCodeOnRun(data);
      break;
    case 'collab_timer_start':
    case 'collab_timer_pause':
    case 'collab_timer_resume':
    case 'collab_timer_reset':
    case 'collab_timer_done':
      if (window._collabTimerOnUpdate) window._collabTimerOnUpdate(data);
      break;
    case 'collab_quiz_created':
    case 'collab_quiz_state':
    case 'collab_quiz_submission':
      if (window._collabQuizOnUpdate) window._collabQuizOnUpdate(data);
      break;
    case 'collab_event_created':
    case 'collab_event_updated':
    case 'collab_event_deleted':
    case 'collab_event_rsvp':
      if (window._collabCalendarOnUpdate) window._collabCalendarOnUpdate(data);
      break;

    case 'collab_flashcards_updated':
    case 'collab_mindmap_updated':
    case 'collab_review_created':
    case 'collab_review_feedback':
    case 'collab_summary_ready':
    case 'collab_goal_created':
    case 'collab_goal_updated':
    case 'collab_resource_added':
    case 'collab_resource_commented':
      if (window._collabExtraOnUpdate) window._collabExtraOnUpdate(data);
      break;

    case 'error':
      console.warn('[WS] Server error:', data.message);
      break;

    default:
      // Unhandled type — ignore silently
      break;
  }
}

// ── Incoming message handler ─────────────────────────────────────────────────
function handleIncomingMessage(msg) {
  if (!msg) return;
  const myChannelId = window.ECOLLAB?.currentChannelId;
  const myUserId    = parseInt(window.ECOLLAB?.userId);
  const isFromMe    = parseInt(msg.sender_id) === myUserId;

  // ── Mention detection — all channels, all messages not from self ──
  if (!isFromMe) {
    const myUsername = (window.ECOLLAB?.username || '').toLowerCase();
    const myFullName = (window.ECOLLAB?.fullName  || '').toLowerCase();
    const content    = (msg.content || '').toLowerCase();
    const mentionedMe = (
      (myUsername && content.includes('@' + myUsername)) ||
      (myFullName  && content.includes('@' + myFullName))
    );
    if (mentionedMe) {
      const entry = {
        id:        msg.id,
        author:    msg.full_name || msg.username || '?',
        text:      msg.content,
        channel:   msg.channel_name || msg.channel_id,
        channelId: msg.channel_id,
        time:      new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
        letter:    (msg.full_name || msg.username || '?').charAt(0).toUpperCase(),
        read:      false,
      };
      _storeMention(entry);
    }
  }

  // Only render if the message belongs to the currently open channel
  if (parseInt(msg.channel_id) !== parseInt(myChannelId)) return;
  if (isFromMe) return; // already rendered optimistically

  if (typeof appendMessageToUI === 'function') appendMessageToUI(msg);
}

function handleMessageEdited(msg) {
  if (!msg) return;
  const el = document.querySelector(`.message-group[data-msg-id="${msg.id}"] .msg-text`);
  if (!el) return;
  el.querySelectorAll('.edited-tag').forEach(t => t.remove());
  if (el.firstChild) el.firstChild.textContent = msg.content;
  const tag = document.createElement('span');
  tag.className = 'edited-tag';
  tag.style.cssText = 'font-size:10px;color:var(--text-muted);margin-left:4px;';
  tag.textContent = '(edited)';
  el.appendChild(tag);
}

function handleMessageDeleted(messageId) {
  const el = document.querySelector(`.message-group[data-msg-id="${messageId}"]`);
  if (!el) return;
  el.style.transition = 'opacity 0.2s, transform 0.2s';
  el.style.opacity    = '0';
  el.style.transform  = 'scaleY(0)';
  setTimeout(() => el.remove(), 220);
}

function handleMessagePinned(data) {
  // Refresh the pinned-messages panel if it's open
  if (window._refreshPinnedMessages) window._refreshPinnedMessages(data.channel_id);
  if (typeof showToast === 'function')
    showToast(data.pinned ? '📌 Message pinned' : '📌 Message unpinned', 'info');
}

// ── Reactions ────────────────────────────────────────────────────────────────
function handleReactionUpdate(data) {
  const msgEl = document.querySelector(`.message-group[data-msg-id="${data.message_id}"]`);
  if (!msgEl) return;
  if (typeof renderMessageReactions === 'function')
    renderMessageReactions(msgEl, data.reactions || []);
}

// ── Typing ───────────────────────────────────────────────────────────────────
function handleTypingEvent(data) {
  if (parseInt(data.user_id) === parseInt(window.ECOLLAB?.userId)) return;
  if (parseInt(data.channel_id) !== parseInt(window.ECOLLAB?.currentChannelId)) return;

  const indicator = document.getElementById('typingIndicator');
  const textEl    = document.getElementById('typingText');
  if (!indicator || !textEl) return;

  if (data.typing) {
    typingUsers.add(data.username);
    // Auto-clear stale typing state after 5 s (in case stop event is missed)
    clearTimeout(typingUsers['_t_' + data.username]);
    typingUsers['_t_' + data.username] = setTimeout(() => {
      typingUsers.delete(data.username);
      _renderTypingIndicator(indicator, textEl);
    }, 5000);
  } else {
    typingUsers.delete(data.username);
  }

  _renderTypingIndicator(indicator, textEl);
}

function _renderTypingIndicator(indicator, textEl) {
  if (typingUsers.size === 0) {
    indicator.style.display = 'none';
  } else {
    const names = [...typingUsers].filter(n => typeof n === 'string');
    const text  = names.length === 1
      ? `${names[0]} is typing…`
      : names.length === 2
        ? `${names[0]} and ${names[1]} are typing…`
        : `${names.length} people are typing…`;
    textEl.textContent  = text;
    indicator.style.display = 'flex';
  }
}

// ── Presence / Active status ─────────────────────────────────────────────────
function handlePresenceUpdate(data) {
  // Update every online-dot that references this user
  document.querySelectorAll(`[data-user-id="${data.user_id}"] .online-dot`).forEach(dot => {
    dot.style.background = data.online ? 'var(--accent-green)' : 'var(--text-muted)';
    dot.title = data.online ? 'Online' : 'Offline';
  });
  // Also update member list avatars with a status ring
  document.querySelectorAll(`.member-avatar[data-uid="${data.user_id}"]`).forEach(av => {
    av.dataset.online = data.online ? '1' : '0';
  });

  if (window._fetchActiveNow) window._fetchActiveNow();
  if (window.ECOLLAB?.currentChannelId && typeof refreshMembersPanel === 'function') {
    refreshMembersPanel();
  }
}

// ── Channel ──────────────────────────────────────────────────────────────────
function handleChannelCreated(channel) {
  if (!channel) return;
  const list = document.getElementById('channelList');
  if (!list) return;
  const item = document.createElement('div');
  item.className         = 'channel-item';
  item.dataset.channelId   = channel.id;
  item.dataset.channelName = channel.name;
  item.innerHTML = `<span class="channel-hash">#</span>${escHtml(channel.name)}`;
  item.onclick   = () => switchChannel(item, channel.id);
  list.appendChild(item);
  if (typeof showToast === 'function') showToast(`# ${channel.name} created`, 'success');
}

function handleChannelSeen(data) {
  // Clear the unread badge on the sidebar channel item
  const item = document.querySelector(`.channel-item[data-channel-id="${data.channel_id}"]`);
  if (item) {
    const badge = item.querySelector('.unread-badge');
    if (badge) badge.remove();
    item.classList.remove('has-unread');
  }
}

// ── Drafts (cross-tab / cross-device sync) ────────────────────────────────────
function handleDraftSync(data) {
  if (!data.channel_id) return;
  if (data.text) {
    _drafts[data.channel_id] = {
      text:      data.text,
      saved:     new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
      channelId: data.channel_id,
      channel:   data.channel_name || data.channel_id,
    };
  } else {
    delete _drafts[data.channel_id];
  }
  localStorage.setItem('ec_drafts', JSON.stringify(_drafts));
  if (window._notifyDraftChange) window._notifyDraftChange();
  // If the draft's channel is currently open, populate the input
  if (parseInt(data.channel_id) === parseInt(window.ECOLLAB?.currentChannelId)) {
    const input = document.getElementById('messageInput');
    if (input && data.text && input.value === '') input.value = data.text;
  }
}

// ── Threads ──────────────────────────────────────────────────────────────────
function handleThreadReply(data) {
  // data: { channel_id, parent_id, reply: { id, sender, content, ... } }
  // Refresh the thread panel if it's open for this parent_id
  if (window._activeThreadParentId && window._activeThreadParentId === data.parent_id) {
    if (window._appendThreadReply) window._appendThreadReply(data.reply);
  }
  // Update reply count badge on the parent message
  const parentEl = document.querySelector(`.message-group[data-msg-id="${data.parent_id}"]`);
  if (parentEl) {
    let badge = parentEl.querySelector('.thread-reply-count');
    if (!badge) {
      badge = document.createElement('span');
      badge.className = 'thread-reply-count';
      badge.style.cssText = 'font-size:11px;color:var(--accent-purple);margin-left:6px;cursor:pointer;';
      badge.onclick = () => window._openThread && window._openThread(data.parent_id);
      parentEl.querySelector('.msg-text')?.appendChild(badge);
    }
    const count = parseInt(badge.dataset.count || '0') + 1;
    badge.dataset.count  = count;
    badge.textContent    = `↳ ${count} repl${count === 1 ? 'y' : 'ies'}`;
  }
}

// ── Mentions ─────────────────────────────────────────────────────────────────
function handleMentionEvent(data) {
  if (!data.entry) return;
  _storeMention(data.entry);
  if (typeof showToast === 'function')
    showToast(`💬 You were mentioned in #${data.entry.channel || 'a channel'}`, 'info');
}

function _storeMention(entry) {
  const stored   = JSON.parse(localStorage.getItem('ec_mentions') || '[]');
  const ids      = new Set(stored.map(s => s.id));
  if (entry.id && ids.has(entry.id)) return; // dedupe
  stored.unshift(entry);
  if (stored.length > 100) stored.length = 100;
  localStorage.setItem('ec_mentions', JSON.stringify(stored));
  if (window._mentions) { window._mentions.length = 0; stored.forEach(i => window._mentions.push(i)); }
  if (window._updateMentionBadge) window._updateMentionBadge();
}

// ── Screen share ─────────────────────────────────────────────────────────────
function handleScreenShareNotify(data) {
  if (data.active) {
    window._pendingScreenTrack = window._pendingScreenTrack || {};
    window._pendingScreenTrack[data.user_id] = true;
  } else {
    if (window._pendingScreenTrack) delete window._pendingScreenTrack[data.user_id];
    if (window._hideRemoteScreenShareSection) window._hideRemoteScreenShareSection(data.user_id);
  }
}

// ── Voice join / leave ───────────────────────────────────────────────────────
function handleVoiceJoin(data) {
  if (window.addVcParticipant && data.user) {
    const isMe          = parseInt(data.user.id) === parseInt(window.ECOLLAB?.userId);
    const alreadyDrawn  = !!document.querySelector(
      `.vc-speaker-card[data-user-id="${data.user.id}"], .vc-listener-card[data-user-id="${data.user.id}"]`
    );
    if (!isMe && !alreadyDrawn) window.addVcParticipant(data.user, !data.user.muted);
  }

  // Initiate WebRTC offer to the new joiner if we're already in the channel
  if (window.vcChannelId && data.channel_id &&
      parseInt(data.channel_id) === parseInt(window.vcChannelId) &&
      data.user && parseInt(data.user.id) !== parseInt(window.ECOLLAB?.userId)) {
    const uid   = data.user.id;
    const uname = data.user.username || data.user.full_name || 'Peer';
    if (!window.peerConnections?.[uid]) {
      setTimeout(() => {
        if (window._initiateWebRtcOffer) window._initiateWebRtcOffer(uid, uname);
      }, 500);
    }
  }

  if (typeof showToast === 'function')
    showToast(`🔊 ${data.user?.full_name || data.user?.username || 'Someone'} joined voice`, 'info');
}

function handleVoiceLeave(data) {
  const uid = data.user_id;

  // Remove voice cards
  ['vcSpeakingGrid', 'vcListeningGrid'].forEach(id => {
    const grid = document.getElementById(id);
    if (!grid) return;
    const card = grid.querySelector(`.vc-speaker-card[data-user-id="${uid}"], .vc-listener-card[data-user-id="${uid}"]`);
    if (card) card.remove();
  });

  // Remove screen share card
  const screenGrid = document.getElementById('vcScreenGrid');
  if (screenGrid) {
    const sc = screenGrid.querySelector(`[data-screen-user="${uid}"]`);
    if (sc) sc.remove();
    const rem = screenGrid.querySelectorAll('.vc-screen-card').length;
    const cnt = document.getElementById('vcScreenSectionCount');
    if (cnt) cnt.textContent = rem;
    const sec = document.getElementById('vcScreenSection');
    if (sec && rem === 0) sec.style.display = 'none';
  }

  // Update counts
  const speaking  = document.querySelectorAll('#vcSpeakingGrid  .vc-speaker-card').length;
  const listening = document.querySelectorAll('#vcListeningGrid .vc-listener-card').length;
  if (window.updateVcCounts) window.updateVcCounts(speaking, listening);

  // Stop remote audio
  const audio = document.getElementById(`remote-audio-${uid}`);
  if (audio) { audio.srcObject = null; audio.remove(); }

  // Close WebRTC connection
  if (window.peerConnections?.[uid]) {
    try { window.peerConnections[uid].close(); } catch { }
    delete window.peerConnections[uid];
  }

  if (window._fetchActiveNow) window._fetchActiveNow();
  if (typeof showToast === 'function')
    showToast(`📵 ${data.username || 'Someone'} left voice`, 'info');
}

// ── Typing send (throttled) ───────────────────────────────────────────────────
let _typingThrottle = null;
let _typingState    = false;

function sendTypingEvent(isTyping) {
  if (!window.ECOLLAB?.currentChannelId) return;
  if (isTyping === _typingState) return; // no-op if state unchanged
  _typingState = isTyping;

  clearTimeout(_typingThrottle);
  _typingThrottle = setTimeout(() => {
    wsSend({
      type:       'typing',
      channel_id: window.ECOLLAB.currentChannelId,
      typing:     isTyping,
    });
  }, 80);

  // Auto-send stop after 4 s of no keystrokes
  if (isTyping) {
    clearTimeout(_typingThrottle._stop);
    _typingThrottle._stop = setTimeout(() => {
      _typingState = false;
      wsSend({ type: 'typing', channel_id: window.ECOLLAB.currentChannelId, typing: false });
    }, 4000);
  }
}

// ── Draft broadcast (notify other tabs via WS) ────────────────────────────────
function broadcastDraftSave(channelId, text, channelName) {
  wsSend({ type: 'draft_save', channel_id: channelId, text, channel_name: channelName || '' });
}
window.broadcastDraftSave = broadcastDraftSave;

// ── Channel subscribe / unsubscribe ──────────────────────────────────────────
function subscribeToChannel(channelId) {
  wsSend({ type: 'join_channel', channel_id: channelId });
}
function unsubscribeFromChannel(channelId) {
  wsSend({ type: 'leave_channel', channel_id: channelId });
}

// ── Polling fallback when WS is unavailable ───────────────────────────────────
let pollInterval    = null;
let lastMessageId   = 0;

function startPollingFallback() {
  if (pollInterval) return;
  console.info('[WS] Starting polling fallback (3 s interval)');
  pollInterval = setInterval(async () => {
    const channelId = window.ECOLLAB?.currentChannelId;
    if (!channelId) return;
    try {
      const base = window.ECOLLAB?.baseUrl || '';
      const data = await apiFetch(
        `${base}/API/chat/get-messages.php?channel_id=${channelId}&after=${lastMessageId}`
      );
      if (data.messages?.length) {
        data.messages.forEach(msg => {
          if (parseInt(msg.sender_id) !== parseInt(window.ECOLLAB?.userId)) {
            if (typeof appendMessageToUI === 'function') appendMessageToUI(msg);
          }
          lastMessageId = Math.max(lastMessageId, msg.id);
        });
      }
    } catch { /* ignore */ }
  }, 3000);
}

function stopPollingFallback() {
  clearInterval(pollInterval);
  pollInterval = null;
}

// ── Mention polling (delta every 15 s) ───────────────────────────────────────
let _mentionPollTimer  = null;
let _lastMentionPollId = 0;

async function _fetchMentions(after) {
  try {
    const base = window.ECOLLAB?.baseUrl || '';
    const res  = await fetch(`${base}/API/chat/get-mentions.php?after=${after}`);
    if (!res.ok) return;
    const data = await res.json();
    if (!data.mentions?.length) return;

    const stored   = JSON.parse(localStorage.getItem('ec_mentions') || '[]');
    const storedIds = new Set(stored.map(s => s.id));
    let added = 0;
    data.mentions.forEach(m => {
      if (!storedIds.has(m.id)) { stored.unshift(m); added++; }
    });
    if (!added) return;

    _lastMentionPollId = Math.max(_lastMentionPollId, ...data.mentions.map(m => m.id));
    if (stored.length > 100) stored.length = 100;
    localStorage.setItem('ec_mentions', JSON.stringify(stored));
    if (window._mentions) { window._mentions.length = 0; stored.forEach(i => window._mentions.push(i)); }
    if (window._updateMentionBadge) window._updateMentionBadge();
  } catch { }
}

function _startMentionPolling() {
  if (_mentionPollTimer) return;
  _fetchMentions(0);
  _mentionPollTimer = setInterval(() => _fetchMentions(_lastMentionPollId), 15000);
}
setTimeout(_startMentionPolling, 2000);

// ── Heartbeat — keep connection alive through proxies ─────────────────────────
setInterval(() => {
  if (chatSocket && chatSocket.readyState === WebSocket.OPEN && _authed) {
    chatSocket.send(JSON.stringify({ type: 'ping' }));
  }
}, 25000);

// ── Expose globals ───────────────────────────────────────────────────────────
window.connectWebSocket       = connectWebSocket;
window.initWebSocket          = initWebSocket;   // keep for backward compat
window.sendTypingEvent        = sendTypingEvent;
window.subscribeToChannel     = subscribeToChannel;
window.unsubscribeFromChannel = unsubscribeFromChannel;
window.chatSocket             = null;

// ── Auto-start when the page is ready ────────────────────────────────────────
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', connectWebSocket);
} else {
  connectWebSocket();
}
