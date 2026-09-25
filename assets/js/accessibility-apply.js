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
    html.classList.toggle('density-compact', settings.message_density === 'compact');
    html.classList.toggle('density-spacious', settings.message_density === 'spacious');
    html.style.setProperty('--ec-font-scale', String((settings.font_scale || 100) / 100));
    html.style.setProperty('--ec-sidebar-scale', String((settings.sidebar_scale || 100) / 100));
    html.style.fontSize = (settings.font_scale || 100) + '%';

    if (settings.theme) {
      const light = settings.theme === 'light' ||
        (settings.theme === 'system' && window.matchMedia('(prefers-color-scheme: light)').matches);
      html.dataset.theme = light ? 'light' : 'dark';
    }

    // Cached globally so other scripts (notification triggers, etc.) don't
    // need a second round-trip to know the user's current preferences.
    window._userSettings = settings;
    window.dispatchEvent(new CustomEvent('ecollab:settings-applied', { detail: settings }));
  }

  function run() {
    const base = window.ECOLLAB?.baseUrl || window.BASE_URL || '';
    fetch(base + '/API/profile/settings.php', { credentials: 'same-origin' })
      .then(r => r.ok ? r.json() : null)
      .then(d => { if (d && d.settings) applyClasses(d.settings); })
      .catch(() => {});
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }
})();
