# Live QA runtime follow-up — 2026-11-17

Base release: `b86a6bfdfa02ed1d59db31f14b3233f7f2f4947a`.

## Scope and traced causes

- `chat.php` → `socket.js` → `socket-core.js` → `ws-token.php`: legacy `initWebSocket()` could run before the asynchronous token fetch, opening an unauthenticated socket. All entry points now share one token request and capture a validated token per socket. Reconnect never reuses a token after a failed fetch.
- `session.php` → `excalidraw-whiteboard.js` → `yjs-presence.js`: an empty room acknowledgement changed `joined` without rendering. Solo boards now render live status immediately. This changes the indicator, not resource permissions or drawing state.
- Channel presence ignores stale channel acknowledgements and refuses to install a second provider. The duplicate module tag/early closing body in chat.php is removed. Server-side authenticated identity binding remains unchanged.
- `chat.php` → `livekit-voice.js` → LiveKit capture: close setup immediately after requesting capture, keep a separate screen-request lock, keep microphone controls available, restore the button after denial/completion, and disable late captures if the room was left.
- `JarredDmReplyWorker` → `JarredResponseFormat` → `OllamaService::generate`: explicit numeric-only and one-to-eight sentence requests use bounded JSON schemas, then decode into ordinary chat text. Malformed output fails through the existing retryable queue rather than publishing a known format violation. Code Lab and capability policies run first and are rechecked before publication. Default response style now explicitly yields to the latest user format.

## Validation

New regressions cover token/startup races, provider duplication/stale joins, solo-board connection state, pending chooser/control isolation, late capture cleanup and structured AI output. Queue integration checks verify the schema reaches generation and only plain text is published. Existing uploads, media, call continuity, modal and navigation checks remain applicable.

Live QA confirmed bidirectional same-account two-tab delivery without reload. That does not establish multi-user media reception. A browser-controlled file chooser timed out; this is not evidence of an application upload defect. Existing upload control/access tests cover channel isolation and permission checks. Real microphone/camera capture and native chooser behavior still require device-level verification.

## Release and rollback

No database migration, dependency installation, data cleanup or upload replacement is needed. Preserve production-local files and private configuration. Create a private readable archive and branch for the exact predeploy HEAD, verify archive integrity and checksum, then fast-forward the deployed checkout to the CI-verified merge revision. Reload PHP-FPM and restart the two Jarred workers so their loaded PHP classes are current. The WebSocket server does not change and need not restart.

For rollback, restore tracked code to the recorded predeploy revision (without removing untracked files or changing the database), reload PHP-FPM and restart both Jarred workers. Verify revision, clean tracked diff, service health and HTTP response. Keep the archive outside the web root. Backups are code rollback points, not database backups.
