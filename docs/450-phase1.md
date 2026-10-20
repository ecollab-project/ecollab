# 450-user optimization: Phase 1 review

Base: `ecollab-collabs`, `c838359711a14e9b6eb3f345c4386d184a178b25`.
Branch: `optimization/450-users-phase1`. Base was rechecked before publication and had not moved. All execution used synthetic local fixtures. No production access, migrations, cron changes, deployment, restart or merge occurred.

Status: targeted implementation and focused regressions completed; **draft review, not release approval**. Repository-wide gates have reproducible baseline failures below. These changes do not demonstrate capacity for 450 simultaneous students.

## Implementation

- **A2:** Removed constructor DDL and global cleanup from presence and Jarred requests. Migration 063 contains the original additive table definitions. A CLI worker performs bounded expiry cleanup under a database-specific advisory lock. Joins, leaves and deletion serialize on the channel row; authorization runs under that lock. User-row locking serializes concurrent moves. Stale leave events compare the expected channel before clearing presence. Last-participant deletion remains immediate. WebSocket final-tab disconnects also clean rooms without clearing a newer different room. Pending actions, invitations and creation behavior are retained. Existing presence reads still refresh activity because clients rely on that behavior; separating that write requires a coordinated client heartbeat change.
- **A3:** `loadProfiles` loads four profile tables in batches of up to 100 users; the single-profile API remains available. Relationships use one query per batch with correlated lookups, preserving direction and existing LIMIT 1 behavior. Cache upserts are batched and continue to store rule-only scores/tags. Candidate order remains aligned with semantic scores; ranking, candidate selection and eligibility code remain unchanged. Tags have explicit ID ordering (previous database order was implicit).
- **A6:** One database-backed policy now governs token issuance, HTTP/WS voice joins and count visibility. Private channels require server membership plus explicit membership, creator status or server owner/admin privileges. Platform admin/super-admin retain their existing bypass; facilitator/moderator do not acquire a global bypass. Deleted/banned/suspended/deactivated accounts, inactive servers and active applicable moderation restrictions are denied. Offline/idle are valid presence states. Server suspensions allow message reads but block voice joins; chat mutes retain voice. Locked channels permit only the existing platform administrator exception.
- **A7:** Search releases the session immediately after authentication; whiteboard sync releases it after applicable CSRF validation. Downstream code was inspected for session mutation. Search responses are explicitly non-cacheable. Permissions, save/restore and whiteboard lock logic are unchanged.
- **A9:** Both regular and pinned service reads enforce the shared access policy, eliminating the API's duplicate queries. The same 1–100 normalization is used for retrieval and `has_more`; after takes precedence over before. The existing full-page `has_more` heuristic is retained (a full final page may require one empty follow-up). Read-marker updates, reactions, polls and attachments are preserved. Authorization is not cached across requests or reused after membership revocation.

## Changed file inventory

| File | Purpose |
|---|---|
| `API/chat/active-now.php` | Remove global cleanup; transactional authorized joins/leaves |
| `services/TemporaryVoiceService.php` | DDL-free construction, channel/user locking, bounded maintenance |
| `database/migrations/063_temporary_voice.sql` | Additive pending-action and temporary-room tables |
| `scripts/temporary-voice-maintenance.php` | CLI-only, singleton cleanup |
| `websocket/ChatServer.php` | Shared voice policy/locking and multi-tab disconnect cleanup |
| `services/PeerMatchingService.php` | Batched profiles, relationships and compatibility upserts |
| `API/chat/peer-match.php` | Use batches while preserving semantic ordering and filters |
| `services/ChannelAccessService.php` | Shared current-database message/voice authorization |
| `API/chat/livekit-token.php` | Shared voice authorization before issuing tokens |
| `API/chat/livekit-counts.php` | Batched allowed-channel filtering |
| `API/chat/search-messages.php` | Early session release, private no-store response |
| `API/chat/whiteboard-sync.php` | Release session after authentication/CSRF |
| `services/MessageService.php` | Protect normal/pinned reads and normalize limits |
| `API/chat/get-messages.php` | Remove duplicated policy and normalize pagination metadata |
| `tests/phase1-temporary-voice.php` | Constructor/lifecycle/rollback/stale leave fixtures |
| `tests/phase1-peer-batch.php` | Legacy parity and measured profile-query counts |
| `tests/phase1-access.php` | 200 access combinations plus revocation/status restrictions |
| `tests/phase1-messages.php` | Pagination, service-level privacy and batched message features |
| `tests/phase1-session.py` | Real local endpoints, auth/CSRF and parallel shared sessions |
| `tests/phase1-mariadb.php` | Migration rerun, both lock orderings, singleton and cache upserts |
| `tests/phase1-websocket.php` | Final-tab cleanup and stale-socket isolation |
| `.github/workflows/phase1.yml` | Isolated PHP 8.3/MariaDB 10.11 PR regression job |
| `docs/450-phase1.md` | Evidence, limitations and operational review instructions |

## Measured results

PHP 8.3.6, SQLite in-memory synthetic fixture, one local run of `php tests/phase1-peer-batch.php`. These numbers measure **profile loading only**, not an HTTP request, MariaDB workload or production throughput. The same fixture and legacy query implementation are used for both paths; results, scores and semantic inputs are compared exactly.

| Candidates | Legacy SQL statements | Batch SQL statements | Legacy PHP duration ms | Batch PHP duration ms |
|---:|---:|---:|---:|---:|
| 0 | 0 | 0 | 0.001 | 0.008 |
| 1 | 4 | 4 | 0.067 | 0.048 |
| 50 | 200 | 4 | 1.329 | 0.359 |
| 100 | 400 | 4 | 2.408 | 0.624 |

Whole fixture-process peak allocated memory: 2,097,152 bytes; separate old/new peak memory **NOT MEASURED**. Batch relationship statement count was also asserted as 0 for no candidates and 1 for 1/50/100 candidates. This is a round-trip reduction; correlated database work still scales with candidate count.

Two independent PHP HTTP servers sharing one synthetic authenticated session: a deliberately delayed request remained in shutdown for 800 ms while the second request completed in 0.80 ms (search GET), 1.07 ms (whiteboard GET) and 1.12 ms (whiteboard POST with valid CSRF). This verifies session release, not real database-heavy endpoint latency. No before/after HTTP latency claim is made.

Full request SQL count, production-shaped HTTP p50/p95/p99, slow-query behavior, comparative authorization overhead, old/new whole-request memory, sustained-load error rate and 450-user capacity: **NOT MEASURED**.

## Regression results

### PASSED

- PHP syntax checks: 272 PHP files under API/services/websocket/scripts/tests at initial validation; subsequently added WebSocket fixture and final changed files also linted.
- All Phase 1 SQLite fixtures: 200 access combinations, lifecycle, 0/1/50/100 peer profiles, exact scoring/semantic inputs, relationships, keyset boundaries, normalized limits, pinned access, reactions, attachments and poll votes.
- MariaDB 10.11.14 isolated fixture: migration 063 applied twice without data loss; waiting join rejected after deletion; waiting cleanup retained committed occupancy; occupied expiry reset; last leave deletion; competing advisory lock rejected; 100-row cache insert/update.
- Real endpoint session test: anonymous search rejected, invalid whiteboard CSRF rejected, valid same-session parallel requests did not serialize behind delayed shutdown.
- WebSocket fixture: closing one of two tabs retains presence; final non-voice tab cleans the room joined by the other tab; stale disconnect preserves a newer room.
- Existing PHPUnit Unit suite: **25 tests, 56 assertions**.
- Existing `peer-ml-regression.php`, `chat-read-regression.php`, `centrifugo-regression.php`, `whiteboard-scene-regression.php`, `whiteboard-presence-regression.php`.
- Existing MariaDB `whiteboard-realtime-regression.php`: 11 authorization/permission/revocation checks.
- Existing `livekit-voice-regression.cjs`, `livekit-calls-regression.cjs`, `request-security-regression.cjs` with local fixtures.
- Local LiveKit JWT fixture: room/user scope, 600-second lifetime and HMAC signature, using dummy credentials and no LiveKit server.
- `git diff --check`; manual complete diff/SQL/caller review. Parameterized access and batch SQL; only clamped integer limits are interpolated. No secrets or environment files added.

### FAILED (also reproduced on unchanged base)

- PHPStan level 2: **15 findings on both base and Phase 1**, same messages/files after allowing line-number shifts. Existing issues: missing MAIL_FROM; Code Lab possibly undefined score; absent WebPush dependency; Ratchet resourceId ignore-count mismatch; private persistDirtyCodeSessions calls. No new diagnostic introduced; no ignores added.
- Full fresh migration chain stops at **025_coworkspace_one_active_per_server.sql**, MariaDB error 1901 for generated active_server_id referencing server_id. Reproduced on base. Migration 063 itself passes independently.
- PHPUnit Integration on that partially migrated fixture: **21 tests, 40 assertions, 5 failures, 6 errors**, identical on base. Registration fixtures omit required email verification; rate-limit tests lack rate_limit_log. This is not a successful full integration run.

### BLOCKED

- A clean all-migrations/full-integration gate is blocked by the existing migration/fixture issues above. Resolve separately before release; this PR does not silently skip or rewrite those tests.

### NOT RUN

- Browser login/logout, email-verification flow, full chat-send/DM/group workflows, whiteboard HTTP save/restore/locking end-to-end, live ML service and live LiveKit transport/reconnect/revocation.
- Production/staging load tests and real-server latency/error/slow-query measurements. No production benchmark or production access attempted.
- CI execution is separate from local evidence; consult the PR's checks for its result.

## Reproduction and safe remaining validation

Use a disposable local checkout with no production `.env`, PHP 8.3, MariaDB 10.11, Composer dependencies and Python 3. `composer install --no-interaction --prefer-dist` uses the existing lockfile. The Phase 1 workflow provisions a disposable database automatically. For manual runs:

```sh
for test in phase1-access phase1-temporary-voice phase1-peer-batch phase1-messages phase1-websocket peer-ml-regression chat-read-regression centrifugo-regression whiteboard-scene-regression whiteboard-presence-regression; do
  php "tests/$test.php" || exit 1
done
python3 tests/phase1-session.py
php vendor/bin/phpunit --testsuite Unit --stderr
# Create an EMPTY ecollab_phase1_test schema in your disposable local MariaDB first.
PHASE1_TEST_DSN='mysql:host=127.0.0.1;port=3306;dbname=ecollab_phase1_test' php tests/phase1-mariadb.php
php vendor/bin/phpstan analyse --memory-limit=1G --no-progress
```

The MariaDB fixture refuses non-loopback/non-dedicated DSNs and nonempty schemas. Use PHASE1_TEST_USER/PHASE1_TEST_PASSWORD for that disposable database if needed. Never point it at a live application schema.

To reproduce the known full-chain blocker, provision a separate empty `ecollab_test` database locally, set DB_HOST=127.0.0.1, DB_PORT, DB_NAME=ecollab_test, DB_USER and DB_PASS for that local instance, then run `php database/migrate.php`. Do not continue an acceptance test after a failed migration; the partial-fixture integration run above is diagnostic only.

For remaining functional acceptance, use two synthetic student accounts, a facilitator, server owner/admin and platform admin in an isolated staging clone. Verify login/logout; CSRF denial; private-channel grants/revocation; chat/DM/group send/read; paginated unread markers; reactions/polls/attachments; whiteboard lock/save/restore; temporary voice creation/invitation/last-leave cleanup; LiveKit join/reconnect; and ML enabled/disabled/timeout behavior. Record expected/actual results per account. No real student records are needed.

For before/after HTTP measurement, run base and this branch on separate disposable clones of the same synthetic dataset and identical PHP-FPM/MariaDB settings. Replay the same 0/1/50/100-candidate peer requests and permission/pagination cases using synthetic sessions; capture per-request SQL count, PHP duration/peak memory and HTTP latency, plus local MariaDB slow-query log and HTTP errors. Use existing `tests/load-test-*` tooling only after reviewing its dedicated fixture/target guards; start with low concurrency and increase on staging only. No approved staging URL/credentials or load budget were supplied, so no remote execution is implied by these instructions.

## Security and deployment review

Apply/verify migration 063 **before** enabling the changed runtime on a future approved deployment. Both tables may already exist from historical runtime DDL; CREATE IF NOT EXISTS retains records but does not repair a divergent existing schema. Verify engine/columns against migration 063. No automatic cron is installed. After approval, schedule `php /absolute/release/path/scripts/temporary-voice-maintenance.php` approximately once a minute under the application's restricted service account; its DB-scoped GET_LOCK makes overlap a no-op. Monitor nonzero exits. Expiry batches are capped at 100 by default.

All application instances that write voice presence must use the locking path. A mixed old/new worker rollout does not guarantee deletion-race protection; drain old voice writers as part of an approved coordinated rollout. Existing LiveKit tokens live for up to 600 seconds and are not actively evicted by this change after membership revocation; new requests recheck the database. Existing same-room stale disconnects cannot distinguish connection generations in the single voice_channel_id column; different-room stale events are protected. Third-party/manual SQL writers do not participate in these locks.

Rollback code by reverting this branch's commit in a new review/deployment. Keep the additive tables and their data; do not drop them. If reverting to the previous runtime, disable the new maintenance schedule during the coordinated rollback because the old runtime does not share these locks. No destructive down migration is required. Neither deployment nor rollback is performed by this PR.

## Remaining audit risks

| Finding | Remaining work |
|---|---|
| A1 (P0) | Isolate untrusted Piston/student code from privileged/shared application host before broad execution |
| A4 | Reduce/cache LiveKit participant-count RPC amplification |
| A5 | Move synchronous AI inference off scarce PHP-FPM workers |
| A8 | Reduce Code Lab polling and measure queue contention |
| A10 | Safely restore adaptive video subscriptions after resolving DOM lifecycle regressions |
| A11 | Bound/index message-search scans and validate privacy at scale |
| A12 | Remove per-message WebSocket database health probes without losing reconnect safety |

Additional release blockers: baseline migration 025, full integration fixtures and static-analysis debt. The 2-vCPU/8-GB host specification remains historical and was not verified here.
