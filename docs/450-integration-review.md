# Phase 1 and Phase 2 integration review

Review date: 22 October 2026 (Asia/Manila). This is a fresh adversarial review and test pass on the combined code; it is not an external human security sign-off. Production was neither accessed nor changed. No PR was merged or service deployed.

## Decision

| PR | Recommendation at reviewed head | Conditions |
|---|---|---|
| #66 | **APPROVE WITH CONDITIONS** | Merge only after checking the target still matches this review and accepting the documented baseline failures. Deployment additionally requires schema preflight, coordinated voice writers/maintenance, staging smoke tests and recovery validation. Approval does not certify the inherited Jarred paths or capacity. |
| #67 | **REQUEST CHANGES** | Incorporate the isolated fixes below and require CI on the resulting head. Resolve or explicitly gate the remaining moderation, quota/retention and deployment risks before enabling the new worker. Green CI at the old head does not validate these fixes. |

**450 concurrent students remains UNVERIFIED.** Neither synthetic request counts nor a workflow named “Code Lab isolation” proves host isolation, real media capacity or representative VPS performance. Untrusted execution testing is BLOCKED until the execution-host boundary is independently verified.

## Source verification

| Item | Verified value |
|---|---|
| Integration branch | `ecollab-collabs` at `c838359711a14e9b6eb3f345c4386d184a178b25`; comparison with the prior base was identical, zero new commits |
| PR #66 | Open, unmerged, not draft; base `ecollab-collabs`; head `optimization/450-users-phase1` at `8a2dcfd19db15c10d4711856835d68ef799660db`; one commit, 23 files |
| PR #67 | Open, unmerged, draft; base `optimization/450-users-phase1` at that exact Phase 1 head; head `optimization/450-users-phase2` at `e1fe79f7b2cdadf4719e9ef5f1bd82ae14db4b98`; seven commits, 43 files |
| Conflicts | GitHub reported both PRs mergeable at verification |
| Discussion | Combined issue/review/inline-comment retrieval returned no comments on either PR |
| CI | #66: 5/5 returned workflows successful; #67: 11/11 successful, including Phase 2 backend regression and Responsive page layouts. These statuses belong to the exact heads above. |

The GitHub connector returns the first page of PR-triggered workflow runs; this is not a claim that every possible external branch-protection check was enumerated. Diffs and all seven Phase 2 commit records were retrieved. The Phase 2 commits are:

| SHA | Change |
|---|---|
| `5f3997427c989cb40ccc3612aba366d4d083e2e3` | Shared LiveKit snapshot |
| `5cadef4a1f5a4a131787bde11663c6e797230665` | Code Lab polling and hints |
| `6663e9031d4faacf5705152b2a9fb95618348f4b` | WebSocket heartbeat and metrics |
| `b212b411ab12fa348e6e523c3779fe30b6f4dbd2` | Search bounds/keysets |
| `458129dc73c645e01c29a781954cd41b1911feb1` | Jarred queue |
| `871f3df082e534166f167c0d48802f6bb3fac9b4` | Evidence and isolation design |
| `e1fe79f7b2cdadf4719e9ef5f1bd82ae14db4b98` | Client cache versions |

Files changed by both phases: `API/chat/livekit-counts.php`, `API/chat/search-messages.php`, `services/TemporaryVoiceService.php`, `websocket/ChatServer.php`. Review focused on their combined behavior, not just each separate patch. Local review worktree started from the previously verified Phase 2 tree `d03a0db5970b56d46b93153b78655cdfba2a30ed`; local publication commits have different identities from the remote commits, so fixes are published with the actual remote Phase 2 head as parent.

## Findings

### R1 — P1 — Fresh Jarred jobs expire under a non-UTC database clock (introduced in #67; patched)

Location: `services/JarredJobService.php`, `workOne()`; migration `066_jarred_jobs.sql`, `created_at` default. The database stores `CURRENT_TIMESTAMP`, but the worker interpreted its text as UTC with `strtotime(... . ' UTC')`. The isolated MariaDB run observed a new job with age **25,200 seconds**, immediately failed before inference. A positive offset conversely postpones timeout enforcement.

Correction: compute age with `TIMESTAMPDIFF(SECOND,created_at,CURRENT_TIMESTAMP)` in the claim query, using the database clock; SQLite uses its own epoch calculation. The patch does not change existing timestamps or alter production schema. Added MariaDB checks at `-07:00` and `+08:00`: fresh jobs complete and 31-minute-old jobs fail. Application and worker connections must still use consistent database timezone settings; historical DATETIME values do not encode a timezone.

### R2 — P1 — Jarred exposes private resource metadata/context (existing baseline; patched)

Locations: `services/JarredActionService.php::currentSurfaceNavigation()` and `services/JarredTools.php::collaborationContext()`. Navigation queries checked server membership but returned private document/whiteboard titles and resource IDs. The context query treated workspace membership as access to every private resource and could return private whiteboard state summaries.

Reproduction: `tests/review-jarred-resource-access.php` created an ordinary workspace member with no explicit resource permission. Before the patch all three observations were true: private document navigation, private board navigation, private board context. The original action service is byte-identical to the integration baseline, so this is not attributed to the queue implementation.

Correction: use the existing `DocumentAccessService::get()` and `CoworkspaceService::resolveWhiteboardAccess()` boundaries before returning metadata or model context. Regression checks cover private denial, explicit view permission, revoked permission, owner access and an archived workspace. All pass. The fixture directly tests private board context and document navigation; full document-presence/context integration remains covered by code review plus existing access-service tests rather than a browser end-to-end test.

### R3 — P1 — Jarred search gives server moderators private-channel access (existing baseline; patched)

Location: `services/JarredTools.php::searchMessages()`. Its SQL included `moderator` alongside `owner` and `admin`, unlike the Phase 1 private-channel read boundary. An unjoined server moderator could retrieve a private message through the AI context path.

Correction: remove the moderator bypass while preserving creator, explicit member, owner and admin paths. The new fixture fails before correction, passes afterward, and confirms explicit membership restores access. This does not claim every Jarred context method now implements the full channel policy; see R7.

### R4 — P1 deployment blocker — Migration 025 fails on a clean MariaDB 10.11 chain (existing baseline; not changed)

Locations: `database/migrations/024_coworkspace_server_scope.sql` and `025_coworkspace_one_active_per_server.sql`. The latter creates a stored generated column depending on `server_id`, which has an `ON UPDATE CASCADE` foreign key from 024. Both fresh baseline and combined migration runs fail with error **1901**, naming `server_id` in the generated expression. A two-table minimal reproduction with the cascading key reproduces the same failure.

063–066 are therefore not reached by the ordinary fresh migration chain. Do not mark 025 successful or skip it to obtain a green report. Repair requires a separately reviewed compatibility decision for the FK update rule and active-workspace invariant, including existing data; changing a historical migration blindly is not part of this patch.

### R5 — P0 prerequisite — Execution-host isolation is still design, not verified infrastructure (existing; blocked)

Location: `deploy/code-lab/piston/compose.yml`, `privileged: true`; plan in `docs/450-phase2-execution-isolation.md`. No separate runner host, external network boundary, hostile-workload containment or recovery drill was verified in this review. If privileged execution shares the application host, it cannot be treated as a safe boundary for untrusted student programs. No live code-execution load was attempted.

### R6 — P2 — Queue bounds are not an AI usage quota; terminal jobs have no retention (unresolved)

Locations: `JarredJobService::reserveConversation()`, `cancel()`, `recover()` and migration 066. One active job per conversation and 100 global active jobs bound admission, but there is no per-user time-window/token budget or terminal-job cleanup. Repeated completion/cancellation can grow persisted source/job records indefinitely. Cancellation fences results but does not abort an already running provider call. This is not a regression in a previously enforced DM quota: no such quota was found on the old DM path. A product-approved fair-use/retention policy is required before calling quota enforcement complete; none is silently invented here.

### R7 — P2 — Moderation/revocation policy remains inconsistent in inherited paths (unresolved)

Locations: `TemporaryVoiceService::canAccessServer()` / `createRoom()`, `JarredTools::canAccessServer()` / `searchMessages()`, `RealtimeOutbox::recipients()` channel/DM/group branches, and `API/auth/realtime-token.php`. Several inherited paths check membership/deletion without current account/server status or active moderation actions. In particular, facilitator suspension retains membership (`API/facilitator/server-tools.php`), while temporary room creation checks only that membership. Channel outbox delivery does not apply the Phase 1 ban/status policy. Hints contain IDs rather than bodies, and authorized reads are still required, but continued metadata delivery is not equivalent to revocation.

These are code-review findings, not a measured cross-user body leak through the broker. Code Lab's new audience query checks owner, membership, active server and account status, matching its current status-reader service. Immediate revocation of already-issued LiveKit/Centrifugo tokens and existing transport sessions is not implemented by either phase. Resolve these policy gaps before broadening the deployment security claim; the supplied patch is deliberately limited to reproduced disclosure and queue failures.

### R8 — P3 — A revoked room can retain its old sidebar count (unresolved display issue)

Location: `assets/js/chat/livekit-voice.js::refreshSidebarCounts()`. The API omits unauthorized IDs correctly, but the success handler updates only returned IDs, leaving the old badge visible for an omitted room. This does not fetch a new private count. Clear omitted requested IDs or mark them unavailable when the sidebar has not yet removed the channel.

### R9 — P3 — Failed queue timestamps and outage observability are incomplete (unresolved)

Locations: `JarredJobService::recover()` and `workOne()` failure update. Failed jobs do not consistently set `finished_at`. There is also no implemented WebSocket send-buffer/backpressure metric. These limit operations and retention diagnostics; they do not invalidate the verified response fencing.

## Queue, realtime and Phase 1 evidence

| Boundary | Result and limit |
|---|---|
| Source/job atomicity | Admission failure rolls back source, job and outbox intent. Rejected busy turns do not persist orphan sources. |
| Admission and singleton | Real MariaDB contender waits on the gate; after first commit it sees the active job and rejects it. Second advisory lock acquisition fails. These are isolated two-process tests, not a reconnect storm. |
| Crash before message / after message / before job insert | Abrupt child exit rolls back uncommitted state; a subsequent valid admission succeeds. |
| Crash after claim / during inference | Recovery retries running work; one final assistant response persists. Inference itself can run again, so provider execution is at least once. |
| Crash before response commit | Response/job/outbox roll back together; restart produces one committed reply. |
| Crash after response commit | Completed job is not reclaimed; no second response. |
| Crash during outbox insertion | Transaction rolls back and retry preserves exactly one response intent. |
| Crash after broadcast, before ACK | Local HTTP broker mock receives the same event ID again. Durable messages remain unchanged. Delivery is at least once; the existing client deduplicates event IDs. |
| Tool actions | Existing rollback fixture proves a room and dialogue state join the outer transaction; failed action rolls back, retry commits once. This is not proof of idempotency for future external side effects. |
| Cancellation/order | Cancellation during inference prevents final response commit. One active turn per conversation; cancelled source text is still persisted and can enter later history. Already committed actions are not undone. |
| Ownership/preferences | Other user's job status denied; disabled Jarred preference fails queued work; current account/source/conversation checked by worker and again before inferred response commit. Tool action authorization is additionally bounded by the reused resource services. |
| Academic integrity | Existing Code Lab surface/referer rejection and UI tests pass. Client-supplied context cannot prove where pasted code originated; general DM cheating prevention is not established. |
| LiveKit | Count reader has no RPC path, filters through current `ChannelAccessService`, distinguishes stale/missing/future snapshots from fresh zero. 200 authorization combinations and membership revocation pass. Collector uses a database-scoped advisory lock and bounded RPC; failure retains last snapshot until stale. |
| Code Lab | Owner/server-scoped result reads; completion payload has IDs only. Membership removal excludes the new hint. Hidden inputs and grading stdout are not in hints or grading results. Polling recovers missed hints via authorized status reads and visibility/online wake. |
| WebSocket | 1,850 authenticated ping dispatches touch no PDO; unauthenticated ping rejected. Non-ping frames still enter authorization. A ping proves transport liveness only, not continued resource permission. Actual loop/backlog behavior under outage/storm remains unmeasured. |
| Phase 1 | Voice matrix, join/delete locking in both orderings, temporary-room cleanup, multi-tab presence, exact peer-score/batch equivalence, pinned/private reads, pagination and unread fixtures, session lock release all pass on the combined branch. |

Abrupt crash tests use temporary SQLite files and real child exits. MariaDB separately covers transactions, gate contention, advisory locks, migration reruns and timezone expiry. We did not kill a real Ollama process or exercise a real broker cluster/LiveKit cluster. No successful synthetic test is evidence of production capacity.

## Test classification

See `450-integration-review-evidence.json` for individual local commands and outcomes and exact-head GitHub workflow results.

| Classification | Evidence |
|---|---|
| PASS | 289 PHP files linted; `git diff --check`; 25 PHPUnit Unit tests / 56 assertions; focused Phase 1/2 PHP and JS suites; HTTP session/CSRF and Jarred enqueue/status fixtures; eight abrupt queue crash points plus publish/ACK crash; resource authorization regression; MariaDB 063–066 rerun/preservation/locking; document/whiteboard access fixtures. |
| FAIL — introduced at original #67 head | R1: fresh MariaDB queue job timed out; corrected branch passes both positive/negative timezone tests. |
| FAIL — existing baseline, patched here | R2/R3 reproductions expose private metadata/context/message before the corrections; corrected fixture passes. |
| FAIL — existing baseline, unresolved | Fresh migration 025 fails identically. Full Integration: 21 tests, 40 assertions, 5 failures (email-verification prerequisites), 6 errors (missing `rate_limit_log`) on both unchanged baseline and combined code. |
| FAIL — existing baseline, static analysis | Baseline PHPStan: 15 diagnostics; combined/review: 13. Two old private timer-method diagnostics disappear in Phase 2. No new suppression was added; remaining diagnostics are not called a pass. |
| BLOCKED | Representative isolated execution/media/VPS tests: no verified staging or isolated runner was supplied or provisioned. |
| NOT RUN | Full interactive browser end-to-end flows, real Ollama quality/cancellation, production-schema preflight, large-table metadata-lock timing, representative mixed-workload benchmark, real reconnect storm/database-outage recovery and media lifecycle/adaptive streaming tests. |

Initial local harness issues are recorded separately: missing copied Node dependencies caused one client fixture failure, then the locked dependencies were copied and it passed. A whiteboard test correctly refused an absent dedicated DSN, then passed on a newly created local `collabora_test`. Neither was hidden or attributed to application logic. Not every unrelated repository test was executed; the complete repository is not certified green.

## Migration safety and compatibility

| Migration | Review result | Operational requirement |
|---|---|---|
| 063 | Two `CREATE TABLE IF NOT EXISTS` InnoDB tables; existing rows retained in rerun fixture. No FK declarations. | Inspect historical runtime-created table definitions/engine. IF NOT EXISTS does not repair a partial or incompatible table. All voice writers must use shared locking before relying on cleanup races being fixed. |
| 064 | Singleton snapshot table; epoch freshness; rerun and upsert preserve behavior. No FK. | Collector must be active before count reads can be fresh. Missing schema returns unavailable; it must not trigger request-time DDL. |
| 065 | Appends `code_lab` to existing enum; existing values and enum order preserved; rerun works. Unique `(kind,message_id)` and pending-delivery index remain. | Requires existing 052 outbox. ALTER needs metadata locking even when a server can execute it cheaply. Measure on restored representative data, bound lock waits, stop/retry on timeout. No production timing was measured. |
| 066 | InnoDB job and gate tables; unique source ID; queue and conversation/state indexes; rerun preserves rows. No FK declarations. | Application owns referential validation; do not assume cascade cleanup of jobs. Existing partial tables are not repaired. Gate row must exist. Standardize DB session timezone across API/worker. |

Sequence: existing reviewed chain through 062, then 063, 064, 065, 066. Do not run the ordinary fresh chain on an existing deployment until its migration ledger and 025 status are reconciled. MariaDB DDL can commit implicitly; wrapping this sequence in a transaction is not a rollback plan. A crash between the two CREATE statements in 063/066 is recoverable by rerunning only after schema inspection. Concurrent migration runners are not supported by a new global lock: use exactly one deployment operator/runner.

No new foreign keys were added, so there is no new FK type mismatch in these four files. The absence of FKs means orphan prevention/retention must be enforced by application and operations. Do not add destructive down migrations or shrink the outbox enum while pending `code_lab` rows exist.

## Proposed deployment runbook — not executed

1. **Freeze and record:** record approved integration/fix SHAs, checksums, artifact versions, client URL versions and migration ledger. Require corrected-head CI. Check branches again if any head changes.
2. **Backup/recovery:** take a consistent database backup plus uploads/configuration/service definitions through the normal approved process; keep secrets out of logs. Restore to an isolated database and verify row counts, sampled content, permissions, jobs and restore duration. Deployment is blocked until restore works.
3. **Schema preflight:** compare SHOW CREATE TABLE for outbox, temporary voice tables, jobs/gate and upstream dependencies against reviewed SQL. Check engine, column/index definitions, partial migrations and migration 025. Run 063–066 in order on the restored clone first, then rerun; verify existing data. Establish metadata-lock wait/abort limits with the DBA.
4. **Configuration:** verify DB host/port/name/user/password and consistent session timezone; PHP CLI extensions/dependencies; existing `OLLAMA_URL`/`OLLAMA_MODEL` configuration as read by `OllamaService`; `LIVEKIT_URL`, `LIVEKIT_API_KEY`, `LIVEKIT_API_SECRET`; `LIVEKIT_COUNT_INTERVAL` (same in PHP and collector, default 5 seconds); `CENTRIFUGO_ENABLED`, token secret, API key and localhost API URL. Validate exact env names against the release rather than copying secrets. Retain existing Code Lab runner/worker limits. Do not enable adaptive streaming.
5. **Control admissions:** use an approved maintenance/access gate for new Jarred sends during cutover; no dedicated queue-drain flag exists in this patch. Keep existing messages readable. Record queued/running counts and worker ownership. Do not introduce an undocumented feature flag into the runbook.
6. **Migrate:** one operator applies the reviewed compatible migration sequence. Stop on failure. Inspect DDL state and preserve data; never mark failed SQL successful. No service begins using an absent table or enum value.
7. **Application/client/outbox rollout:** deploy compatible PHP, bundle and client URLs as one release. Upgrade outbox dispatcher before Code Lab can emit the new kind. Restart PHP/opcache and long-lived WebSocket/Code Lab processes through the approved deployment mechanism. Coordinate all voice writers. Force a page refresh for clients with old in-memory Jarred synchronous-response logic; cache busting alone does not refresh already-open tabs.
8. **Collector:** validate the staging service template paths/user/limits; activate one collector per database and confirm snapshot age advances, counts are filtered, stale is distinguishable from zero and second instance does no work. Test a controlled upstream failure only in staging.
9. **Jarred worker:** validate the staging template; activate the singleton and verify lock ownership, queue age, attempts, provider timeout, private reply/outbox atomicity and recovery. Keep admissions closed if health fails. Establish quotas/retention and moderation gating before broad access.
10. **Health/smoke:** monitor PHP-FPM busy workers, error rate, DB locks/slow queries, outbox oldest age/retries, queue states/age, collector age, WS connections/loop lag, memory/CPU/disk and provider latency. Smoke-test ordinary/human DM, queued AI, busy rejection, cancellation, disabled AI, cross-user denial, private resource denial, Code Lab authorized completion, voice joins/leaves/private counts, search, unread/pagination, peer matching and two-tab voice cleanup.
11. **Roll back safely:** close new async admissions; drain or explicitly cancel queued/running jobs. Wait for in-flight inference or terminate under the approved procedure, understanding that cancelled results are fenced while committed actions persist. Stop Jarred worker after queue reconciliation. Drain pending `code_lab` events with the new dispatcher before reverting dispatcher code. Revert application/client/WS versions together; coordinate old voice writers and maintenance. Retain additive tables, jobs and messages. Do not start the old synchronous AI path with an overlapping async worker. If recovery cannot preserve post-backup writes, stop and use the approved recovery plan rather than silently restoring away data.

Readiness checklist: corrected CI, migration-025 reconciliation, restored backup, production-schema compatibility, coordinated workers/clients, bounded operational policies, security follow-ups, staging smoke tests and isolated execution host are **open deployment gates**. The local review does not complete them.

## Next prompt

> Perform read-only VPS diagnostics for ecollab-project/ecollab and design a representative staging benchmark for 450 concurrent students out of 900 registered students. First read the integration review and verify actual merged/deployed SHAs, services and migration ledger. Do not deploy, restart, change configuration, execute production migrations or load-test production. Collect redacted host/service versions, CPU/RAM/disk, PHP-FPM capacity and status, MariaDB configuration/locks/slow-query evidence, WebSocket/Centrifugo health, LiveKit/TURN configuration and bandwidth limits, Ollama utilization and existing queue metrics. Do not print credentials or personal message content. Treat a verified separate Code Lab execution host, private authenticated gateway and externally enforced network restrictions as prerequisites for any untrusted-execution workload. Produce a staging-only mixed-workload plan with realistic chat/read/write/search/peer/AI/Code Lab/media proportions, stepped concurrency to 450, warm-up/steady-state/recovery, failure injection, measured latency/error/resource gates, abort conditions and rollback. Distinguish requested staging work from actions authorized now: execute no benchmark until the staging target, isolation and workload are verified. Reserve higher reasoning effort for unresolved security or concurrency defects. Capacity remains UNVERIFIED until representative measurements pass.
