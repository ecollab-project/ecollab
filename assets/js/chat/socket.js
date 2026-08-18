/*
 * WebSocket bootstrap wrapper.
 * The legacy socket implementation starts itself automatically. chat.js also
 * calls initWebSocket(), which previously created a second socket and consumed
 * the one-time ws token, causing "Invalid or expired auth token" and a race
 * where the first socket tried to send through the second CONNECTING socket.
 *
 * Load the original implementation once, then expose a harmless no-op while it
 * is loading. The original implementation will replace this function when it
 * is evaluated and will perform the single automatic connection.
 */
(function () {
  'use strict';

  if (window.__ECOLLAB_SOCKET_CORE_LOADING || window.__ECOLLAB_SOCKET_CORE_LOADED) {
    return;
  }

  window.__ECOLLAB_SOCKET_CORE_LOADING = true;
  window.initWebSocket = function () {
    console.debug('[WS] Socket core is already booting; duplicate init ignored.');
  };

  // The WS-token endpoint is authenticated and must never be satisfied from
  // a browser/HTTP cache. A stale cached token can be perfectly well formed
  // but no longer exist in ws_tokens, producing the misleading "Invalid or
  // expired auth token" response from the WebSocket server.
  var nativeFetch = window.fetch.bind(window);
  window.fetch = function (input, init) {
    try {
      var url = typeof input === 'string' ? input : (input && input.url) || '';
      if (url.indexOf('/API/auth/ws-token.php') !== -1) {
        var busted = new URL(url, window.location.href);
        busted.searchParams.set('_ts', String(Date.now()));
        var nextInit = Object.assign({}, init || {}, { cache: 'no-store', credentials: 'same-origin' });
        return nativeFetch(busted.toString(), nextInit);
      }
    } catch (e) {
      // Fall through to the normal fetch implementation.
    }
    return nativeFetch(input, init);
  };

  var script = document.createElement('script');
  // Bump the cache key so the browser also gets this bootstrap fix.
  script.src = (window.ECOLLAB?.baseUrl || '') + '/assets/js/chat/socket-core.js?v=wsfix5';
  script.onload = function () {
    window.__ECOLLAB_SOCKET_CORE_LOADED = true;
    window.__ECOLLAB_SOCKET_CORE_LOADING = false;

    // socket-core.js historically attached the stop timer to the numeric
    // return value of setTimeout(). Browsers return a number for setTimeout,
    // so assigning `_stop` to that value throws. Keep timer handles separate.
    var typingSendTimer = null;
    var typingStopTimer = null;
    var typingState = false;

    window.sendTypingEvent = function (isTyping) {
      var channelId = window.ECOLLAB?.currentChannelId;
      if (!channelId || typeof window.wsSend !== 'function') return;

      if (isTyping) {
        typingState = true;
        clearTimeout(typingSendTimer);
        typingSendTimer = setTimeout(function () {
          window.wsSend({
            type: 'typing',
            channel_id: channelId,
            typing: true,
          });
        }, 80);

        clearTimeout(typingStopTimer);
        typingStopTimer = setTimeout(function () {
          typingState = false;
          window.wsSend({
            type: 'typing',
            channel_id: window.ECOLLAB?.currentChannelId || channelId,
            typing: false,
          });
        }, 4000);
        return;
      }

      if (!typingState) return;
      typingState = false;
      clearTimeout(typingSendTimer);
      clearTimeout(typingStopTimer);
      typingSendTimer = setTimeout(function () {
        window.wsSend({
          type: 'typing',
          channel_id: window.ECOLLAB?.currentChannelId || channelId,
          typing: false,
        });
      }, 80);
    };
  };
  script.onerror = function () {
    window.__ECOLLAB_SOCKET_CORE_LOADING = false;
    console.error('[WS] Failed to load socket core.');
  };
  document.head.appendChild(script);
})();
