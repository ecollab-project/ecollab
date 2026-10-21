# A10: adaptive streaming remains blocked

The application loads LiveKit JS **2.22.3** in `modules/chat/chat.php`; the media capacity harness pins the same version. Voice constructs `Room` with `adaptiveStream:false` and `dynacast:true`.

The current lifecycle is not safe for an adaptive-stream experiment yet:

- `attach()` starts by detaching every element and removing it, including on repeated subscription/unmute events.
- Camera attachment then removes the temporary element, replaces the in-card renderer, and attaches the track to the visible video.
- Screen sharing detaches the SDK element and sends a new `MediaStream` into the separate screen-share renderer. Adaptive visibility observation cannot be assumed to follow that element.
- Participant removal and layout rendering can replace elements while subscriptions remain active.

No staging LiveKit deployment, real media browsers, or mobile devices are available in this session. Existing DOM/SDK fixtures can verify renderer behavior, but cannot prove bandwidth selection, visibility observation, or foreground recovery. A feature flag without those lifecycle guarantees would expose the already documented playback regression. **No flag enabled or introduced; existing behavior retained. A10 is a documented blocker under the Phase 2 definition of done.**

## Required experiment before implementation approval

1. Maintain a stable element registry keyed by participant, publication SID and source; move existing elements across layouts without detach/recreate. Update the screen renderer to retain SDK attachment to the actual visible video.
2. Test camera and screen concurrently, both publication orders, repeated mute/unmute, camera device switches, layout changes, reconnects, and mobile background/foreground.
3. Add a server-controlled flag that is permitted only in a verified staging environment and defaults off. Keep dynacast unchanged.
4. Run paired baseline/experimental sessions with identical real WebRTC publishers/subscribers; record inbound bytes, frames decoded, video dimensions, stream-state transitions and packet loss. Include device/browser and network conditions.
5. Define a failure threshold from these measurements (for example a confirmed subscribed/unmuted track with no decoded-frame progress for a measured interval). Test the recovery transition before allowing automatic fallback. Do not invent a universal threshold or silently rebuild active rooms.
6. Disable the flag and reconnect staging sessions to restore baseline. Only consider production rollout after the separate acceptance gate.

Real bandwidth, quality, camera switching, screen subscriptions and mobile recovery tests: **NOT RUN**. This blocker is independent of the shared room-count collector.
