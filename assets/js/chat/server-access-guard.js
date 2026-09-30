/* Client gate for direct server URLs. Backend remains the authority. */
(function () {
  'use strict';

  function base() { return window.ECOLLAB?.baseUrl || ''; }

  function showDenied(message) {
    document.documentElement.innerHTML = `
      <head><meta charset="UTF-8"><title>Private Server</title></head>
      <body style="margin:0;min-height:100vh;display:grid;place-items:center;background:#0b1020;color:#f8fafc;font-family:Inter,system-ui,sans-serif;">
        <main style="width:min(460px,90vw);padding:28px;border:1px solid rgba(148,163,184,.16);border-radius:16px;background:#111827;box-shadow:0 24px 80px rgba(0,0,0,.45);text-align:center;">
          <div style="font-size:34px;margin-bottom:12px;">🔒</div>
          <h1 style="font-size:20px;margin:0 0 8px;">Private server</h1>
          <p style="font-size:13px;line-height:1.55;color:#94a3b8;margin:0 0 20px;">${message}</p>
          <button onclick="history.back()" style="height:36px;padding:0 15px;border:1px solid #475569;border-radius:8px;background:#1e293b;color:#e2e8f0;font:600 12px Inter,system-ui,sans-serif;cursor:pointer;">Go back</button>
        </main>
      </body>`;
  }

  async function check() {
    const id = Number(new URLSearchParams(window.location.search).get('server_id') || 0);
    if (!id) return;
    try {
      const response = await fetch(`${base()}/API/server/access.php?server_id=${encodeURIComponent(id)}`, {credentials:'same-origin', cache:'no-store'});
      if (response.status === 403) {
        const data = await response.json().catch(() => ({}));
        showDenied(data.error || 'You do not have access to this private server.');
        return;
      }
      if (response.status === 404) {
        showDenied('This server does not exist or is no longer active.');
      }
    } catch (_) {
      // Do not block normal navigation when the access check itself is unavailable.
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', check, {once:true});
  else check();
})();
