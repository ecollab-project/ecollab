# Yjs in chat and collaboration latency

The chat module uses Yjs awareness for channel viewers, active/idle status and typing indicators. Updates are bound to the authenticated user's subscribed channel. PHP replaces supplied identity fields and broadcasts only to authorized channel subscribers. No message or draft text is sent as awareness. Typing stops after 2.5 seconds of inactivity, on blur or on channel exit. The existing message database, private drafts, notifications and LiveKit calls retain their existing transport.

## Recommended next uses

- Y.Text with an editor binding for an explicitly shared notes document or a jointly authored announcement.
- Y.Map for shared task-board state, with an authorized persistence provider and revision/audit policy.
- Presence/selection for collaborative editors that expose an integration API.

Shared drafts should be opt-in. Posting/moderation, read receipts and invitation permissions remain server-authoritative operations. Collabora has its own editing engine; installing Yjs does not change that engine's network or resource latency.

## Whiteboard updates

Live Excalidraw broadcasts now carry only changed element revisions (including deletion tombstones) and newly added image files. Failed sends retain those revisions for retry. Full scene autosaves remain separate. This reduces repeated scene payloads; actual cross-device latency depends on network and server load.

## Deployment and checks

Pull collabora-document-integration, make changed application files readable by www-data, reload php8.3-fpm and restart ecollab-websocket. No database migration is required. Hard-refresh both chat and whiteboard tabs.

CI tests real Yjs typing propagation, inactivity clearing, no echo, switching rooms, disconnect cleanup and whiteboard delta contents. Verify those behaviors with two accounts after deployment.

For Collabora delays, check container CPU/RAM during simultaneous edits, the production Nginx WebSocket upgrade and proxy_buffering settings, and WebSocket connectivity in the editor's browser Network panel. The repository proxy example has proxy_buffering off; that does not verify the VPS's current configuration.

References: https://docs.yjs.dev/api/about-awareness and https://docs.yjs.dev/api/shared-types/y.text
