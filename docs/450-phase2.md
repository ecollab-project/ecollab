# Phase 2: realtime, queue and search work

## Status and dependency

Based on Phase 1 commit `8a2dcfd19db15c10d4711856835d68ef799660db`, PR #66. All five Phase 1 GitHub workflow runs were verified successful. PR #66 remained unmerged when this branch was prepared; the Phase 2 draft must target `optimization/450-users-phase1`.

**Not ready for a verified 450-student production claim.** The implementation and isolated regression evidence below are reviewable. No production access, load test, migration, service installation, merge or deployment occurred. A10 remains a documented media blocker; A1 is an execution-host design, not provisioned infrastructure.

## Architecture and behavior

| Item | Before | Phase 2 |
|---|---|---|
| A4 | Each authorized count request calls LiveKit | Database snapshot read after current channel authorization; one database-locked CLI collector performs ListRooms, normally every five seconds |
| A8 | Three-second status polling; queue-position query on every poll | Initial request, jittered backoff capped at ten seconds, hidden-page pause, one outstanding status request, reconnect/event wake, less frequent advisory position reads; terminal completion records an outbox event |
| A12 | Connection probe before parsing every frame | Bounded JSON/frame validation and authenticated ping before SQL; connection health probe at most once per five seconds per process; handler/loop/connection aggregates |
| A11 | Leading-wildcard search with optional-channel OR predicate | Same substring matching, separate channel predicate, 120-character bound, existing debounce plus abort/stale-result protection, current visibility checks and optional timestamp/ID keyset |
| A5 | DM request performs action routing and Ollama inference | Source message and job commit together; HTTP 202 acknowledgement; one database-locked worker; atomic action/response or durable action checkpoint followed by inference outside a transaction |

Dependencies:

- LiveKit collector → `livekit_count_snapshot` → authorized count endpoint → sidebar. No Redis added. Fresh empty snapshots yield zero; missing, future-dated or stale snapshots yield null, displayed as unavailable. Network errors do not fabricate zero occupancy. `collected_at` is an epoch timestamp.
- Code Lab worker transaction → existing realtime outbox → existing Centrifugo inbox → client wake → existing authorized result endpoint. Events contain identifiers only, never code, grading outputs or hidden tests. Normal successful and failed completions use durable delivery with fresh event IDs across retries; crash-recovery failures and event loss remain discoverable through bounded polling. Leaving a page stops its poll generation; it does **not** cancel grading or change submission state. Existing worker concurrency remains unchanged.
- Jarred API → admission-gate/conversation locks → immutable source message + queued job → singleton CLI worker → action transaction/checkpoint → Ollama → fenced response + DM outbox transaction → authorized status/realtime fetch. Existing DM prompt text is preserved byte-for-byte; history remains bounded and is additionally capped at the source-message ID. Preferences and account/conversation access are rechecked. Provider implementation and its existing timeout/local-only restrictions are unchanged. Usage metadata remains available in job status.

The AI queue admits at most 100 active jobs and one active turn per conversation. This is a conservative admission bound, not measured hardware capacity. One worker is enforced with a database-scoped advisory lock; inference concurrency was not increased. Three attempts maximum; jobs older than 30 minutes fail when picked up. Cancellation fences pending/running replies, and may discard an already-running provider result; it does not interrupt the model's network request or undo a committed action. The existing provider has a 90-second request timeout. On worker restart, running jobs recover under the singleton lock. Action writes and response/checkpoint commit together; an uncommitted action rolls back. No database transaction spans inference. HTTP retries themselves are not a general idempotency-key API; the pending-turn gate prevents a second active turn.

A12 also fixes an existing timer invoking private `persistDirtyCodeSessions()` from outside its class. Making the timer entry point public preserves the intended periodic collaboration persistence.

## Measured evidence and limits

Environment: isolated local PHP 8.3.6, MariaDB 10.11.14, SQLite fixtures and Node 24.19.0. No production data. Browser checks use DOM/SDK fixtures, not real WebRTC devices.

| Measurement | Before | After | Conditions |
|---|---:|---:|---|
| Mock ListRooms HTTP calls, 50 readers | 50 | 1 | Identical mock room response; one collected snapshot read 50 times |
| Mock ListRooms HTTP calls, 150 readers | 150 | 1 | Same fixture |
| Mock ListRooms HTTP calls, 450 readers | 450 | 1 | Same fixture; 653 total HTTP calls observed across all paired trials |
| Status SQL executions, 450 queued readers | 1,350 | 900 | Actual prepared-statement executions; omit advisory position; membership and result lookup retained |
| Code Lab status calls in simulated 60 seconds, 450 clients | 9,000 | 4,500 | Application polling function with virtual clock, fixed jitter sample 0.5, always queued, no network latency |
| Queue-position requests in that simulation | 9,000 | 900 | Initial and every sixth status request |
| Authenticated heartbeat DB accesses, 1,850 frames | Old path probes on every frame | 0 | 50/100/200/300/350/400/450 frame batches; uninitialized PDO makes any DB access fail the test |
| Synthetic queued AI acknowledgement | Not compared | 5.6 ms in one run | Actual PHP endpoint, SQLite, synthetic authenticated session, no worker running; not an FPM or inference throughput benchmark |

The polling fixture also covers 50/100/200/300/350/400 clients: before/after status calls are respectively 1,000/500; 2,000/1,000; 4,000/2,000; 6,000/3,000; 7,000/3,500; 8,000/4,000. These are executed virtual-time schedules, **not** measured HTTP load capacity. The heartbeat fixture took approximately 1.1 ms in one run; that is function-dispatch time, not a network/event-loop acceptance result.

Live LiveKit RPC latency, PHP-FPM occupancy, real media bandwidth/quality, SQL explain plans against staging-scale data, production search latency, complete mixed-workload throughput and real event-loop lag are **NOT MEASURED**. No FULLTEXT index was added. Leading-wildcard scans remain and require staging plans/data before an indexing decision.

## Validation

Passed:

- New snapshot missing/stale/future-data and filtered-reader fixtures.
- Actual mock HTTP ListRooms calls plus MariaDB snapshot upserts and additive migration reruns/data preservation.
- MariaDB competing queue admissions and singleton worker-lock exclusion.
- SQLite and MariaDB Jarred ownership, admission, atomic source/job rejection, action checkpoint, rollback, retries, cancellation fencing, restart recovery, current preferences and usage metadata.
- Real temporary-voice creation inside the worker's outer transaction: rollback restores dialogue state and retry creates one room.
- Actual authenticated HTTP enqueue/status/cancel endpoints: unauthenticated/CSRF denial, Code Lab policy denial, HTTP 202, pending-turn rejection without orphan messages and cross-user rejection.
- Jarred client pending/cancel UI, realtime wake, action navigation deduplication and stale-conversation responses.
- Code Lab SQL counts, owner-only outbox recipients and membership revocation; polling backoff/visibility/realtime wake fixtures.
- Search substring behavior, private membership, cross-server isolation, bans, bounded input and tied-timestamp pagination.
- Phase 1 access, temporary voice, peer batch, message and WebSocket fixtures; existing Code Lab execution/server queue/batch/integrity/worker pool; settings runtime, peer ML, unread state, Centrifugo and whiteboard scene/presence fixtures.
- Existing DOM/client tests: Code Lab UI/integrity, Centrifugo delivery, notifications, LiveKit calls/voice, chat media/uploads, request security, Jarred provider/session races and realtime settings.
- **25 PHPUnit unit tests, 56 assertions.** Changed PHP/JS syntax and whitespace checks.
- Static analysis: **15 Phase 1 findings → 13**, with no new diagnostic messages. Two existing private-method timer diagnostics resolved; remaining baseline findings are not suppressed.

The initial local worker-pool run lacked PHP's `posix` extension; it passed after enabling that runtime dependency. The initial static analysis exceeded the local 128-MB limit; it completed at 512 MB. These were test-environment issues, not changed expectations.

Phase 1 already documented a migration-025 MariaDB blocker and full-integration prerequisite failures (five failures/six errors on a partial fixture). Those remain unresolved; the full integration chain was **not rerun** here. New migrations were tested independently on a disposable database. CI for this new branch must still be reviewed after publication. Full browser workflows, actual Ollama response quality, hostile-code execution-host tests, real broker delivery under load and real WebRTC tests are NOT RUN.

## Security review

- Count readers retain Phase 1 `ChannelAccessService` authorization before selecting snapshot IDs. Private room lists never reach clients.
- Search retains private membership/creator visibility, adds active-account/server checks and current bans, and keeps parameterized SQL. Cursor values are validated and do not bypass the visibility predicate.
- Heartbeat bypass applies only to authenticated ping frames and performs no persistent state change. Resource-changing handlers still execute their existing authorization. Malformed frames do not reach SQL.
- Code Lab notifications expose only a job identifier to its current owning server member. Result authorization and hidden-case filtering remain authoritative; grading code/weights are unchanged.
- Jarred status and cancellation require an authenticated conversation owner; cancellation requires CSRF. Jobs cannot publish after cancellation or a lost lease. Current account/preferences are rechecked before processing and reply commit. The queue adds no model write authority.
- New logs contain counts, duration, queue age and fixed categories, not credentials, JWTs, messages, code or hidden cases.
- P0 isolation remains a provisioning gate. See `450-phase2-execution-isolation.md`; production's privileged Piston configuration was not changed.

## Observability and remaining gates

New CLI/server aggregates: collector RPC count/outcome/duration; Jarred queue age/attempt and inference duration; WebSocket connections/open/close/frame counts and maximum handler/loop lag; optional `SEARCH_METRICS_ENABLED` endpoint duration. Existing realtime-dispatch and Code Lab worker metrics remain.

A transport send-buffer/backpressure gauge is **not implemented**: the current Ratchet public connection interface provides no stable queue-size method. Do not report handler duration as send backlog. Add and validate an adapter-level drain/backpressure hook before staging acceptance. PHP-FPM occupancy requires private FPM status/process metrics; no public diagnostic endpoint was added. Media subscription measurements and reconnect-under-loss remain staging work.

Run a representative 50 → 100 → 200 → 300 → 350 → 400 → 450 mixed-workload test only on an approved isolated staging system. Include real publishers/subscribers, chat/DM/group traffic, Code Lab collaboration/grading, documents, whiteboards, AI, matching and dashboard navigation. Capture host CPU/RAM, FPM occupancy, DB plans/queries/locks, event-loop lag/backlog, broker delivery and media quality. Unit/query improvements do not establish capacity.

## Staging rollout prerequisites (not executed)

1. Resolve the Phase 1 deployment and full-regression prerequisites. Keep the stacked PR dependency until Phase 1 is merged and the resulting diff is reviewed.
2. Back up staging; verify InnoDB and schema compatibility. Apply 064, 065 and 066 using the repository migration workflow. Migration 065 extends the outbox enum; test its metadata-lock impact on a representative outbox before production approval.
3. Review the two templates in `deploy/phase2/`; adjust paths/user and memory limits using measurements. They are examples targeting `/srv/ecollab-staging`, not installed services. Start the count collector before expecting fresh counts, and the Jarred worker before exposing asynchronous submission.
4. Keep `LIVEKIT_COUNT_INTERVAL` consistent between collector and PHP application. Default five seconds, bounded 5–60; freshness is three intervals with a 15-second minimum.
5. Roll out API, clients, outbox dispatcher and workers together. Old dispatchers do not understand `code_lab` events; drain/restart them with the new code. Verify private inbox subscription tokens and poll fallback with Centrifugo enabled and disabled.
6. Enable private metrics and complete the acceptance tests above. Model capacity and queue age must be measured before changing the single-worker bound or queue cap.

## Rollback

Stop accepting new asynchronous submissions using the application's existing maintenance/deployment procedure; drain or explicitly cancel pending jobs, then stop the Jarred worker. Do not leave it publishing while reverting to synchronous requests. Retain job/source/response data for reconciliation. Stop the collector when reverting count readers; the old endpoint resumes its former per-request RPC cost.

Drain pending `code_lab` outbox events with the new dispatcher before reverting it; otherwise the old dispatcher cannot process that enum value. Revert the corresponding API/client/service commits together. Keep additive tables and the expanded enum; do not drop data or narrow the enum during rollback. Neither media defaults nor Piston privileges were changed, so they require no rollback here.

## Reproduction

Run `tests/phase2-*.php` as indicated by `.github/workflows/phase2.yml`; the MariaDB scripts require an **empty disposable** `ecollab_phase2_test` database on 127.0.0.1. Run the Jarred fixture first, then the MariaDB continuation. `LIVEKIT_FIXTURE_RPC=1` is only for an explicitly supplied localhost mock LiveKit endpoint. Default CI does not call a real LiveKit deployment. Run the Node polling/client fixtures and the Python HTTP fixture with PHP available. No test command targets production.

## Changed-file inventory
- `.github/workflows/phase2.yml`
- `API/chat/livekit-counts.php`
- `API/chat/search-messages.php`
- `API/code-lab/index.php`
- `API/dm/jarred-job.php`
- `API/dm/send-message.php`
- `assets/js/chat/centrifugo-delivery.js`
- `assets/js/chat/chat-features.js`
- `assets/js/chat/dm-notifications.js`
- `assets/js/chat/livekit-voice.js`
- `assets/js/code-lab.js`
- `database/migrations/064_livekit_count_snapshot.sql`
- `database/migrations/065_code_lab_delivery.sql`
- `database/migrations/066_jarred_jobs.sql`
- `deploy/phase2/ecollab-jarred-worker.service`
- `deploy/phase2/ecollab-livekit-counts.service`
- `docs/450-phase2-execution-isolation.md`
- `docs/450-phase2-media-experiment.md`
- `docs/450-phase2.md`
- `modules/code-lab/index.php`
- `scripts/jarred-worker.php`
- `scripts/livekit-count-collector.php`
- `services/ChatSearchService.php`
- `services/CodeLabExecutionService.php`
- `services/JarredJobService.php`
- `services/JarredReplyGenerator.php`
- `services/LiveKitCountSnapshot.php`
- `services/RealtimeOutbox.php`
- `services/TemporaryVoiceService.php`
- `tests/phase2-code-lab.php`
- `tests/phase2-jarred-client.cjs`
- `tests/phase2-jarred-http.py`
- `tests/phase2-jarred.php`
- `tests/phase2-livekit.php`
- `tests/phase2-mariadb.php`
- `tests/phase2-polling.cjs`
- `tests/phase2-search.php`
- `tests/phase2-voice-action.php`
- `tests/phase2-websocket.php`
- `tools/chat-delivery/index.js`
- `websocket/ChatServer.php`
- `websocket/bin/server.php`
