# Calls while using other workspaces

A full PHP page navigation unloads the document that owns LiveKit and the microphone. Keep that document as the Call tab and open internal workspace navigation in a separate reusable tab. Media, video and screen sharing remain in the original document; no track transfer or reconnect is attempted. Authenticated workspace pages expose status and microphone/audio/leave commands in the existing bottom call dock. The account-footer microphone/audio buttons also proxy to the media-owning tab. No floating left-hand call panel is created.

Implementation:
- Shared call bootstrap loads continuity controls on authenticated top-level pages, including Code Lab.
- DM/group outgoing and active calls and server voice connections publish generic state; incoming ringing alone does not trap navigation. Explicit controllers prevent a second call from starting in another tab.
- Standard links and native forms preserve the Call tab; existing AJAX handlers run first. Chrome Navigation API catches document-changing imperative navigation and ignores same-document history updates used by chat views; key dashboard/whiteboard/Code Lab entry points explicitly use the helper for browsers without it. Primary reference: https://developer.chrome.com/docs/web-platform/navigation-api/ .
- Same-origin workspace handles are reused, opener is removed, external tabs are never taken over, and blocked popups leave the call intact with an actionable message.
- Per-account BroadcastChannel with storage fallback conveys only generic status and narrowly allowed controls. No peer names, room IDs, tokens or media go into storage. Owner-targeted commands have unique IDs, freshness checks and deduplication across transports. Logout explicitly ends the call.
- Explicit reload/close and browser history leave the usual unload warning; actual pagehide still cleans up media. Keep the Call tab open. Browser/OS background suspension can still interrupt media, especially on mobile; this is not a service-worker media implementation.

Acceptance:
1. Start DM/group or server voice, open Code Lab/dashboard/whiteboard; original media document remains open.
2. Navigate within workspace pages and mute/leave using shared controls; commands apply exactly once.
3. Other accounts do not receive status; blocked popups cannot silently unload the owner.
4. End/logout removes remote state; normal navigation without an owned call remains unchanged.

Verification: JSDOM tests cover navigation, blocked popups/forms, account isolation, dual transport deduplication, storage-only voice controls and logout. A Chromium CI test covers actual popup creation, opener removal, workspace reuse, native imperative navigation and cross-tab commands with a stub media controller. Existing LiveKit controller regressions cover provider-facing operations. Real two-user LiveKit media, screen-share continuity and mobile background behavior remain VPS acceptance checks.

Deploy: pull feat/collaboration-assistance-matching, run pending migrations (including earlier DM identity migration 065), restart php8.3-fpm, hard refresh. No additional database migration or Ratchet protocol change is introduced here.

## Navigation and stream follow-up

Source: feat/collaboration-assistance-matching, prior remote 9c0a0a0. Realtime leads, with frontend controller, integration and browser QA reviews executed as playbooks. No permission or server protocol changes.

Reproduced two regressions before patching: a synthetic same-document navigate event was intercepted and spawned a workspace tab; clicking the actual remote stream Watch button left expansion disabled. The first came from intercepting chat history.replaceState. The second came from both voice.js addEventListener and livekit-voice.js onclick firing for one remote click. The adapter now leaves Watch ownership to voice.js. Restore controls remain visible after expansion, with keyboard/touch access.

Verification adds actual click regressions, existing-footer remote control checks, no-floating-panel assertions, existing-dock mounting, history-only browser navigation and Chromium desktop/touch stream Watch/Restore. Synthetic media tracks exercise rendered controls; this does not certify two-user LiveKit network delivery. Source rollback is a revert of this follow-up; no data migration is required.
