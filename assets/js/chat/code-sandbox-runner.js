'use strict';

/*
 * Code Sandbox runner.
 *
 * This file executes only inside an iframe with sandbox="allow-scripts".
 * The parent deliberately omits allow-same-origin, giving this document
 * an opaque origin with no access to the application's cookies/storage/DOM.
 */
(() => {
  const channel = 'ecollab-code-sandbox';
  const idleMs = 250;
  let activeNonce = null;
  let idleTimer = null;
  let finished = false;
  const lines = [];

  const send = (nonce, type, payload = {}) => {
    // Opaque sandbox origins serialize as "null", so no concrete targetOrigin
    // exists for the parent; the parent validates event.source and nonce.
    window.parent.postMessage({ channel, nonce, type, ...payload }, '*');
  };

  const finish = (error = '') => {
    if (finished || !activeNonce) return;
    finished = true;
    if (idleTimer) clearTimeout(idleTimer);
    send(activeNonce, 'done', {
      output: lines.join('\n') || '(no output)',
      error: error ? String(error) : '',
    });
  };

  const pushLine = (level, args) => {
    const text = args.map((value) => {
      try {
        if (typeof value === 'string') return value;
        const json = JSON.stringify(value);
        return json === undefined ? String(value) : json;
      } catch (_) {
        return String(value);
      }
    }).join(' ');
    lines.push(level === 'log' ? text : `${level.toUpperCase()}: ${text}`);
    if (idleTimer) clearTimeout(idleTimer);
    idleTimer = setTimeout(() => finish(), idleMs);
  };

  const fakeConsole = Object.freeze({
    log: (...args) => pushLine('log', args),
    warn: (...args) => pushLine('warn', args),
    error: (...args) => pushLine('error', args),
    info: (...args) => pushLine('log', args),
  });

  window.onerror = (message) => {
    finish(message);
    return true;
  };

  window.onunhandledrejection = (event) => {
    finish(event.reason?.message || event.reason || 'Unhandled promise rejection');
  };

  window.addEventListener('message', (event) => {
    const data = event.data;
    if (event.source !== window.parent) return;
    if (!data || data.channel !== channel || data.type !== 'ecollab-code-run') return;
    if (typeof data.nonce !== 'string' || data.nonce.length < 16 || data.nonce.length > 128) return;
    if (typeof data.parentOrigin !== 'string' || data.parentOrigin !== event.origin) return;
    if (typeof data.code !== 'string') return;
    if (activeNonce || finished) return;

    activeNonce = data.nonce;
    try {
      window.console = fakeConsole;
      const script = document.createElement('script');
      script.textContent = data.code;
      document.documentElement.appendChild(script);
      if (!finished) {
        if (idleTimer) clearTimeout(idleTimer);
        idleTimer = setTimeout(() => finish(), idleMs);
      }
    } catch (error) {
      finish(error?.message || error);
    }
  }, { once: true });
})();
