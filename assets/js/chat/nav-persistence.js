(function () {
  'use strict';

  const VIEW_KEY = 'ecollab.chat.activeView';
  const SERVER_KEY = 'ecollab.chat.activeServerId';
  const CHANNEL_KEY = 'ecollab.chat.activeChannelId';
  const VOICE_KEY = 'ecollab.chat.activeVoiceChannelId';

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

  function findServerIcon(serverId) {
    return Array.from(document.querySelectorAll('.workspace-icon')).find(el =>
      String(el.dataset.serverId || '') === String(serverId)
    ) || null;
  }

  function findChannel(channelId) {
    return Array.from(document.querySelectorAll('.channel-item')).find(el =>
      String(el.dataset.channelId || '') === String(channelId)
    ) || null;
  }

  function findVoiceChannel(channelId) {
    return Array.from(document.querySelectorAll('.voice-channel')).find(el =>
      String(el.dataset.channelId || '') === String(channelId)
    ) || null;
  }

  function install() {
    // switchView() is defined by chat-features.js. This file is loaded last
    // so we can wrap the final implementation instead of racing another script.
    if (typeof window.switchView !== 'function') return false;

    if (!window.__ecollabNavPersistenceInstalled) {
      const originalSwitchView = window.switchView;
      window.switchView = function (viewName, el) {
        save(VIEW_KEY, viewName || 'home');
        return originalSwitchView.apply(this, arguments);
      };
      window.__ecollabNavPersistenceInstalled = true;
    }

    if (typeof window.switchChannel === 'function' && !window.__ecollabChannelPersistenceInstalled) {
      const originalSwitchChannel = window.switchChannel;
      window.switchChannel = function (el, channelId) {
        save(CHANNEL_KEY, channelId);
        return originalSwitchChannel.apply(this, arguments);
      };
      window.__ecollabChannelPersistenceInstalled = true;
    }

    if (typeof window.switchWorkspace === 'function' && !window.__ecollabWorkspacePersistenceInstalled) {
      const originalSwitchWorkspace = window.switchWorkspace;
      window.switchWorkspace = function (wsIdx, serverId) {
        save(SERVER_KEY, serverId);
        return originalSwitchWorkspace.apply(this, arguments);
      };
      window.__ecollabWorkspacePersistenceInstalled = true;
    }

    if (typeof window.joinVoice === 'function' && !window.__ecollabVoicePersistenceInstalled) {
      const originalJoinVoice = window.joinVoice;
      window.joinVoice = function (slug, el, channelId) {
        save(VOICE_KEY, channelId);
        return originalJoinVoice.apply(this, arguments);
      };
      window.__ecollabVoicePersistenceInstalled = true;
    }

    return true;
  }

  function restore() {
    if (!install()) return;

    const savedServer = get(SERVER_KEY);
    const savedChannel = get(CHANNEL_KEY);
    const savedView = get(VIEW_KEY);

    // Restore server first. loadServerChannels() is async, so channel restore
    // is retried after the server/channel DOM has had time to populate.
    if (savedServer && typeof window.switchWorkspace === 'function') {
      const icon = findServerIcon(savedServer);
      if (icon) {
        const icons = Array.from(document.querySelectorAll('.workspace-icon'));
        window.switchWorkspace(icons.indexOf(icon), Number(savedServer));
      }
    }

    if (savedChannel) {
      let tries = 0;
      const restoreChannel = () => {
        const channel = findChannel(savedChannel);
        if (channel && typeof window.switchChannel === 'function') {
          window.switchChannel(channel, Number(savedChannel));
          return true;
        }
        if (++tries < 30) {
          setTimeout(restoreChannel, 150);
        }
        return false;
      };
      restoreChannel();
    }

    if (savedView && NAV_VIEWS.has(savedView) && typeof window.switchView === 'function') {
      const item = findNavItem(savedView);
      window.switchView(savedView, item);
    }

    // Voice calls are deliberately not auto-joined after F5. Browsers may
    // require a fresh media permission/user gesture. The selected voice
    // channel remains saved for future restoration/UI work.
  }

  // Run after all deferred Chat scripts and DOM initialization have settled.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => setTimeout(restore, 0), { once: true });
  } else {
    setTimeout(restore, 0);
  }
})();
