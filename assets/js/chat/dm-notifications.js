/**
 * dm-notifications.js — Ecollab DM Feature & Live Notifications
 *
 * DROP-IN: Add <script src="<?= BASE_URL ?>/assets/js/chat/dm-notifications.js"></script>
 * after chat.js and chat-features.js in chat.php.
 *
 * Requires:
 *  - window.ECOLLAB.baseUrl    (already set in chat.php)
 *  - window.ECOLLAB.userId     (already set in chat.php)
 *  - window.apiFetch           (already defined in chat.js)
 *  - window.showToast          (already defined in chat.js)
 *  - window._wsSocket          (the Ratchet WebSocket from socket.js)
 *
 * Overrides:
 *  - window.openNewDMModal()    — was "DMs coming soon"
 *  - window.toggleNotifications()
 *  - window.markAllRead()
 */

'use strict';

// ═══════════════════════════════════════════════════════════════
//  UTILITIES
// ═══════════════════════════════════════════════════════════════

const BASE  = () => window.ECOLLAB?.baseUrl || '';
const ME_ID = () => window.ECOLLAB?.userId  || 0;

function _esc(s) {
  return String(s ?? '')
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function _timeAgo(dateStr) {
  const diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);
  if (diff < 60)  return 'just now';
  if (diff < 3600) return Math.floor(diff/60) + 'm ago';
  if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
  return Math.floor(diff/86400) + 'd ago';
}

function _avatar(name, gradient, size = 34) {
  const [c1,c2] = (gradient || '#a855f7,#ec4899').split(',');
  const init = String(name||'?').charAt(0).toUpperCase();
  return `<div style="width:${size}px;height:${size}px;border-radius:50%;background:linear-gradient(135deg,${c1},${c2});display:flex;align-items:center;justify-content:center;font-size:${Math.floor(size*0.4)}px;font-weight:800;color:#fff;flex-shrink:0;">${init}</div>`;
}

// ═══════════════════════════════════════════════════════════════
//  STATE
// ═══════════════════════════════════════════════════════════════

const DM = {
  conversations: [],       // [{conversation_id, partner_id, partner_name, ...}]
  activeConvId: null,
  activePartnerId: null,
  activePartnerName: '',
  typingTimers: {},         // conversation_id => clearTimeout handle
};

const NOTIF = {
  items: [],               // [{id, type, title, body, is_read, created_at}]
  unreadCount: 0,
  pollInterval: null,
};

// ═══════════════════════════════════════════════════════════════
//  WEBSOCKET — hook into the existing socket from socket.js
// ═══════════════════════════════════════════════════════════════

/**
 * Called once after socket.js has opened the WS connection.
 * We piggyback on the existing _wsSocket.
 */
function _hookWebSocket() {
  const ws = window._wsSocket;
  if (!ws) return;

  const original = ws.onmessage;
  ws.onmessage = function(event) {
    // Let the original handler run first
    if (typeof original === 'function') original.call(ws, event);

    try {
      const data = JSON.parse(event.data);
      switch (data.type) {
        case 'dm_message':          _onWsDmMessage(data);       break;
        case 'dm_typing':           _onWsDmTyping(data);        break;
        case 'connection_request':  _onWsConnRequest(data);     break;
        case 'connection_accepted': _onWsConnAccepted(data);    break;
        case 'notification':        _onWsNotification(data);    break;
      }
    } catch (_) {}
  };
}

function _wsSend(payload) {
  const ws = window._wsSocket;
  if (ws && ws.readyState === WebSocket.OPEN) {
    ws.send(JSON.stringify(payload));
  }
}

// ═══════════════════════════════════════════════════════════════
//  WEBSOCKET INBOUND HANDLERS
// ═══════════════════════════════════════════════════════════════

function _onWsDmMessage(data) {
  const convId = data.conversation_id;

  // Update conversation list preview
  const conv = DM.conversations.find(c => c.conversation_id == convId);
  if (conv) {
    conv.last_message = data.body;
    conv.last_msg_at  = data.created_at;
    // Increment unread if not viewing this conversation
    if (DM.activeConvId !== convId) {
      conv.unread_count = (parseInt(conv.unread_count) || 0) + 1;
    }
    _renderDmList();
  } else {
    // New conversation appeared — refresh list
    loadDmList();
  }

  // If this conversation is open, append message
  if (DM.activeConvId === convId) {
    _appendDmMessage(data);
  } else {
    // Show floating toast
    const name = data.sender_name || 'Someone';
    showToast(`💬 ${name}: ${String(data.body || '').slice(0, 60)}`, 'info');
  }

  // Add notification badge
  if (data.sender_id !== ME_ID()) {
    _addInlineNotification({
      type: 'dm',
      title: (data.sender_name || 'Someone') + ' sent you a message',
      body: String(data.body || '').slice(0, 80),
      ref_id: data.message_id,
      created_at: data.created_at,
    });
  }
}

function _onWsDmTyping(data) {
  if (DM.activeConvId !== data.conversation_id) return;
  const indicator = document.getElementById('dmTypingIndicator');
  if (!indicator) return;

  if (data.is_typing) {
    indicator.style.display = 'flex';
    indicator.textContent   = `${_esc(data.sender_name)} is typing…`;
    clearTimeout(DM.typingTimers[data.conversation_id]);
    DM.typingTimers[data.conversation_id] = setTimeout(() => {
      indicator.style.display = 'none';
    }, 3000);
  } else {
    indicator.style.display = 'none';
    clearTimeout(DM.typingTimers[data.conversation_id]);
  }
}

function _onWsConnRequest(data) {
  // Show connection request banner (same UI as before, but now real-time)
  if (typeof window._showConnectionRequestBanner === 'function') {
    window._showConnectionRequestBanner(data);
  }
  _addInlineNotification({
    type: 'connection_request',
    title: (data.requester?.fullName || 'Someone') + ' wants to connect',
    body: 'Tap to accept or decline',
    ref_id: data.request_id,
    created_at: new Date().toISOString(),
  });
}

function _onWsConnAccepted(data) {
  const name = data.accepted_by?.fullName || 'Someone';
  showToast(`🤝 ${name} accepted your connection!`, 'success');
  _addInlineNotification({
    type: 'connection_accepted',
    title: name + ' accepted your connection',
    body: 'You are now connected',
    created_at: new Date().toISOString(),
  });
}

function _onWsNotification(data) {
  _addInlineNotification(data);
}

// ═══════════════════════════════════════════════════════════════
//  NOTIFICATION SYSTEM
// ═══════════════════════════════════════════════════════════════

/** Add a notification item to the in-memory list + update badge + dropdown */
function _addInlineNotification(notif) {
  NOTIF.items.unshift({
    id:         notif.id || Date.now(),
    type:       notif.type,
    title:      notif.title,
    body:       notif.body || '',
    is_read:    0,
    created_at: notif.created_at || new Date().toISOString(),
  });
  if (NOTIF.items.length > 30) NOTIF.items.pop();
  NOTIF.unreadCount++;
  _updateNotifBadge();
  _renderNotifDropdown();
}

function _updateNotifBadge() {
  const badge = document.getElementById('notifBadge');
  if (!badge) return;
  if (NOTIF.unreadCount > 0) {
    badge.style.display = 'flex';
    badge.textContent   = NOTIF.unreadCount > 99 ? '99+' : String(NOTIF.unreadCount);
  } else {
    badge.style.display = 'none';
  }
}

function _notifIcon(type) {
  const icons = {
    dm:                 '💬',
    connection_request: '🤝',
    connection_accepted:'✅',
    mention:            '@',
  };
  return icons[type] || '🔔';
}

function _renderNotifDropdown() {
  const list = document.getElementById('notifList');
  if (!list) return;

  if (NOTIF.items.length === 0) {
    list.innerHTML = '<div style="padding:24px 16px;text-align:center;color:var(--text-muted);font-size:13px;">No notifications yet</div>';
    return;
  }

  list.innerHTML = NOTIF.items.map(n => `
    <div class="notif-item ${n.is_read ? '' : 'unread'}" data-notif-id="${n.id}" onclick="_handleNotifClick(${n.id},'${_esc(n.type)}',${n.ref_id || 0})" style="cursor:pointer;">
      <div class="notif-dot" style="${n.is_read ? 'opacity:0' : ''}"></div>
      <div style="display:flex;align-items:flex-start;gap:10px;flex:1;">
        <div style="font-size:18px;flex-shrink:0;margin-top:1px;">${_notifIcon(n.type)}</div>
        <div class="notif-content">
          <div class="notif-text">${_esc(n.title)}</div>
          ${n.body ? `<div style="font-size:11px;color:var(--text-muted);margin-top:2px;">${_esc(String(n.body).slice(0,80))}</div>` : ''}
          <div class="notif-time">${_timeAgo(n.created_at)}</div>
        </div>
      </div>
    </div>`).join('');
}

window._handleNotifClick = function(notifId, type, refId) {
  // Mark this notification read
  const notif = NOTIF.items.find(n => n.id === notifId);
  if (notif && !notif.is_read) {
    notif.is_read = 1;
    NOTIF.unreadCount = Math.max(0, NOTIF.unreadCount - 1);
    _updateNotifBadge();
    _renderNotifDropdown();
    // Persist to DB
    apiFetch(BASE() + '/API/notifications/mark-read.php', {
      method: 'POST',
      body: JSON.stringify({ ids: [notifId] }),
    }).catch(() => {});
  }

  // Navigate
  if (type === 'dm' || type === 'dm_message') {
    // Close dropdown, open DM panel — refId is message_id, we need conv
    const conv = DM.conversations.find(c => c.unread_count > 0);
    if (conv) openDmConversation(conv.partner_id, conv.partner_name, conv.partner_gradient);
  }
  // connection_request banner already shown separately
};

// Poll notifications from DB on a slow cadence (backup to WebSocket)
async function _pollNotifications() {
  try {
    const data = await apiFetch(BASE() + '/API/notifications/get.php');
    if (data.notifications) {
      NOTIF.items        = data.notifications;
      NOTIF.unreadCount  = data.unread_count;
      _updateNotifBadge();
      _renderNotifDropdown();
    }
  } catch (_) {}
}

// ═══════════════════════════════════════════════════════════════
//  OVERRIDE: toggleNotifications & markAllRead
// ═══════════════════════════════════════════════════════════════

window.toggleNotifications = function() {
  const dd = document.getElementById('notifDropdown');
  if (!dd) return;
  const isOpen = dd.style.display === 'block';
  dd.style.display = isOpen ? 'none' : 'block';
  dd.classList.toggle('open', !isOpen);
  if (!isOpen) {
    _renderNotifDropdown();
  }
};

window.markAllRead = function(event) {
  if (event) event.stopPropagation();
  NOTIF.items.forEach(n => { n.is_read = 1; });
  NOTIF.unreadCount = 0;
  _updateNotifBadge();
  _renderNotifDropdown();
  apiFetch(BASE() + '/API/notifications/mark-read.php', {
    method: 'POST',
    body: JSON.stringify({ ids: null }),
  }).catch(() => {});
  if (window.showToast) showToast('✓ All notifications marked as read', 'info');
};

// ═══════════════════════════════════════════════════════════════
//  DM LIST
// ═══════════════════════════════════════════════════════════════

async function loadDmList() {
  try {
    const data = await apiFetch(BASE() + '/API/dm/get-conversations.php');
    if (data.conversations) {
      DM.conversations = data.conversations;
      _renderDmList();
    }
  } catch (_) {}
}

function _renderDmList() {
  const container = document.getElementById('dmList');
  if (!container) return;

  if (DM.conversations.length === 0) {
    container.innerHTML = `<div style="padding:8px 16px;font-size:12px;color:var(--text-muted);">No direct messages yet</div>`;
    return;
  }

  container.innerHTML = DM.conversations.map(c => {
    const isActive  = DM.activeConvId === c.conversation_id;
    const name      = c.partner_name || c.partner_username || 'Unknown';
    const preview   = c.last_message ? String(c.last_message).slice(0, 40) : 'No messages yet';
    const unread    = parseInt(c.unread_count) || 0;

    return `
      <div class="channel-item dm-item ${isActive ? 'active' : ''}"
           data-conv-id="${c.conversation_id}"
           onclick="openDmConversation(${c.partner_id},'${_esc(name)}','${_esc(c.partner_gradient || '')}')">
        ${_avatar(name, c.partner_gradient, 28)}
        <div style="flex:1;min-width:0;">
          <div style="font-size:13px;font-weight:${unread > 0 ? 700 : 500};color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${_esc(name)}</div>
          <div style="font-size:11px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${_esc(preview)}</div>
        </div>
        ${unread > 0 ? `<span style="background:var(--accent-purple);color:#fff;font-size:10px;font-weight:700;padding:2px 6px;border-radius:10px;flex-shrink:0;">${unread}</span>` : ''}
      </div>`;
  }).join('');
}

// ═══════════════════════════════════════════════════════════════
//  OPEN / CLOSE DM PANEL
// ═══════════════════════════════════════════════════════════════

window.openNewDMModal = function() {
  _ensureDmSearchModal();
  document.getElementById('dmSearchModal').style.display = 'flex';
  setTimeout(() => document.getElementById('dmSearchModal').classList.add('open'), 10);
  document.getElementById('dmSearchInput')?.focus();
  _loadDmSearchResults('');
};

function _ensureDmSearchModal() {
  if (document.getElementById('dmSearchModal')) return;
  const modal = document.createElement('div');
  modal.id        = 'dmSearchModal';
  modal.className = 'modal-overlay';
  modal.onclick   = function(e) { if (e.target === modal) closeDmSearchModal(); };
  modal.innerHTML = `
    <div class="modal" style="max-width:440px;width:100%;max-height:80vh;">
      <div class="modal-header">
        <span style="font-size:16px;font-weight:800;">💬 New Direct Message</span>
        <button class="modal-close" onclick="closeDmSearchModal()">×</button>
      </div>
      <div style="padding:12px 16px;">
        <input id="dmSearchInput" type="text" placeholder="Search by name or username…"
          style="width:100%;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:8px;padding:9px 12px;color:var(--text-primary);font-size:13px;font-family:inherit;outline:none;box-sizing:border-box;"
          oninput="_loadDmSearchResults(this.value)" />
      </div>
      <div id="dmSearchResults" style="overflow-y:auto;max-height:340px;padding:0 8px 12px;"></div>
    </div>`;
  document.body.appendChild(modal);
}

window.closeDmSearchModal = function() {
  const modal = document.getElementById('dmSearchModal');
  if (!modal) return;
  modal.classList.remove('open');
  setTimeout(() => { modal.style.display = ''; }, 200);
};

let _dmSearchTimer;
async function _loadDmSearchResults(query) {
  clearTimeout(_dmSearchTimer);
  _dmSearchTimer = setTimeout(async () => {
    const container = document.getElementById('dmSearchResults');
    if (!container) return;
    container.innerHTML = '<div style="padding:16px;text-align:center;color:var(--text-muted);font-size:13px;">Searching…</div>';

    try {
      // Reuse existing get-matches or search endpoint; fall back to friends list
      const url = query.trim()
        ? BASE() + '/API/chat/get-matches.php?q=' + encodeURIComponent(query)
        : BASE() + '/API/chat/get-matches.php';
      const data = await apiFetch(url);
      const matches = data.matches || data.users || [];

      if (matches.length === 0) {
        container.innerHTML = '<div style="padding:16px;text-align:center;color:var(--text-muted);font-size:13px;">No users found</div>';
        return;
      }

      container.innerHTML = matches.map(u => {
        const name = u.full_name || u.fullName || u.name || u.username || 'User';
        return `
          <div onclick="openDmConversation(${u.id},'${_esc(name)}','${_esc(u.avatar_color_gradient || u.gradient || '')}');closeDmSearchModal();"
               style="display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:8px;cursor:pointer;transition:background 0.1s;"
               onmouseover="this.style.background='var(--bg-tertiary)'" onmouseout="this.style.background=''">
            ${_avatar(name, u.avatar_color_gradient || u.gradient, 34)}
            <div>
              <div style="font-size:13px;font-weight:600;color:var(--text-primary);">${_esc(name)}</div>
              <div style="font-size:11px;color:var(--text-muted);">@${_esc(u.username || '')}</div>
            </div>
          </div>`;
      }).join('');
    } catch (_) {
      container.innerHTML = '<div style="padding:16px;text-align:center;color:var(--text-muted);font-size:13px;">Failed to load users</div>';
    }
  }, 250);
}

// ═══════════════════════════════════════════════════════════════
//  DM CONVERSATION PANEL
// ═══════════════════════════════════════════════════════════════

window.openDmConversation = async function(partnerId, partnerName, partnerGradient) {
  partnerId    = parseInt(partnerId);
  partnerName  = partnerName || 'User';

  DM.activePartnerId   = partnerId;
  DM.activePartnerName = partnerName;

  _ensureDmPanel();

  const panel = document.getElementById('dmConversationPanel');
  panel.style.display = 'flex';
  setTimeout(() => panel.classList.add('open'), 10);

  // Header
  document.getElementById('dmPanelTitle').innerHTML = `
    ${_avatar(partnerName, partnerGradient, 30)}
    <span style="font-size:15px;font-weight:700;">${_esc(partnerName)}</span>`;

  // Load messages
  const msgArea = document.getElementById('dmMessagesArea');
  msgArea.innerHTML = '<div style="text-align:center;padding:20px;color:var(--text-muted);font-size:13px;">Loading…</div>';

  try {
    const data = await apiFetch(BASE() + `/API/dm/open-conversation.php?partner_id=${partnerId}`);
    DM.activeConvId = data.conversation_id;

    // Clear unread from list
    const conv = DM.conversations.find(c => c.conversation_id === DM.activeConvId);
    if (conv) { conv.unread_count = 0; _renderDmList(); }

    _renderDmMessages(data.messages || []);
  } catch (err) {
    msgArea.innerHTML = `<div style="text-align:center;padding:20px;color:#f87171;font-size:13px;">Failed to load: ${_esc(err.message)}</div>`;
  }
};

function _ensureDmPanel() {
  if (document.getElementById('dmConversationPanel')) return;

  const panel = document.createElement('div');
  panel.id    = 'dmConversationPanel';
  panel.style.cssText = [
    'position:fixed', 'bottom:0', 'right:24px', 'z-index:9000',
    'width:340px', 'height:480px',
    'background:var(--bg-secondary)',
    'border:1px solid var(--border)',
    'border-radius:12px 12px 0 0',
    'display:none', 'flex-direction:column',
    'box-shadow:0 -4px 32px rgba(0,0,0,0.5)',
    'overflow:hidden',
    'transition:transform 0.25s ease',
  ].join(';');

  panel.innerHTML = `
    <!-- Header -->
    <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid var(--border);background:var(--bg-tertiary);flex-shrink:0;">
      <div id="dmPanelTitle" style="display:flex;align-items:center;gap:8px;flex:1;min-width:0;"></div>
      <button onclick="closeDmPanel()" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:18px;padding:0;line-height:1;">×</button>
    </div>

    <!-- Messages -->
    <div id="dmMessagesArea" style="flex:1;overflow-y:auto;padding:12px;display:flex;flex-direction:column;gap:8px;"></div>

    <!-- Typing indicator -->
    <div id="dmTypingIndicator" style="display:none;padding:4px 14px;font-size:11px;color:var(--text-muted);font-style:italic;"></div>

    <!-- Input -->
    <div style="display:flex;gap:8px;padding:10px 12px;border-top:1px solid var(--border);flex-shrink:0;">
      <input id="dmInputField" type="text" placeholder="Message…"
        style="flex:1;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:8px;padding:8px 12px;color:var(--text-primary);font-size:13px;font-family:inherit;outline:none;"
        onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();sendDmMessage();}"
        oninput="_dmTypingSignal()" />
      <button onclick="sendDmMessage()" style="background:var(--accent-purple);border:none;border-radius:8px;padding:8px 14px;color:#fff;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:opacity 0.1s;" onmouseover="this.style.opacity=0.85" onmouseout="this.style.opacity=1">Send</button>
    </div>`;

  document.body.appendChild(panel);
}

function _renderDmMessages(messages) {
  const area = document.getElementById('dmMessagesArea');
  if (!area) return;

  if (messages.length === 0) {
    area.innerHTML = `<div style="text-align:center;padding:30px 20px;color:var(--text-muted);font-size:13px;">No messages yet. Say hello! 👋</div>`;
    return;
  }

  area.innerHTML = messages.map(m => _dmMessageHTML(m)).join('');
  area.scrollTop = area.scrollHeight;
}

function _dmMessageHTML(m) {
  const isMine = m.sender_id == ME_ID();
  const name   = m.sender_name || m.sender_username || 'User';
  const grad   = m.sender_gradient || '';
  return `
    <div style="display:flex;flex-direction:${isMine ? 'row-reverse' : 'row'};align-items:flex-end;gap:8px;" data-msg-id="${m.id}">
      ${!isMine ? _avatar(name, grad, 26) : ''}
      <div style="max-width:72%;background:${isMine ? 'var(--accent-purple)' : 'var(--bg-tertiary)'};color:${isMine ? '#fff' : 'var(--text-primary)'};padding:8px 12px;border-radius:${isMine ? '12px 12px 4px 12px' : '12px 12px 12px 4px'};font-size:13px;line-height:1.5;word-break:break-word;">
        ${_esc(m.body)}
        <div style="font-size:10px;opacity:0.65;margin-top:4px;text-align:${isMine ? 'right' : 'left'};">${_timeAgo(m.created_at)}</div>
      </div>
    </div>`;
}

function _appendDmMessage(m) {
  const area = document.getElementById('dmMessagesArea');
  if (!area) return;
  const el = document.createElement('div');
  el.innerHTML = _dmMessageHTML(m);
  area.appendChild(el.firstElementChild);
  area.scrollTop = area.scrollHeight;
}

// Typing signal debounce
let _dmTypingTimeout;
function _dmTypingSignal() {
  if (!DM.activeConvId || !DM.activePartnerId) return;
  _wsSend({ type: 'dm_typing', conversation_id: DM.activeConvId, recipient_id: DM.activePartnerId, is_typing: true });
  clearTimeout(_dmTypingTimeout);
  _dmTypingTimeout = setTimeout(() => {
    _wsSend({ type: 'dm_typing', conversation_id: DM.activeConvId, recipient_id: DM.activePartnerId, is_typing: false });
  }, 2500);
}

window.sendDmMessage = async function() {
  const input = document.getElementById('dmInputField');
  if (!input) return;
  const text = input.value.trim();
  if (!text || !DM.activeConvId) return;

  input.value    = '';
  input.disabled = true;

  // Optimistic UI
  const optimistic = {
    id: 'opt_' + Date.now(),
    sender_id: ME_ID(),
    body: text,
    created_at: new Date().toISOString(),
    sender_name: window.ECOLLAB?.fullName || 'You',
    sender_gradient: window.ECOLLAB?.gradient || '',
  };
  _appendDmMessage(optimistic);

  try {
    const data = await apiFetch(BASE() + '/API/dm/send-message.php', {
      method: 'POST',
      body: JSON.stringify({ conversation_id: DM.activeConvId, body: text }),
    });

    // Notify recipient via WS
    _wsSend({
      type:            'dm_message',
      conversation_id: DM.activeConvId,
      message_id:      data.message_id,
      recipient_id:    data.recipient_id,
      body:            text,
      created_at:      data.created_at,
    });

    // Notify server to push connection request notification if applicable
    if (data.recipient_id) {
      _wsSend({ type: 'notify_conn_req_check', recipient_id: data.recipient_id });
    }

    // Update sidebar preview
    const conv = DM.conversations.find(c => c.conversation_id === DM.activeConvId);
    if (conv) {
      conv.last_message = text.slice(0, 120);
      conv.last_msg_at  = data.created_at;
      _renderDmList();
    }

  } catch (err) {
    showToast('Failed to send: ' + err.message, 'error');
    // Remove optimistic message
    document.querySelector(`[data-msg-id="${optimistic.id}"]`)?.remove();
  } finally {
    input.disabled = false;
    input.focus();
  }
};

window.closeDmPanel = function() {
  const panel = document.getElementById('dmConversationPanel');
  if (!panel) return;
  panel.classList.remove('open');
  setTimeout(() => { panel.style.display = 'none'; }, 250);
  DM.activeConvId    = null;
  DM.activePartnerId = null;
};

// ═══════════════════════════════════════════════════════════════
//  CONNECT BUTTON — upgrade sendConnectionRequest to also push WS
// ═══════════════════════════════════════════════════════════════

/**
 * Patch the existing sendConnectionRequest so it also pushes a
 * real-time WS notification to the addressee.
 */
(function _patchConnectButton() {
  const _orig = window.sendConnectionRequest;
  if (typeof _orig !== 'function') return;

  window.sendConnectionRequest = async function(btn, nameOrId) {
    // Run original first
    const resultPromise = _orig.call(this, btn, nameOrId);

    // After a short delay (so original fetch completes), push WS notification
    setTimeout(async () => {
      try {
        const payload = typeof nameOrId === 'number' || /^\d+$/.test(String(nameOrId))
          ? { addressee_id: parseInt(nameOrId) }
          : { addressee_name: nameOrId };

        const data = await apiFetch(BASE() + '/API/friendship/send-request.php', {
          method: 'POST',
          body: JSON.stringify(payload),
        });

        if (data.request_id && data.addressee_id) {
          // Push via WS so the online recipient gets the banner immediately
          _wsSend({
            type:         'notify_conn_req',
            addressee_id: data.addressee_id,
            request_id:   data.request_id,
          });
        }
      } catch (_) {}
    }, 400);

    return resultPromise;
  };
})();

// ═══════════════════════════════════════════════════════════════
//  INITIALISE
// ═══════════════════════════════════════════════════════════════

function _init() {
  // Wait for socket.js to set window._wsSocket
  const interval = setInterval(() => {
    if (window._wsSocket) {
      clearInterval(interval);
      _hookWebSocket();
    }
  }, 500);

  // Load initial data
  loadDmList();
  _pollNotifications();

  // Poll notifications every 30 seconds as backup
  NOTIF.pollInterval = setInterval(_pollNotifications, 30_000);

  // Close notif dropdown when clicking outside
  document.addEventListener('click', function(e) {
    const dd  = document.getElementById('notifDropdown');
    const btn = document.getElementById('notifBtn');
    if (dd && dd.style.display === 'block' && !dd.contains(e.target) && !btn?.contains(e.target)) {
      dd.style.display = 'none';
      dd.classList.remove('open');
    }
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', _init);
} else {
  _init();
}

// Export for global access
Object.assign(window, {
  openDmConversation,
  closeDmPanel,
  loadDmList,
  sendDmMessage,
});
