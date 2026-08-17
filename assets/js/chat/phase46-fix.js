/**
 * Ecollab Phase 4.6 hotfix
 *
 * Keeps the selected channel id available to every Phase 4.6 action.
 * Also disables the legacy private-channel modal so it cannot call the
 * channel-access API without a channel_id.
 */
(function () {
  'use strict';

  function ensureEcollab() {
    window.ECOLLAB = window.ECOLLAB || {};
    return window.ECOLLAB;
  }

  function readChannelId() {
    const e = ensureEcollab();
    const candidates = [
      e.currentChannelId,
      window._currentChannelMeta && window._currentChannelMeta.id,
      window.currentChannelId,
      window.activeChannelId,
      window.selectedChannelId,
      window.currentChannel && window.currentChannel.id,
      document.querySelector('#channelList .channel-item.active[data-channel-id]')?.dataset.channelId,
      document.querySelector('.channel-item.active[data-channel-id]')?.dataset.channelId,
      document.querySelector('.voice-channel.active[data-channel-id]')?.dataset.channelId
    ];

    for (const value of candidates) {
      const id = Number(value || 0);
      if (Number.isInteger(id) && id > 0) return id;
    }
    return 0;
  }

  function readChannelName(id) {
    const el = document.querySelector(
      `.channel-item[data-channel-id="${CSS.escape(String(id))}"]`
    );
    return el?.dataset.channelName || window._currentChannelMeta?.name || document.getElementById('channelTitle')?.textContent || '';
  }

  function syncChannelContext(id) {
    id = Number(id || 0);
    if (!id) return 0;

    const e = ensureEcollab();
    e.currentChannelId = id;
    window.currentChannelId = id;
    window.activeChannelId = id;

    const name = readChannelName(id);
    window._currentChannelMeta = Object.assign({}, window._currentChannelMeta || {}, {
      id,
      name
    });

    return id;
  }

  function syncFromDom() {
    return syncChannelContext(readChannelId());
  }

  function hideLegacyManager() {
    const legacy = document.getElementById('privateChannelManagerModal');
    if (legacy) {
      legacy.style.display = 'none';
      legacy.setAttribute('aria-hidden', 'true');
    }
  }

  function installChannelTracking() {
    syncFromDom();

    document.addEventListener('click', function (event) {
      const item = event.target.closest?.('[data-channel-id]');
      if (!item) return;
      const id = Number(item.dataset.channelId || 0);
      if (id) syncChannelContext(id);
    }, true);

    const observer = new MutationObserver(function () {
      hideLegacyManager();
      syncFromDom();
    });
    observer.observe(document.body, {
      subtree: true,
      childList: true,
      attributes: true,
      attributeFilter: ['class', 'data-channel-id', 'data-channel-name']
    });
  }

  function installManagerBridge() {
    // The new Phase 4.6 manager is the only manager allowed to open.
    // Keep the legacy global name because the existing header button uses it.
    const bridge = function () {
      hideLegacyManager();
      const id = syncFromDom();
      if (!id) {
        const title = document.getElementById('channelTitle')?.textContent || 'this channel';
        if (typeof window.showToast === 'function') {
          window.showToast(`Could not determine the channel id for ${title}. Select the channel again.`, 'error');
        }
        return;
      }
      if (typeof window.openChannelManager === 'function') {
        window.openChannelManager('Members');
      }
    };

    window.openPrivateChannelManager = bridge;
  }

  function boot() {
    hideLegacyManager();
    installChannelTracking();
    installManagerBridge();

    // server-channel-management.js is deferred too; re-apply the bridge after
    // it has initialized so the old modal can never take control again.
    setTimeout(function () {
      hideLegacyManager();
      installManagerBridge();
      syncFromDom();
    }, 0);
    setTimeout(function () {
      hideLegacyManager();
      installManagerBridge();
      syncFromDom();
    }, 100);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();
