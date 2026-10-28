# Phase 3Z — OTP clock policy investigation

**Ready for independent scoped review; application/security correction BLOCKED pending separate authorization.** This is a test/proposal phase, not a performance optimization or release approval. Exact accepted base: `b09e66c3fcba5066c63da8bece245ae5f4068410`, tree `032d070ef79a57d6b9498332dea9f690d79fb6af`. Branch `investigation/phase3z-otp-clock-20261028`, stacked on Phase3Y. PR94 was rechecked open/draft/unmerged at accepted head; all seven returned accepted-head runs still succeeded. Publication PR/head/tree and completed final-head CI are pinned in the PR description; this document is an explicitly pre-CI snapshot.

## Findings

| Finding | Reproduced observation | Disposition |
|---|---|---|
| Forward timezone mismatch | PHP UTC/DB+06 yields effective DB expiry -21000s relative to generation and rejects a fresh optional-login OTP | **FAIL**, known adverse condition retained |
| Reverse timezone mismatch | PHP Manila/DB UTC gives a nominal600s persisted OTP an effective DB window29400s (8h10m). The real reset-token service accepts at a simulated database age601s without a pending-login intent | **FAIL**, independently demonstrated clock condition, no correction |
| Verification-time DB zone change | A naive persisted DATETIME is reinterpreted by the new DB wall clock; the effective interval changes. Merely switching PHP timezone after generation does not repair/change the SQL predicate | Characterized across all three actions |
| Verified signup marker | Successful verification removes pending hash/expiry. Registration still accepts the verified marker after a test control reintroduces an expired pending timer | Current behavior characterized; no independent verified-at TTL exists, no new policy |
| Consumption concurrency | Two same-original-session overlapping HTTP OTP submissions have one success; session rotation/locking/CSRF outcomes are retained | PASS for that bounded case; separate-session SELECT/UPDATE atomicity is **NOT RUN** |

The reverse result is **not** proof of extending the real optional-login PHP session intent: that independent epoch deadline remains in force. Native experiments advance/rewind only the owned connection with `SET SESSION timestamp`; PHP time is unchanged. Password-reset OTP verification has no equivalent pre-verification session intent gate, and the real AuthService issues a reset token in the adverse controlled condition. No claim of waiting601 real seconds or reproducing production configuration.

## Executed validation

| Check | Local result |
|---|---|
| Extended four-cohort direct FPM HTTP | PASS for defined characterization;169 requests,17 cases; retained adverse OTP outcomes |
| Native clock matrix |144 records:4 cohorts ×3 clock scenarios ×3 actions ×4 points |
| Boundary points |Stored expiry-1s accepted, exact expiry rejected,+1s rejected; separate generation+601s control for every scenario/action |
| Regeneration |4 cohort controls: old code rejected,new accepted; explicitly owned expiry override isolates invalidation from timezone failure |
| Purge |24 controls:expiry/used cutoff24h-1/at/+1; strict older-than predicate preserved; NULL used_at included |
| New HTTP reset flow |Real forgot→OTP verify→reset→replay rejection→old password rejection→new login PASS |
| Debug OTP disclosure |169 actual additional JSON responses recursively checked for absent/null debug-only OTP fields,APP_DEBUG=false |
| Same-session OTP overlap |Two real overlapping requests,exactly1 successful authentication,no new user |
| Existing Phase3Y HTTP |192 direct requests,27 direct cases,74 independently checked rejection records; local browser NOT RUN |
| Additional state rejection checks |28 records; aggregate concurrent records checked separately. Combined102 state-preservation records |
| Original control8274702 |**FAIL** retained:46 tests114 assertions,5 original failures,child exit1 |
| Accepted Phase3X928a3b8 |PASS57 tests245 assertions; current60/280 in exact comparison |
| Accepted Phase3Yb09e66c |PASS60 tests280 assertions; current60/280 in exact comparison |
| Current default/reverse/random31028 |PASS60 tests280 assertions each,0 errors/failures/skips,exit0 |
| Unit/security/source/syntax |Unit25/56; existing client-IP/request-security regressions; source/diff gates PASS |
| Full exact-base PHPStan |15 inherited baseline and candidate,0 added. Both analyzers exit1,comparison exit0; full-clean **FAIL** |
| Hosted final-head/browser |**PENDING in this snapshot**. Workflow requires actual Chromium and complete hosted artifact. Final PR body records independently verified outcome without changing tested head |

Total361 direct requests combine the two local harnesses, with overlapping standard-journey coverage intentionally retained to preserve Phase3Y acceptance. Native clock records and browser navigation are separate counts. Expected negative HTTP responses and adverse timezone controls are not described as zero failures. No throughput/latency improvement is claimed.

Each cohort uses its own migration-derived MariaDB10.11 database,PHP8.3 CLI/FPM,4-worker pool,real middleware/session/CSRF/rate limits and existing loopback-only delivery receiver. No authentication API stubs or real email. Initial schemas:68 successful migration entries,160 tables,matching DDL hashes. otp_codes is lazily created by application service and separately hashed. All cohorts clean up to0 synthetic users/0 orphan OTP rows. Business-state assertions cover nine relevant tables plus OTP checksum,not all160 tables. Legitimate audit/rate-counter writes are not treated as business corruption.

Local runtime:PHP8.3.6. Visible resource limits recorded in full raw evidence:affinity CPUs0–8,cgroup CPU max800000/100000 (8CPU quota),memory8589934592 bytes (8GiB). These are test-host limits,not the intended two-vCPU VPS. Loopback HTTP has HttpOnly/Lax/Secure=false by explicit fixture settings. TLS/proxy/production cookie or runtime parity remains BLOCKED. Local Playwright module exists but Chromium executable is absent; NOT RUN stays distinct from hosted browser proof.

## Source provenance and retained failures

Native full/static frozen harness: `5b144551241d119b8508a2c5c4f75dfce8fcf452`, tree `9038485b451841e03311d860f30a3ea66c3fc656`. Extended matrix frozen harness: `d252d22c90bbb5938a331142219bb36eac9052f5`, tree `07fcac17e71a2720d904d1cdd907d35e6b6aa866`. Final reporting/proposal harness: `1539c2b9df60dec4c9b2a0e2635af0e3c4bd7dcc`, tree `34a77c2b1e600b98714f408ff64cb8f732cd595b`.

The latter reporter change distinguishes requests intentionally lacking individual snapshots in the concurrency experiment from its separately checked aggregate; no application or outcome assertion is relaxed. Exact source hashes in successful HTTP/matrix/full records match final publication. Local Git-generated equivalent commits differ from connector-created commits through metadata: b0dea84d/5b144551 share9038485tree; e7dd00a3/1539c2b9 share34a77c2tree. Actual SHA/tree and intended head are retained separately. Command-log `head` is captured on completion; raw source snapshots identify execution-start source. Some accepted-control execution began on an equivalent local metadata commit; no app overlay. Native suite source dependencies remain exactly unchanged through publication; hosted final-head execution revalidates combined sources.

First discovery attempt is retained **FAIL**: four empty500 responses at initial CSRF endpoint. It used a vendor symlink resolving the previous worktree. A private vendor copy and Composer dump-autoload corrected fixture setup; application source remained unchanged. The original HTTP bodies are empty and no app-defect cause is asserted beyond the observed setup difference. Discovery2 and earlier successful frozen matrix are retained under their own sources; they precede the added reset/concurrency coverage and are not substituted for the final run.

Phase3Y's first hosted browser failure at79fa2c3 and skipped static step remain in the unchanged accepted raw files. Successful b09e66c hosted artifacts are additionally archived here, separately labelled. Both downloaded ZIP hashes were recalculated:success `cfb082a00415e6a1d95643bec4d4f5791a900c62c7503911d1733b6946366b29`;failure `e1e7b273b23708dfe82f3424d7a887ad1de7905f12bbaa912de9439030507a7c`. The original coarse failure exception remains unavailable. Nothing is relabelled as successful.

Raw JSON/log bytes are losslessly gzip archived with compressed/expanded digests in [manifest](450-phase3z-raw/manifest.json). Records are semantic redacted data with original response hashes,not an unredacted wire replay. No plaintext OTP/password/cookie/CSRF/reset token or production identity is persisted. Private loopback transport retains codes only in memory. Test source necessarily declares owned fixture constants; evidence excludes submitted credential values.

## Reproduction and commands

See `450-phase3z-evidence.json` and raw local-commands/frozen-checks/full/static records for exact commands,environment,stdout,stderr and exit codes. Commands below assume dependencies installed and owned local binaries configured,as the new workflow does:

```sh
# full original failing control and all current orderings
CLASSROOM_BASE=827470217e6a6a86a165b14f4b6650a10e2c2395 CLASSROOM_PHASE3X=1 CLASSROOM_EVIDENCE=phase3z-raw/full.json TZ=UTC python3 -B -u tests/classroom-performance.py
# accepted exact controls, run once per accepted SHA (928a3b8 and b09e66c)
CLASSROOM_BASE=<exact-accepted-sha> CLASSROOM_PHASE3W_SCHEMA=1 PHASE3W_FULL_PHPUNIT=1 CLASSROOM_EVIDENCE=<raw-path> TZ=UTC python3 -B -u tests/classroom-performance.py
PHASE3Y_EVIDENCE=phase3z-raw/http.json python3 -B -u tests/phase3y-http.py
PHASE3Z_EVIDENCE=phase3z-raw/timezone.json python3 -B -u tests/phase3z-timezone.py
python3 -B tests/phase3z-report.py phase3z-raw/timezone.json phase3z-raw/timezone-summary.json
python3 -B tests/phase3y-report.py phase3z-raw/http.json phase3z-raw/http-summary.json
python3 -B tests/phase3x-report.py phase3z-raw/full.json phase3z-raw/full-summary.json
PEER_THREADS_BASE=b09e66c3fcba5066c63da8bece245ae5f4068410 PEER_THREADS_STATIC_EVIDENCE=phase3z-raw/static.json python3 -B -u tests/peer-threads-static.py
```

All seven local orchestrated commands and six source/unit/security checks exited0. Original failing-control child and PHPStan analyzers exit1 are retained separately. Full-suite harness wrapper exited0 while correctly retaining five expected original failures. Application-source diff and relevant dependency hashes are empty/equal against accepted base. New CI workflow executes native/current/accepted controls,real required Chromium journey,extended clock matrix,source/security and static comparison; static/upload use `always()` to retain failures.

## Correction gate and remaining scope

Read [complete clock inventory/options and transition proposal](450-phase3z-otp-contract.md). Recommendation is explicit UTC database timestamps for all persisted OTP operations in OtpService,not a global clock change. **No fix is implemented.** Existing naive records cannot be safely converted without provenance. A coordinated reviewed invalidation or versioned transition is required before new writers; no migration/invalidation is run. No new marker TTL,session equality rule,ordinary-login MFA rule or atomic-consumption policy is introduced.

Retain BLOCKED/NOT RUN:Phase3W independent acceptance;production clock/TLS/proxy/session parity;15 inherited static diagnostics;original natural-lock attribution;FPM saturation/queue calibration;physical-empty process/resource baselines;instrumentation/transaction telemetry;broader security/release gates;representative sustained50–100-student capacity. No merge/deploy/Hostinger/production credentials/database/schema change.

Rollback:keep draft unmerged; if separately integrated,revert only Phase3Z tests/workflow/docs to exact accepted b09e66c. No application/schema rollback needed. Any future UTC correction requires a separately reviewed data-compatible rollback,including mixed-writer handling and no resurrection of used/invalidated codes.
