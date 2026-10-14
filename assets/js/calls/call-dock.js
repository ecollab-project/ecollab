/* Call controls share the workspace taskbar. Small screens and other pages
   get a bottom call taskbar independent of the navigation drawer. */
'use strict';
(() => {
  let fallback;
  const controls = new Set();
  const clocks = new Map();
  let ticker;
  function updateClocks() {
    for (const [control, started] of clocks) {
      if (!control.isConnected) { clocks.delete(control); continue; }
      const seconds = Math.max(0, Math.floor((performance.now() - started) / 1000));
      const label = `${Math.floor(seconds / 3600)}:${String(Math.floor(seconds / 60) % 60).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
      const display = control.querySelector('.ec-call-timer');
      if (display) display.textContent = `(${label})`;
    }
    if (!clocks.size && ticker) { clearInterval(ticker); ticker = null; }
  }
  function startClock(control) {
    if (!control) return;
    if (!clocks.has(control)) clocks.set(control, performance.now());
    updateClocks();
    if (!ticker) ticker = setInterval(updateClocks, 1000);
  }
  function stopClock(control) {
    clocks.delete(control);
    updateClocks();
    const display = control?.querySelector('.ec-call-timer');
    if (display) display.textContent = '(0:00:00)';
  }
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
  window.EcollabCallDock = {mount, refresh, startClock, stopClock};
  window.addEventListener('pagehide', () => { clocks.clear(); updateClocks(); });
  mount(document.getElementById('vcConnectedBar'));
  window.addEventListener('resize', () => {
    for (const control of controls) {
      if (!control.isConnected) { controls.delete(control); continue; }
      if (control.id === 'vcConnectedBar' || control.classList.contains('is-taskbar-docked')) mount(control);
    }
    refresh();
  });
})();
