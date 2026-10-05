# Calls deployment and acceptance

This update replaces direct-call peer-to-peer media with LiveKit and shares one call panel across authenticated pages. Chat keeps its existing Ratchet connection; other pages open an authenticated signaling connection. Embedded editors do not open a duplicate call panel.

## Deployment

Pull `collabora-document-integration`. Give `www-data` read access to changed files and traversal access to the new `includes/calls`, `assets/js/calls`, and `assets/css/calls` directories. Run PHP syntax checks, reload PHP-FPM, and restart `ecollab-websocket` after current calls have finished. Both users must refresh their pages: old WebRTC direct-call clients are deliberately rejected rather than connected to incompatible media.

No database migration or Composer dependency update is required. The existing `dm_call_history` migration must already be installed. The configured LiveKit host must proxy `/twirp/livekit.RoomService/` to LiveKit as well as its WebSocket endpoints. The application uses server-only `CreateRoom` and `ListRooms` calls with short-lived signed credentials. Never publish API secrets to browser code.

Group calls use new `ecollab-dm-group-v2-ID` rooms with a server-enforced limit of 50. Direct rooms are scoped to the authorized call log and limited to two participants. An unavailable room API prevents joining instead of silently bypassing the cap. Existing unlimited group rooms are not modified or disconnected.

## Automated checks

```bash
php -l services/LiveKitService.php
php -l API/dm/livekit-call-token.php
php -l API/chat/livekit-counts.php
php -l websocket/handlers/DmHandler.php
npm install --prefix /tmp/ecollab-call-tests jsdom
NODE_PATH=/tmp/ecollab-call-tests/node_modules node tests/livekit-calls-regression.cjs
NODE_PATH=/tmp/ecollab-call-tests/node_modules node tests/livekit-voice-regression.cjs
```

The browser-state tests use mocked media/signaling. They check independent cameras, local group preview, foreign call-id rejection, six-tile selection, cleanup, cancellation, quality options, matching mute sections and participant counts. They do not prove real device media connectivity or 50-user performance.

## Two-device acceptance

Use two separate accounts, one desktop browser and one phone.

1. Direct video: both cameras on. Turn A off/on, then B off/on. Only the actor's camera and local button should change; both users see the other participant correctly. Repeat after muting/unmuting.
2. Incoming calls: keep B on each dashboard, a document, a whiteboard, and the library. Call from A. Desktop incoming panels are centered and minimizable; touch/narrow devices receive a top panel. Accept audio only, decline, and allow a 45-second timeout. No navigation to chat is required.
3. Multiple tabs: accept in one tab. Other ringing tabs should dismiss. Starting another call in the same browser is blocked while the call lock is held.
4. Groups: start a group call and accept on the phone. Both users should see their own local preview and the remote camera. Add participants: six people are displayed, with active speakers prioritized, while all participants remain in the audio call. Test the capacity boundary on a staging room; no 50-user load test was performed during development.
5. Settings: change microphone/camera devices, toggle browser noise suppression, and deafen/undeafen. Speaker selection is disabled where the browser does not support it. Camera or microphone denial should leave the call connected with an actionable message.
6. Voice channels: join/leave, mute/unmute, and compare both devices. Muted users appear in Listening on both; enabled microphones appear in Speaking with activity indicated separately. Joined-room counts update from room events; other sidebar counts refresh approximately every ten seconds.
7. Screen sharing: choose 720p/1080p/source, start sharing, and stop using the browser's own stop control. Check the sharing indicator clears and no stale preview remains. Confirm remote text is legible. Source/network/browser constraints can reduce delivered quality. Mobile browsers without display capture show sharing unavailable.
8. Coworkspaces: compare the same server with both accounts. Public sessions across all accessible workspaces should appear. A private unshared session must remain hidden. Opening, permission changes and deletion must use each resource's own workspace id.

## Boundaries

The page must remain open for incoming web calls. This is not native push calling when a phone/browser is suspended. A full page navigation or refresh ends the active call; minimizing the panel preserves it on the current page. Ringtone autoplay can be blocked until the user interacts; the visual notification still appears. Noise suppression is the browser's supported boolean control, not an advertised proprietary AI cancellation mode. Screen-share quality is a capture/encoding target, not a guaranteed resolution under congestion.
