/* Call controls share the workspace taskbar. Small screens and other pages
   get a bottom call taskbar independent of the navigation drawer. */
'use strict';
(() => {
  let fallback;
  const controls = new Set();
  function host() {
    const taskbar = document.getElementById('workspaceSwitcher');
    if (taskbar && window.matchMedia('(min-width:861px)').matches) return taskbar;
    if (!fallback) {
      fallback = document.createElement('nav');
      fallback.className = 'ec-call-taskbar';
      fallback.setAttribute('aria-label', 'Active calls');
      fallback.hidden = true;
      document.body.appendChild(fallback);
    }
    return fallback;
  }
  function refresh() {
    for (const control of controls) if (!control.isConnected) controls.delete(control);
    if (!fallback) return;
    fallback.hidden = ![...fallback.children].some(el => el.style.display !== 'none');
    document.body.classList.toggle('has-call-taskbar', !fallback.hidden);
  }
  function mount(control) {
    if (!control) return;
    controls.add(control);
    control.classList.add('ec-docked-call-control');
    const target = host();
    if (control.parentNode !== target) target.appendChild(control);
    refresh();
  }
  window.EcollabCallDock = {mount, refresh};
  mount(document.getElementById('vcConnectedBar'));
  window.addEventListener('resize', () => {
    for (const control of controls) {
      if (!control.isConnected) { controls.delete(control); continue; }
      if (control.id === 'vcConnectedBar' || control.classList.contains('is-taskbar-docked')) mount(control);
    }
    refresh();
  });
})();
