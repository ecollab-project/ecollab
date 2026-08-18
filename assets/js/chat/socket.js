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

  var script = document.createElement('script');
  // Bump the cache key whenever the socket core changes so browsers cannot
  // keep executing an older token/authentication implementation.
  script.src = (window.ECOLLAB?.baseUrl || '') + '/assets/js/chat/socket-core.js?v=wsfix3';
  script.onload = function () {
    window.__ECOLLAB_SOCKET_CORE_LOADED = true;
    window.__ECOLLAB_SOCKET_CORE_LOADING = false;
  };
  script.onerror = function () {
    window.__ECOLLAB_SOCKET_CORE_LOADING = false;
    console.error('[WS] Failed to load socket core.');
  };
  document.head.appendChild(script);
})();
