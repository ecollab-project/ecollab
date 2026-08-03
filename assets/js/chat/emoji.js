/**
 * emoji.js — Emoji picker module for Ecollab Chat
 * Extracted and modularized from chat-sample4.html
 */

'use strict';

const emojiFreqMap = {};

const emojiCategories = [
  { id: 'frequent', label: 'Frequently Used', icon: '🕐', emojis: [] },
  { id: 'smileys', label: 'Smileys & People', icon: '😀', emojis: ['😀', '😃', '😄', '😁', '😆', '😅', '🤣', '😂', '🙂', '🙃', '😉', '😊', '😇', '🥰', '😍', '🤩', '😘', '😗', '☺️', '😚', '😙', '🥲', '😋', '😛', '😜', '🤪', '😝', '🤑', '🤗', '🤭', '🤫', '🤔', '🤐', '🤨', '😐', '😑', '😶', '😏', '😒', '🙄', '😬', '🤥', '😌', '😔', '😪', '🤤', '😴', '😷', '🤒', '🤕', '🤢', '🤮', '🤧', '🥵', '🥶', '🥴', '😵', '🤯', '🤠', '🥳', '🥸', '😎', '🤓', '🧐', '😕', '😟', '🙁', '☹️', '😮', '😯', '😲', '😳', '🥺', '😦', '😧', '😨', '😰', '😥', '😢', '😭', '😱', '😖', '😣', '😞', '😓', '😩', '😫', '🥱', '😤', '😡', '😠', '🤬', '😈', '👿', '💀', '☠️', '💩', '🤡', '👹', '👺', '👻', '👽', '👾', '🤖'] },
  { id: 'gestures', label: 'Gestures', icon: '👋', emojis: ['👋', '🤚', '🖐️', '✋', '🖖', '👌', '🤌', '🤏', '✌️', '🤞', '🤟', '🤘', '🤙', '👈', '👉', '👆', '🖕', '👇', '☝️', '👍', '👎', '✊', '👊', '🤛', '🤜', '👏', '🙌', '👐', '🤲', '🤝', '🙏', '✍️', '💅', '🤳', '💪', '🦵', '🦶', '👂', '🦻', '👃', '🫀', '🫁', '🧠', '🦷', '🦴', '👀', '👁️', '👅', '👄', '💋', '🩸'] },
  { id: 'nature', label: 'Animals & Nature', icon: '🐶', emojis: ['🐶', '🐱', '🐭', '🐹', '🐰', '🦊', '🐻', '🐼', '🐨', '🐯', '🦁', '🐮', '🐷', '🐸', '🐵', '🐔', '🐧', '🐦', '🐤', '🦆', '🦅', '🦉', '🦇', '🐺', '🐗', '🐴', '🦄', '🐝', '🐛', '🦋', '🐌', '🐞', '🐜', '🦟', '🦗', '🕷️', '🦂', '🐢', '🐍', '🦎', '🦖', '🦕', '🐙', '🦑', '🦐', '🦞', '🦀', '🐡', '🐠', '🐟', '🐬', '🐳', '🐋', '🦈', '🐊', '🐅', '🐆', '🦓', '🦍', '🦧', '🦣', '🐘', '🦛', '🦏', '🐪', '🐫', '🦒', '🦘', '🦬', '🐃', '🐂', '🐄', '🎄', '🌲', '🌳', '🌴', '🌵', '🌿', '☘️', '🍀', '🎋', '🎍', '🍃', '🍂', '🍁', '🪺', '🍄', '🌾', '💐', '🌷', '🌹', '🥀', '🌺', '🌸', '🌼', '🌻', '🌞', '🌝', '🌛', '🌜', '🌚', '🌕', '🌖', '🌗', '🌘', '🌑', '🌒', '🌓', '🌔', '🌙', '🌟', '⭐', '🌠', '🌌', '☀️', '⛅', '🌤️', '🌥️', '🌦️', '🌧️', '⛈️', '🌩️', '🌨️', '❄️', '☃️', '⛄', '🌬️', '💨', '💧', '💦', '🌊'] },
  { id: 'food', label: 'Food & Drink', icon: '🍕', emojis: ['🍕', '🍔', '🍟', '🌭', '🥪', '🌮', '🌯', '🫔', '🥙', '🧆', '🥚', '🍳', '🥘', '🍲', '🫕', '🥣', '🥗', '🍿', '🧈', '🧂', '🥫', '🍱', '🍘', '🍙', '🍚', '🍛', '🍜', '🍝', '🍠', '🍢', '🧁', '🍡', '🍧', '🍨', '🍦', '🥧', '🍰', '🎂', '🍮', '🍭', '🍬', '🍫', '🍿', '🍩', '🍪', '🌰', '🥜', '🍯', '🥛', '🍼', '☕', '🫖', '🍵', '🧃', '🥤', '🧋', '🍶', '🍺', '🍻', '🥂', '🍷', '🥃', '🍸', '🍹', '🧉', '🍾'] },
  { id: 'activity', label: 'Activity', icon: '⚽', emojis: ['⚽', '🏀', '🏈', '⚾', '🥎', '🎾', '🏐', '🏉', '🥏', '🎱', '🪀', '🏓', '🏸', '🏒', '🥍', '🏑', '🎿', '🛷', '🥌', '⛸️', '🤺', '🏇', '⛷️', '🏂', '🪂', '🏋️', '🤼', '🤸', '⛹️', '🤾', '🏌️', '🏄', '🚣', '🧗', '🚵', '🚴', '🏆', '🥇', '🥈', '🥉', '🏅', '🎖️', '🏵️', '🎗️', '🎫', '🎟️', '🎪', '🤹', '🎭', '🩰', '🎨', '🎬', '🎤', '🎧', '🎼', '🎹', '🥁', '🪘', '🎷', '🎺', '🎸', '🪕', '🎻', '🎲', '♟️', '🎯', '🎳', '🎮', '🎰', '🧩'] },
  { id: 'travel', label: 'Travel & Places', icon: '✈️', emojis: ['✈️', '🚀', '🛸', '🚁', '🛺', '🚂', '🚃', '🚄', '🚅', '🚆', '🚇', '🚈', '🚉', '🚊', '🚞', '🚝', '🚋', '🚌', '🚍', '🚎', '🐎', '🚐', '🚑', '🚒', '🚓', '🚔', '🚕', '🚖', '🚗', '🚘', '🚙', '🛻', '🚚', '🚛', '🚜', '🏎️', '🏍️', '🛵', '🦽', '🦼', '🛺', '🚲', '🛴', '🛹', '🛼', '🚏', '🛣️', '🛤️', '⛽', '🚨', '🚥', '🚦', '🛑', '🚧', '⚓', '🛟', '⛵', '🚤', '🛥️', '🛳️', '⛴️', '🚢', '🏖️', '🏝️', '🏜️', '🏕️', '🌋', '🗻', '🏔️', '🏗️', '🏘️', '🏙️', '🌃', '🌉', '🌆', '🌇', '🌁'] },
  { id: 'objects', label: 'Objects', icon: '💡', emojis: ['💡', '🔦', '🕯️', '🪔', '🧱', '💎', '🔮', '🪄', '🔭', '🔬', '🧬', '💊', '🩺', '🩹', '🧪', '🧫', '🧲', '🔋', '💿', '📀', '💾', '💻', '🖥️', '🖨️', '⌨️', '🖱️', '📱', '📲', '☎️', '📞', '📟', '📠', '📷', '📸', '📹', '🎥', '📽️', '🎞️', '📡', '📺', '📻', '🎙️', '🎚️', '🎛️', '🧭', '⏱️', '⏰', '⌚', '🔑', '🗝️', '🔐', '🔒', '🔓', '🔏', '🗄️', '🗃️', '📦', '📫', '📬', '📭', '📮', '📯', '📢', '📣', '🔔', '🔕', '📜', '📋', '📊', '📈', '📉', '📌', '📍', '📎', '🖇️', '📏', '📐', '✂️', '🗂️', '🗞️', '📰', '📓', '📔', '📒', '📕', '📗', '📘', '📙', '📚', '📖', '🔖', '🏷️', '💰', '💴', '💵', '💶', '💷', '💸', '💳', '🧾', '💹', '🔧', '🔨', '⚒️', '🛠️', '⛏️', '🪚', '🔩', '🪛', '⚙️', '🗜️', '🔗', '⛓️', '🪝', '🧰', '🪜', '🧲'] },
  { id: 'symbols', label: 'Symbols', icon: '❤️', emojis: ['❤️', '🧡', '💛', '💚', '💙', '💜', '🖤', '🤍', '🤎', '❤️‍🔥', '❤️‍🩹', '💔', '💯', '💢', '💥', '💫', '💦', '💨', '🕳️', '💬', '💭', '💤', '🔴', '🟠', '🟡', '🟢', '🔵', '🟣', '⚫', '⚪', '🟤', '🔶', '🔷', '🔸', '🔹', '🔺', '🔻', '💠', '🔘', '🔲', '🔳', '▪️', '▫️', '◾', '◽', '◼️', '◻️', '⬛', '⬜', '🔷', '🔵', '✅', '❎', '🔰', '⭕', '❌', '💲', '💱', '™️', '©️', '®️', '〰️', '➰', '➿', '🔁', '🔂', '▶️', '⏩', '⏭️', '⏯️', '◀️', '⏪', '⏮️', '🔼', '⏫', '🔽', '⏬', '⏸️', '⏹️', '⏺️', '🎵', '🎶', '🔇', '🔈', '🔉', '🔊', '📢', '📣', '📯', '🔔', '🔕', '🎼', '🎙️'] },
];

const emojiNames = {};
emojiCategories.forEach(cat => cat.emojis.forEach(e => { emojiNames[e] = cat.label.toLowerCase(); }));
const allEmojisFlat = emojiCategories.slice(1).flatMap(c => c.emojis);

// Inject emoji picker HTML into the picker element
function initEmojiPicker() {
  const picker = document.getElementById('emojiPicker');
  if (!picker) return;

  picker.innerHTML = `
    <div class="ep-search-wrap">
      <div class="ep-search-row">
        <span class="ep-search-icon">🔍</span>
        <input class="ep-search" id="epSearchInput" placeholder="Search emoji…" oninput="epSearch(this.value)" autocomplete="off"/>
      </div>
    </div>
    <div class="ep-tabs" id="epTabs"></div>
    <div class="ep-body" id="epBody"></div>
    <div class="ep-footer">
      <span class="ep-preview-emoji" id="epPreviewEmoji">😀</span>
      <span class="ep-preview-name" id="epPreviewName">:grinning:</span>
    </div>
  `;

  // Render category tabs
  const tabs = document.getElementById('epTabs');
  emojiCategories.forEach((cat, i) => {
    const btn = document.createElement('button');
    btn.className = 'ep-tab' + (i === 0 ? ' active' : '');
    btn.textContent = cat.icon;
    btn.title = cat.label;
    btn.onclick = () => epShowCategory(i, btn);
    tabs.appendChild(btn);
  });

  epShowCategory(0, tabs.children[0]);
}

function epShowCategory(idx, tabEl) {
  document.querySelectorAll('.ep-tab').forEach(t => t.classList.remove('active'));
  if (tabEl) tabEl.classList.add('active');

  const cat = idx === 0 ? getFrequentEmojis() : emojiCategories[idx];
  const body = document.getElementById('epBody');
  if (!body) return;

  if (!cat.emojis.length) {
    body.innerHTML = `<div style="text-align:center;padding:20px;color:var(--text-muted);font-size:13px;">No emojis yet</div>`;
    return;
  }

  body.innerHTML = `
    <div class="ep-section-label">${cat.label}</div>
    <div class="ep-grid" id="epGrid"></div>
  `;
  renderEmojiGrid(cat.emojis, document.getElementById('epGrid'));
}

function renderEmojiGrid(emojis, container) {
  if (!container) return;
  container.innerHTML = '';
  emojis.forEach(emoji => {
    const btn = document.createElement('button');
    btn.className = 'ep-emoji';
    btn.textContent = emoji;
    btn.onmouseenter = () => {
      const previewEl = document.getElementById('epPreviewEmoji');
      const nameEl = document.getElementById('epPreviewName');
      if (previewEl) previewEl.textContent = emoji;
      if (nameEl) nameEl.textContent = ':' + (emojiNames[emoji] || 'emoji') + ':';
    };
    btn.onclick = () => insertEmoji(emoji);
    container.appendChild(btn);
  });
}

function epSearch(query) {
  const body = document.getElementById('epBody');
  if (!body) return;
  if (!query.trim()) {
    epShowCategory(0, null);
    return;
  }
  const q = query.toLowerCase();
  const results = allEmojisFlat.filter(e => {
    const name = emojiNames[e] || '';
    return e.includes(q) || name.includes(q);
  });
  body.innerHTML = `
    <div class="ep-section-label">${results.length} result${results.length !== 1 ? 's' : ''}</div>
    <div class="ep-grid" id="epGrid"></div>
  `;
  renderEmojiGrid(results.slice(0, 72), document.getElementById('epGrid'));
}

function insertEmoji(emoji) {
  const input = document.getElementById('chatInputField');
  if (input) {
    const start = input.selectionStart ?? input.value.length;
    const end = input.selectionEnd ?? input.value.length;
    input.value = input.value.slice(0, start) + emoji + input.value.slice(end);
    input.selectionStart = input.selectionEnd = start + emoji.length;
    input.focus();
    input.dispatchEvent(new Event('input'));
  }
  // Update frequent
  emojiFreqMap[emoji] = (emojiFreqMap[emoji] || 0) + 1;
  closeEmojiPicker();
}

function getFrequentEmojis() {
  const sorted = Object.entries(emojiFreqMap)
    .sort((a, b) => b[1] - a[1])
    .slice(0, 36)
    .map(([e]) => e);
  return { id: 'frequent', label: 'Frequently Used', icon: '🕐', emojis: sorted };
}

function toggleEmojiPicker(e) {
  if (e) e.stopPropagation();
  const picker = document.getElementById('emojiPicker');
  if (!picker) return;
  const isOpen = picker.classList.contains('open');
  closeAllMenus();
  if (!isOpen) {
    if (!picker.querySelector('.ep-tabs')) initEmojiPicker();
    picker.classList.add('open');
    document.getElementById('emojiBtn')?.classList.add('active');
  }
}

function closeEmojiPicker() {
  document.getElementById('emojiPicker')?.classList.remove('open');
  document.getElementById('emojiBtn')?.classList.remove('active');
}

// Quick-reaction emoji popup (on message hover bar)
function showEmojiForMsgBar(event, btn) {
  event.stopPropagation();
  const old = document.getElementById('_quickEmojiMenu');
  if (old) old.remove();

  const quickEmojis = ['👍', '❤️', '😂', '😮', '😢', '🔥', '👏', '✅', '🤔', '🎉', '💯', '🚀'];
  const menu = document.createElement('div');
  menu.id = '_quickEmojiMenu';
  menu.style.cssText = `position:fixed;background:var(--bg-secondary);border:1px solid var(--border);border-radius:12px;padding:6px;display:flex;gap:3px;flex-wrap:wrap;z-index:2000;box-shadow:0 12px 40px rgba(0,0,0,0.6);width:176px;`;
  const rect = btn.getBoundingClientRect();
  menu.style.top = (rect.bottom + 6) + 'px';
  menu.style.left = Math.min(rect.left, window.innerWidth - 200) + 'px';

  quickEmojis.forEach(emoji => {
    const b = document.createElement('button');
    b.textContent = emoji;
    b.style.cssText = `background:none;border:none;font-size:20px;cursor:pointer;padding:3px;border-radius:6px;transition:transform 0.1s,background 0.1s;width:34px;height:34px;`;
    b.onmouseover = () => { b.style.transform = 'scale(1.3)'; b.style.background = 'rgba(255,255,255,0.1)'; };
    b.onmouseout = () => { b.style.transform = 'scale(1)'; b.style.background = ''; };
    b.onclick = (e2) => {
      e2.stopPropagation();
      const msgGroup = btn.closest('.message-group');
      const messageId = msgGroup?.dataset.msgId;
      if (messageId && window.ECOLLAB?.currentChannelId) {
        toggleReactionApi(parseInt(messageId), emoji, msgGroup);
      } else {
        addLocalReaction(msgGroup, emoji);
      }
      menu.remove();
    };
    menu.appendChild(b);
  });

  document.body.appendChild(menu);
  setTimeout(() => {
    document.addEventListener('click', function rm(e) {
      if (!menu.contains(e.target)) { menu.remove(); document.removeEventListener('click', rm); }
    });
  }, 50);
}

// Add reaction locally (UI only, for quick feedback)
function addLocalReaction(msgGroup, emoji) {
  if (!msgGroup) return;
  const reactions = msgGroup.querySelector('.reactions');
  if (!reactions) return;
  let found = false;
  reactions.querySelectorAll('.reaction').forEach(r => {
    if (r.querySelector('span')?.textContent === emoji) {
      const cnt = r.querySelector('.reaction-count');
      if (cnt) cnt.textContent = parseInt(cnt.textContent || '0') + 1;
      r.classList.add('reacted');
      found = true;
    }
  });
  if (!found) {
    const r = document.createElement('div');
    r.className = 'reaction reacted';
    r.innerHTML = `<span>${emoji}</span><span class="reaction-count">1</span>`;
    r.onclick = function () { addLocalReaction(msgGroup, emoji); };
    const addBtn = reactions.querySelector('.reaction-add');
    reactions.insertBefore(r, addBtn || null);
  }
}

async function toggleReactionApi(messageId, emoji, msgGroup) {
  try {
    const resp = await apiFetch('../../API/chat/send-message.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'reaction', message_id: messageId, emoji }),
    });
    if (resp.success) addLocalReaction(msgGroup, emoji);
  } catch {
    addLocalReaction(msgGroup, emoji);
  }
}

// Expose globally
window.initEmojiPicker = initEmojiPicker;
window.toggleEmojiPicker = toggleEmojiPicker;
window.closeEmojiPicker = closeEmojiPicker;
window.insertEmoji = insertEmoji;
window.epSearch = epSearch;
window.showEmojiForMsgBar = showEmojiForMsgBar;
window.addLocalReaction = addLocalReaction;
window.emojiCategories = emojiCategories;
