/* Final create-channel bridge: maps the new privacy UI to the existing createChannel flow. */
(function () {
  'use strict';

  const base = () => window.ECOLLAB?.baseUrl || '';
  const csrf = () => window.ECOLLAB?.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content || '';

  async function createChannelOverride() {
    const name = document.getElementById('newChannelName')?.value?.trim();
    const desc = document.getElementById('newChannelDesc')?.value?.trim() || '';
    const type = document.querySelector('#addChannelModal .channel-type-opt.active')?.dataset?.type || 'text';
    const isPrivate = document.querySelector('#addChannelModal input[name="cvChannelPrivacy"]:checked')?.value === '1';
    const serverId = Number(window.ECOLLAB?.currentServerId || new URLSearchParams(location.search).get('server_id') || 0);

    if (!name) { window.showToast?.('Channel name is required', 'info'); return; }
    if (!serverId) { window.showToast?.('Select a server first', 'info'); return; }

    const button = document.querySelector('#addChannelModal .cv-btn.create');
    if (button) { button.disabled = true; button.textContent = 'Creating…'; }

    try {
      const response = await fetch(`${base()}/API/chat/create-channel.php`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
        body: JSON.stringify({
          server_id: serverId,
          name,
          description: desc,
          type,
          is_private: isPrivate ? 1 : 0,
        }),
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || data.success === false) throw new Error(data.error || 'Failed to create channel');

      const channelId = Number(data.channel?.id || 0);
      const selected = isPrivate ? Array.from(window._privateChannelSelectedUsers || new Set()) : [];
      if (isPrivate && channelId && selected.length) {
        await Promise.all(selected.map(userId => fetch(`${base()}/API/chat/channel-members.php`, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
          body: JSON.stringify({ action: 'add', channel_id: channelId, user_id: Number(userId) }),
        })));
      }

      window._privateChannelSelectedUsers = new Set();
      if (typeof window.closeModal === 'function') window.closeModal('addChannelModal');
      window.showToast?.(`✅ Channel #${name} created`, 'success');

      if (typeof window.loadServerChannels === 'function') {
        window.loadServerChannels(serverId);
      } else {
        location.reload();
      }
    } catch (error) {
      window.showToast?.(error.message || 'Failed to create channel', 'info');
    } finally {
      if (button) { button.disabled = false; button.textContent = 'Create Channel'; }
    }
  }

  function install() {
    window.createChannel = createChannelOverride;
    window.__ecollabCreateChannelOverrideInstalled = true;
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install, { once: true });
  else install();
})();
