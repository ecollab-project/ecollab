/**
 * accessibility-apply.js
 *
 * The settings page previously applied reduce-motion / high-contrast classes
 * to <html> using logic defined inline in its own <script> tag — nothing
 * else in the app ever ran that logic, so the effect only ever showed up
 * on the settings page itself and vanished the moment you navigated away.
 *
 * This is the same logic, extracted so every page can run it. Include this
 * script on any authenticated page (after the CSRF meta tag is present) and
 * it will fetch the user's real settings and apply them immediately.
 *
 * Also newly applies `compact_mode`, which previously saved correctly but
 * had no corresponding CSS/class anywhere — see the .compact-mode rules
 * added alongside this file.
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

    // Cached globally so other scripts (notification triggers, etc.) don't
    // need a second round-trip to know the user's current preferences.
    window._userSettings = settings;
  }

  function injectResourceAccessStyles() {
    if (document.getElementById('ecollab-resource-access-ui')) return;
    const style = document.createElement('style');
    style.id = 'ecollab-resource-access-ui';
    style.textContent = `
      #accessModal .perm-row,
      #accessModal .invite {
        align-items: center;
      }
      #accessModal .perm-row select,
      #accessModal #grantPermission,
      #accessModal #invitePermission {
        appearance: none;
        -webkit-appearance: none;
        min-height: 36px;
        padding: 0 32px 0 11px;
        border: 1px solid #3a4354;
        border-radius: 8px;
        background-color: #10141d;
        color: #e5e7eb;
        font: 600 12px Inter, system-ui, sans-serif;
        line-height: 34px;
        cursor: pointer;
        background-image: linear-gradient(45deg, transparent 50%, #94a3b8 50%), linear-gradient(135deg, #94a3b8 50%, transparent 50%);
        background-position: calc(100% - 14px) 15px, calc(100% - 9px) 15px;
        background-size: 5px 5px, 5px 5px;
        background-repeat: no-repeat;
      }
      #accessModal .perm-row select:hover,
      #accessModal #grantPermission:hover,
      #accessModal #invitePermission:hover {
        border-color: #58667d;
        background-color: #151b27;
      }
      #accessModal .perm-row select:focus,
      #accessModal #grantPermission:focus,
      #accessModal #invitePermission:focus {
        outline: none;
        border-color: #7c8ba3;
        box-shadow: 0 0 0 2px rgba(124, 139, 163, .16);
      }
      #accessModal .perm-row .btn,
      #accessModal .invite .btn {
        min-height: 36px;
        border: 1px solid #3a4354;
        border-radius: 8px;
        background: #171d29;
        color: #e5e7eb;
        font: 600 12px Inter, system-ui, sans-serif;
        padding: 0 13px;
        white-space: nowrap;
      }
      #accessModal .perm-row .btn:hover,
      #accessModal .invite .btn:hover {
        background: #202838;
        border-color: #58667d;
      }
      #accessModal .perm-row .btn.danger {
        border-color: rgba(248, 113, 113, .38);
        background: rgba(127, 29, 29, .12);
        color: #fca5a5;
      }
      #accessModal .perm-row .btn.danger:hover {
        background: rgba(127, 29, 29, .25);
        border-color: rgba(248, 113, 113, .65);
        color: #fecaca;
      }
      #accessModal .invite {
        display: flex;
        gap: 8px;
      }
      #accessModal .invite #userSearch {
        flex: 1;
        min-width: 0;
      }
      @media (max-width: 560px) {
        #accessModal .perm-row {
          flex-wrap: wrap;
        }
        #accessModal .perm-row span:first-child {
          flex-basis: 100%;
        }
        #accessModal .perm-row select {
          flex: 1;
        }
        #accessModal .invite {
          flex-wrap: wrap;
        }
        #accessModal .invite #userSearch {
          flex-basis: 100%;
        }
      }
    `;
    document.head.appendChild(style);
  }

  function run() {
    injectResourceAccessStyles();
    const base = window.ECOLLAB?.baseUrl || window.BASE_URL || '';
    // Read-only GET request — no CSRF token needed. Fails silently (caught
    // below) on pages where the user isn't authenticated, so this script is
    // safe to include on any page without checking for page-specific markup.
    fetch(base + '/API/profile/settings.php', { credentials: 'same-origin' })
      .then(r => r.ok ? r.json() : null)
      .then(d => { if (d && d.settings) applyClasses(d.settings); })
      .catch(() => {}); // fail silently — page renders with defaults
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }
})();
