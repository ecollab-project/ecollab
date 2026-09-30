# eCollab LiveKit deployment

This branch introduces LiveKit as eCollab's media SFU. The existing Ratchet WebSocket remains responsible for text chat, presence, invitations and other application events.

## Required environment

Set these only on the server; never expose the API secret to browser JavaScript:

```bash
LIVEKIT_URL=wss://livekit.ecollab.cloud
LIVEKIT_API_KEY=<generated-key>
LIVEKIT_API_SECRET=<generated-secret>
```

The PHP endpoint `API/chat/livekit-token.php` validates the authenticated eCollab user and channel membership, then issues a short-lived room-scoped JWT.

Room names are deterministic:

```
ecollab-channel-<channel_id>
```

## VPS outline

Run LiveKit on the VPS/container host and place its HTTPS/WebSocket endpoint on a dedicated hostname such as `livekit.ecollab.cloud`. Production WebRTC also requires LiveKit's documented TCP/UDP media ports and TURN/TLS configuration to be reachable through the VPS firewall and any upstream firewall.

Do not use `livekit-server --dev` in production.

## Browser integration

Load the LiveKit browser client before:

```
assets/js/chat/livekit-voice.js
```

The adapter exposes:

- `EcollabLiveKit.connect(channelId)`
- `EcollabLiveKit.disconnect()`
- `EcollabLiveKit.setMic(enabled)`
- `EcollabLiveKit.setCamera(enabled)`
- `EcollabLiveKit.setScreen(enabled)`

The next migration step is to route the existing functions in `assets/js/chat/voice.js` through this adapter and then delete the old per-user `RTCPeerConnection` signaling paths after regression testing.
