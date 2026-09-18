(function () {
  'use strict';

  const VIEW_KEY = 'ecollab.chat.activeView';
  const SERVER_KEY = 'ecollab.chat.activeServerId';
  const CHANNEL_KEY = 'ecollab.chat.activeChannelId';
  const VOICE_KEY = 'ecollab.chat.activeVoiceChannelId';
  const THREAD_VIEW_KEY = 'ecollab.threads.navView';

  const NAV_VIEWS = new Set(['home', 'mentions', 'bookmarks', 'threads', 'drafts']);

  function save(key, value) {
    try {
      if (value == null || value === '') localStorage.removeItem(key);
      else localStorage.setItem(key, String(value));
    } catch (_) {}
  }

  function get(key) {
    try { return localStorage.getItem(key); } catch (_) { return null; }
  }

  function findNavItem(view) {
    return Array.from(document.querySelectorAll('.sidebar-nav-item')).find(el => {
      const click = el.getAttribute('onclick') || '';
      return el.dataset.view === view ||
        click.includes("switchView('" + view + "'") ||
        click.includes('switchView("' + view + '"');
    }) || null;
  }

  function captureNavigation(event) {
    const target = event.target && event.target.closest
      ? event.target.closest('.sidebar-nav-item')
      : null;
    if (!target) return;

    const handler = target.getAttribute('onclick') || '';
    const match = handler.match(/switchView\s*\(\s*['"]([^'"]+)['"]/);
    const view = target.dataset.view || (match && match[1]);
    if (!view || !NAV_VIEWS.has(view)) return;

    save(VIEW_KEY, view);

    // threads-v2 has its own legacy restore key. Keep it synchronized so
    // leaving Threads cannot cause a later load handler to force Threads back.
    if (view === 'threads') save(THREAD_VIEW_KEY, 'threads');
    else {
      try { localStorage.removeItem(THREAD_VIEW_KEY); } catch (_) {}
      try { localStorage.removeItem('ecollab.threads.activeThread'); } catch (_) {}
    }
  }

  function captureWorkspaceAndChannel(event) {
    const target = event.target && event.target.closest ? event.target.closest('.workspace-icon, .channel-item, .voice-channel') : null;
    if (!target) return;

    if (target.matches('.workspace-icon') && target.dataset.serverId) {
      save(SERVER_KEY, target.dataset.serverId);
      return;
    }
    if (target.matches('.channel-item') && target.dataset.channelId) {
      save(CHANNEL_KEY, target.dataset.channelId);
      return;
    }
    if (target.matches('.voice-channel') && target.dataset.channelId) {
      save(VOICE_KEY, target.dataset.channelId);
    }
  }

  function restore() {
    const savedView = get(VIEW_KEY);

    // The global chat view is authoritative. Do not let the old Threads-only
    // restore state override Home, Mentions, Bookmarks, or Drafts.
    if (savedView && NAV_VIEWS.has(savedView)) {
      if (savedView !== 'threads') {
        try { localStorage.removeItem(THREAD_VIEW_KEY); } catch (_) {}
        try { localStorage.removeItem('ecollab.threads.activeThread'); } catch (_) {}
      }

      const item = findNavItem(savedView);
      if (item && !item.classList.contains('active')) item.click();
    }

    // Restore only the selected server/channel state. Voice is intentionally
    // not auto-joined after F5 because getUserMedia may require user gesture.
  }

  // Event delegation works for dynamically-created navigation elements and does
  // not depend on switchView/switchChannel being defined when this script loads.
  document.addEventListener('click', captureNavigation, true);
  document.addEventListener('click', captureWorkspaceAndChannel, true);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => setTimeout(restore, 250), { once: true });
  } else {
    setTimeout(restore, 250);
  }
})();
