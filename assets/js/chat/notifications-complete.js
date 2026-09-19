/* Ecollab notification completion layer.
 * Load after dm-notifications.js and after the chat DOM is available.
 * It intentionally decorates the existing notification system instead of
 * replacing DM functionality.
 */
'use strict';

(function () {
  const base = () => window.ECOLLAB?.baseUrl || '';
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  function routeNotification(n) {
    const type = String(n.type || '');
    const url = n.link_url || '';
    if (type === 'connection_request' || type === 'connection_accepted') {
      if (typeof window.openStudyPartnersModal === 'function') return window.openStudyPartnersModal({ openRequests: true });
      if (typeof window.openPeerMatchingModal === 'function') return window.openPeerMatchingModal({ openRequests: true });
      window.location.href = url || (base() + '/modules/chat/chat.php?view=study-partners&open_requests=1');
      return;
    }
    if (url) {
      const absolute = /^https?:\/\//i.test(url) ? url : base().replace(/\/$/, '') + '/' + url.replace(/^\//, '');
      window.location.href = absolute;
      return;
    }
    if (type === 'dm' || type === 'dm_message') {
      if (typeof window.loadDmList === 'function') window.loadDmList();
    }
  }

  window.EcollabRouteNotification = routeNotification;

  // Enhance the existing click handler while preserving its read behavior.
  const previousClick = window._handleNotifClick;
  window._handleNotifClick = function (id, type, refId) {
    const item = window.NOTIF?.items?.find?.(n => Number(n.id) === Number(id));
    if (typeof previousClick === 'function') previousClick(id, type, refId);
    if (item) routeNotification(item);
  };

  // Render Accept/Reject controls for connection request notifications.
  const previousRender = window._renderNotifDropdown;
  window._renderNotifDropdown = function () {
    if (typeof previousRender === 'function') previousRender();
    const list = document.getElementById('notifList');
    if (!list) return;
    list.querySelectorAll('.notif-item').forEach(node => {
      const id = Number(node.dataset.notifId || 0);
      const item = window.NOTIF?.items?.find?.(n => Number(n.id) === id);
      if (!item || item.type !== 'connection_request' || node.querySelector('.notif-request-actions')) return;
      const actions = document.createElement('div');
      actions.className = 'notif-request-actions';
      actions.style.cssText = 'display:flex;gap:6px;margin:7px 0 0 28px;';
      actions.innerHTML = '<button type="button" data-action="accept" style="border:0;border-radius:6px;padding:4px 10px;background:#22c55e;color:#fff;font-size:11px;font-weight:700;cursor:pointer">Accept</button><button type="button" data-action="reject" style="border:0;border-radius:6px;padding:4px 10px;background:#ef4444;color:#fff;font-size:11px;font-weight:700;cursor:pointer">Reject</button>';
      actions.addEventListener('click', async event => {
        const button = event.target.closest('button');
        if (!button) return;
        event.stopPropagation();
        button.disabled = true;
        try {
          const response = await window.apiFetch(base() + '/API/notifications/respond-request.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ request_id: id, action: button.dataset.action })
          });
          if (!response?.success) throw new Error(response?.error || 'Request failed');
          item.is_read = 1;
          item.type = 'connection_request_resolved';
          actions.remove();
          if (typeof window.showToast === 'function') window.showToast(button.dataset.action === 'accept' ? 'Connection request accepted' : 'Connection request rejected', 'success');
          if (typeof window.loadStudyPartnerRequests === 'function') window.loadStudyPartnerRequests();
        } catch (error) {
          button.disabled = false;
          if (typeof window.showToast === 'function') window.showToast(error.message || 'Unable to respond', 'error');
        }
      });
      node.appendChild(actions);
    });
  };
})();
