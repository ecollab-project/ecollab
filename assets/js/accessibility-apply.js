/**
 * accessibility-apply.js
 *
 * Shared accessibility/settings bootstrap used by authenticated pages.
 */
(function () {
  function applyClasses(settings) {
    const html = document.documentElement;
    html.classList.toggle('no-motion', !!settings.reduce_motion);
    html.classList.toggle('high-contrast', !!settings.high_contrast);
    html.classList.toggle('compact-mode', !!settings.compact_mode);
    html.classList.toggle('screen-reader-mode', !!settings.screen_reader_mode);

    if (settings.theme) {
      const light = settings.theme === 'light' ||
        (settings.theme === 'system' && window.matchMedia('(prefers-color-scheme: light)').matches);
      html.dataset.theme = light ? 'light' : 'dark';
    }

    window._userSettings = settings;
  }

  function injectResourceAccessStyles() {
    if (document.getElementById('ecollab-resource-access-ui')) return;
    const style = document.createElement('style');
    style.id = 'ecollab-resource-access-ui';
    style.textContent = `
      #accessModal .perm-row{display:flex;align-items:center;gap:10px;min-height:48px;padding:8px 0;border-bottom:1px solid #292e3b}
      #accessModal .perm-row>span:first-child{flex:1;min-width:0;color:#e2e8f0;font-size:13px;line-height:1.35}
      #accessModal .perm-row select{appearance:none!important;-webkit-appearance:none!important;-moz-appearance:none!important;box-sizing:border-box;width:82px;min-width:82px;height:34px;padding:0 27px 0 10px;border:1px solid #3a4354!important;border-radius:8px!important;background-color:#10141d!important;background-image:linear-gradient(45deg,transparent 50%,#94a3b8 50%),linear-gradient(135deg,#94a3b8 50%,transparent 50%)!important;background-position:calc(100% - 13px) 14px,calc(100% - 8px) 14px!important;background-size:5px 5px,5px 5px!important;background-repeat:no-repeat!important;color:#e5e7eb!important;color-scheme:dark;font:600 12px/32px Inter,system-ui,sans-serif!important;cursor:pointer;outline:none}
      #accessModal .perm-row select:hover{background-color:#171e2b!important;border-color:#58667d!important}
      #accessModal .perm-row select:focus-visible{border-color:#7c8ba3!important;box-shadow:0 0 0 2px rgba(124,139,163,.18)!important}
      #accessModal .perm-row select option{background:#10141d;color:#e5e7eb}
      #accessModal .perm-row .btn.danger{display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;width:76px;min-width:76px;height:34px;min-height:34px;padding:0 12px!important;border:1px solid rgba(248,113,113,.38)!important;border-radius:8px!important;background:rgba(127,29,29,.14)!important;color:#fca5a5!important;font:600 12px/32px Inter,system-ui,sans-serif!important;cursor:pointer;white-space:nowrap;transition:background .15s ease,border-color .15s ease,color .15s ease}
      #accessModal .perm-row .btn.danger:hover{background:rgba(127,29,29,.30)!important;border-color:rgba(248,113,113,.68)!important;color:#fecaca!important}
      #accessModal .perm-row .btn.danger:focus-visible{outline:none;box-shadow:0 0 0 2px rgba(248,113,113,.16)}
      #accessModal .invite{display:flex;align-items:center;gap:8px}
      #accessModal .invite #userSearch{flex:1;min-width:0}
      #accessModal .invite .btn,#accessModal .perm-row .btn:not(.danger){display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:0 13px!important;border:1px solid #3a4354!important;border-radius:8px!important;background:#171d29!important;color:#e5e7eb!important;font:600 12px/34px Inter,system-ui,sans-serif!important;white-space:nowrap;cursor:pointer}
      #accessModal .invite .btn:hover,#accessModal .perm-row .btn:not(.danger):hover{background:#202838!important;border-color:#58667d!important}
      #accessModal #grantPermission,#accessModal #invitePermission{appearance:none;-webkit-appearance:none;box-sizing:border-box;min-height:36px;padding:0 32px 0 11px;border:1px solid #3a4354;border-radius:8px;background-color:#10141d;color:#e5e7eb;color-scheme:dark;font:600 12px/34px Inter,system-ui,sans-serif;cursor:pointer}
      @media(max-width:560px){#accessModal .perm-row{flex-wrap:wrap}#accessModal .perm-row>span:first-child{flex-basis:100%}#accessModal .perm-row select{flex:1;width:auto;min-width:0}}
    `;
    document.head.appendChild(style);
  }

  function loadScript(id, src) {
    if (document.getElementById(id)) return;
    const script = document.createElement('script');
    script.id = id;
    script.defer = true;
    script.src = src;
    document.head.appendChild(script);
  }

  function run() {
    injectResourceAccessStyles();
    const base = window.ECOLLAB?.baseUrl || '';
    loadScript('ecollab-server-visibility-script', `${base}/assets/js/chat/server-visibility.js?v=1`);
    loadScript('ecollab-server-access-guard-script', `${base}/assets/js/chat/server-access-guard.js?v=1`);
    loadScript('ecollab-channel-visibility-script', `${base}/assets/js/chat/channel-visibility.js?v=1`);

    fetch(base + '/API/profile/settings.php', {credentials:'same-origin'})
      .then(r => r.ok ? r.json() : null)
      .then(d => { if (d && d.settings) applyClasses(d.settings); })
      .catch(() => {});
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run);
  else run();
})();
