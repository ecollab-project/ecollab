/** Shared self-hosted emoji picker; quick reactions keep Unicode storage. */
'use strict';
function initEmojiPicker(){window.EcollabMedia?.load().catch(()=>{});}
function toggleEmojiPicker(event){event?.stopPropagation();window.EcollabMedia?.open('emoji',document.getElementById('chatInputField'),document.getElementById('emojiBtn'));}
function closeEmojiPicker(){window.EcollabMedia?.close(false);}
function insertEmoji(emoji){window.EcollabMedia?.insertEmoji(emoji);}
function epSearch(){}
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
