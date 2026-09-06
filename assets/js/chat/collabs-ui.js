/* Dedicated Collabs navigation for the Chat sidebar. */
(function () {
  'use strict';

  function baseUrl() {
    return window.ECOLLAB?.baseUrl || '';
  }

  function collabsUrl() {
    const params = new URLSearchParams(window.location.search);
    const channelId = params.get('channel_id');
    const url = baseUrl() + '/modules/collaboration/server-coworkspaces.php';
    return channelId ? url + '?channel_id=' + encodeURIComponent(channelId) : url;
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
      item.addEventListener('click', () => { window.location.href = collabsUrl(); });
      item.addEventListener('keydown', event => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          window.location.href = collabsUrl();
        }
      });
      if (drafts?.parentNode) drafts.parentNode.insertBefore(item, drafts.nextSibling);
      else nav.appendChild(item);
    }

    // If an older toolbar/button still calls the legacy hub, route it to Collabs.
    const oldOpen = window.openCollabHub;
    if (typeof oldOpen === 'function' && !oldOpen.__dedicatedCollabs) {
      const replacement = function () { window.location.href = collabsUrl(); };
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
