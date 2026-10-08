# Whiteboard presence with Yjs

Yjs 13.6.27 and y-protocols 1.0.6 provide transient awareness for Excalidraw whiteboards. Both are MIT-licensed open-source packages, loaded through the whiteboard import map.

## Behavior

- Colored cursors, selected-element highlights, active/idle state and a collaborator avatar/count display.
- Joining sends the current room snapshot. Reconnecting clears stale state before restoring it.
- Closing a tab removes that connection immediately. Other tabs remain present; missed heartbeats expire after 30 seconds.
- Cursor updates are coalesced every 100 ms. Presence does not write drawing state or database rows.

## Transport and access

The existing authenticated Ratchet connection carries JSON awareness states. PHP binds the Yjs client ID to a connection, rejects duplicate IDs and stale clocks, validates cursor/selection fields and supplies the authenticated user's identity. Incoming room IDs never override the room joined by the connection. Recipients must still have access to the board. The browser converts canonical updates into standard Yjs awareness frames and applies them with y-protocols.

This adds awareness to whiteboards. Existing Excalidraw drawing revision merging, Collabora editing and platform online status retain their current storage and permission boundaries.

## Deployment

Pull collabora-document-integration, make changed application files readable by www-data, reload php8.3-fpm and restart ecollab-websocket. No new database migration, Node daemon, Composer dependency or port is required. Hard-refresh each whiteboard tab after deployment.

## Verification

CI runs real Yjs protocol tests and pure PHP presence tests. Test the same board with two accounts: move cursors, select shapes, switch tabs, reconnect, then close one of two tabs for the same account. A third account without board access must not receive the room's awareness.

References: https://docs.yjs.dev/api/about-awareness and https://docs.excalidraw.com/docs/@excalidraw/excalidraw/api/props/
