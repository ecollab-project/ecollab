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
  }

  function run() {
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
