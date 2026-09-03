/**
 * chat.js — Main chat controller for Ecollab Chat
 * Handles channels, messages, UI interactions, and all chat features.
 */

'use strict';

// ── Helpers ──
function _renderMsgContent(text) {
  const escaped = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  // Replace @word or @First Last with a purple mention span using the existing .mention class
  return escaped.replace(/@([A-Za-z0-9_]+(?: [A-Za-z0-9_]+)?)/g, function (match) {
    return '<span class="mention" style="color:#c084fc!important;font-weight:700;background:rgba(168,85,247,0.15);border-radius:3px;padding:1px 4px;">' + match + '</span>';
  });
}

function escHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function timeAgo(dateStr) {
  const d = new Date(dateStr);
  const now = new Date();
  const diff = (now - d) / 1000;
  if (diff < 60) return 'Just now';
  if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
  if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
  return d.toLocaleDateString();
}

function formatTime(dateStr) {
  const d = new Date(dateStr);
  return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

async function apiFetch(url, options = {}, _retried = false) {
  const headers = {
    'Content-Type': 'application/json',
    'X-CSRF-Token': window.ECOLLAB?.csrfToken || '',
    ...(options.headers || {}),
  };
  const res = await fetch(url, { ...options, headers });
  if (!res.ok) {
    const err = await res.json().catch(() => ({ error: res.statusText }));
    // Auto-refresh CSRF token on 403 and retry once
    if (res.status === 403 && !_retried) {
      try {
        const base = window.ECOLLAB?.baseUrl || '';
        const r = await fetch(`${base}/API/auth/csrf-token.php`);
        const d = await r.json();
        if (d.token) {
          window.ECOLLAB.csrfToken = d.token;
          return apiFetch(url, options, true);
        }
      } catch (_) { /* fallback */ }
    }
    throw new Error(err.error || 'Request failed');
  }
  return res.json();
}

const API_BASE = (window.ECOLLAB?.baseUrl || '') + '/API/chat';
const UPLOAD_ENDPOINT = (window.ECOLLAB?.baseUrl || '') + '/API/chat/upload-file.php';

// Expose globally so other modules can use it
window.apiFetch = apiFetch;
window.escHtml = escHtml;

// ── State ──
let currentChannelId = null;
let currentServerId = window.ECOLLAB?.currentServerId || 0;
let replyParentId = null;
let pendingAttachment = null;
let isLoadingMessages = false;
let hasMoreMessages = true;
let oldestMessageId = null;
let typingTimeout = null;

// ── Init ──
document.addEventListener('DOMContentLoaded', () => {
  // Deep-link: if navigated here from a dashboard with a specific
  // channel requested (e.g. "My Channels & Servers" card), select
  // that channel instead of the default first one.
  const params = new URLSearchParams(window.location.search);
  const wantedId   = params.get('channel_id');
  const wantedName = params.get('channel_name');
  let target = null;

  if (wantedId) {
    target = document.querySelector(`.channel-item[data-channel-id="${CSS.escape(wantedId)}"]`);
  }
  if (!target && wantedName) {
    // Fallback: match by channel name (legacy links without an ID)
    target = [...document.querySelectorAll('.channel-item[data-channel-name]')]
      .find(el => el.dataset.channelName === wantedName);
  }

  // Auto-select first channel if no deep-link target found
  if (!target) {
    target = document.querySelector('.channel-item[data-channel-id]');
  }

  if (target) {
    switchChannel(target, parseInt(target.dataset.channelId));
  }

  // Clean the URL so reloading/sharing doesn't re-trigger the deep link
  if (wantedId || wantedName) {
    const cleanUrl = window.location.pathname;
    window.history.replaceState({}, '', cleanUrl);
  }

  // Keyboard shortcut: Cmd/Ctrl + K → focus search
  document.addEventListener('keydown', (e) => {
    if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
      e.preventDefault();
      document.getElementById('sidebarSearch')?.focus();
    }
    if (e.key === 'Escape') closeAllMenus();
  });

  // Global click: close menus
  document.addEventListener('click', (e) => {
    if (!e.target.closest('#emojiPicker') && !e.target.closest('#emojiBtn')) closeEmojiPicker?.();
    if (!e.target.closest('#attachMenu') && !e.target.closest('#attachBtn')) closeAttachMenu();
    if (!e.target.closest('#extrasMenu') && !e.target.closest('#extrasBtn')) closeExtrasMenu();
    if (!e.target.closest('#notifDropdown') && !e.target.closest('#notifBtn')) closeNotifications();
    if (!e.target.closest('#miniProfile')) closeMiniProfile();
  });

  // Infinite scroll — load older messages
  const area = document.getElementById('messagesArea');
  if (area) {
    area.addEventListener('scroll', () => {
      if (area.scrollTop < 200 && hasMoreMessages && !isLoadingMessages && currentChannelId) {
        loadMoreMessages();
      }
    });
  }

  // Start WebSocket
  if (window.initWebSocket) window.initWebSocket();

  // Presence heartbeat
  setInterval(updatePresence, 30000);
});

async function updatePresence() {
  try {
    await apiFetch(`${API_BASE}/get-channel.php?id=${currentChannelId || 0}`);
  } catch { /* silent */ }
}

// ── Workspace switch ──
function switchWorkspace(wsIdx, serverId) {
  if (!serverId) return;
  currentServerId = serverId;
  // Keep ECOLLAB object in sync so chat-features.js can read it
  if (window.ECOLLAB) window.ECOLLAB.currentServerId = serverId;

  document.querySelectorAll('.workspace-icon').forEach((icon, i) => {
    icon.classList.toggle('active', i === wsIdx);
  });

  loadServerChannels(serverId);
}

async function loadServerChannels(serverId) {
  try {
    const data = await apiFetch(`${API_BASE}/get-channels.php?server_id=${serverId}`);
    if (!data.success) return;

    const server = data.servers?.find(s => parseInt(s.id) === parseInt(serverId));
    if (server) {
      document.getElementById('wsIcon').textContent = server.icon_emoji || '⭐';
      document.getElementById('wsName').textContent = server.name;
    }

    renderChannelList(data.channels || []);
  } catch (err) {
    showToast('Failed to load channels', 'info');
    console.error(err);
  }
}

function renderChannelList(channels) {
  const textList = document.getElementById('channelList');
  const voiceList = document.getElementById('voiceChannelList');
  const wbList = document.getElementById('whiteboardChannelList');
  const wbSection = document.getElementById('whiteboardSection');
  if (!textList || !voiceList) return;

  textList.innerHTML = '';
  voiceList.innerHTML = '';
  if (wbList) wbList.innerHTML = '';

  let hasWhiteboard = false;

  channels.forEach(ch => {
    // ── Voice ─────────────────────────────────────────────────────
    if (ch.type === 'voice') {
      const el = document.createElement('div');
      el.className = 'voice-channel';
      el.dataset.channelId = ch.id;
      el.dataset.vc = ch.slug;
      el.innerHTML = `
        <svg width="15" height="15" fill="currentColor" viewBox="0 0 24 24"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>
        ${escHtml(ch.name)}
        <span class="vc-count">${ch.member_count || 0}</span>
      `;
      el.onclick = () => window.joinVoice?.(ch.slug, el, ch.id);
      voiceList.appendChild(el);

      // ── Whiteboard ────────────────────────────────────────────────
    } else if (ch.type === 'whiteboard') {
      hasWhiteboard = true;
      if (!wbList) return;
      const el = document.createElement('div');
      el.className = 'channel-item wb-channel-item';
      el.dataset.channelId = ch.id;
      el.dataset.channelName = ch.name;
      el.innerHTML = `
        <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24" style="color:var(--accent-purple);flex-shrink:0;">
          <path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/>
        </svg>
        <span class="channel-name" style="flex:1;">${escHtml(ch.name)}</span>
      `;
      el.onclick = () => openWhiteboardChannel(parseInt(ch.id), ch.name);
      wbList.appendChild(el);

      // ── Text / Announcement / everything else ─────────────────────
    } else {
      const el = document.createElement('div');
      el.className = 'channel-item';
      el.dataset.channelId = ch.id;
      el.dataset.channelName = ch.name;
      if (ch.is_new == 1 || ch.is_new === true) el.dataset.isNew = '1';
      const isAnnouncement = ch.type === 'announcement';
      const isPrivate = ch.is_private == 1 || ch.is_private === true;
      const isNew = el.dataset.isNew === '1';
      el.innerHTML = `
        ${isAnnouncement
          ? `<svg width="13" height="13" fill="currentColor" viewBox="0 0 24 24" style="color:var(--accent-yellow);flex-shrink:0;margin-right:2px;"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6V11c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></svg>`
          : isPrivate
            ? `<svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor" style="color:var(--text-muted);flex-shrink:0;margin-right:2px;" title="Private channel"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg>`
            : `<span class="channel-hash">#</span>`
        }
        <span class="channel-name-text" style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escHtml(ch.name)}${isNew ? ' <span class="ch-new-badge" style="font-size:9px;background:rgba(168,85,247,0.18);color:#c084fc;border-radius:4px;padding:1px 5px;font-weight:700;vertical-align:middle;">new</span>' : ''}</span>
        ${ch.unread_count > 0 ? `<span class="channel-unread">${ch.unread_count}</span>` : ''}
      `;
      el.onclick = () => switchChannel(el, parseInt(ch.id));
      textList.appendChild(el);
    }
  });

  // Show/hide whiteboard section based on whether channels exist
  if (wbSection) wbSection.style.display = hasWhiteboard ? '' : 'none';

  // Auto-select first text channel
  const first = textList.querySelector('.channel-item');
  if (first) switchChannel(first, parseInt(first.dataset.channelId));
}

// ── Channel switch ──
async function switchChannel(el, channelId) {
  if (channelId === currentChannelId) return;

  // Save draft of current input before switching
  const inputEl = document.getElementById('chatInputField');
  if (inputEl && inputEl.value.trim() && currentChannelId) {
    _saveDraft(currentChannelId, inputEl.value.trim());
    showToast('📝 Draft saved', 'info');
    inputEl.value = '';
  }

  // Unsubscribe old channel
  if (currentChannelId && window.unsubscribeFromChannel) {
    window.unsubscribeFromChannel(currentChannelId);
  }

  currentChannelId = channelId;
  window.ECOLLAB.currentChannelId = channelId;
  oldestMessageId = null;
  hasMoreMessages = true;
  lastMessageId = 0;

  // Update sidebar active state
  document.querySelectorAll('.channel-item').forEach(c => c.classList.remove('active'));
  if (el) el.classList.add('active');

  // Remove unread badge and "new" badge unconditionally when channel is opened
  el?.querySelector('.channel-unread')?.remove();
  const newBadge = el?.querySelector('.ch-new-badge');
  if (newBadge) {
    newBadge.remove();
    delete el.dataset.isNew;
  }
  // Always mark channel seen on the server (idempotent INSERT IGNORE, cheap call)
  apiFetch((window.ECOLLAB?.baseUrl || '') + '/API/chat/mark-channel-seen.php', {
    method: 'POST',
    body: JSON.stringify({ channel_id: channelId }),
  }).catch(() => {});
  // Notify other tabs via WS so they also clear the unread badge immediately
  if (window.wsSend) window.wsSend({ type: 'channel_seen', channel_id: channelId });

  // Show input
  const inputWrapper = document.getElementById('chatInputWrapper');
  if (inputWrapper) inputWrapper.style.display = '';

  // Hide placeholder
  const placeholder = document.getElementById('messagesPlaceholder');
  if (placeholder) placeholder.style.display = 'none';

  // Load channel details + messages
  try {
    const [chanData, msgData] = await Promise.all([
      apiFetch(`${API_BASE}/get-channel.php?id=${channelId}`),
      apiFetch(`${API_BASE}/get-messages.php?channel_id=${channelId}`),
    ]);

    if (chanData.channel) {
      const ch = chanData.channel;
      const isPrivate = ch.is_private == 1 || ch.is_private === true;
      const canManage = chanData.can_manage == 1 || chanData.can_manage === true;
      const hasAccess = chanData.has_access == 1 || chanData.has_access === true || !isPrivate;

      document.getElementById('channelTitle').textContent = ch.name;
      document.getElementById('channelDesc').textContent = ch.description || '';
      document.getElementById('chatInputField').placeholder = `Message #${ch.name}`;
      document.getElementById('mobChannelName').textContent = ch.name;

      // Show/hide manage button for private channels
      const manageBtn = document.getElementById('manageChannelBtn');
      if (manageBtn) manageBtn.style.display = isPrivate && canManage ? '' : 'none';

      // Store channel meta for access request functions
      window._currentChannelMeta = { id: channelId, name: ch.name, isPrivate, canManage, hasAccess };

      // Access banner for locked-out users
      const banner = document.getElementById('channelAccessBanner');
      const inputWrapper = document.getElementById('chatInputWrapper');
      const msgList = document.getElementById('messageList');

      if (isPrivate && !hasAccess && !canManage) {
        if (banner) {
          document.getElementById('accessBannerName').textContent = '#' + ch.name;
          const statusEl = document.getElementById('accessBannerStatus');
          statusEl.style.display = 'none';
          const btn = document.getElementById('accessBannerBtn');
          btn.disabled = false;
          btn.textContent = 'Request Access';
          banner.style.display = 'block';
        }
        if (inputWrapper) inputWrapper.style.display = 'none';
        if (msgList) { msgList.innerHTML = ''; msgList.style.opacity = '0.1'; }
        return;
      } else {
        if (banner) banner.style.display = 'none';
        if (msgList) msgList.style.opacity = '';
      }

      // Hide input for non-text channel types
      const isTextChannel = ['text', 'study_room'].includes(ch.type);
      if (inputWrapper) inputWrapper.style.display = isTextChannel ? '' : 'none';
      renderMembersPanel(chanData.members || []);
      // Cache full member objects for @mention autocomplete
      window.serverMembers = chanData.members || [];
    }

    if (msgData.messages) {
      renderMessages(msgData.messages, false);
      hasMoreMessages = msgData.has_more;
      if (msgData.messages.length) {
        oldestMessageId = msgData.messages[0].id;
        lastMessageId = msgData.messages[msgData.messages.length - 1].id;
      }
    }
  } catch (err) {
    showToast('Failed to load channel', 'info');
    console.error(err);
  }

  // Subscribe via WebSocket
  if (window.subscribeToChannel) window.subscribeToChannel(channelId);
}

// ── Render messages ──
function renderMessages(messages, prepend = false) {
  const area = document.getElementById('messagesArea');
  if (!area) return;

  // Remove typing indicator temporarily
  const typing = document.getElementById('typingIndicator');
  if (!prepend) {
    area.innerHTML = '';
    if (typing) area.appendChild(typing);
  }

  const fragment = document.createDocumentFragment();
  messages.forEach(msg => {
    const el = buildMessageElement(msg);
    if (prepend) {
      fragment.appendChild(el);
    } else {
      fragment.appendChild(el);
    }
  });

  if (prepend) {
    const oldScrollHeight = area.scrollHeight;
    area.insertBefore(fragment, area.firstChild);
    // Maintain scroll position
    area.scrollTop += area.scrollHeight - oldScrollHeight;
  } else {
    if (typing) area.insertBefore(fragment, typing);
    else area.appendChild(fragment);
    scrollToBottom();
  }
}

function buildMessageElement(msg) {
  const div = document.createElement('div');
  div.className = 'message-group' + (msg.is_pinned ? ' pinned-msg-highlight' : '');
  div.dataset.msgId = msg.id;
  div.dataset.senderId = msg.sender_id;

  const grad = msg.avatar_color_gradient || '#3b82f6,#6366f1';
  const [c1, c2] = grad.split(',');
  const init = (msg.full_name || msg.username || '?').charAt(0).toUpperCase();
  const isMe = parseInt(msg.sender_id) === parseInt(window.ECOLLAB?.userId);
  const time = formatTime(msg.created_at);
  const edited = msg.is_edited ? '<span class="edited-tag" style="font-size:10px;color:var(--text-muted);margin-left:4px;">(edited)</span>' : '';

  // Reply preview
  let replyHtml = '';
  if (msg.parent_id && msg.parent_content) {
    replyHtml = `
      <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;padding:4px 8px;background:rgba(255,255,255,0.04);border-left:2px solid var(--accent-purple);border-radius:4px;font-size:12px;color:var(--text-muted);">
        <svg width="11" height="11" fill="currentColor" viewBox="0 0 24 24"><path d="M10 9V5l-7 7 7 7v-4.1c5 0 8.5 1.6 11 5.1-1-5-4-10-11-11z"/></svg>
        <strong style="color:var(--accent-purple);">${escHtml(msg.parent_author || '')}</strong>
        <span>${escHtml((msg.parent_content || '').substring(0, 80))}</span>
      </div>
    `;
  }

  // Attachment
  let attachHtml = '';
  if (msg.attachments && msg.attachments.length) {
    msg.attachments.forEach(att => {
      if (att.mime_type && att.mime_type.startsWith('image/')) {
        attachHtml += `<img src="${(window.ECOLLAB?.baseUrl || '')}/${escHtml(att.file_path)}" style="max-width:300px;max-height:220px;border-radius:8px;margin-top:6px;display:block;cursor:pointer;" onclick="window.open('${(window.ECOLLAB?.baseUrl || '')}/${escHtml(att.file_path)}','_blank')" alt="${escHtml(att.file_name)}">`;
      } else {
        attachHtml += `
          <div style="display:flex;align-items:center;gap:10px;margin-top:6px;padding:10px 12px;background:var(--bg-tertiary);border:1px solid var(--border);border-radius:8px;max-width:300px;">
            <div style="font-size:24px;">📎</div>
            <div style="flex:1;min-width:0;">
              <div style="font-size:13px;font-weight:600;color:var(--text-primary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escHtml(att.file_name)}</div>
              <div style="font-size:11px;color:var(--text-muted);">${formatBytes(att.file_size)}</div>
            </div>
            <a href="${(window.ECOLLAB?.baseUrl || '')}/${escHtml(att.file_path)}" download="${escHtml(att.file_name)}" style="color:var(--accent-purple);font-size:12px;font-weight:600;text-decoration:none;">↓</a>
          </div>
        `;
      }
    });
  }

  // Reactions
  let reactionsHtml = '<div class="reactions">';
  if (msg.reactions && msg.reactions.length) {
    msg.reactions.forEach(r => {
      const reacted = r.reacted_by_me ? ' reacted' : '';
      reactionsHtml += `<div class="reaction${reacted}" data-emoji="${escHtml(r.emoji)}" onclick="handleReactionClick(this, ${msg.id}, '${escHtml(r.emoji)}')">${escHtml(r.emoji)}<span class="reaction-count">${r.cnt}</span></div>`;
    });
  }
  reactionsHtml += `<div class="reaction-add" onclick="showEmojiForMsgBar(event, this)">😊</div></div>`;

  // Username color class
  const roleClass = msg.role === 'facilitator' || msg.role === 'admin' ? 'ai-name'
    : isMe ? 'student-name' : 'student2-name';

  div.innerHTML = `
    <div class="msg-action-bar">
      <button class="msg-action-btn" title="Add Reaction" onclick="showEmojiForMsgBar(event,this)">😊</button>
      <button class="msg-action-btn" title="Reply" onclick="msgReply(this,'${escHtml(msg.username)}', ${msg.id}, '${escHtml((msg.content || '').substring(0, 60))}')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M10 9V5l-7 7 7 7v-4.1c5 0 8.5 1.6 11 5.1-1-5-4-10-11-11z"/></svg>
      </button>
      <button class="msg-action-btn ${msg.is_pinned ? 'pin-active' : ''}" title="${msg.is_pinned ? 'Unpin Message' : 'Pin Message'}" onclick="msgPin(this,'${escHtml(msg.username)}','${escHtml((msg.content || '').substring(0, 60))}', ${msg.id})">📌</button>
      <button class="msg-action-btn" title="More Options" onclick="showMsgMenu(event,this,'${escHtml(msg.username)}', ${msg.id}, ${isMe ? 'true' : 'false'})">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg>
      </button>
    </div>
    <div class="msg-avatar">
      <div class="avatar-placeholder" style="width:36px;height:36px;font-size:14px;border-radius:50%;background:linear-gradient(135deg,${c1},${c2});display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;position:relative;flex-shrink:0;">
        ${init}
        <div class="online-dot"></div>
      </div>
    </div>
    <div class="msg-content">
      <div class="msg-header">
        <span class="msg-username ${roleClass}" onclick="openMiniProfile(event, '${escHtml(msg.full_name || msg.username)}', '${escHtml(msg.role || 'Student')}', '', '${init}', ${msg.sender_id || 0})">${escHtml(msg.username)}</span>
        ${msg.role === 'facilitator' ? '<span class="msg-badge">FACULTY</span>' : ''}
        ${msg.is_verified ? '<span style="color:#a855f7;font-size:12px;" title="Verified">✓</span>' : ''}
        <span class="msg-timestamp">${time}</span>
      </div>
      ${replyHtml}
      ${msg.content_type === 'poll' && msg.poll ? buildPollWidget(msg) : `<div class="msg-text">${_renderMsgContent(msg.content || '')}${edited}</div>`}
      ${attachHtml}
      ${reactionsHtml}
    </div>
  `;

  return div;
}

function buildPollWidget(msg) {
  const poll = msg.poll;
  if (!poll) return '';
  const total = poll.total_votes || 0;
  const bars = poll.options.map(opt => {
    const pct = total > 0 ? Math.round((opt.vote_count / total) * 100) : 0;
    const isMyVote = poll.my_vote === opt.id;
    return `
      <div class="poll-option-row${isMyVote ? ' my-vote' : ''}" data-option-id="${opt.id}"
           onclick="castPollVote(${msg.id}, ${poll.id}, ${opt.id})"
           style="margin-bottom:8px;cursor:pointer;border-radius:6px;padding:6px 8px;transition:background 0.15s;background:${isMyVote ? 'rgba(168,85,247,0.12)' : 'transparent'};">
        <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-secondary);margin-bottom:4px;">
          <span style="color:${isMyVote ? 'var(--accent-purple)' : 'var(--text-primary)'};">${escHtml(opt.text)}${isMyVote ? ' ✓' : ''}</span>
          <span class="poll-pct">${pct}%</span>
        </div>
        <div style="height:6px;background:var(--bg-hover);border-radius:99px;overflow:hidden;">
          <div class="poll-bar-fill" style="height:100%;width:${pct}%;background:linear-gradient(135deg,#a855f7,#ec4899);border-radius:99px;transition:width 0.4s;"></div>
        </div>
      </div>`;
  }).join('');
  return `
    <div class="poll-widget" style="background:var(--bg-tertiary);border:1px solid var(--border);border-radius:10px;padding:14px;margin-top:6px;max-width:340px;">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#a855f7;margin-bottom:8px;">📊 POLL</div>
      <div style="font-size:14px;font-weight:600;color:var(--text-primary);margin-bottom:12px;">${escHtml(poll.question)}</div>
      ${bars}
      <div class="poll-footer" style="font-size:11px;color:var(--text-muted);margin-top:8px;">${total} vote${total !== 1 ? 's' : ''} • Click to vote</div>
    </div>`;
}

function appendMessageToUI(msg) {
  const area = document.getElementById('messagesArea');
  const typing = document.getElementById('typingIndicator');
  if (!area) return;
  const el = buildMessageElement(msg);
  if (typing && typing.parentNode === area) {
    area.insertBefore(el, typing);
  } else {
    area.appendChild(el);
  }
  scrollToBottom();
  lastMessageId = Math.max(lastMessageId || 0, msg.id);
}
window.appendMessageToUI = appendMessageToUI;
window.buildPollWidget = buildPollWidget;

async function loadMoreMessages() {
  if (!currentChannelId || !oldestMessageId || !hasMoreMessages) return;
  isLoadingMessages = true;
  try {
    const data = await apiFetch(`${API_BASE}/get-messages.php?channel_id=${currentChannelId}&before=${oldestMessageId}`);
    if (data.messages && data.messages.length) {
      renderMessages(data.messages, true);
      hasMoreMessages = data.has_more;
      oldestMessageId = data.messages[0].id;
    } else {
      hasMoreMessages = false;
    }
  } catch (err) {
    console.error('loadMoreMessages error:', err);
  }
  isLoadingMessages = false;
}

function scrollToBottom() {
  const area = document.getElementById('messagesArea');
  if (area) area.scrollTop = area.scrollHeight;
}

// ── Send message ──
async function sendMessage() {
  const input = document.getElementById('chatInputField');
  if (!input || !currentChannelId) return;

  const content = input.value.trim();
  if (!content && !pendingAttachment) return;

  const body = {
    channel_id: currentChannelId,
    content: content,
    content_type: 'text',
  };
  if (replyParentId) body.parent_id = replyParentId;
  if (pendingAttachment) {
    body.attachment_path = pendingAttachment.file_path;
    body.attachment_name = pendingAttachment.file_name;
    body.attachment_size = pendingAttachment.file_size;
    body.attachment_mime = pendingAttachment.mime_type;
  }

  input.value = '';
  clearAttachmentPreview();
  cancelReply();

  // Optimistic UI
  const optimisticMsg = {
    id: 'opt_' + Date.now(),
    channel_id: currentChannelId,
    sender_id: window.ECOLLAB?.userId,
    content: content,
    username: window.ECOLLAB?.username,
    full_name: window.ECOLLAB?.fullName,
    avatar_color_gradient: window.ECOLLAB?.avatarGradient,
    role: window.ECOLLAB?.role,
    is_verified: false,
    is_pinned: false,
    is_edited: false,
    parent_id: replyParentId,
    reactions: [],
    attachments: pendingAttachment ? [pendingAttachment] : [],
    created_at: new Date().toISOString(),
  };
  appendMessageToUI(optimisticMsg);
  pendingAttachment = null;

  // Notify typing stopped
  if (window.sendTypingEvent) window.sendTypingEvent(false);

  try {
    const data = await apiFetch(`${API_BASE}/send-message.php`, {
      method: 'POST',
      body: JSON.stringify(body),
    });
    if (data.success && data.message) {
      // Replace optimistic with real message
      const optEl = document.querySelector(`[data-msg-id="opt_${optimisticMsg.id.replace('opt_', '')}"]`) ||
        document.querySelector(`[data-msg-id="${optimisticMsg.id}"]`);
      if (optEl) {
        const realEl = buildMessageElement(data.message);
        optEl.replaceWith(realEl);
      }
      // Broadcast via WebSocket
      if (window.chatSocket && window.chatSocket.readyState === WebSocket.OPEN) {
        window.chatSocket.send(JSON.stringify({ type: 'message', message: data.message }));
      }
      lastMessageId = data.message.id;
    }
  } catch (err) {
    // Remove optimistic message on failure
    const optFail = document.querySelector(`[data-msg-id="${optimisticMsg.id}"]`);
    if (optFail) optFail.remove();
    showToast('Failed to send: ' + (err.message || 'Unknown error'), 'error');
    console.error('[sendMessage]', err);
    // Restore message in input so user doesn't lose it
    const inputRestore = document.getElementById('chatInputField');
    if (inputRestore && content) inputRestore.value = content;
  }
}

// ── Handle typing ──
// Mention state
let _mentionActive = false;
let _mentionQuery = '';
let _mentionStart = -1;

let _draftSaveTimer = null;
function handleTyping(e) {
  // Auto-save draft 2s after user stops typing
  clearTimeout(_draftSaveTimer);
  _draftSaveTimer = setTimeout(function () {
    const val = document.getElementById('chatInputField')?.value?.trim();
    if (val && currentChannelId) {
      _saveDraft(currentChannelId, val);
    } else if (!val && currentChannelId && _drafts[currentChannelId]) {
      _deleteDraft(currentChannelId);
    }
  }, 2000);

  if (window.sendTypingEvent) window.sendTypingEvent(true);
  clearTimeout(typingTimeout);
  typingTimeout = setTimeout(() => {
    if (window.sendTypingEvent) window.sendTypingEvent(false);
  }, 3000);

  // @ mention detection
  const input = e.target;
  const val = input.value;
  const pos = input.selectionStart;

  // Find the last @ before cursor
  const textBefore = val.substring(0, pos);
  const atIdx = textBefore.lastIndexOf('@');

  if (atIdx !== -1 && (atIdx === 0 || /\s/.test(val[atIdx - 1]))) {
    const query = textBefore.substring(atIdx + 1);
    if (!/\s/.test(query)) {
      _mentionActive = true;
      _mentionStart = atIdx;
      _mentionQuery = query.toLowerCase();
      _showMentionDropdown(_mentionQuery);
      return;
    }
  }
  _hideMentionDropdown();
}

function handleKeyDown(e) {
  // Navigate mention dropdown
  if (_mentionActive) {
    const dd = document.getElementById('mentionDropdown');
    if (dd && dd.style.display !== 'none') {
      const items = dd.querySelectorAll('.mention-item');
      const active = dd.querySelector('.mention-item.active');
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        const next = active ? (active.nextElementSibling || items[0]) : items[0];
        items.forEach(i => i.classList.remove('active'));
        if (next) next.classList.add('active');
        return;
      }
      if (e.key === 'ArrowUp') {
        e.preventDefault();
        const prev = active ? (active.previousElementSibling || items[items.length - 1]) : items[items.length - 1];
        items.forEach(i => i.classList.remove('active'));
        if (prev) prev.classList.add('active');
        return;
      }
      if (e.key === 'Enter' || e.key === 'Tab') {
        const sel = dd.querySelector('.mention-item.active') || dd.querySelector('.mention-item');
        if (sel) { e.preventDefault(); _insertMention(sel.dataset.username); return; }
      }
      if (e.key === 'Escape') { _hideMentionDropdown(); return; }
    }
  }
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    sendMessage();
  }
}

function _showMentionDropdown(query) {
  // Get members from sidebar
  const members = [];
  document.querySelectorAll('#membersList .member-item, #activeMembersList .active-user').forEach(el => {
    const nameEl = el.querySelector('.member-name, [class*="name"]') || el;
    const name = nameEl.textContent.trim().split('\n')[0].trim();
    if (name && name.length > 1) members.push(name);
  });

  // Add server members from window.serverMembers if available
  const allMembers = window.serverMembers || members;

  const filtered = allMembers.filter(m => {
    if (typeof m === 'string') return m.toLowerCase().includes(query);
    const uname = (m.username || '').toLowerCase();
    const fname = (m.full_name || '').toLowerCase();
    return uname.includes(query) || fname.includes(query);
  }).slice(0, 8);

  let dd = document.getElementById('mentionDropdown');
  if (!dd) {
    dd = document.createElement('div');
    dd.id = 'mentionDropdown';
    dd.style.cssText = [
      'position:absolute', 'bottom:100%', 'left:0', 'right:0', 'margin-bottom:4px',
      'background:var(--bg-secondary)', 'border:1px solid var(--border)',
      'border-radius:10px', 'box-shadow:0 8px 32px rgba(0,0,0,0.5)',
      'z-index:9999', 'overflow:hidden', 'max-height:240px', 'overflow-y:auto',
    ].join(';');
    const wrap = document.querySelector('.chat-input-wrap') || document.getElementById('chatInputField')?.parentNode;
    if (wrap) { wrap.style.position = 'relative'; wrap.appendChild(dd); }
    else document.body.appendChild(dd);
  }

  if (!filtered.length) { _hideMentionDropdown(); return; }

  dd.innerHTML = filtered.map((m, i) => {
    const name = typeof m === 'string' ? m : (m.full_name || m.username);
    const username = typeof m === 'string' ? m : (m.username || m.full_name);
    const role = typeof m === 'object' ? (m.role || '') : '';
    const init = name.charAt(0).toUpperCase();
    const grad = typeof m === 'object' ? (m.avatar_color_gradient || '#a855f7,#ec4899') : '#a855f7,#ec4899';
    const [c1, c2] = grad.split(',');
    return `<div class="mention-item${i === 0 ? ' active' : ''}" data-username="${escHtml(username)}"
      style="display:flex;align-items:center;gap:10px;padding:8px 12px;cursor:pointer;transition:background 0.1s;"
      onmouseenter="document.querySelectorAll('.mention-item').forEach(i=>i.classList.remove('active'));this.classList.add('active')"
      onclick="_insertMention('${escHtml(name)}')">
      <div style="width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,${c1},${c2});display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#fff;flex-shrink:0;">${init}</div>
      <div>
        <div style="font-size:13px;font-weight:600;color:var(--text-primary);">${escHtml(name)}</div>
        ${role ? `<div style="font-size:11px;color:var(--text-muted);">${escHtml(role)}</div>` : ''}
      </div>
    </div>`;
  }).join('');
  dd.style.display = 'block';
}

function _insertMention(username) {
  const input = document.getElementById('chatInputField');
  if (!input) return;
  const val = input.value;
  const before = val.substring(0, _mentionStart);
  const after = val.substring(input.selectionStart);
  input.value = before + '@' + username + ' ' + after;
  input.focus();
  const newPos = _mentionStart + username.length + 2;
  input.setSelectionRange(newPos, newPos);
  _hideMentionDropdown();
}

function _hideMentionDropdown() {
  _mentionActive = false;
  const dd = document.getElementById('mentionDropdown');
  if (dd) dd.style.display = 'none';
}

// ── Reply ──
function msgReply(btn, authorName, msgId, previewText) {
  replyParentId = msgId;
  const bar = document.getElementById('replyBar');
  const author = document.getElementById('replyAuthor');
  const preview = document.getElementById('replyPreview');
  if (bar) { bar.style.display = 'flex'; }
  if (author) author.textContent = authorName || '';
  if (preview) preview.textContent = previewText || '';
  document.getElementById('chatInputField')?.focus();
}

function cancelReply() {
  replyParentId = null;
  const bar = document.getElementById('replyBar');
  if (bar) bar.style.display = 'none';
}

// ── Pin ──
async function msgPin(btn, author, text, msgId) {
  if (!msgId) return;
  try {
    const data = await apiFetch(`${API_BASE}/pin-message.php`, {
      method: 'POST',
      body: JSON.stringify({ message_id: msgId }),
    });
    const isPinned = !!data.message?.is_pinned;
    btn?.classList.toggle('pin-active', isPinned);
    btn?.setAttribute('title', isPinned ? 'Unpin Message' : 'Pin Message');
    const group = btn?.closest('.message-group');
    if (group) group.classList.toggle('pinned-msg-highlight', isPinned);
    showToast(isPinned ? '📌 Message pinned' : '📌 Message unpinned', 'success');
    // Broadcast to other channel members so their pinned panel refreshes
    if (window.wsSend) window.wsSend({
      type:       'message_pinned',
      channel_id: currentChannelId,
      message_id: msgId,
      pinned:     isPinned,
    });
  } catch (e) {
    showToast('📌 ' + (e?.message || 'Could not pin message'), 'info');
  }
}

// ── Message context menu ──
function showMsgMenu(event, btn, author, msgId, isMe) {
  event.stopPropagation();
  const old = document.getElementById('_msgCtxMenu');
  if (old) old.remove();

  const msgGroup = btn.closest('.message-group');
  const msgText = msgGroup?.querySelector('.msg-text')?.textContent?.trim() ?? '';
  const menu = document.createElement('div');
  menu.id = '_msgCtxMenu';
  menu.style.cssText = `position:fixed;background:var(--bg-secondary);border:1px solid var(--border);border-radius:12px;padding:4px;min-width:190px;z-index:2000;box-shadow:0 12px 40px rgba(0,0,0,0.6);`;
  const rect = btn.getBoundingClientRect();
  menu.style.top = (rect.bottom + 6) + 'px';
  menu.style.left = Math.min(rect.left, window.innerWidth - 200) + 'px';

  const items = [
    { icon: '↩️', label: 'Reply', action: () => msgReply(btn, author, msgId, msgText) },
    { icon: '😊', label: 'Add Reaction', action: (e) => { menu.remove(); showEmojiForMsgBar(e, btn); } },
    { icon: '📋', label: 'Copy Text', action: () => { navigator.clipboard?.writeText(msgText); showToast('📋 Copied!', 'info'); } },
    {
      icon: '⭐', label: 'Save Message', action: () => {
        const channelName = document.querySelector('.channel-topbar-name')?.textContent || '';
        _addBookmark({ id: msgId, author, text: msgText, channel: channelName.replace('#', ''), channelId: currentChannelId, letter: author.charAt(0).toUpperCase() });
        showToast('⭐ Saved to bookmarks!', 'success');
      }
    },
    {
      icon: '💬', label: 'Follow Thread', action: () => {
        const channelName = document.querySelector('.channel-topbar-name')?.textContent || '';
        _addThread({ id: msgId, author, text: msgText, channel: channelName.replace('#', ''), channelId: currentChannelId, letter: author.charAt(0).toUpperCase() });
        showToast('💬 Following thread', 'success');
      }
    },
  ];

  if (isMe) {
    items.push({ icon: '✏️', label: 'Edit Message', action: () => startEditMsg(msgGroup, msgText, msgId) });
    items.push({ sep: true });
    items.push({ icon: '🗑️', label: 'Delete Message', red: true, action: () => deleteMsg(msgGroup, msgId) });
  } else {
    items.push({ sep: true });
    items.push({ icon: '🚩', label: 'Report Message', red: true, action: () => openReportModal(msgId) });
  }

  items.forEach(item => {
    if (item.sep) {
      const sep = document.createElement('div');
      sep.style.cssText = 'height:1px;background:var(--border);margin:3px 0;';
      menu.appendChild(sep); return;
    }
    const el = document.createElement('div');
    el.style.cssText = `display:flex;align-items:center;gap:9px;padding:9px 12px;border-radius:8px;font-size:12px;font-weight:500;color:${item.red ? '#f87171' : 'var(--text-secondary)'};cursor:pointer;transition:background 0.1s;`;
    el.innerHTML = `<span style="font-size:14px;">${item.icon}</span><span>${item.label}</span>`;
    el.onmouseover = () => { el.style.background = item.red ? 'rgba(239,68,68,0.1)' : 'var(--bg-hover)'; if (!item.red) el.style.color = '#fff'; };
    el.onmouseout = () => { el.style.background = ''; el.style.color = item.red ? '#f87171' : 'var(--text-secondary)'; };
    el.onclick = (e) => { e.stopPropagation(); menu.remove(); item.action(e); };
    menu.appendChild(el);
  });

  document.body.appendChild(menu);
  setTimeout(() => {
    document.addEventListener('click', function rm() { menu.remove(); document.removeEventListener('click', rm); });
  }, 50);
}

// ── Edit message ──
function startEditMsg(msgGroup, originalText, msgId) {
  const textEl = msgGroup.querySelector('.msg-text');
  if (!textEl) return;
  textEl.contentEditable = 'true';
  textEl.style.outline = '1px solid var(--accent-purple)';
  textEl.style.borderRadius = '4px';
  textEl.style.padding = '2px 4px';
  textEl.focus();
  const range = document.createRange();
  range.selectNodeContents(textEl);
  range.collapse(false);
  window.getSelection()?.removeAllRanges();
  window.getSelection()?.addRange(range);

  const hint = document.createElement('div');
  hint.id = '_editHint';
  hint.style.cssText = 'font-size:11px;color:var(--text-muted);margin-top:4px;';
  hint.innerHTML = `<span style="color:var(--accent-green);cursor:pointer;" onclick="saveEditMsg(this, ${msgId})">✓ Save</span>  <span style="cursor:pointer;" onclick="cancelEditMsg(this,'${escHtml(originalText)}')">✕ Cancel</span>  <span style="color:var(--text-muted);">· or Enter / Esc</span>`;
  textEl.parentNode.insertBefore(hint, textEl.nextSibling);

  textEl.onkeydown = (e) => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); saveEditMsg(hint.querySelector('span'), msgId); }
    if (e.key === 'Escape') cancelEditMsg(hint.querySelector('span:nth-child(2)'), originalText);
  };
}

async function saveEditMsg(el, msgId) {
  const hint = el.closest('#_editHint') ?? el.parentNode;
  const msgContent = hint?.parentNode;
  if (!msgContent) return;
  const textEl = msgContent.querySelector('.msg-text');
  if (!textEl) return;

  const newContent = textEl.textContent?.trim();
  textEl.contentEditable = 'false';
  textEl.style.outline = '';
  textEl.style.padding = '';

  if (!hint.querySelector('.edited-tag')) {
    const tag = document.createElement('span');
    tag.className = 'edited-tag';
    tag.style.cssText = 'font-size:10px;color:var(--text-muted);margin-left:4px;';
    tag.textContent = '(edited)';
    textEl.appendChild(tag);
  }
  hint.remove();

  try {
    const updated = await apiFetch(`${API_BASE}/edit-message.php`, {
      method: 'POST',
      body: JSON.stringify({ message_id: msgId, content: newContent }),
    });
    showToast('✅ Message updated', 'success');
    // Broadcast edit to all channel members so their UI updates in real time
    if (window.wsSend) window.wsSend({
      type:       'message_edited',
      channel_id: currentChannelId,
      message:    updated?.message ?? { id: msgId, content: newContent },
    });
  } catch { showToast('Failed to update message', 'info'); }
}

function cancelEditMsg(el, original) {
  const hint = el.closest('#_editHint') ?? el.parentNode;
  const msgContent = hint?.parentNode;
  if (!msgContent) return;
  const textEl = msgContent.querySelector('.msg-text');
  if (textEl) {
    textEl.contentEditable = 'false';
    textEl.style.outline = '';
    textEl.style.padding = '';
    if (textEl.firstChild) textEl.firstChild.textContent = original;
  }
  hint.remove();
}

async function deleteMsg(msgGroup, msgId) {
  msgGroup.style.opacity = '0';
  msgGroup.style.transform = 'scaleY(0)';
  msgGroup.style.transition = 'opacity 0.2s, transform 0.2s';
  setTimeout(() => msgGroup.remove(), 220);
  try {
    await apiFetch(`${API_BASE}/delete-message.php`, {
      method: 'POST',
      body: JSON.stringify({ message_id: msgId }),
    });
    showToast('🗑️ Message deleted', 'info');
    if (window.chatSocket && window.chatSocket.readyState === WebSocket.OPEN) {
      window.chatSocket.send(JSON.stringify({ type: 'message_deleted', message_id: msgId }));
    }
  } catch { showToast('Failed to delete message', 'info'); }
}

// ── Reaction click ──
async function handleReactionClick(el, msgId, emoji) {
  el.classList.toggle('reacted');
  const countEl = el.querySelector('.reaction-count');
  if (countEl) {
    const curr = parseInt(countEl.textContent || '0');
    countEl.textContent = el.classList.contains('reacted') ? curr + 1 : Math.max(0, curr - 1);
  }
  try {
    await apiFetch(`${API_BASE}/send-message.php`, {
      method: 'POST',
      body: JSON.stringify({ action: 'reaction', message_id: msgId, emoji }),
    });
  } catch { /* silent */ }
}

function renderMessageReactions(msgEl, reactions) {
  const container = msgEl.querySelector('.reactions');
  if (!container) return;
  const addBtn = container.querySelector('.reaction-add');
  container.innerHTML = '';
  reactions.forEach(r => {
    const el = document.createElement('div');
    el.className = 'reaction' + (r.reacted_by_me ? ' reacted' : '');
    el.dataset.emoji = r.emoji;
    el.innerHTML = `${r.emoji}<span class="reaction-count">${r.cnt}</span>`;
    el.onclick = () => handleReactionClick(el, msgEl.dataset.msgId, r.emoji);
    container.appendChild(el);
  });
  if (addBtn) container.appendChild(addBtn);
}

// ── File upload ──
function triggerFileInput(id) {
  document.getElementById(id)?.click();
  closeAttachMenu();
}

async function handleFileUpload(input, type) {
  const file = input.files?.[0];
  if (!file) return;
  const fd = new FormData();
  fd.append('file', file);
  try {
    showToast('📎 Uploading…', 'info');
    const resp = await fetch((window.ECOLLAB?.baseUrl || '') + '/API/chat/upload-file.php', {
      method: 'POST',
      headers: { 'X-CSRF-Token': window.ECOLLAB?.csrfToken || '' },
      body: fd,
    });
    const data = await resp.json();
    if (data.success) {
      pendingAttachment = data;
      showAttachmentPreview(data, type);
      showToast('📎 File ready to send', 'success');
    } else {
      showToast('Upload failed: ' + (data.error || 'unknown error'), 'info');
    }
  } catch (err) {
    showToast('Upload failed', 'info');
    console.error(err);
  }
  input.value = '';
}

function showAttachmentPreview(attachment, type) {
  const wrapper = document.getElementById('chatInputContainer');
  let preview = document.getElementById('_attachPreview');
  if (!preview) {
    preview = document.createElement('div');
    preview.id = '_attachPreview';
    preview.style.cssText = 'padding:8px 12px;border-top:1px solid var(--border);display:flex;align-items:center;gap:10px;';
    wrapper?.appendChild(preview);
  }
  const isImage = attachment.mime_type?.startsWith('image/');
  preview.innerHTML = `
    ${isImage ? `<img src="${escHtml(attachment.url)}" style="height:40px;border-radius:4px;">` : '<span style="font-size:20px;">📎</span>'}
    <div style="flex:1;min-width:0;">
      <div style="font-size:12px;font-weight:600;color:var(--text-primary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escHtml(attachment.file_name)}</div>
      <div style="font-size:10px;color:var(--text-muted);">${formatBytes(attachment.file_size)}</div>
    </div>
    <button onclick="clearAttachmentPreview()" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:16px;">×</button>
  `;
}

function clearAttachmentPreview() {
  pendingAttachment = null;
  document.getElementById('_attachPreview')?.remove();
}

function formatBytes(bytes) {
  if (!bytes) return '0 B';
  const k = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

// ── Create channel ──
// Open channel modal and pre-select type based on which section was clicked
function openAddChannelModal(defaultType = 'text') {
  // Reset form
  const nameInput = document.getElementById('newChannelName');
  const descInput = document.getElementById('newChannelDesc');
  const toggle = document.getElementById('privateChannelToggle');
  if (nameInput) nameInput.value = '';
  if (descInput) descInput.value = '';
  if (toggle) toggle.classList.remove('on');

  // Pre-select the channel type
  document.querySelectorAll('.channel-type-opt').forEach(opt => {
    const isActive = opt.dataset.type === defaultType;
    opt.classList.toggle('active', isActive);
    opt.style.background = isActive ? 'rgba(168,85,247,0.08)' : '';
    opt.style.borderColor = isActive ? 'rgba(168,85,247,0.4)' : 'var(--border)';
  });

  if (window.openModal) openModal('addChannelModal');
}

async function createChannel() {
  const name = document.getElementById('newChannelName')?.value?.trim();
  const desc = document.getElementById('newChannelDesc')?.value?.trim();
  const isPriv = document.getElementById('privateChannelToggle')?.classList.contains('on');
  const typeOpt = document.querySelector('.channel-type-opt.active');
  const type = typeOpt?.dataset?.type || 'text';

  if (!name) { showToast('Channel name is required', 'info'); return; }

  try {
    const data = await apiFetch(`${API_BASE}/create-channel.php`, {
      method: 'POST',
      body: JSON.stringify({
        server_id: currentServerId,
        name,
        description: desc,
        type,
        is_private: isPriv ? 1 : 0,
      }),
    });
    if (data.success) {
      const newChannelId = data.channel?.id;
      // If private, grant access to selected users
      if (isPriv && newChannelId && window._privateChannelSelectedUsers?.size > 0) {
        const userIds = Array.from(window._privateChannelSelectedUsers);
        await Promise.all(userIds.map(uid =>
          apiFetch(`${API_BASE}/channel-members.php`, {
            method: 'POST',
            body: JSON.stringify({ action: 'add', channel_id: newChannelId, user_id: uid }),
          }).catch(() => {})
        ));
      }
      window._privateChannelSelectedUsers = new Set();
      closeModal('addChannelModal');
      showToast('✅ Channel #' + name + ' created', 'success');
      loadServerChannels(currentServerId);
    }
  } catch (err) {
    showToast(err.message || 'Failed to create channel', 'info');
  }
}

function selectChannelType(el, type) {
  document.querySelectorAll('.channel-type-opt').forEach(o => o.classList.remove('active'));
  el.classList.add('active');
}

// ── Members panel ──
function renderMembersPanel(members) {
  const list = document.getElementById('membersList');
  const activeList = document.getElementById('activeMembersList');
  const badge = document.getElementById('memberCountBadge');
  if (badge) badge.textContent = '— ' + members.length;
  if (!list) return;

  const html = members.slice(0, 20).map(m => {
    const grad = m.avatar_color_gradient || '#3b82f6,#6366f1';
    const [c1, c2] = grad.split(',');
    const init = (m.full_name || m.username || '?').charAt(0).toUpperCase();
    const online = m.is_online ? 'online' : '';
    return `
      <div class="member-item" data-user-id="${m.id || m.user_id || 0}" data-user-grad="${grad}" onclick="openMiniProfile(event, '${escHtml(m.full_name || m.username)}', '${escHtml(m.role || 'Student')}', '', '${init}', ${m.id || m.user_id || 0})">
        <div class="user-avatar">
          <div class="avatar-placeholder" style="width:28px;height:28px;font-size:11px;border-radius:50%;background:linear-gradient(135deg,${c1},${c2});display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;">${init}</div>
          <div class="online-dot ${m.is_online ? '' : 'offline'}"></div>
        </div>
        <div class="member-info">
          <div class="member-name">${escHtml(m.nickname || m.username)}${m.server_role === 'owner' ? ' <span class="member-badge">👑</span>' : ''}</div>
          <div class="member-sub" style="color:${m.is_online ? 'var(--accent-green)' : 'var(--text-muted)'};font-size:10px;">${m.is_online ? 'Online' : 'Offline'}</div>
        </div>
        <div class="member-status ${online}">● ${m.is_online ? 'Online' : ''}</div>
      </div>
    `;
  }).join('');

  list.innerHTML = html + (members.length > 20 ? `<div class="members-more">+${members.length - 20} more members</div>` : '');

  // Active now panel
  if (activeList) {
    const online = members.filter(m => m.is_online).slice(0, 5);
    activeList.innerHTML = online.map(m => {
      const grad = m.avatar_color_gradient || '#3b82f6,#6366f1';
      const [c1, c2] = grad.split(',');
      const init = (m.full_name || m.username || '?').charAt(0).toUpperCase();
      return `
        <div class="active-user" onclick="openMiniProfile(event, '${escHtml(m.full_name || m.username)}', '${escHtml(m.role)}', '', '${init}')">
          <div class="user-avatar">
            <div class="avatar-placeholder" style="width:34px;height:34px;font-size:13px;border-radius:50%;background:linear-gradient(135deg,${c1},${c2});display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;">${init}</div>
            <div class="online-dot"></div>
          </div>
          <div class="active-user-info">
            <div class="active-user-name">${escHtml(m.full_name || m.username)}</div>
            <div class="active-user-status">${escHtml(m.role || 'Student')}</div>
          </div>
          <div class="activity-bars"><div class="activity-bar"></div><div class="activity-bar"></div><div class="activity-bar"></div></div>
        </div>
      `;
    }).join('');
  }
}

async function refreshMembersPanel() {
  if (!currentChannelId) return;
  try {
    const data = await apiFetch(`${API_BASE}/get-channel.php?id=${currentChannelId}`);
    if (data.members) renderMembersPanel(data.members);
  } catch { /* silent */ }
}
window.refreshMembersPanel = refreshMembersPanel;

function filterMembers(filter) {
  showToast('👥 Filter: ' + filter, 'info');
}

// ── Mini profile ──
function openMiniProfile(event, name, role, avatarClass, initials, userId) {
  event.stopPropagation();
  // If chat-features.js has loaded its richer version, delegate to it
  if (window._chatFeaturesOpenMiniProfile) {
    window._chatFeaturesOpenMiniProfile(event, name, role, avatarClass, initials, userId);
    return;
  }
  const mp = document.getElementById('miniProfile');
  if (!mp) return;
  const av = document.getElementById('mpAvatar');
  const nm = document.getElementById('mpName');
  const rl = document.getElementById('mpRole');
  if (av) av.textContent = initials || name.charAt(0).toUpperCase();
  if (nm) nm.textContent = name;
  if (rl) rl.textContent = role;

  mp.style.display = 'block';
  const x = Math.min(event.clientX + 10, window.innerWidth - 300);
  const y = Math.min(event.clientY - 20, window.innerHeight - 250);
  mp.style.left = x + 'px';
  mp.style.top = y + 'px';
}

function closeMiniProfile() {
  const mp = document.getElementById('miniProfile');
  if (mp) mp.style.display = 'none';
}

// ── Pinned messages ──
async function togglePinnedMessages() {
  openModal('pinnedModal');
  if (!currentChannelId) return;
  try {
    const data = await apiFetch(`${API_BASE}/get-messages.php?channel_id=${currentChannelId}&pinned=1`);
    renderPinnedModal(data.messages || []);
  } catch { renderPinnedModal([]); }
}

function renderPinnedModal(messages) {
  const list = document.getElementById('pinnedList');
  if (!list) return;
  if (!messages.length) {
    list.innerHTML = '<div style="text-align:center;padding:30px;color:var(--text-muted);">No pinned messages</div>';
    return;
  }
  list.innerHTML = messages.map(msg => `
    <div class="pinned-msg">
      <div class="pinned-msg-meta">${escHtml(msg.username)} · ${timeAgo(msg.created_at)}</div>
      <div class="pinned-msg-text">${escHtml(msg.content || '')}</div>
    </div>
  `).join('');
}

// ── Attach / Extras menus ──
function toggleAttachMenu(e) {
  if (e) e.stopPropagation();
  const menu = document.getElementById('attachMenu');
  const isOpen = menu?.classList.contains('open');
  closeAllMenus();
  if (!isOpen) menu?.classList.add('open');
}

function closeAttachMenu() {
  document.getElementById('attachMenu')?.classList.remove('open');
}

function toggleExtrasMenu(e) {
  if (e) e.stopPropagation();
  const menu = document.getElementById('extrasMenu');
  const isOpen = menu?.classList.contains('open');
  closeAllMenus();
  if (!isOpen) menu?.classList.add('open');
}

function closeExtrasMenu() {
  document.getElementById('extrasMenu')?.classList.remove('open');
}

function closeAllMenus() {
  closeAttachMenu();
  closeExtrasMenu();
  if (window.closeEmojiPicker) window.closeEmojiPicker();
}
window.closeAllMenus = closeAllMenus;

// ── Notifications ──
function toggleNotifications() {
  const dd = document.getElementById('notifDropdown');
  if (!dd) return;
  dd.classList.toggle('open');
  if (dd.classList.contains('open')) {
    dd.style.display = 'block';
  }
}

function closeNotifications() {
  const dd = document.getElementById('notifDropdown');
  if (dd) dd.classList.remove('open');
}

function markAllRead() {
  document.querySelectorAll('.notif-dot').forEach(d => d.classList.add('read'));
  const badge = document.getElementById('notifBadge');
  if (badge) badge.style.display = 'none';
  showToast('✅ All notifications marked as read', 'success');
}

// ── Modals ──
function openModal(id) {
  const el = document.getElementById(id);
  if (el) {
    el.style.display = 'flex';
    setTimeout(() => el.classList.add('open'), 10);
  }
}

function closeModal(id) {
  const el = document.getElementById(id);
  if (el) {
    el.classList.remove('open');
    setTimeout(() => { el.style.display = ''; }, 200);
  }
}
window.openModal = openModal;
window.closeModal = closeModal;

// ── Sidebar filter ──
function filterSidebar(query) {
  const q = query.toLowerCase().trim();
  document.querySelectorAll('.channel-item, .voice-channel').forEach(el => {
    const name = (el.dataset.channelName || el.textContent || '').toLowerCase();
    el.style.display = !q || name.includes(q) ? '' : 'none';
  });
}

// ── AI Assist ──
async function generateAIReply() {
  const input = document.getElementById('chatInputField');
  if (!input) return;
  const btn = document.getElementById('aiAssistBtn');
  if (btn) { btn.textContent = '✨ Generating…'; btn.disabled = true; }
  try {
    // Gather last 8 messages as context
    const msgEls = document.querySelectorAll('.msg-text');
    const context = Array.from(msgEls).slice(-8).map(el => el.textContent.trim()).filter(Boolean).join('\n');

    const data = await apiFetch(`${API_BASE}/ai-assist.php`, {
      method: 'POST',
      body: JSON.stringify({
        prompt: input.value || 'Suggest a helpful reply for this study chat',
        context,
      }),
    });

    if (data.suggestion) {
      input.value = data.suggestion;
      input.focus();
      input.dispatchEvent(new Event('input'));
    }
  } catch (err) {
    if (window.showToast) showToast(err.message || 'AI assist unavailable', 'info');
  } finally {
    if (btn) { btn.innerHTML = '✨ AI Assist <span class="ai-assist-chevron">▾</span>'; btn.disabled = false; }
  }
}

// ── Mobile sidebar ──
function toggleSidebar() {
  const open = document.body.classList.toggle('sidebar-open');
  document.getElementById('mobileOverlay')?.classList.toggle('open', open);
}

function closeSidebar() {
  document.body.classList.remove('sidebar-open');
  document.getElementById('mobileOverlay')?.classList.remove('open');
}

// ── Toast ──
function showToast(message, type = 'info') {
  const container = document.getElementById('toastContainer');
  if (!container) return;
  const toast = document.createElement('div');
  toast.className = 'toast ' + type;
  toast.innerHTML = `<span>${message}</span>`;
  container.appendChild(toast);
  setTimeout(() => {
    toast.style.transition = 'opacity 0.3s';
    toast.style.opacity = '0';
    setTimeout(() => toast.remove(), 300);
  }, 3000);
}
window.showToast = showToast;

// ── Expose all public functions ──
window.switchWorkspace = switchWorkspace;
window.switchChannel = switchChannel;
// switchView is defined in chat-features.js — expose it after that script loads
if (typeof switchView !== 'undefined') window.switchView = switchView;

// ── Open whiteboard channel ──
function openWhiteboardChannel(channelId, channelName) {
  // Highlight the wb channel item
  document.querySelectorAll('.wb-channel-item').forEach(el => el.classList.remove('active'));
  const el = document.querySelector(`.wb-channel-item[data-channel-id="${channelId}"]`);
  if (el) el.classList.add('active');

  // Update header
  const nameEl = document.getElementById('channelName');
  if (nameEl) nameEl.textContent = channelName;
  const topicEl = document.getElementById('channelTopic');
  if (topicEl) topicEl.textContent = 'Collaborative whiteboard';

  // Whiteboards have their own workspace so refresh/recovery and saved versions
  // are not tied to the chat panel lifecycle.
  window.location.href = `${window.ECOLLAB?.baseUrl || ''}/modules/whiteboard/index.php?channel_id=${encodeURIComponent(channelId)}`;
}
window.openWhiteboardChannel = openWhiteboardChannel;

// ── Draft / Bookmark / Thread / Mention storage (client-side) ──────────────
const _drafts = JSON.parse(localStorage.getItem('ec_drafts') || '{}');
const _bookmarks = JSON.parse(localStorage.getItem('ec_bookmarks') || '[]');
const _threads = JSON.parse(localStorage.getItem('ec_threads') || '[]');
const _mentions = JSON.parse(localStorage.getItem('ec_mentions') || '[]');

function _saveDraft(channelId, text) {
  const channelName = document.querySelector('.channel-topbar-name')?.textContent?.replace('#', '') || channelId;
  _drafts[channelId] = { text, saved: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }), channelId, channel: channelName };
  localStorage.setItem('ec_drafts', JSON.stringify(_drafts));
  if (window._notifyDraftChange) window._notifyDraftChange();
  // Sync to other open tabs/devices via WebSocket
  if (window.broadcastDraftSave) window.broadcastDraftSave(channelId, text, channelName);
}
function _deleteDraft(channelId) {
  delete _drafts[channelId];
  localStorage.setItem('ec_drafts', JSON.stringify(_drafts));
  if (window._notifyDraftChange) window._notifyDraftChange();
  // Notify other tabs that this draft was cleared
  if (window.broadcastDraftSave) window.broadcastDraftSave(channelId, '', '');
}
function _checkResumeDraft(channelId) {
  const draft = _drafts[channelId];
  if (!draft) return;
  // Show resume banner above input
  let banner = document.getElementById('draftResumeBanner');
  if (!banner) {
    banner = document.createElement('div');
    banner.id = 'draftResumeBanner';
    banner.style.cssText = 'background:rgba(245,158,11,0.12);border:1px solid rgba(245,158,11,0.3);border-radius:8px;padding:8px 12px;margin:4px 16px;display:flex;align-items:center;gap:8px;font-size:12px;color:#fbbf24;';
    const inputWrap = document.querySelector('.chat-input-wrap');
    if (inputWrap) inputWrap.parentNode.insertBefore(banner, inputWrap);
  }
  banner.style.display = 'flex';
  banner.innerHTML = `<span>📝 You have a draft in this channel</span>
    <button onclick="document.getElementById('chatInputField').value=window._drafts['${channelId}']?.text||'';document.getElementById('draftResumeBanner').style.display='none';" style="margin-left:auto;background:rgba(245,158,11,0.2);border:1px solid rgba(245,158,11,0.3);border-radius:6px;padding:3px 10px;color:#fbbf24;cursor:pointer;font-family:inherit;font-size:11px;">Resume</button>
    <button onclick="window._deleteDraft('${channelId}');document.getElementById('draftResumeBanner').style.display='none';" style="background:transparent;border:none;color:var(--text-muted);cursor:pointer;font-size:14px;padding:0 4px;">✕</button>`;
}
function _addBookmark(item) {
  const existing = _bookmarks.findIndex(b => b.id === item.id);
  if (existing === -1) _bookmarks.push(item);
  localStorage.setItem('ec_bookmarks', JSON.stringify(_bookmarks));
}
function _addThread(item) {
  const existing = _threads.findIndex(t => t.id === item.id);
  if (existing === -1) _threads.push({ ...item, replies: 1, lastReply: 'Just now' });
  localStorage.setItem('ec_threads', JSON.stringify(_threads));
}
function _detectAndStoreMentions(text, channelId) {
  const myUsername = window.ECOLLAB?.username || '';
  const myFullName = window.ECOLLAB?.fullName || '';
  const channelName = document.querySelector('.channel-topbar-name')?.textContent?.replace('#', '') || '';
  // Check if message mentions the current user
  if (myUsername && text.toLowerCase().includes('@' + myUsername.toLowerCase())) {
    _mentions.unshift({ author: myFullName || myUsername, text, channel: channelName, channelId, time: new Date().toLocaleTimeString(), letter: (myFullName || myUsername).charAt(0).toUpperCase() });
    if (_mentions.length > 50) _mentions.pop();
    localStorage.setItem('ec_mentions', JSON.stringify(_mentions));
    _updateMentionBadge();
  }
}
function _updateMentionBadge() {
  const fresh = JSON.parse(localStorage.getItem('ec_mentions') || '[]');
  // Sync the in-memory array
  _mentions.length = 0;
  fresh.forEach(i => _mentions.push(i));
  const count = fresh.filter(m => !m.read).length;
  let badge = document.getElementById('mentionNavBadge');
  if (!badge) {
    const mentionNavItem = Array.from(document.querySelectorAll('.sidebar-nav-item')).find(el => el.textContent.trim().startsWith('Mentions'));
    if (mentionNavItem) {
      badge = document.createElement('span');
      badge.id = 'mentionNavBadge';
      badge.style.cssText = 'margin-left:auto;background:#ef4444;color:#fff;border-radius:10px;padding:1px 6px;font-size:10px;font-weight:700;';
      mentionNavItem.style.display = 'flex';
      mentionNavItem.style.alignItems = 'center';
      mentionNavItem.appendChild(badge);
    }
  }
  if (badge) badge.textContent = count > 0 ? count : '';
  if (badge) badge.style.display = count > 0 ? '' : 'none';
}
// Expose to chat-features for view rendering
window._drafts = _drafts;
window._bookmarks = _bookmarks;
window._threads = _threads;
window._mentions = _mentions;
window._deleteDraft = _deleteDraft;
window._saveDraft = _saveDraft;
window._updateMentionBadge = _updateMentionBadge;
// Init badge on page load
setTimeout(_updateMentionBadge, 1000);

window.sendMessage = sendMessage;
window.handleTyping = handleTyping;
window.handleKeyDown = handleKeyDown;
window.msgReply = msgReply;
window.cancelReply = cancelReply;
window.msgPin = msgPin;
window.showMsgMenu = showMsgMenu;
window.startEditMsg = startEditMsg;
window.saveEditMsg = saveEditMsg;
window.cancelEditMsg = cancelEditMsg;
window.deleteMsg = deleteMsg;
window.handleReactionClick = handleReactionClick;
window.triggerFileInput = triggerFileInput;
window.handleFileUpload = handleFileUpload;
window.clearAttachmentPreview = clearAttachmentPreview;
window.createChannel = createChannel;
window.selectChannelType = selectChannelType;
window.openMiniProfile = openMiniProfile;
window.closeMiniProfile = closeMiniProfile;
window.togglePinnedMessages = togglePinnedMessages;
window.toggleAttachMenu = toggleAttachMenu;
window.toggleExtrasMenu = toggleExtrasMenu;
// openExtrasAction is defined in chat-features.js — resolved at call time
window.openExtrasAction = function (...args) {
  // Avoid self-reference: look for the function on the window object under a temp name,
  // or defer to chat-features export if available
  const fn = window.__openExtrasAction;
  if (typeof fn === 'function') return fn(...args);
};
window.toggleNotifications = toggleNotifications;
window.markAllRead = markAllRead;
window.filterSidebar = filterSidebar;
window.generateAIReply = generateAIReply;
window.toggleSidebar = toggleSidebar;
window.closeSidebar = closeSidebar;
window.filterMembers = filterMembers;
window.openNewDMModal = () => showToast('💬 DMs coming soon', 'info');
window.lastMessageId = 0;

// Register chat.js functions as __real_* for the stub system in chat.php
(function () {
  var fns = [
    'togglePinnedMessages', 'toggleAttachMenu', 'toggleExtrasMenu',
    'toggleNotifications', 'markAllRead', 'filterSidebar', 'generateAIReply',
    'toggleSidebar', 'closeSidebar', 'filterMembers', 'openMiniProfile', 'closeMiniProfile',
    'msgReact', 'msgPin', 'msgEdit', 'msgDelete', 'showMsgMenu',
    'cancelReply', 'sendMessage', 'loadMoreMessages', 'switchWorkspace', 'switchChannel',
    'openWhiteboardChannel', 'openNewDMModal',
  ];
  fns.forEach(function (name) {
    if (typeof window[name] === 'function') window['__real_' + name] = window[name];
  });
})();