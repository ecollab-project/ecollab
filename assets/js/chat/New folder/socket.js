/**
 * socket.js — WebSocket client for real-time messaging
 * Connects to the Ratchet WebSocket server
 */

'use strict';

let chatSocket = null;
let socketReconnectTimer = null;
let socketReconnectDelay = 5000;
let socketReconnectAttempts = 0;
const MAX_RECONNECT_ATTEMPTS = 3;
const typingUsers = new Set();

function initWebSocket() {
  const wsUrl = window.ECOLLAB?.wsUrl || 'ws://localhost:8080';

  try {
    chatSocket = new WebSocket(wsUrl);
    window.chatSocket = chatSocket;
  } catch (err) {
    console.warn('WebSocket unavailable — running in polling mode');
    startPollingFallback();
    return;
  }

  chatSocket.onopen = () => {
    console.log('[WS] Connected');
    socketReconnectDelay = 2000;
    socketReconnectAttempts = 0;

    // Authenticate
    chatSocket.send(JSON.stringify({
      type: 'auth',
      user_id: window.ECOLLAB?.userId,
      username: window.ECOLLAB?.username,
      csrf: window.ECOLLAB?.csrfToken,
    }));

    // Join current channel if any
    if (window.ECOLLAB?.currentChannelId) {
      chatSocket.send(JSON.stringify({
        type: 'join_channel',
        channel_id: window.ECOLLAB.currentChannelId,
      }));
    }
  };

  chatSocket.onmessage = (event) => {
    let data;
    try { data = JSON.parse(event.data); } catch { return; }
    handleSocketMessage(data);
  };

  chatSocket.onclose = (event) => {
    // Code 1006 = abnormal closure (server not running / connection refused)
    // After 3 failures with 1006, go straight to polling — don't keep hammering
    if (event.code === 1006 && socketReconnectAttempts >= MAX_RECONNECT_ATTEMPTS) {
      console.info('[WS] WebSocket server unavailable — switching to polling mode');
      startPollingFallback();
      return;
    }
    if (socketReconnectAttempts < MAX_RECONNECT_ATTEMPTS) {
      scheduleReconnect();
    } else {
      console.info('[WS] Max reconnect attempts reached — switching to polling mode');
      startPollingFallback();
    }
  };

  chatSocket.onerror = () => {
    // onclose fires right after — no need to log here too
  };
}

function scheduleReconnect() {
  clearTimeout(socketReconnectTimer);
  socketReconnectAttempts++;
  socketReconnectTimer = setTimeout(() => {
    console.log('[WS] Reconnecting... attempt', socketReconnectAttempts);
    initWebSocket();
  }, socketReconnectDelay);
  socketReconnectDelay = Math.min(socketReconnectDelay * 1.5, 30000);
}

function handleSocketMessage(data) {
  switch (data.type) {
    case 'message':
      handleIncomingMessage(data.message);
      break;
    case 'message_edited':
      handleMessageEdited(data.message);
      break;
    case 'message_deleted':
      handleMessageDeleted(data.message_id);
      break;
    case 'typing':
      handleTypingEvent(data);
      break;
    case 'presence':
      handlePresenceUpdate(data);
      break;
    case 'reaction':
      handleReactionUpdate(data);
      break;
    case 'channel_created':
      handleChannelCreated(data.channel);
      break;
    case 'voice_join':
      handleVoiceJoin(data);
      break;
    case 'voice_leave':
      handleVoiceLeave(data);
      break;
    case 'voice_peers':
      if (window.handleVoicePeers) window.handleVoicePeers(data);
      break;
    // WebRTC signaling
    case 'webrtc_offer':
      if (window._handleWebRtcOffer) window._handleWebRtcOffer(data.from_user_id, data.from_username, data.sdp);
      break;
    case 'webrtc_answer':
      if (window._handleWebRtcAnswer) window._handleWebRtcAnswer(data.from_user_id, data.sdp);
      break;
    case 'webrtc_candidate':
      if (window._handleWebRtcCandidate) window._handleWebRtcCandidate(data.from_user_id, data.candidate);
      break;
    case 'whiteboard_sync':
      handleWhiteboardSync(data);
      break;
    case 'error':
      console.warn('[WS] Server error:', data.message);
      break;
  }
}

// ── Message handlers ──
function handleIncomingMessage(msg) {
  if (!msg) return;
  const myChannelId = window.ECOLLAB?.currentChannelId;

  // ── Mention detection: runs for ALL channels, not just the active one ──
  const myUsername  = (window.ECOLLAB?.username  || '').toLowerCase();
  const myFullName  = (window.ECOLLAB?.fullName   || '').toLowerCase();
  const content     = (msg.content || '').toLowerCase();
  const isFromMe    = parseInt(msg.sender_id) === parseInt(window.ECOLLAB?.userId);

  const mentionedMe = !isFromMe && (
    (myUsername && content.includes('@' + myUsername)) ||
    (myFullName && content.includes('@' + myFullName))
  );
  if (mentionedMe) {
    const channelName = msg.channel_name || msg.channel_id;
    const entry = {
      author:    msg.full_name || msg.username || '?',
      text:      msg.content,
      channel:   channelName,
      channelId: msg.channel_id,
      time:      new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
      letter:    (msg.full_name || msg.username || '?').charAt(0).toUpperCase(),
      read:      false,
    };
    const stored = JSON.parse(localStorage.getItem('ec_mentions') || '[]');
    stored.unshift(entry);
    if (stored.length > 100) stored.pop();
    localStorage.setItem('ec_mentions', JSON.stringify(stored));
    if (window._mentions) { window._mentions.length = 0; stored.forEach(i => window._mentions.push(i)); }
    if (window._updateMentionBadge) window._updateMentionBadge();
  }

  // Only render if this message belongs to the currently open channel
  if (parseInt(msg.channel_id) !== parseInt(myChannelId)) return;
  if (isFromMe) return; // already rendered optimistically

  appendMessageToUI(msg);
}

function handleMessageEdited(msg) {
  const el = document.querySelector(`.message-group[data-msg-id="${msg.id}"] .msg-text`);
  if (!el) return;
  // Strip existing edited tag
  el.querySelectorAll('.edited-tag').forEach(t => t.remove());
  el.firstChild && (el.firstChild.textContent = msg.content);
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
  el.style.opacity = '0';
  el.style.transform = 'scaleY(0)';
  setTimeout(() => el.remove(), 220);
}

function handleTypingEvent(data) {
  if (parseInt(data.user_id) === parseInt(window.ECOLLAB?.userId)) return;
  if (parseInt(data.channel_id) !== parseInt(window.ECOLLAB?.currentChannelId)) return;

  const indicator = document.getElementById('typingIndicator');
  const textEl = document.getElementById('typingText');
  if (!indicator || !textEl) return;

  if (data.typing) {
    typingUsers.add(data.username);
  } else {
    typingUsers.delete(data.username);
  }

  if (typingUsers.size === 0) {
    indicator.style.display = 'none';
  } else {
    const names = [...typingUsers];
    const text = names.length === 1
      ? `${names[0]} is typing…`
      : names.length === 2
        ? `${names[0]} and ${names[1]} are typing…`
        : `${names.length} people are typing…`;
    textEl.textContent = text;
    indicator.style.display = 'flex';
  }
}

function handlePresenceUpdate(data) {
  // Update online dot for user
  const dots = document.querySelectorAll(`[data-user-id="${data.user_id}"] .online-dot`);
  dots.forEach(dot => {
    dot.style.background = data.online ? 'var(--accent-green)' : 'var(--text-muted)';
  });
  // Refresh active-now list (will re-render if modal is open)
  if (window._fetchActiveNow) window._fetchActiveNow();
  if (window.ECOLLAB?.currentChannelId) {
    refreshMembersPanel();
  }
}

function handleReactionUpdate(data) {
  const msgEl = document.querySelector(`.message-group[data-msg-id="${data.message_id}"]`);
  if (!msgEl) return;
  // Refresh reactions section
  renderMessageReactions(msgEl, data.reactions || []);
}

function handleChannelCreated(channel) {
  if (!channel) return;
  const list = document.getElementById('channelList');
  if (!list) return;
  const item = document.createElement('div');
  item.className = 'channel-item';
  item.dataset.channelId = channel.id;
  item.dataset.channelName = channel.name;
  item.innerHTML = `<span class="channel-hash">#</span>${escHtml(channel.name)}`;
  item.onclick = () => switchChannel(item, channel.id);
  list.appendChild(item);
}

function handleVoiceJoin(data) {
  if (window.addVcParticipant && data.user) {
    window.addVcParticipant(data.user, false);
  }
  // If we are already in that voice channel, initiate a WebRTC offer to the new joiner.
  // The joiner will initiate offers to us via voice_peers — but we also send from our side
  // in case the joiner's offer doesn't arrive (race condition protection).
  // Deduplicate: only offer if we don't already have a peer connection to them.
  if (window.vcChannelId && data.channel_id &&
      parseInt(data.channel_id) === parseInt(window.vcChannelId) &&
      data.user && parseInt(data.user.id) !== parseInt(window.ECOLLAB?.userId)) {
    const uid = data.user.id;
    const uname = data.user.username || data.user.full_name || 'Peer';
    if (!window.peerConnections?.[uid]) {
      setTimeout(() => {
        if (window._initiateWebRtcOffer) window._initiateWebRtcOffer(uid, uname);
      }, 500); // small delay so the joiner's side is ready
    }
  }
  showToast(`🔊 ${data.user?.full_name || data.user?.username} joined voice`, 'info');
}

function handleVoiceLeave(data) {
  const card = document.querySelector(`[data-user-id="${data.user_id}"]`);
  if (card) card.remove();
  // Also remove remote audio element
  const audio = document.getElementById(`remote-audio-${data.user_id}`);
  if (audio) { audio.srcObject = null; audio.remove(); }
  // Close WebRTC peer connection if any
  if (window.peerConnections && window.peerConnections[data.user_id]) {
    try { window.peerConnections[data.user_id].close(); } catch {}
    delete window.peerConnections[data.user_id];
  }
  if (window._fetchActiveNow) window._fetchActiveNow();
  showToast(`📵 ${data.username || 'Someone'} left voice`, 'info');
}

function handleWhiteboardSync(data) {
  // Delegate to whiteboard module
  if (window.handleRemoteWhiteboardSync) {
    window.handleRemoteWhiteboardSync(data);
  }
}

// ── Typing events ──
let typingThrottle = null;
function sendTypingEvent(isTyping) {
  if (!chatSocket || chatSocket.readyState !== WebSocket.OPEN) return;
  if (!window.ECOLLAB?.currentChannelId) return;
  clearTimeout(typingThrottle);
  typingThrottle = setTimeout(() => {
    chatSocket.send(JSON.stringify({
      type: 'typing',
      channel_id: window.ECOLLAB.currentChannelId,
      typing: isTyping,
    }));
  }, 100);
}

// ── Polling fallback when WS unavailable ──
let pollInterval = null;
let lastMessageId = 0;

function startPollingFallback() {
  if (pollInterval) return;
  pollInterval = setInterval(async () => {
    const channelId = window.ECOLLAB?.currentChannelId;
    if (!channelId) return;
    try {
      const data = await apiFetch(`${window.ECOLLAB?.baseUrl||''}/API/chat/get-messages.php?channel_id=${channelId}&after=${lastMessageId}`);
      if (data.messages && data.messages.length) {
        data.messages.forEach(msg => {
          if (parseInt(msg.sender_id) !== parseInt(window.ECOLLAB?.userId)) {
            appendMessageToUI(msg);
          }
          lastMessageId = Math.max(lastMessageId, msg.id);
        });
      }
    } catch { /* ignore polling errors */ }
  }, 3000);
}

function stopPollingFallback() {
  clearInterval(pollInterval);
  pollInterval = null;
}

// ── Mention polling (fetches all mentions, then deltas every 15s) ──
let _mentionPollTimer = null;
let _lastMentionPollId = 0;

async function _fetchMentions(after) {
  try {
    const base = window.ECOLLAB?.baseUrl || '';
    const res  = await fetch(`${base}/API/chat/get-mentions.php?after=${after}`);
    if (!res.ok) return;
    const data = await res.json();
    if (!data.mentions || !data.mentions.length) return;

    // Merge into localStorage deduplicating by id
    const stored = JSON.parse(localStorage.getItem('ec_mentions') || '[]');
    const storedIds = new Set(stored.map(s => s.id));
    let added = 0;
    data.mentions.forEach(m => {
      if (!storedIds.has(m.id)) {
        stored.unshift(m);
        added++;
      }
    });
    if (added === 0) return;

    // Track highest id for delta polling
    _lastMentionPollId = Math.max(_lastMentionPollId, ...data.mentions.map(m => m.id));

    // Cap at 100
    if (stored.length > 100) stored.length = 100;
    localStorage.setItem('ec_mentions', JSON.stringify(stored));
    if (window._mentions) { window._mentions.length = 0; stored.forEach(i => window._mentions.push(i)); }
    if (window._updateMentionBadge) window._updateMentionBadge();
  } catch {}
}

function _startMentionPolling() {
  if (_mentionPollTimer) return;
  // Full fetch on first load (after=0 gets everything)
  _fetchMentions(0);
  // Delta poll every 15s
  _mentionPollTimer = setInterval(() => _fetchMentions(_lastMentionPollId), 15000);
}
// Start after page settles
setTimeout(_startMentionPolling, 2000);

// ── Channel subscribe ──
function subscribeToChannel(channelId) {
  if (chatSocket && chatSocket.readyState === WebSocket.OPEN) {
    chatSocket.send(JSON.stringify({ type: 'join_channel', channel_id: channelId }));
  }
}

function unsubscribeFromChannel(channelId) {
  if (chatSocket && chatSocket.readyState === WebSocket.OPEN) {
    chatSocket.send(JSON.stringify({ type: 'leave_channel', channel_id: channelId }));
  }
}

// ── Expose ──
window.initWebSocket = initWebSocket;
window.sendTypingEvent = sendTypingEvent;
window.subscribeToChannel = subscribeToChannel;
window.unsubscribeFromChannel = unsubscribeFromChannel;
window.chatSocket = null;
