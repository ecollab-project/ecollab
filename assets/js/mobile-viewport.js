/* Follow usable browser space, including toolbar and keyboard changes. */
(() => {
  'use strict';
  if (window.ecollabMobileViewport) return;
  window.ecollabMobileViewport = true;
  const root = document.documentElement;
  const mobile = window.matchMedia('(max-width: 860px)');
  const viewport = window.visualViewport;
  let pending = false;
  function update() {
    pending = false;
    if (!mobile.matches) {
      root.style.removeProperty('--app-viewport-height');
      root.style.removeProperty('--app-viewport-top');
      return;
    }
    // Preserve native pinch zoom instead of shrinking the application under it.
    if (viewport && Math.abs(viewport.scale - 1) > 0.05) return;
    const height = viewport ? viewport.height : window.innerHeight;
    if (!Number.isFinite(height) || height <= 0) return;
    root.style.setProperty('--app-viewport-height', `${Math.round(height)}px`);
    root.style.setProperty('--app-viewport-top', `${Math.max(0, Math.round(viewport ? viewport.offsetTop : 0))}px`);
  }
  function schedule() {
    if (pending) return;
    pending = true;
    window.requestAnimationFrame(update);
  }
  window.addEventListener('resize', schedule, { passive: true });
  window.addEventListener('orientationchange', schedule, { passive: true });
  window.addEventListener('pageshow', schedule);
  if (viewport) {
    viewport.addEventListener('resize', schedule, { passive: true });
    viewport.addEventListener('scroll', schedule, { passive: true });
  }
  if (mobile.addEventListener) mobile.addEventListener('change', schedule);
  else if (mobile.addListener) mobile.addListener(schedule);
  update();
})();
