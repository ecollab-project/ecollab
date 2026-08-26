/*
 * C1 secure Code Sandbox runner.
 *
 * Untrusted JavaScript executes only inside a sandboxed iframe with an opaque
 * origin. The parent never executes user code. Communication uses a private
 * MessageChannel and an unguessable per-run nonce; sandbox-to-parent window
 * messages are actively blocked while a run is active.
 */
(function () {
  'use strict';

  const MAX_RUN_MS = 5000;
  const MAX_OUTPUT_CHARS = 20000;
  let activeFrame = null;
  let activeRun = null;

  function clip(value) {
    const text = String(value ?? '');
    return text.length > MAX_OUTPUT_CHARS ? text.slice(0, MAX_OUTPUT_CHARS) + '\n…[output truncated]' : text;
  }

  function makeNonce() {
    if (window.crypto?.getRandomValues) {
      const bytes = new Uint8Array(24);
      window.crypto.getRandomValues(bytes);
      return Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
    }
    return `${Date.now()}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`;
  }

  function destroyActive() {
    if (activeRun?.timer) clearTimeout(activeRun.timer);
    if (activeRun?.port) {
      try { activeRun.port.close(); } catch (_) {}
    }
    if (activeRun?.blockParentMessages) {
      window.removeEventListener('message', activeRun.blockParentMessages, true);
    }
    if (activeFrame) {
      activeFrame.remove();
      activeFrame = null;
    }
    activeRun = null;
  }

  function frameDocument() {
    const parentOrigin = JSON.stringify(window.location.origin);
    return `<!doctype html><html><head><meta charset="utf-8">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; script-src 'unsafe-inline' 'unsafe-eval'; connect-src 'none'; img-src data:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; object-src 'none'; frame-ancestors 'none'">
</head><body><script>
(function () {
  'use strict';

  const expectedParentOrigin = ${parentOrigin};
  let consumed = false;
  let port = null;
  let expectedNonce = null;

  function safeString(value) {
    try { return String(value); } catch (_) { return '[unprintable]'; }
  }

  function send(type, payload) {
    if (!port || !expectedNonce) return;
    try { port.postMessage({ type: type, nonce: expectedNonce, ...payload }); } catch (_) {}
  }

  function runOnce(message) {
    // Check the guard first, before any attacker-controlled operation.
    if (consumed) return;
    consumed = true;

    if (!message || message.type !== 'run' || typeof message.code !== 'string' || typeof message.nonce !== 'string') return;
    expectedNonce = message.nonce;

    const lines = [];
    const fakeConsole = Object.freeze({
      log: (...args) => lines.push(args.map(safeString).join(' ')),
      info: (...args) => lines.push(args.map(safeString).join(' ')),
      warn: (...args) => lines.push('WARN: ' + args.map(safeString).join(' ')),
      error: (...args) => lines.push('ERROR: ' + args.map(safeString).join(' ')),
    });

    let output = '';
    let error = '';
    const started = performance.now();

    try {
      // Function evaluation is confined to this opaque, sandboxed iframe.
      const fn = new Function('console', message.code);
      fn(fakeConsole);
      output = lines.join('\n') || '(no output)';
    } catch (err) {
      error = safeString(err && err.message ? err.message : err);
    }

    send('result', {
      output: output.slice(0, 20000),
      error: error.slice(0, 4000),
      duration_ms: Math.max(0, Math.round(performance.now() - started)),
    });
  }

  window.addEventListener('message', function (event) {
    if (!event.isTrusted || event.source !== window.parent || event.origin !== expectedParentOrigin || consumed) return;
    if (!event.ports || event.ports.length !== 1) return;
    if (!event.data || event.data.type !== 'connect') return;
    const candidate = event.ports[0];
    if (!candidate) return;
    port = candidate;
    port.onmessage = function (portEvent) {
      runOnce(portEvent.data);
    };
    port.start();
  }, { once: true });
})();
</script></body></html>`;
  }

  async function runIsolatedJavaScript(code) {
    destroyActive();
    const nonce = makeNonce();
    const channel = new MessageChannel();
    const frame = document.createElement('iframe');

    frame.setAttribute('sandbox', 'allow-scripts');
    frame.setAttribute('aria-hidden', 'true');
    frame.tabIndex = -1;
    frame.style.cssText = 'position:fixed;width:1px;height:1px;left:-10000px;top:-10000px;border:0;opacity:0;pointer-events:none;';
    frame.srcdoc = frameDocument();

    // Prevent untrusted sandbox code from reaching unrelated host message
    // listeners. The private MessageChannel is the only result channel.
    const blockParentMessages = (event) => {
      if (event.source !== frame.contentWindow) return;
      event.stopImmediatePropagation();
      event.preventDefault();
    };
    window.addEventListener('message', blockParentMessages, true);

    document.body.appendChild(frame);
    activeFrame = frame;

    return await new Promise((resolve) => {
      const finish = (result) => {
        if (!activeRun) return;
        clearTimeout(activeRun.timer);
        try { channel.port1.close(); } catch (_) {}
        window.removeEventListener('message', blockParentMessages, true);
        if (activeFrame === frame) {
          frame.remove();
          activeFrame = null;
        }
        activeRun = null;
        resolve(result);
      };

      const timer = setTimeout(() => finish({
        output: '',
        error: 'Execution timed out after 5 seconds.',
        duration_ms: MAX_RUN_MS,
      }), MAX_RUN_MS);

      activeRun = { port: channel.port1, timer, blockParentMessages };
      channel.port1.onmessage = (event) => {
        const data = event.data;
        if (!data || data.type !== 'result' || data.nonce !== nonce) return;
        finish({
          output: clip(data.output),
          error: clip(data.error),
          duration_ms: Number.isFinite(data.duration_ms) ? data.duration_ms : 0,
        });
      };
      channel.port1.start();

      frame.addEventListener('load', () => {
        try {
          // Sandboxed srcdoc has an opaque origin, so '*' is required for this
          // initial port transfer. The receiver checks source, origin and
          // isTrusted before accepting it.
          frame.contentWindow.postMessage({ type: 'connect' }, '*', [channel.port2]);
          // The transferred port is now owned by the iframe. Send the actual
          // execution request over the private channel; never use window.postMessage
          // for the untrusted code payload itself.
          channel.port1.postMessage({ type: 'run', code: String(code || ''), nonce });
        } catch (_) {
          finish({ output: '', error: 'Sandbox initialization failed.', duration_ms: 0 });
        }
      }, { once: true });
    });
  }

  async function logRun(result, duration) {
    const snippetId = parseInt(document.querySelector('[data-snippet-id]')?.dataset.snippetId || '0', 10);
    const channelId = Number(window.ECOLLAB?.currentChannelId || 0);
    if (!snippetId || !channelId) return;
    try {
      const base = window.ECOLLAB?.baseUrl || '';
      const csrf = window.ECOLLAB?.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content || '';
      await fetch(`${base}/API/collab/collab.php?tool=code&action=log_run&channel_id=${channelId}`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({
          channel_id: channelId,
          snippet_id: snippetId,
          output: result.output || '',
          error: result.error || '',
          duration_ms: duration,
        }),
      });
    } catch (_) {}
  }

  window.runCodeSecure = async function runCodeSecure(lang, code) {
    if (lang !== 'javascript') {
      return {
        output: `▶ ${lang} execution runs server-side.\nSave the snippet and use your dev environment.`,
        error: '',
        duration_ms: 0,
      };
    }
    return runIsolatedJavaScript(String(code || ''));
  };

  window.runCode = async function runCode() {
    const lang = document.getElementById('codeLang')?.value || 'javascript';
    const code = document.getElementById('codeEditor')?.value || '';
    const outEl = document.getElementById('codeOutput');
    if (outEl) { outEl.textContent = '⏳ Running…'; outEl.style.color = ''; }
    const started = Date.now();
    const result = await window.runCodeSecure(lang, code);
    const duration = Number.isFinite(result.duration_ms) ? result.duration_ms : Date.now() - started;
    if (outEl) {
      outEl.textContent = result.error ? `⚠ Error:\n${result.error}` : result.output;
      outEl.style.color = result.error ? '#ef4444' : '#22c55e';
    }
    await logRun(result, duration);
  };
})();
