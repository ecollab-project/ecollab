# Authorized Phase 3AF correction checkpoint

Base:28132097544b555f71fc6b6023fd3dc2ef0f6616. Branch:correction/phase3af-code-attachments-20261030.

Owner authorized native channel code editing,authenticated attachment confidentiality and a local synthetic environment proposal. No merge,production/deployment/live-mail or capacity certification.

QA leads;Security,Integration and Database provide review lenses. No independent agents were spawned.

Initial editor scope:assets/js/chat/functionality-overrides.js and chat.js. Existing send-message API remains authoritative. Code is rendered as escaped literal text;Collabs routing for other actions is retained. Bind destination at editor open,retain draft on API error,prevent duplicate submit,and clear only after success.

Attachment work must cover API/chat/upload-file.php,API/dm/upload-file.php,API/chat/resumable-upload.php,services/ChatTusStorage.php,services/ChatUploadAccess.php,services/MessageService.php,API/dm/send-message.php,API/dm/group-message.php and their UI consumers. These are an inventory,not a finalized implementation manifest. Legacy writers return web-root paths;completed tus writes do likewise. Public avatars use uploads/avatars;thread images use uploads/threads. A blanket uploads denial would affect unrelated consumers. Review them before any routing change.

Proposed design:private storage outside the web root;server-issued upload IDs with uploader/destination metadata;message binding inside transaction;authenticated download checks current message existence and current membership/participation at read time. Deny orphan access except uploader preview before binding;deny deleted-message/revoked-member/anonymous/outsider access. Never adopt an arbitrary legacy path as a newly owned upload. Existing physical files remain untouched;legacy private URLs require narrowly scoped server routing and authoritative relationship lookups,with explicit handling of missing/ambiguous ownership. Public avatars remain public. No production transition occurs.

Remaining:complete upload-consumer inventory and allowed-file guard,implement confidentiality across legacy and tus paths,actual MariaDB/HTTP/Chromium negative controls,full accepted regression/OTP/identity/static/backup suite,final-head CI and independent audit. Current editor edits are NOT yet browser-validated or accepted. Phase3AG remains blocked.

Checkpoint verification:node --check passed for both edited scripts. NODE_PATH=../ecollab/node_modules node tests/phase3af-code-editor.cjs passed,using jsdom dialog shims and a simulated API. It verifies destination capture,error draft retention,successful clearing and preserved resource/Collabs dispatch. This is not an HTTP/MariaDB/Chromium or safe-rendering acceptance result. Attachment confidentiality implementation and all required final gates remain NOT RUN/incomplete.

## Restored implementation, November 1

The published editor checkpoint survived a workspace reset; unpublished attachment work did not. The restored candidate must be judged by fresh records, not the earlier lost samples.

Implemented private attachment bytes and immutable uploader/destination receipts, checks at message binding, live message/thread/DM read authorization, scoped upload routing, and public-avatar preservation. Original physical legacy files are retained. Unknown legacy paths cannot be adopted by new message writers. A path reused across old references fails closed if any reference is deleted or unreadable. Current thread owner/admin and channel-member rules are distinguished from chat moderator rules. Code messages preserve whitespace and use literal rendering; reply/pin/menu no longer embed message text in executable attributes.

The runtime manifest pins the exact authorized paths and preserves security, identity, migrations, dependency locks and application configuration from the accepted baseline. Historical source guards remain active unless the explicit correction flag selects this manifest.

Fresh local controls: editor DOM, literal rendering, 17 SQLite authorization cases and private-store ownership controls pass. The native FPM/MariaDB test passes 34 attachment/code cases through the owned gateway. Actual Nginx, hosted Chromium, full regression/OTP/identity/static, full private-storage backup/restore and final artifact verification remain required. CI must run the tracked Nginx upload rule; a PHP-only or gateway-only result cannot certify legacy static-file confidentiality.

A fresh reviewer identified a private-thread moderator mismatch and a tuple-key environment typo in the backup harness. Both were corrected. That reviewer stopped at a usage limit, so the review was incomplete and does not provide independent acceptance.

Rollback for the isolated candidate: stop the owned services, restore the complete source/configuration/database/legacy uploads/private attachment receipt+byte backup into an empty local installation, rebind only owned filesystem paths, and verify fresh login plus authenticated download. Never roll back only application files while leaving a mismatched storage layout. No production rollback or transition is authorized.
