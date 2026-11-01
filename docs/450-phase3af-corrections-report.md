# Phase 3AF corrected candidate — independent audit handoff

**November1 provenance addendum:** See [the reconciliation](450-phase3af-provenance-reconciliation.md) and [separate original/later manifests](450-phase3af-correction/provenance/provenance-ledger.json). The historical `hosted-raw` TAR/manifest describe post-download recomputation; three derived hash-map reports differ from the original ZIP, with unchanged verdict payloads. Original hosted report bytes are now preserved separately. Four later derived-summary references are explicitly recorded as stale. See the [selected VPS proposal](450-phase3af-hostinger-defense-proposal.md); no VPS operation has been performed.

Engineering verification: PASS within isolated tested scope. Independent acceptance is PENDING. Full defense certification remains BLOCKED pending independent audit and final stable-environment acceptance. Phase 3AG has not begun.

Draft PR: https://github.com/ecollab-project/ecollab/pull/105

| Identity | Exact value |
|---|---|
| Branch | correction/phase3af-code-attachments-20261030 |
| Base | `28132097544b555f71fc6b6023fd3dc2ef0f6616` |
| Candidate head | `f9e63ff750c0a6c6209810e1e37167105875d371` |
| Candidate tree | `a8502d5da68184d3aa2504231e8083ad88df156f` |
| Accepted PR102 application | `c65d2f994b10dfa7890f6509a2342e58a064571e` |
| Unchanged integration reference | `c838359711a14e9b6eb3f345c4386d184a178b25` |

The separate evidence branch publishes this report without changing the tested PR head. Its commit is an evidence container, not a separately tested implementation. All changed paths are in `450-phase3af-correction/final/changed-files.txt`; the 21 runtime paths and complete SHA-256 values are pinned in `450-phase3af-correction/runtime-manifest.json`. Accepted OTP, identity, database/migrations, configuration and dependency locks remain unchanged. Hosted installation records no uncommitted substitutions or harness overlays.

## Implemented and tested

The existing Code Snippet action opens a native channel editor and submits through the existing message API. Code preserves whitespace, renders literally and remains editable after reload. Editing uses a textarea with ordinary Enter for newlines and Ctrl/Cmd+Enter to save. Cancellation and realtime edits retain literal code. Existing Collabs documents and whiteboard dispatch remain separate.

New legacy chat, DM/group and completed resumable uploads store bytes and immutable uploader/destination receipts outside the document root. Message/thread writers bind those receipts to the authenticated uploader and authorized destination. Downloads check current stored message/thread relationships and current access, including public channels. Orphaned, deleted and unreadable reused legacy references fail closed. Legacy physical files are retained; arbitrary legacy paths cannot be adopted as fresh uploads. Original download names and public avatars are preserved. Pending image previews use local object URLs.

The nginx test runs the actual scoped upload rule against PHP-FPM; direct root upload URLs and the complete threads subtree reach authorization. It covers encoded paths, HEAD requests, legacy bytes, public-avatar access, cross-user/destination binding, revoked membership and deleted content. Apache configuration is included but was NOT runtime-certified. A generic static handler or `^~ /uploads/` bypass would invalidate confidentiality; the isolated nginx rule must precede static regex handlers.

## Exact-head results

| Gate | Result |
|---|---|
| Returned workflow runs | 18/18 PASS (17 workflow definitions; resumable uploads also ran on push) |
| Latest-attempt jobs | 19/19 PASS |
| Job steps | 250 PASS /0 FAIL /0 skipped |
| Required integration commands | 17/17 exit 0 |
| Actual expanded Chromium journeys | 11/11 PASS |
| Native nginx/HTTP attachment and code checks | 54 cases; status PASS |
| Current PHPUnit | 60 tests /280 assertions, default/reverse/random PASS |
| Accepted historical suite comparisons | PASS; original46-test five failures retained |
| OTP clocks / competing sessions | 180 observations /8 single-winner rounds PASS |
| Original unsafe controls | 360 clock observations /10 unsafe race rounds retained |
| Identity | 32 HTTP,22 contract checks,9 migration outcomes PASS |
| Message/poll | 61 HTTP,27 cases PASS |
| Composed collaboration | 52 HTTP and role/viewport browser checks PASS |
| Authentication | 207 HTTP, six browser checkpoints and two-context reset PASS |
| PHPStan | Zero additions;15 inherited diagnostics |
| Cold backup/restore | PASS;4990 source/vendor/upload files,162 table fingerprints |
| Restored negative attachment controls | Five PASS:401 anonymous,404 outsider,200 member,404 revoked,404 deleted |

Actual Chromium covers classroom creation/navigation, text, code dialog and multiline edit/cancel/save/reload, public/private polls and voting, matching, human DMs, threads, notifications, owned upload/download and CSRF/private-history controls. The real download click verifies original filename and bytes. Backup quiesces HTTP/FPM writers before dump and file snapshots, restores source/vendor/configuration/database/legacy/private receipts and bytes into independent empty owned directories, compares fingerprints, then verifies fresh login and authorization. Private configuration/backups are not public artifacts. Old sessions are not resurrected.

Full hosted artifact: `13079396055` from run `41295404805`; ZIP SHA-256 `e40036c33bbd1706c3f4441523f6353d8112413753788bd9452e5b5ed49f93e2`. Downloaded bytes matched GitHub's digest. ZIP members were checked for traversal, absolute paths and symlinks before safe JSON/log extraction. Trusted candidate reporters recomputed security, integration and Phase3AF acceptance with exit0; artifact executables were not run. Screenshots/recordings remain in the hosted artifact. Raw JSON/JSONL/logs are also retained in `450-phase3af-correction/final/hosted-raw.tar.gz`, SHA-256 `7784ec90949873335eaf53052330c52dc58a84d9087072edfceb434848bbee87`; its member hashes and all required commands/exits are published alongside it.

## Failed controls and investigation history

Original code-rendering, multiline editing, whitespace, attachment exposure and download-name controls are retained under `450-phase3af-correction/raw/`. Earlier hosted origin mismatch and hover-only menu test failures are preserved. Browser interaction now uses the actual hover gesture; the fixture holds a consistent public Host across owned listeners. At c48c07c3c58584eea0cb489f293a6202343637dc the installed diagnostic run41295076454 failed the DM send response wait: the visible panel preceded assignment of activeConvId, and no send request was made. Artifact13078659322 SHA-256c697604e6f650f20769ffb3bfffe18f5e6d30a5494f54cc65fe46efbd83ed81a is retained, with its JSON as hosted-dm-before-ready.json.gz. The candidate test requires the intended partner and a loaded positive conversation ID before the real UI send; no application behavior or API assertion is bypassed.

The required migration rehearsal failed on earlier full runs3848 andd9 despite correct duplicate-key behavior. A controlled final-barrier experiment reproduced the observation failure at20ms. MariaDB10.11.14's diagnostic cache requires over100ms idle before refresh; frequent polling prevented visibility. Closing the cursor and increasing total timeout did not fix it. The test now primes an empty snapshot, releases the child explicitly, polls at250ms and still requires the exact child connection to wait. Both parent commit1062 and rollback-created outcomes and final row counts remain asserted. `450-phase3af-migration-observer.md` and five raw logs retain failed hypotheses, the red control and passing full rehearsal. Application and migration code were not changed for this test correction.

An earlier local UTC contract invocation exited255 without retained stdout and with empty stderr. Its cause remains unexplained. The failed raw security record, passing isolated rerun and local browser-unavailable record remain preserved on the evidence branch. A subsequent complete local UTC prefix and final hosted clock/concurrency/contracts runs passed. Passing reruns do not erase this anomaly; the independent auditor must assess it.

## Defense candidate and environment boundary

Nominate the exact head/tree above only as the isolated synthetic candidate for audit. The tested environment is Ubuntu24.04 hosted CI, PHP8.3-FPM and MariaDB10.11 with loopback listeners, fresh synthetic fixtures and CLI-only machine provisioning. Source, lock, runtime, configuration and backup hashes are recorded in the installed evidence. No real accounts or production database are used.

The persistent local environment plan is `450-phase3af-correction/local-defense-environment.md`. Complete cold restore and restart passed within the runner/workspace lifetime. Persistent retention across a dedicated local machine reboot is NOT RUN, and no stable external/public defense host is provisioned. This prevents claiming a final defense environment is certified. Demonstration list: authentication, synthetic classrooms, dashboards, channel text/code/polls, matching, human DMs, threads, notifications and owned attachments. Fallback: verified hosted recordings and a privately restored synthetic local installation after the same checks; readiness of a future machine is not implied.

Excluded/unverified: Apache execution; existing-database migration069; production OTP writer inventory/cutover/history retention; real TLS/proxy/database/storage durability; external inference/broker/CodeLab/mTLS/media dependencies; inherited ws_tokens capacity-fixture cleanup; sustained50–100-student capacity. No production release or capacity claim is made.

## Rollback and next gate

Stop the isolated HTTP/FPM writers. Preserve the failed installation and evidence. Restore the complete matching source/vendor/configuration/database/legacy/private receipt+byte backup into empty owned directories, rebind only documented local paths, compare every manifest/table fingerprint, then verify fresh authentication, code history and positive/negative download controls. Do not combine old application files with candidate private-storage state. No production rollback, database transition, merge or deployment is authorized.

Next required work is independent Phase3AF audit, using `450-phase3af-corrections-audit-prompt.md`. Keep PR105 draft. Do not advance to Phase3AG until independent acceptance and remaining essential stable-environment gates pass.
