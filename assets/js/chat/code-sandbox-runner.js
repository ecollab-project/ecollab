'use strict';

/*
 * Code Sandbox runner.
 *
 * This file executes inside a sandboxed iframe with allow-scripts only.
 * The parent deliberately omits allow-same-origin, giving this document
 * an opaque origin with no access to the application's cookies/storage/DOM.
 */
(() => {
  const send = (nonce, type, payload = {}) => {
    window.parent.postMessage({
      channel: 'ecollab-code-sandbox',
      nonce,
      type,
      ...payload,
    }, '*');
  };

  window.addEventListener('message', (event) => {
    if (event.source !== window.parent || event.origin !== 'null') return;

    const data = event.data;
    if (!data || data.type !== 'ecollab-code-run' || typeof data.nonce !== 'string') return;

    const nonce = data.nonce;
    const lines = [];
    let finished = false;

    const pushLine = (level, args) => {
      const text = args.map((value) => {
        try {
          if (typeof value === 'string') return value;
          return JSON.stringify(value);
        } catch (_) {
          return String(value);
        }
      }).join(' ');
      lines.push(level === 'log' ? text : `${level.toUpperCase()}: ${text}`);
    };

    const finish = (error = '') => {
      if (finished) return;
      finished = true;
      send(nonce, 'done', {
        output: lines.join('\n') || '(no output)',
        error: error ? String(error) : '',
      });
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

    try {
      window.console = fakeConsole;
      const script = document.createElement('script');
      script.textContent = String(data.code || '');
      document.documentElement.appendChild(script);
      if (!finished) finish();
    } catch (error) {
      finish(error?.message || error);
    }
  });
})();
