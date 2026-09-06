/* Dedicated Collabs navigation for the Chat sidebar. */
(function () {
  'use strict';

  function baseUrl() {
    return window.ECOLLAB?.baseUrl || '';
  }

  function activeServerId() {
    const params = new URLSearchParams(window.location.search);
    const fromUrl = params.get('server_id') || params.get('guild_id');
    if (fromUrl) return fromUrl;

    // Chat keeps the currently selected server on the active workspace icon.
    const activeWorkspace = document.querySelector('.workspace-icon.active[data-server-id]');
    if (activeWorkspace?.dataset.serverId) return activeWorkspace.dataset.serverId;

    // Some Chat code exposes the current server directly.
    const fromState = window.ECOLLAB?.currentServerId || window.ECOLLAB?.serverId;
    return fromState ? String(fromState) : '';
  }

  function activeChannelId() {
    const params = new URLSearchParams(window.location.search);
    const fromUrl = params.get('channel_id');
    if (fromUrl) return fromUrl;
    const fromState = window.ECOLLAB?.currentChannelId;
    return fromState ? String(fromState) : '';
  }

  function collabsUrl() {
    const url = baseUrl() + '/modules/collaboration/server-coworkspaces.php';
    const query = new URLSearchParams();
    const serverId = activeServerId();
    const channelId = activeChannelId();
    if (serverId) query.set('server_id', serverId);
    if (channelId) query.set('channel_id', channelId);
    const queryString = query.toString();
    return queryString ? url + '?' + queryString : url;
  }

  function navigateToCollabs() {
    const serverId = activeServerId();
    if (!serverId) {
      if (window.showToast) window.showToast('Select a server in Chat first', 'info');
      return;
    }
    window.location.href = collabsUrl();
  }

  function install() {
    const nav = document.querySelector('.sidebar-nav');
    if (!nav) return false;

    // The old whiteboard-channel area belongs to the dedicated Collabs workspace.
    const whiteboardSection = document.getElementById('whiteboardSection');
    if (whiteboardSection) whiteboardSection.hidden = true;

    if (!document.getElementById('dedicatedCollabsNav')) {
      const items = Array.from(nav.querySelectorAll('.sidebar-nav-item'));
      const drafts = items.find(el => /drafts/i.test(el.textContent || ''));
      const item = document.createElement('div');
      item.id = 'dedicatedCollabsNav';
      item.className = 'sidebar-nav-item ecollab-collabs-nav';
      item.setAttribute('role', 'button');
      item.tabIndex = 0;
      item.innerHTML = '<span class="nav-icon">🤝</span><span>Collabs</span>';
      item.addEventListener('click', navigateToCollabs);
      item.addEventListener('keydown', event => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          navigateToCollabs();
        }
      });
      if (drafts?.parentNode) drafts.parentNode.insertBefore(item, drafts.nextSibling);
      else nav.appendChild(item);
    }

    // If an older toolbar/button still calls the legacy hub, route it to the server-wide Collabs page.
    const oldOpen = window.openCollabHub;
    if (typeof oldOpen === 'function' && !oldOpen.__dedicatedCollabs) {
      const replacement = function () { navigateToCollabs(); };
      replacement.__dedicatedCollabs = true;
      window.openCollabHub = replacement;
    }

    if (!document.getElementById('ecollabCollabsNavStyle')) {
      const style = document.createElement('style');
      style.id = 'ecollabCollabsNavStyle';
      style.textContent = '.ecollab-collabs-nav{cursor:pointer}.ecollab-collabs-nav .nav-icon{font-size:1.05em}#whiteboardSection[hidden]{display:none!important}';
      document.head.appendChild(style);
    }
    return true;
  }

  function boot() {
    if (install()) return;
    let tries = 0;
    const timer = setInterval(() => {
      if (install() || ++tries >= 20) clearInterval(timer);
    }, 250);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
  else boot();
})();
