/* Channel visibility UI, private-channel membership controls, and owner settings. */
(function () {
  'use strict';

  const base = () => window.ECOLLAB?.baseUrl || '';
  const csrf = () => window.ECOLLAB?.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content || '';
  const currentServer = () => Number(window.ECOLLAB?.currentServerId || new URLSearchParams(location.search).get('server_id') || 0);
  const currentChannel = () => Number(window.ECOLLAB?.currentChannelId || 0);

  async function request(url, options = {}) {
    const headers = {
      'Content-Type': 'application/json',
      'X-CSRF-Token': csrf(),
      ...(options.headers || {}),
    };
    const response = await fetch(url, { ...options, headers, credentials: 'same-origin' });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.success === false) throw new Error(data.error || 'Request failed');
    return data;
  }

  function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, c => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[c]));
  }

  function installStyles() {
    if (document.getElementById('ecollab-channel-visibility-styles')) return;
    const style = document.createElement('style');
    style.id = 'ecollab-channel-visibility-styles';
    style.textContent = `
      .channel-item,.voice-channel{position:relative;min-width:0}
      .channel-visibility-symbol{display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;width:18px;height:18px;margin-left:6px;border-radius:5px;background:rgba(15,23,42,.78);border:1px solid rgba(148,163,184,.14);font-size:10px;line-height:1;pointer-events:none}
      .channel-visibility-symbol.private{background:rgba(30,41,59,.9);border-color:rgba(148,163,184,.22)}
      .channel-visibility-symbol.public{background:rgba(15,23,42,.72)}

      #addChannelModal .modal-content,#addChannelModal .modal-box,#addChannelModal .modal-dialog{width:min(620px,calc(100vw - 28px));max-width:620px;overflow:hidden}
      #addChannelModal .cv-create{font-family:Inter,system-ui,sans-serif;color:#e5e7eb}
      #addChannelModal .cv-create *{box-sizing:border-box}
      #addChannelModal .cv-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:20px 20px 16px;border-bottom:1px solid rgba(148,163,184,.1)}
      #addChannelModal .cv-title{font-size:18px;font-weight:800;line-height:1.2;color:#f8fafc}
      #addChannelModal .cv-subtitle{margin-top:4px;color:#7f8ea5;font-size:11px;line-height:1.4}
      #addChannelModal .cv-close{border:0;background:transparent;color:#738199;font-size:22px;line-height:1;cursor:pointer;padding:0 2px}
      #addChannelModal .cv-close:hover{color:#fff}
      #addChannelModal .cv-body{padding:17px 20px 18px;max-height:min(66vh,560px);overflow:auto}
      #addChannelModal .cv-label{display:block;margin:0 0 7px;color:#8290a7;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
      #addChannelModal .cv-types{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-bottom:17px}
      #addChannelModal .channel-type-opt{min-width:0;min-height:70px;padding:10px;border:1px solid #293348;border-radius:9px;background:#111827;color:#64748b;cursor:pointer;display:flex;align-items:flex-start;gap:8px;text-align:left;transition:.15s ease}
      #addChannelModal .channel-type-opt:hover{border-color:#4b5870;background:#151d2d}
      #addChannelModal .channel-type-opt.active{border-color:rgba(168,85,247,.72)!important;background:rgba(168,85,247,.09)!important;color:#f8fafc!important;box-shadow:inset 0 0 0 1px rgba(168,85,247,.08)}
      #addChannelModal .cv-type-icon{font-size:14px;line-height:18px;flex:0 0 auto}
      #addChannelModal .cv-type-copy{min-width:0}
      #addChannelModal .cv-type-name{display:block;color:#e5e7eb;font-size:12px;font-weight:800;line-height:1.25}
      #addChannelModal .cv-type-help{display:block;margin-top:3px;color:#7f8ea5;font-size:9px;line-height:1.35}
      #addChannelModal .cv-input{width:100%;height:40px;margin:0 0 15px;padding:0 11px;border:1px solid #334155;border-radius:9px;background:#182236;color:#f8fafc;color-scheme:dark;outline:none;font:500 12px Inter,system-ui,sans-serif}
      #addChannelModal .cv-input:focus{border-color:#8b5cf6;box-shadow:0 0 0 2px rgba(139,92,246,.13)}
      #addChannelModal textarea.cv-input{height:72px;padding:10px 11px;resize:vertical}
      #addChannelModal .cv-privacy{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin:0 0 14px}
      #addChannelModal .cv-privacy-card{min-width:0;display:flex;align-items:flex-start;gap:9px;padding:11px;border:1px solid #303a4e;border-radius:9px;background:#182236;cursor:pointer}
      #addChannelModal .cv-privacy-card.selected{border-color:rgba(168,85,247,.65);background:rgba(168,85,247,.08)}
      #addChannelModal .cv-privacy-radio{width:16px;height:16px;border:1px solid #64748b;border-radius:50%;margin-top:1px;flex:0 0 auto;position:relative}
      #addChannelModal .cv-privacy-card.selected .cv-privacy-radio{border-color:#a855f7}
      #addChannelModal .cv-privacy-card.selected .cv-privacy-radio:after{content:"";position:absolute;inset:3px;border-radius:50%;background:#a855f7}
      #addChannelModal .cv-privacy-card input{position:absolute;opacity:0;pointer-events:none}
      #addChannelModal .cv-privacy-name{display:block;color:#f1f5f9;font-size:12px;font-weight:800}
      #addChannelModal .cv-privacy-help{display:block;color:#7f8ea5;font-size:9px;line-height:1.4;margin-top:3px}
      #addChannelModal .cv-members{display:none;margin-top:2px;padding:12px;border:1px solid #2c374c;border-radius:10px;background:#111827}
      #addChannelModal .cv-members.show{display:block}
      #addChannelModal .cv-member-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px}
      #addChannelModal .cv-member-title{font-size:11px;font-weight:800;color:#cbd5e1}
      #addChannelModal .cv-member-count{font-size:10px;color:#7f8ea5}
      #addChannelModal .cv-member-search{width:100%;height:34px;padding:0 10px;border:1px solid #334155;border-radius:8px;background:#0f172a;color:#e5e7eb;outline:none;font:500 11px Inter,system-ui,sans-serif;margin-bottom:7px}
      #addChannelModal .cv-member-list{max-height:150px;overflow:auto;display:grid;gap:4px}
      #addChannelModal .cv-member{display:flex;align-items:center;gap:8px;padding:7px 8px;border-radius:7px;cursor:pointer}
      #addChannelModal .cv-member:hover{background:#192338}
      #addChannelModal .cv-member.selected{background:rgba(168,85,247,.09)}
      #addChannelModal .cv-check{width:15px;height:15px;border:1px solid #536177;border-radius:4px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:10px;flex:0 0 auto}
      #addChannelModal .cv-member.selected .cv-check{background:#8b5cf6;border-color:#a855f7}
      #addChannelModal .cv-member-name{font-size:11px;color:#e2e8f0;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      #addChannelModal .cv-empty{padding:12px;text-align:center;color:#64748b;font-size:10px}
      #addChannelModal .cv-foot{display:flex;justify-content:flex-end;gap:8px;padding:14px 20px;border-top:1px solid rgba(148,163,184,.1)}
      #addChannelModal .cv-btn{height:36px;padding:0 14px;border-radius:8px;font:700 12px Inter,system-ui,sans-serif;cursor:pointer}
      #addChannelModal .cv-btn.cancel{border:1px solid #334155;background:#182236;color:#a8b4c7}
      #addChannelModal .cv-btn.create{border:1px solid rgba(168,85,247,.55);background:linear-gradient(135deg,#a855f7,#ec4899);color:#fff}

      #ecollabChannelSettingsModal{position:fixed;inset:0;z-index:130000;display:none;align-items:center;justify-content:center;background:rgba(2,6,23,.76);backdrop-filter:blur(5px);padding:14px}
      #ecollabChannelSettingsModal.open{display:flex}
      #ecollabChannelSettingsModal .csm-box{width:min(590px,100%);max-height:min(86vh,720px);overflow:hidden;background:#111827;color:#e5e7eb;border:1px solid rgba(148,163,184,.17);border-radius:16px;box-shadow:0 28px 90px rgba(0,0,0,.58);font-family:Inter,system-ui,sans-serif}
      #ecollabChannelSettingsModal .csm-head{display:flex;justify-content:space-between;gap:12px;padding:18px 20px;border-bottom:1px solid rgba(148,163,184,.1)}
      #ecollabChannelSettingsModal .csm-title{font-size:16px;font-weight:800;color:#f8fafc}.csm-sub{font-size:10px;color:#7f8ea5;margin-top:3px}
      #ecollabChannelSettingsModal .csm-close{border:0;background:none;color:#718096;font-size:22px;cursor:pointer}.csm-close:hover{color:#fff}
      #ecollabChannelSettingsModal .csm-body{padding:16px 20px;overflow:auto;max-height:58vh}
      #ecollabChannelSettingsModal .csm-section{margin-bottom:16px}.csm-section:last-child{margin-bottom:0}
      #ecollabChannelSettingsModal .csm-label{display:block;margin-bottom:7px;color:#93a0b5;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.08em}
      #ecollabChannelSettingsModal .csm-input{width:100%;height:38px;padding:0 10px;border:1px solid #334155;border-radius:8px;background:#182236;color:#f8fafc;color-scheme:dark;outline:none;font:500 12px Inter,system-ui,sans-serif}
      #ecollabChannelSettingsModal textarea.csm-input{height:68px;padding:9px;resize:vertical}
      #ecollabChannelSettingsModal .csm-visibility{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
      #ecollabChannelSettingsModal .csm-visibility button{padding:10px;text-align:left;border:1px solid #303a4e;border-radius:9px;background:#182236;color:#94a3b8;cursor:pointer}
      #ecollabChannelSettingsModal .csm-visibility button.active{border-color:rgba(168,85,247,.65);background:rgba(168,85,247,.08);color:#f8fafc}
      #ecollabChannelSettingsModal .csm-visibility strong{display:block;font-size:11px}.csm-visibility span{display:block;margin-top:3px;font-size:9px;color:#718096}
      #ecollabChannelSettingsModal .csm-members{border:1px solid #2c374c;border-radius:10px;overflow:hidden;background:#0f172a}
      #ecollabChannelSettingsModal .csm-search{width:100%;height:34px;padding:0 10px;border:0;border-bottom:1px solid #273247;background:#0f172a;color:#e5e7eb;outline:none;font:500 11px Inter,system-ui,sans-serif}
      #ecollabChannelSettingsModal .csm-list{max-height:190px;overflow:auto}.csm-user{display:flex;align-items:center;gap:8px;padding:8px 10px;border-bottom:1px solid rgba(148,163,184,.06)}
      #ecollabChannelSettingsModal .csm-user:last-child{border-bottom:0}.csm-user-name{flex:1;min-width:0;font-size:11px;color:#dbe4ef}.csm-user-state{font-size:9px;color:#64748b}.csm-remove{height:28px;padding:0 9px;border-radius:7px;border:1px solid rgba(248,113,113,.3);background:rgba(127,29,29,.12);color:#fca5a5;font:700 10px Inter,system-ui,sans-serif;cursor:pointer}.csm-grant{height:28px;padding:0 9px;border-radius:7px;border:1px solid #3b465a;background:#172033;color:#cbd5e1;font:700 10px Inter,system-ui,sans-serif;cursor:pointer}.csm-grant:hover{background:#202b3e}.csm-remove:hover{background:rgba(127,29,29,.25)}
      #ecollabChannelSettingsModal .csm-invite{display:flex;gap:7px}.csm-invite input{flex:1;min-width:0}.csm-invite button{height:38px;padding:0 12px;border-radius:8px;border:1px solid #3b465a;background:#172033;color:#dbe4ef;font:700 11px Inter,system-ui,sans-serif;cursor:pointer;white-space:nowrap}
      #ecollabChannelSettingsModal .csm-invite button:hover{background:#202b3e}.csm-note{font-size:9px;color:#64748b;margin-top:5px}.csm-error{padding:8px 10px;border:1px solid rgba(248,113,113,.3);border-radius:8px;background:rgba(127,29,29,.13);color:#fca5a5;font-size:10px;margin-bottom:10px}.csm-foot{display:flex;justify-content:flex-end;gap:8px;padding:13px 20px;border-top:1px solid rgba(148,163,184,.1)}.csm-btn{height:36px;padding:0 14px;border-radius:8px;font:700 11px Inter,system-ui,sans-serif;cursor:pointer}.csm-btn.cancel{border:1px solid #334155;background:#182236;color:#a8b4c7}.csm-btn.save{border:1px solid rgba(168,85,247,.55);background:linear-gradient(135deg,#a855f7,#ec4899);color:#fff}
      @media(max-width:620px){#addChannelModal .cv-types{grid-template-columns:1fr}.#addChannelModal .cv-privacy,#ecollabChannelSettingsModal .csm-visibility{grid-template-columns:1fr}}
    `;
    document.head.appendChild(style);
  }

  function rebuildCreateModal() {
    const modal = document.getElementById('addChannelModal');
    if (!modal || modal.dataset.cvRebuilt === '1') return !!modal;
    modal.dataset.cvRebuilt = '1';
    modal.innerHTML = `
      <div class="modal-content cv-create">
        <div class="cv-head">
          <div><div class="cv-title">Create Channel</div><div class="cv-subtitle">Choose a channel type and who can access it.</div></div>
          <button type="button" class="cv-close" aria-label="Close" onclick="closeModal('addChannelModal')">×</button>
        </div>
        <div class="cv-body">
          <div class="cv-label">Channel type</div>
          <div class="cv-types">
            <button type="button" class="channel-type-opt active" data-type="text" onclick="selectChannelType(this,'text')"><span class="cv-type-icon">#</span><span class="cv-type-copy"><span class="cv-type-name">Text</span><span class="cv-type-help">Messages, files &amp; links</span></span></button>
            <button type="button" class="channel-type-opt" data-type="voice" onclick="selectChannelType(this,'voice')"><span class="cv-type-icon">🔊</span><span class="cv-type-copy"><span class="cv-type-name">Voice</span><span class="cv-type-help">Voice &amp; video hangout</span></span></button>
            <button type="button" class="channel-type-opt" data-type="whiteboard" onclick="selectChannelType(this,'whiteboard')"><span class="cv-type-icon">✏️</span><span class="cv-type-copy"><span class="cv-type-name">Whiteboard</span><span class="cv-type-help">Collaborate on a canvas</span></span></button>
          </div>

          <div class="cv-label">Channel name</div>
          <input id="newChannelName" class="cv-input" type="text" maxlength="60" placeholder="new-channel" autocomplete="off">

          <div class="cv-label">Description (optional)</div>
          <textarea id="newChannelDesc" class="cv-input" maxlength="255" placeholder="What's this channel about?"></textarea>

          <div class="cv-label">Channel access</div>
          <div class="cv-privacy">
            <label class="cv-privacy-card selected" data-privacy="public"><input type="radio" name="cvChannelPrivacy" value="0" checked><span class="cv-privacy-radio"></span><span><span class="cv-privacy-name">🌐 Public Channel</span><span class="cv-privacy-help">Anyone on the same server can access it.</span></span></label>
            <label class="cv-privacy-card" data-privacy="private"><input type="radio" name="cvChannelPrivacy" value="1"><span class="cv-privacy-radio"></span><span><span class="cv-privacy-name">🔒 Private Channel</span><span class="cv-privacy-help">Only selected server members can access it.</span></span></label>
          </div>

          <div class="cv-members" id="cvCreateMembers">
            <div class="cv-member-head"><span class="cv-member-title">Select members</span><span class="cv-member-count" id="cvSelectedCount">0 selected</span></div>
            <input id="cvMemberSearch" class="cv-member-search" type="search" placeholder="Search server members…" autocomplete="off">
            <div id="cvMemberList" class="cv-member-list"><div class="cv-empty">Loading server members…</div></div>
          </div>
        </div>
        <div class="cv-foot"><button type="button" class="cv-btn cancel" onclick="closeModal('addChannelModal')">Cancel</button><button type="button" class="cv-btn create" onclick="createChannel()">Create Channel</button></div>
      </div>`;

    window._privateChannelSelectedUsers = new Set();
    modal.querySelectorAll('input[name="cvChannelPrivacy"]').forEach(input => {
      input.addEventListener('change', () => setCreatePrivacy(input.value === '1'));
    });
    modal.querySelector('#cvMemberSearch').addEventListener('input', e => filterCreateMembers(e.target.value));
    return true;
  }

  let createMembers = [];
  function setCreatePrivacy(isPrivate) {
    const modal = document.getElementById('addChannelModal');
    if (!modal) return;
    modal.querySelectorAll('.cv-privacy-card').forEach(card => card.classList.toggle('selected', card.dataset.privacy === (isPrivate ? 'private' : 'public')));
    const members = modal.querySelector('#cvCreateMembers');
    if (members) members.classList.toggle('show', isPrivate);
    if (isPrivate) loadCreateMembers();
  }

  async function loadCreateMembers() {
    const list = document.getElementById('cvMemberList');
    if (!list) return;
    const serverId = currentServer();
    if (!serverId) { list.innerHTML = '<div class="cv-empty">Select a server first.</div>'; return; }
    try {
      const data = await request(`${base()}/API/server/members.php?action=list&server_id=${encodeURIComponent(serverId)}`);
      createMembers = (data.members || []).filter(m => Number(m.id) !== Number(window.ECOLLAB?.userId));
      renderCreateMembers();
    } catch (e) {
      list.innerHTML = `<div class="cv-empty">${esc(e.message)}</div>`;
    }
  }

  function renderCreateMembers() {
    const modal = document.getElementById('addChannelModal');
    const list = modal?.querySelector('#cvMemberList');
    if (!list) return;
    const query = (modal.querySelector('#cvMemberSearch')?.value || '').trim().toLowerCase();
    const selected = window._privateChannelSelectedUsers || new Set();
    const filtered = createMembers.filter(m => `${m.full_name || ''} ${m.username || ''}`.toLowerCase().includes(query));
    if (!filtered.length) { list.innerHTML = '<div class="cv-empty">No matching server members.</div>'; return; }
    list.innerHTML = filtered.map(m => {
      const id = Number(m.id);
      const active = selected.has(id);
      return `<div class="cv-member${active ? ' selected' : ''}" data-user-id="${id}" role="button" tabindex="0">
        <span class="cv-check">${active ? '✓' : ''}</span><span class="cv-member-name">${esc(m.full_name || m.username)} <span style="color:#64748b">@${esc(m.username || '')}</span></span>
      </div>`;
    }).join('');
    list.querySelectorAll('.cv-member').forEach(row => {
      const toggle = () => {
        const id = Number(row.dataset.userId);
        const set = window._privateChannelSelectedUsers || (window._privateChannelSelectedUsers = new Set());
        if (set.has(id)) set.delete(id); else set.add(id);
        renderCreateMembers();
        updateSelectedCount();
      };
      row.addEventListener('click', toggle);
      row.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); } });
    });
    updateSelectedCount();
  }

  function filterCreateMembers() { renderCreateMembers(); }
  function updateSelectedCount() {
    const el = document.getElementById('cvSelectedCount');
    if (el) {
      const n = window._privateChannelSelectedUsers?.size || 0;
      el.textContent = `${n} selected`;
    }
  }

  function prepareCreateModal() {
    rebuildCreateModal();
    window._privateChannelSelectedUsers = new Set();
    const modal = document.getElementById('addChannelModal');
    if (!modal) return;
    modal.querySelector('#newChannelName').value = '';
    modal.querySelector('#newChannelDesc').value = '';
    modal.querySelector('#cvMemberSearch').value = '';
    modal.querySelector('input[value="0"]').checked = true;
    setCreatePrivacy(false);
    const type = window.__ecollabRequestedChannelType || 'text';
    modal.querySelectorAll('.channel-type-opt').forEach(opt => {
      const active = opt.dataset.type === type;
      opt.classList.toggle('active', active);
      opt.style.background = active ? 'rgba(168,85,247,0.09)' : '';
      opt.style.borderColor = active ? 'rgba(168,85,247,0.72)' : '';
    });
  }

  function hookCreateModal() {
    const modal = document.getElementById('addChannelModal');
    if (!modal || modal.dataset.cvHooked === '1') return !!modal;
    modal.dataset.cvHooked = '1';
    rebuildCreateModal();
    return true;
  }

  function installCreateInterceptor() {
    if (window.__ecollabChannelVisibilityCreateHook) return;
    window.__ecollabChannelVisibilityCreateHook = true;
    const original = window.openAddChannelModal;
    window.openAddChannelModal = function (defaultType = 'text') {
      window.__ecollabRequestedChannelType = defaultType;
      prepareCreateModal();
      if (typeof window.openModal === 'function') window.openModal('addChannelModal');
      else {
        const modal = document.getElementById('addChannelModal');
        if (modal) modal.style.display = 'flex';
      }
    };
    // Keep a fallback for pages where the original function is not exported yet.
    if (typeof original !== 'function') window.__originalOpenAddChannelModal = original;
  }

  async function refreshChannelSymbols() {
    const serverId = currentServer();
    if (!serverId) return;
    try {
      const data = await request(`${base()}/API/chat/get-channels.php?server_id=${encodeURIComponent(serverId)}`);
      const byId = new Map((data.channels || []).map(ch => [String(ch.id), ch]));
      document.querySelectorAll('.channel-item[data-channel-id],.voice-channel[data-channel-id]').forEach(el => {
        const ch = byId.get(String(el.dataset.channelId));
        if (!ch) return;
        const isPrivate = Number(ch.is_private) === 1;
        el.dataset.channelVisibility = isPrivate ? 'private' : 'public';
        let badge = el.querySelector('.channel-visibility-symbol');
        if (!badge) {
          badge = document.createElement('span');
          badge.className = 'channel-visibility-symbol';
          badge.setAttribute('aria-hidden', 'true');
          el.appendChild(badge);
        }
        badge.classList.toggle('private', isPrivate);
        badge.classList.toggle('public', !isPrivate);
        badge.textContent = isPrivate ? '🔒' : '🌐';
        badge.title = isPrivate ? 'Private channel' : 'Public channel';
      });
    } catch (_) { /* decorative state must never block chat */ }
  }

  async function openSettings() {
    const channelId = currentChannel();
    if (!channelId) { window.showToast?.('Open a channel first', 'info'); return; }
    let modal = document.getElementById('ecollabChannelSettingsModal');
    if (!modal) modal = buildSettingsModal();
    modal.classList.add('open');
    modal.dataset.channelId = String(channelId);
    await loadSettings(channelId);
  }

  function buildSettingsModal() {
    const modal = document.createElement('div');
    modal.id = 'ecollabChannelSettingsModal';
    modal.innerHTML = `
      <div class="csm-box" role="dialog" aria-modal="true" aria-labelledby="csmTitle">
        <div class="csm-head"><div><div class="csm-title" id="csmTitle">Channel Settings</div><div class="csm-sub">Manage privacy and access for this channel.</div></div><button class="csm-close" type="button" aria-label="Close">×</button></div>
        <div class="csm-body">
          <div id="csmError" class="csm-error" hidden></div>
          <div class="csm-section"><label class="csm-label">Channel name</label><input id="csmName" class="csm-input" maxlength="60"></div>
          <div class="csm-section"><label class="csm-label">Description</label><textarea id="csmDescription" class="csm-input" maxlength="255"></textarea></div>
          <div class="csm-section"><label class="csm-label">Channel access</label><div class="csm-visibility"><button type="button" data-value="0"><strong>🌐 Public</strong><span>Anyone on the same server can access it.</span></button><button type="button" data-value="1"><strong>🔒 Private</strong><span>Only selected server members can access it.</span></button></div></div>
          <div class="csm-section"><label class="csm-label">Members with private access</label><div class="csm-members"><input id="csmSearch" class="csm-search" placeholder="Search server members…"><div id="csmList" class="csm-list"><div class="cv-empty">Loading…</div></div></div><div class="csm-note">Server owners, admins and moderators can manage the channel. The channel creator always retains access.</div></div>
          <div class="csm-section"><label class="csm-label">Invite link</label><div class="csm-invite"><input id="csmInvite" class="csm-input" readonly placeholder="Generate a channel invite"><button type="button" id="csmGenerateInvite">Generate</button></div><div class="csm-note">Invite links can grant access to this private channel and automatically add the recipient to the server if needed.</div></div>
        </div>
        <div class="csm-foot"><button type="button" class="csm-btn cancel" id="csmCancel">Close</button><button type="button" class="csm-btn save" id="csmSave">Save Changes</button></div>
      </div>`;
    document.body.appendChild(modal);
    modal.querySelector('.csm-close').onclick = () => modal.classList.remove('open');
    modal.querySelector('#csmCancel').onclick = () => modal.classList.remove('open');
    modal.addEventListener('click', e => { if (e.target === modal) modal.classList.remove('open'); });
    modal.querySelectorAll('.csm-visibility button').forEach(btn => btn.addEventListener('click', () => setSettingsVisibility(btn.dataset.value === '1')));
    modal.querySelector('#csmSearch').addEventListener('input', renderSettingsMembers);
    modal.querySelector('#csmSave').onclick = saveSettings;
    modal.querySelector('#csmGenerateInvite').onclick = generateInvite;
    return modal;
  }

  let settingsMembers = [];
  let settingsChannel = null;

  async function loadSettings(channelId) {
    const modal = document.getElementById('ecollabChannelSettingsModal');
    const error = modal.querySelector('#csmError');
    error.hidden = true;
    try {
      const [settings, members] = await Promise.all([
        request(`${base()}/API/chat/channel-settings.php?channel_id=${channelId}`),
        request(`${base()}/API/chat/channel-members.php?channel_id=${channelId}`),
      ]);
      settingsChannel = settings.channel;
      settingsMembers = members.members || [];
      modal.querySelector('#csmName').value = settings.channel.name || '';
      modal.querySelector('#csmDescription').value = settings.channel.description || '';
      setSettingsVisibility(Number(settings.channel.is_private) === 1);
      renderSettingsMembers();
      modal.querySelector('#csmInvite').value = '';
    } catch (e) {
      error.textContent = e.message || 'Unable to load channel settings.';
      error.hidden = false;
    }
  }

  function setSettingsVisibility(isPrivate) {
    const modal = document.getElementById('ecollabChannelSettingsModal');
    modal?.querySelectorAll('.csm-visibility button').forEach(btn => btn.classList.toggle('active', btn.dataset.value === (isPrivate ? '1' : '0')));
  }

  function renderSettingsMembers() {
    const modal = document.getElementById('ecollabChannelSettingsModal');
    const list = modal?.querySelector('#csmList');
    if (!list) return;
    const q = (modal.querySelector('#csmSearch')?.value || '').trim().toLowerCase();
    const rows = settingsMembers.filter(m => `${m.full_name || ''} ${m.username || ''}`.toLowerCase().includes(q));
    if (!rows.length) { list.innerHTML = '<div class="cv-empty">No matching server members.</div>'; return; }
    list.innerHTML = rows.map(m => {
      const hasAccess = Number(m.has_access) === 1;
      const name = esc(m.full_name || m.username);
      const username = esc(m.username || '');
      return `<div class="csm-user"><div class="csm-user-name">${name} <span style="color:#64748b">@${username}</span></div><div class="csm-user-state">${hasAccess ? 'Access' : 'No access'}</div>${hasAccess ? `<button class="csm-remove" data-user-id="${Number(m.id)}">Remove</button>` : `<button class="csm-grant" data-user-id="${Number(m.id)}">Grant</button>`}</div>`;
    }).join('');
    list.querySelectorAll('.csm-remove').forEach(btn => btn.onclick = () => changeMemberAccess(Number(btn.dataset.userId), false));
    list.querySelectorAll('.csm-grant').forEach(btn => btn.onclick = () => changeMemberAccess(Number(btn.dataset.userId), true));
  }

  async function changeMemberAccess(userId, grant) {
    const channelId = Number(document.getElementById('ecollabChannelSettingsModal')?.dataset.channelId || 0);
    if (!channelId) return;
    try {
      await request(`${base()}/API/chat/channel-members.php`, {
        method: 'POST',
        body: JSON.stringify({ action: grant ? 'add' : 'remove', channel_id: channelId, user_id: userId }),
      });
      const row = settingsMembers.find(m => Number(m.id) === userId);
      if (row) row.has_access = grant ? 1 : 0;
      renderSettingsMembers();
      refreshChannelSymbols();
    } catch (e) { window.showToast?.(e.message || 'Unable to change channel access', 'info'); }
  }

  async function saveSettings() {
    const modal = document.getElementById('ecollabChannelSettingsModal');
    const channelId = Number(modal?.dataset.channelId || 0);
    if (!channelId) return;
    const isPrivate = modal.querySelector('.csm-visibility button.active')?.dataset.value === '1';
    const button = modal.querySelector('#csmSave');
    const error = modal.querySelector('#csmError');
    error.hidden = true;
    button.disabled = true;
    try {
      await request(`${base()}/API/chat/channel-settings.php`, {
        method: 'POST',
        body: JSON.stringify({
          channel_id: channelId,
          name: modal.querySelector('#csmName').value.trim(),
          description: modal.querySelector('#csmDescription').value.trim(),
          is_private: isPrivate ? 1 : 0,
        }),
      });
      modal.classList.remove('open');
      window.showToast?.('Channel settings saved', 'success');
      refreshChannelSymbols();
      if (typeof window.loadServerChannels === 'function') window.loadServerChannels(currentServer());
      else location.reload();
    } catch (e) {
      error.textContent = e.message || 'Unable to save channel settings.';
      error.hidden = false;
    } finally { button.disabled = false; }
  }

  async function generateInvite() {
    const modal = document.getElementById('ecollabChannelSettingsModal');
    const channelId = Number(modal?.dataset.channelId || 0);
    if (!channelId) return;
    const button = modal.querySelector('#csmGenerateInvite');
    button.disabled = true;
    try {
      const data = await request(`${base()}/API/chat/channel-invite.php?action=create`, {
        method: 'POST',
        body: JSON.stringify({ channel_id: channelId, max_uses: 0, expires_hours: 24 }),
      });
      modal.querySelector('#csmInvite').value = data.invite?.invite_url || '';
    } catch (e) { window.showToast?.(e.message || 'Unable to generate invite', 'info'); }
    finally { button.disabled = false; }
  }

  function installManageButton() {
    const button = document.getElementById('manageChannelBtn');
    if (!button || button.dataset.cvHooked === '1') return;
    button.dataset.cvHooked = '1';
    button.addEventListener('click', e => {
      e.preventDefault();
      e.stopImmediatePropagation();
      openSettings();
    }, true);
  }

  function boot() {
    installStyles();
    hookCreateModal();
    installCreateInterceptor();
    installManageButton();
    refreshChannelSymbols();
    const observer = new MutationObserver(() => {
      installManageButton();
      if (document.getElementById('addChannelModal')) hookCreateModal();
      refreshChannelSymbols();
    });
    observer.observe(document.body, { childList: true, subtree: true });
  }

  window.openChannelSettings = openSettings;
  window.__ecollabChannelVisibilityBoot = boot;

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
  else boot();
})();
