'use strict';
(() => {
  let restoring = false;
  window.EcollabDashboardState = {
    select(id) {
      if (!document.getElementById('page-' + id)) return false;
      document.querySelectorAll('.sidebar.open, .sidebar-overlay.open, #sidebarOverlay.open').forEach(el => el.classList.remove('open'));
      document.body.style.overflow = '';
      if (!restoring) {
        const url = new URL(location.href);
        url.searchParams.set('page', id);
        if (url.href !== location.href) history.pushState(null, '', url);
      }
      return true;
    }
  };
  function restore() {
    const id = new URLSearchParams(location.search).get('page');
    const fallback = document.getElementById('page-overview') ? 'overview' : 'dashboard';
    restoring = true;
    try { window.showPage?.(document.getElementById('page-' + id) ? id : fallback); }
    finally { restoring = false; }
  }
  document.addEventListener('DOMContentLoaded', restore);
  window.addEventListener('popstate', restore);
})();
