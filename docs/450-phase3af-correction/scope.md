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

## Final review corrections under validation

The bounded native-code journey also includes the existing edit API and realtime consumer: API/chat/edit-message.php and assets/js/chat/socket-core.js. Code edit/cancel/save/realtime rendering preserves literal code and code-only whitespace; normal text trimming remains. The manifest now pins 21 runtime paths. Extensionless text upload names are supported. New orphan image previews use local object URLs and release them on removal.

Fresh expanded native local coverage passes 51 cases, including group attachments, revoked group membership, deleted DM/reply files, code editing and extensionless text. Hosted Nginx previously passed the pre-expansion candidate a9d097b7b2c0800cbf56a0078192a47a90086c72; final-head validation is still required. A hosted origin-control artifact at 2f0caa8fc236783a97b0cdb464e1caa328bbf3f3 failed private-thread image delivery because the two fixture listeners used different local origins. ZIP SHA256:55d1071f053816a7d30df140f901d04296e3d18f7d718a7cfa14340c66c480b6. Its raw record is preserved as nginx-origin-before.json.gz. The corrected test holds one public Host while using separate owned listeners.

The backup harness now drains HTTP and stops FPM before database/file snapshots. Private receipts and bytes are included and independently hash-checked after restore. Existing Apache routing execution remains unverified; the nominated isolated candidate uses Nginx and must pass its actual scoped-rule gate. No production server routing is changed or certified.

The final download contract retains original client-facing names: the response does not override same-origin download-link filenames with private storage names. A native header regression failed before this correction and passes afterward. Chromium now performs an actual download click and verifies suggested filename plus bytes. Fresh local native coverage passes 52 cases. The download-intermediate-development record reflects a short-lived unpublished editing error and source mutation; it is retained for transparency and excluded from acceptance evidence. Only immutable hosted records may establish final-head acceptance.

A later full hosted run3848 retained a required migration-rehearsal failure: the existing one-second observer did not see the contender waiting during the rollback round. Artifact13065817003 ZIP SHA256:d3faed9facf4a242f1cbf7cfdb5929a7deeaa304ab460b6e28fe5f8ba87dbced. No application or migration source was changed. The test now captures the exact child connection ID and requires that transaction to appear in INNODB_LOCK_WAITS within a bounded five-second deadline, while retaining the duplicate-key/rollback and final-row-count assertions. It is stricter about which transaction waited. A fresh local complete migration/recovery suite passes; the hosted rerun remains required.

Restored-state authorization is now explicitly required by the acceptance reporter. Fresh local FPM restore checks return401 anonymously,404 to an outsider,200 to a current member,404 after membership revocation and404 after message deletion. Those fixture mutations occur only after backup/restore fingerprint equality and preserve the original checkpoint/backup. Local Chromium remains unavailable, so that local run is overall NOT accepted despite its restore checks passing; immutable hosted browser and restored-state results are required.
