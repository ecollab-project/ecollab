/* Per-user, per-chat message preferences and conversation details. */
(() => {
  'use strict';
  let current = null;
  const recent = new Map();
  const escape = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
  const key = (type, id) => `ecollab.dm.preferences.${window.ECOLLAB?.userId || 0}.${type}.${id}`;
  function preferences(type, id) {
    try { return { alerts: true, timestamps: true, compact: false, ...JSON.parse(localStorage.getItem(key(type,id)) || '{}') }; }
    catch (_) { return { alerts: true, timestamps: true, compact: false }; }
  }
  function apply() {
    if (!current) return;
    const prefs = preferences(current.type, current.id);
    const panel = document.getElementById('dmConversationPanel');
    panel?.classList.toggle('dm-compact-messages', prefs.compact);
    panel?.classList.toggle('dm-hide-timestamps', !prefs.timestamps);
  }
  function opened(chat) {
    const dialog = document.getElementById('dmChatSettingsDialog');
    if (dialog?.open) dialog.close();
    current = chat;
    apply();
    const title = document.getElementById('dmPanelTitle');
    if (title && !title.querySelector('.dm-chat-settings-button')) {
      const button = document.createElement('button');
      button.type = 'button'; button.className = 'dm-chat-settings-button';
      button.title = chat.type === 'group' ? 'Group chat settings' : 'Message settings';
      button.setAttribute('aria-label', button.title);
      button.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="m9 3-1 3-3 1-1 3 2 2-2 2 1 3 3 1 1 3h6l1-3 3-1 1-3-2-2 2-2-1-3-3-1-1-3Z"/></svg>';
      button.addEventListener('click', event => { event.stopPropagation(); open(); });
      title.appendChild(button);
    }
  }
  function record(messages, replace = false) {
    if (!current) return;
    const chatKey = key(current.type,current.id);
    const map = replace ? new Map() : recent.get(chatKey) || new Map();
    messages.forEach(message => { const id = message.id ?? message.message_id; if (id != null) map.set(String(id), { ...message, id }); });
    while (map.size > 100) map.delete(map.keys().next().value);
    recent.set(chatKey,map);
  }
  function dialogElement() {
    let dialog = document.getElementById('dmChatSettingsDialog');
    if (dialog) return dialog;
    dialog = document.createElement('dialog'); dialog.id = 'dmChatSettingsDialog'; dialog.className = 'dm-chat-settings-dialog';
    dialog.setAttribute('aria-labelledby','dmSettingsTitle');
    dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
    document.body.appendChild(dialog);
    return dialog;
  }
  function open() {
    if (!current) return;
    const dialog = dialogElement();
    const prefs = preferences(current.type,current.id);
    dialog.innerHTML = `<header><div><h2 id="dmSettingsTitle">${current.type === 'group' ? 'Group chat settings' : 'Message settings'}</h2><p>${escape(current.name)}</p></div><button type="button" data-close aria-label="Close settings">×</button></header>
      <div class="dm-settings-content">
        <section><h3>Conversation</h3><div class="dm-settings-actions"><button type="button" data-details>${current.type === 'group' ? 'View members' : 'View profile'}</button><button type="button" data-files>Recent shared files</button></div><div id="dmSettingsDetails"></div></section>
        <section><h3>Search recent messages</h3><p>Search messages loaded in this chat.</p><input type="search" id="dmSettingsSearch" placeholder="Search messages…" aria-label="Search recent messages"><div id="dmSettingsResults" aria-live="polite"></div></section>
        <section><h3>Preferences</h3><p>Saved for this chat on this browser.</p>
          <label><span>Message alerts <small>Toasts and desktop alerts; notification history stays available.</small></span><input type="checkbox" data-pref="alerts" ${prefs.alerts ? 'checked' : ''}></label>
          <label><span>Show message timestamps</span><input type="checkbox" data-pref="timestamps" ${prefs.timestamps ? 'checked' : ''}></label>
          <label><span>Compact message layout</span><input type="checkbox" data-pref="compact" ${prefs.compact ? 'checked' : ''}></label>
        </section>
      </div><footer><button type="button" data-close>Done</button></footer>`;
    dialog.querySelectorAll('[data-close]').forEach(button => button.addEventListener('click',()=>dialog.close()));
    dialog.querySelectorAll('[data-pref]').forEach(input => input.addEventListener('change', () => {
      const next = preferences(current.type,current.id); next[input.dataset.pref] = input.checked;
      try { localStorage.setItem(key(current.type,current.id), JSON.stringify(next)); }
      catch (_) { window.showToast?.('Could not save this preference.', 'error'); return; }
      apply();
    }));
    dialog.querySelector('[data-details]').addEventListener('click', event => {
      const details = dialog.querySelector('#dmSettingsDetails');
      if (current.type === 'group') {
        details.innerHTML = `<h4>Members (${current.members?.length || 0})</h4>` + (current.members || []).map(member => `<div class="dm-settings-member">${window.chatSidebarAvatar?.(member.full_name || member.username,member.avatar_url,member.avatar_color_gradient,30) || ''}<span>${escape(member.full_name || member.username)}${Number(member.id) === Number(window.ECOLLAB?.userId) ? ' (You)' : ''}</span></div>`).join('');
      } else {
        const chat = current; dialog.close();
        window.openMiniProfile?.(event, chat.name, '', '', chat.name.charAt(0).toUpperCase(), Number(chat.id));
      }
    });
    dialog.querySelector('[data-files]').addEventListener('click',()=>{
      const files = [...(recent.get(key(current.type,current.id))?.values() || [])].filter(message=>message.attachment_path);
      dialog.querySelector('#dmSettingsDetails').innerHTML = '<h4>Recent shared files</h4>' + (files.map(message=>{
        const path = String(message.attachment_path).replace(/^\//,'');
        const url = (window.ECOLLAB?.baseUrl || '') + '/' + path;
        return `<a class="dm-settings-file" href="${escape(url)}" target="_blank" rel="noopener">📎 ${escape(message.attachment_name || 'Attachment')}</a>`;
      }).join('') || '<p>No shared files in the loaded messages.</p>');
    });
    dialog.querySelector('#dmSettingsSearch').addEventListener('input',event=>{
      const query=event.target.value.trim().toLowerCase();const results=dialog.querySelector('#dmSettingsResults');
      const matches=query ? [...(recent.get(key(current.type,current.id))?.values() || [])].filter(message=>`${message.body || ''} ${message.attachment_name || ''}`.toLowerCase().includes(query)).slice(-20) : [];
      results.innerHTML = matches.map(message=>`<button type="button" data-message-id="${escape(message.id)}">${escape(message.body || message.attachment_name || 'Attachment')}</button>`).join('') || (query ? '<p>No matches in the loaded messages.</p>' : '');
      results.querySelectorAll('[data-message-id]').forEach(button=>button.addEventListener('click',()=>{
        const row=document.querySelector(`#dmMessagesArea [data-msg-id="${CSS.escape(button.dataset.messageId)}"]`);
        dialog.close();row?.scrollIntoView({block:'center',behavior:'smooth'});row?.classList.add('dm-message-highlight');setTimeout(()=>row?.classList.remove('dm-message-highlight'),2000);
      }));
    });
    if (!dialog.open) dialog.showModal();
  }
  window.EcollabDmSettings = { opened, record, alertsEnabled: (type,id) => preferences(type,id).alerts !== false };
})();
