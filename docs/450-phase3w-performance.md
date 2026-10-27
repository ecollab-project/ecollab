# eCollab Phase 3W: PDO telemetry, migrated schemas and write-aware controls

This phase changes test tooling, fixtures, CI and evidence only. **No application optimization is retained or proposed.** The flat unread-count join and earlier forced join remain rejected. Independent acceptance and release authorization are separate.

## Exact source and scope

Repository: https://github.com/ecollab-project/ecollab. Branch: `optimization/phase3w-telemetry-schema-20261027`, stacked on `optimization/phase3v-statistics-dashboard-20261027`.

Accepted application/base: `e818bbd84ae85d67aa71a38460fe154a5128a930`, tree `f402fd1db2e5cbaf4acda6a6d183fc1b89a29930`. PR91 was checked open, draft and unmerged at that head. Frozen local harness: `622a6d50bddad42e3256c2163905d5d24f297c1b`, tree `6da545617d189cc30dc0a43b76670af16b748032`. Every control uses the accepted application worktree, without overlays. Publication head/tree and final CI verification appear in the PR body and Independent Audit Handoff; a report cannot embed its own final Git tree hash.

Application services, APIs, database/migrations, assets, security and WebSocket paths have no net diff from the accepted base. StudentDashboardService SHA256 remains `c35eb3fbb1bacf4352866ec2f40b5288ffac5ce9acb5d0e1fc1390fc9f49053e`. Driver commit/tree, actual code hashes, clean application/harness diffs, schema state and runtime configuration are retained separately. The earlier Phase 3V control data is not substituted for this phase's measurements.

The eCollab IT Orchestrator routed Database/SQL performance as lead, with PHP instrumentation, seed/schema and runtime reviews plus QA integration/load gates. These are applied expert perspectives, not spawned agents. Superpowers TDD was used for the instrumentation:12 real PDO regression cases failed before the implementation, then passed on SQLite and native MariaDB.

Flow: student dashboard page/JS → authenticated dashboard API → StudentDashboardService; existing authenticated chat history/send/edit/delete APIs → access/CSRF/session checks → MessageService, ChatReadService and ChannelService → owned MariaDB. A test-only FPM prepend injects the owned connection. No auth, privacy, Code Lab or matching behavior changes.

## PDO capture and replay

The inherited HTTP ClassroomPDO already captured direct query calls. Phase 3V's bare native-PDO demonstration did not prove those HTTP query calls were missing. The verified gaps were binding snapshots, exec calls, prepare failures and bare native-profiler direct calls. This phase centralizes recording in test-only DashboardPDO/DashboardStatement and retains the existing HTTP query coverage.

The recorder snapshots actual execution-time inputs and declared PDO types from execute arrays, bindValue and bindParam, including changed references and repeated calls. execute-array replacement of existing bindings is covered. Snapshots detach from references so a later variable change cannot rewrite previous evidence. Named and positional inputs, NULL/bool/int/string values, exceptions and silent failures are tested. Original PDO returns/exceptions remain in force. query and exec are recorded once; zero affected rows remains success. Failed prepare attempts are labelled separately from successful statement execution.

Legacy params fields remain available; bindings additionally retain parameter identifier, value, PDO type, source and captured status. Parameterized EXPLAIN uses those actual typed bindings with native prepares, including the history query's integer LIMIT. No input is reconstructed from SQL text. Every main control explicitly replays the previously unavailable history plan.

Scope limit: resource/object/non-UTF8 bound values are marked uncaptured and unreplayable rather than guessed or consumed. SQL counts are application PDO observations, not all wire-protocol/server-internal statements or database CPU. Transaction-control APIs and opaque BLOB-stream payloads are not exhaustively instrumented. The sampled APIs use the recorded query/execute/exec paths; the broker/outbox feature is disabled in this owned environment, as in prior controls. No production secrets or student records enter the fixture.

## Schema fidelity

The real `database/migrate.php` runs status, dry-run, apply and status against a fresh owned MariaDB schema. All 70 SQL files are inventoried by filename/hash. The runner applies68 migrations plus registry setup and explicitly supersedes historical `025_coworkspace_one_active_per_server.sql` with its MariaDB replacement. This produces160 tables. Complete DDL, indexes, ledger/checksums, command output and exit codes are retained. This establishes a repository-derived test schema, not knowledge of deployed production schema.

Two test flavours are intentionally separate:

- Minimal/write-ready: inherited performance fixture plus test-only missing moderation reason/created_at fields, message deleted_at, and the is_deleted=0 insertion default needed by existing write APIs. Initial failed smoke records show why these corrections were necessary. This is not silently called the unchanged original fixture.
- Full-derived: actual complete migration sequence, followed by removal of demo rows and controlled synthetic core seeding. The schema definitions/constraints remain, aside from the explicitly controlled existing067 index state. The migration ledger and subscription catalogue stay. Full messages.created_at is NOT NULL, so its seed omits the one invalid NULL-timestamp row accepted by the minimal schema. Ancillary contents differ. No cross-flavour complete-response or production parity claim is made.

Within each flavour, absent/present/present/absent index order uses four independent fresh DB/FPM pools and identical application source. Full/absent removes only dashboard_unread before data seeding; it is a labelled fixture overlay, not a false claim that migration067 was never applied. Minimal/present applies the existing067 file in the owned seeded fixture. No migration source is changed and no new index is introduced.

Exact core counts, all table DDL and structural hashes, index cardinalities, persistent statistics, storage estimates and messages.ibd logical/allocated bytes are recorded. AUTO_INCREMENT counters are excluded only from the structural identity hash; exact DDL remains. Tablespace bytes include free extents and must not be interpreted as live index size. Index setup duration is not a production online-migration estimate.

## Experiment contract

All eight pools run OPcache on, asserted at runtime. This phase does not repeat or infer the on/off effect from separate time blocks. Ten owned student accounts participate in load cells; request-slot caps are25/100, not25/100 students. Auth/session seeding and permission preflights precede sampling, so initial means before explicit statistics refresh, not a fully cold OS/database.

Initial and analyzed serial surveys use the same actor and four routes: dashboard, history, notifications and settings. A separate40-payload corpus covers all ten actors. Repeated25/100-slot finite bursts use dashboard/history. Two mixed fixed-arrival cells run30 seconds each at nominal60 arrivals/s, with caps25/100. The predeclared pattern includes reads, message inserts, read-cursor updates, edits and soft deletes through existing authenticated APIs. Unique owned update targets avoid ambiguous last-writer expectations. Full request bodies/responses and operation IDs/content are retained.

Scheduler accounting distinguishes offered, admitted, cap-refused and completed requests. It does not catch up missed nominal slots. Lag, actual active requests, elapsed/drain/observer time, response bytes, per-operation p50/p95/p99, PHP CPU/allocated peak, SQL count/time, FPM occupancy/queue and database counters remain raw. Unexpected HTTP failures stop new fixed-arrival offers; SQL/state/plan/timeout assertions stop the cohort when observed. Partial files are retained before acceptance assertions.

ANALYZE TABLE is checked and occurs outside timed samples. Typed EXPLAIN checkpoints cover the unread/history shapes at cell boundaries; topology excludes volatile estimates/timings. A transition stops the comparison. These observations do not prove every execution used the same plan. Native controls independently cover sparse small/large, dense-server, hot-channel and empty distributions with initial versus refreshed statistics, four invocation samples per phase and actual ANALYZE FORMAT=JSON plans. Those diagnostics run after timed workloads and explicit owned-fixture reset.

## Functional and data validation

Each pool checks expected nonmember/private-channel/cross-sender denials before timing. Application protections remain intact. Complete static response comparisons normalize only the dashboard envelope generated_at and compare within each flavour across index states and independent pools. Concurrent mutable read snapshots are retained but are not falsely equated across different interleavings.

Operation logs independently determine expected insert IDs/content/owners/channels, edit targets/content, soft-delete flags/timestamps and maximum acknowledged read-cursor IDs. Legacy cursor timestamps must match the fixed clock. Final row counts must equal baseline plus successful inserts; soft deletes are not counted as hard deletions. Untouched named tables retain identical checksums. Written tables use expected-state validation, not unchanged-checksum assertions. The verified final state is preserved before the owned fixture is reset for separate native diagnostics and torn down.

Private-channel unread-count ambiguity is preserved. Permission preflights do not approve that existing product/privacy policy. No new friendship-status precedence, authorization rule, sorting rule or API contract is introduced.

## Lock evidence and resource limits

Natural load cells record InnoDB lock-wait/time/deadlock counters and sampled INNODB_LOCK_WAITS/TRX/LOCKS details, alongside FPM status. The target lock-sampling interval is0.5s; short waits can be missed and observer queries add overhead. Sampling absence is not proof of no contention.

A separately labelled bounded control holds one owned message row and observes the existing edit endpoint waiting on messages.PRIMARY. It validates the attribution mechanism. Its wait is outside timed-cell counter deltas and cannot explain the older Phase 3V waits. No unobserved lock is assigned to a table or endpoint by assumption.

Local host remains Linux6.18.55/glibc2.39, virtual AMD EPYC9V74, affinity0–8 with8 CPU equivalents and8GiB cgroup memory. Native PHP8.3.6/FPM, MariaDB10.11.14, ten FPM workers,128MiB buffer pool and32 connections. This is owned loopback HTTP/FastCGI, not Nginx/TLS, WebSocket/broker traffic or the intended two-vCPU VPS. Local worker/database process CPU/PSS is BLOCKED by namespace visibility; PHP getrusage and allocated peak remain measured. These start after owned connection/bootstrap setup and are sampled before final shutdown-evidence serialization; they are not whole-worker CPU/RSS measurements. SQL execute wall time is not database CPU.

## Corrected frozen local results

All eight controls exited0: **35,028 benchmark requests**,0 unexpected HTTP errors,0 SQL errors,0 missing metrics and0 cap refusals. Expected permission denials (five/pool), induced lock controls (one/pool) and session seeds are separate from that count. Every within-flavour 40-payload comparison passed; full payloads for every dynamic request remain archived. All expected writes, cursor maxima and untouched-table checks passed.

There were 72 checkpoint records/144 plan observations: eight initial snapshots and 64 analyzed/warmed checkpoints. No tracked transition occurred during the analyzed measurements. All sampled scalar bindings were captured; every pool replayed the authentic history query with integer LIMIT. All four schema/index structural hashes matched between their independent pools.

Analyzed dashboard HTTP p50, medians of two pool-level medians (three actor1 requests per pool). Static row counts include 1,001 owned update/control anchors before writes: 101,064 minimal or101,063 full-derived. Native diagnostics reset to 100,063/100,062 rows respectively.

| Schema flavour | Index absent ms | Existing067 present ms | Observed change |
|---|---|---|---|
| minimal | 64.403 | 23.726 | -63.2% |
| full | 56.101 | 22.178 | -60.5% |

Every load-cell index contrast follows. Burst values summarize four cells/state; fixed-arrival values summarize two independent 30-second cells/state. All ranges and operation-level results remain in evidence JSON. These are schema controls, not an application-code improvement.

| Flavour / workload | Absent p95 ms | Present p95 ms | Change | Absent RPS | Present RPS |
|---|---|---|---|---|---|
| minimal / read-burst-25 | 394.91 | 345.38 | -12.5% | 68.07 | 84.48 |
| minimal / read-burst-100 | 1331.34 | 1182.15 | -11.2% | 79.85 | 95.82 |
| minimal / mixed-steady-25 | 190.71 | 130.36 | -31.6% | 59.29 | 59.48 |
| minimal / mixed-steady-100 | 205.61 | 125.71 | -38.9% | 58.64 | 58.90 |
| full / read-burst-25 | 393.70 | 312.59 | -20.6% | 75.29 | 93.74 |
| full / read-burst-100 | 1314.13 | 1039.55 | -20.9% | 80.78 | 106.87 |
| full / mixed-steady-25 | 202.47 | 121.70 | -39.9% | 59.30 | 59.49 |
| full / mixed-steady-100 | 225.66 | 123.39 | -45.3% | 58.88 | 59.41 |

Primary write-statement timings under mixed traffic follow. These pool all actual statement samples in a schema/index cohort; unlike the tables above, they are not medians of pool medians. They include concurrency/commit/environment effects and do not isolate pure index-maintenance CPU. No universal write-cost saving is established.

| Flavour / statement | Absent N | Present N | Absent p50 ms | Present p50 ms | Change | Absent p95 ms | Present p95 ms |
|---|---|---|---|---|---|---|---|
| minimal / message_insert | 1421 | 1429 | 1.0084 | 0.9027 | -10.5% | 2.9887 | 2.7675 |
| minimal / high_water_cursor | 1421 | 1428 | 0.6641 | 0.7030 | +5.9% | 2.6089 | 1.9882 |
| minimal / legacy_read_cursor | 1421 | 1428 | 0.0620 | 0.0574 | -7.3% | 0.2907 | 0.2285 |
| minimal / message_edit | 709 | 712 | 1.0019 | 0.9505 | -5.1% | 3.1613 | 2.9370 |
| minimal / message_soft_delete | 708 | 711 | 0.7657 | 0.7988 | +4.3% | 2.3868 | 1.9777 |
| full / message_insert | 1426 | 1431 | 1.0849 | 0.9617 | -11.4% | 3.1618 | 2.8534 |
| full / high_water_cursor | 1426 | 1429 | 0.7081 | 0.7371 | +4.1% | 2.4898 | 2.1834 |
| full / legacy_read_cursor | 1426 | 1429 | 0.0679 | 0.0641 | -5.6% | 0.3365 | 0.2567 |
| full / message_edit | 712 | 713 | 1.0942 | 0.9916 | -9.4% | 2.8869 | 2.4790 |
| full / message_soft_delete | 711 | 712 | 0.8485 | 0.8464 | -0.3% | 2.7262 | 2.0859 |

No analyzed serial operation p50 contrast was slower in this local matrix. Adverse primary write-statement p50 values above remain: high-water cursor+5.9% minimal/+4.1% full, and minimal soft-delete+4.3%. No causal attribution to index maintenance is asserted.

Native analyzed unread-method medians, two pool-level medians/state, four method invocations per pool (DM+channel statements). All initial-phase samples and actual query plans remain. The empty case means no live channel-message rows; direct messages remain.

| Flavour / native distribution | Absent ms | Present ms | Change |
|---|---|---|---|
| minimal / sparse_small | 6.982 | 2.213 | -68.3% |
| minimal / sparse_large | 34.337 | 9.364 | -72.7% |
| minimal / dense_server | 93.419 | 34.084 | -63.5% |
| minimal / hot_channel | 85.348 | 23.836 | -72.1% |
| minimal / empty | 36.793 | 1.839 | -95.0% |
| full / sparse_small | 4.552 | 2.407 | -47.1% |
| full / sparse_large | 33.516 | 8.037 | -76.0% |
| full / dense_server | 83.825 | 22.674 | -73.0% |
| full / hot_channel | 82.885 | 26.040 | -68.6% |
| full / empty | 107.883 | 2.586 | -97.6% |

Native data distributions use DELETE/reinsert on owned tables, including the logical-empty case after populated histories. Physical page/purge state is not isolated. The surprising empty-case latencies therefore do not establish a fresh-empty-schema speedup; that interpretation is PARTIAL/UNVERIFIED. The trigger is not assumed.

Verified benchmark mutations: 5,707 inserts, 2,846 edits and 2,842 soft deletes, with ten cursor rows/pool. Native resets occur only after these final states have been checked and preserved.

Natural workload lock-wait increments were 0 in these selected cells. All eight induced controls observed a messages.PRIMARY wait and completed successfully. This does not explain the five old Phase 3V waits: the new focused mix omits the old peer route, and historical lock attribution remains UNPROVEN.

**FPM queue freshness: PARTIAL.** Six cells reported queue values above their in-flight caps: minimal/absent mixed25 reported31; minimal/present read25 reported52; minimal/absent read25 reported29; full/present read25 reported44 and61; full/absent mixed25 reported28. Stale or cross-boundary status reporting is plausible but not experimentally isolated. These remain raw status readings, not exact instantaneous per-cell queue proofs. The maximum reported status queue was93. No saturation-elimination claim is made.

PASS: 12 instrumentation cases on SQLite and MariaDB, 35 existing native cases, 21 focused commands including Unit: 25 tests/56 assertions, schema migration reproduction and application-source parity. PASS for zero added full-suite failures and zero added PHPStan diagnostics. **FAIL: full PHPUnit**, 46 tests/114 assertions/five inherited AuthServiceTest failures on both sides. **FAIL: clean PHPStan**,15 inherited findings per side. Commands and child/wrapper exits are retained separately.


## Regression gates and inherited failures

The broader full PHPUnit command is executed in a fresh complete migrated schema, with Composer loaded before injecting the owned Database singleton. External mail credentials are explicitly empty. Both accepted-base and current checkouts execute all 46 tests and reproduce the same five AuthServiceTest failures,114 assertions: registration success, duplicate-email registration, peer-preference seeding, default style/goal registration, and successful login's registration precondition. The tests lack the verified-email session now required by the unchanged application.

The full suite is **FAIL**, not clean. The exact-base comparison passes only for zero additional failed cases; it does not turn those five failures into passed tests. Authentication behavior is not weakened or altered. Unit/native/focused checks and static regression comparison are reported separately. Full PHPStan has 15 inherited findings and must not be called clean.

Discovery failures are preserved: initial migration probe bypassed the configured PHP wrapper and lost its inner child diagnostics before the failed registry lookup; native probe used an incomplete client-charset setup; the first full-suite prepend loaded Database before Composer's autoload registry and exited255 without test output; minimal writes exposed missing moderation columns and insertion defaults. Corrected records and frozen controls remain separate. None of these preliminary timing runs is substituted for an independent frozen pool.

## Reproduction, CI and rollback

Use PHP8.3 with PDO mysql/sqlite and native FPM, MariaDB10.11, Composer and Node/jsdom. The driver owns and tears down every test DB/process/port. Do not supply production DSNs.

```bash
PHASE3W_OUTPUT=/tmp/phase3w-controls python3 -B -u tests/phase3w-compare.py
python3 -B tests/phase3w-report.py /tmp/phase3w-controls /tmp/phase3w-evidence.json
CLASSROOM_BASE=e818bbd84ae85d67aa71a38460fe154a5128a930 CLASSROOM_PHASE3W_SCHEMA=1 PHASE3W_FULL_PHPUNIT=1 CLASSROOM_EVIDENCE=/tmp/phase3w-schema.json python3 -B -u tests/classroom-performance.py
CLASSROOM_BASE=e818bbd84ae85d67aa71a38460fe154a5128a930 CLASSROOM_PHASE3W_TELEMETRY=1 CLASSROOM_EVIDENCE=/tmp/phase3w-telemetry.json python3 -B -u tests/classroom-performance.py
PEER_THREADS_BASE=e818bbd84ae85d67aa71a38460fe154a5128a930 PEER_THREADS_STATIC_EVIDENCE=/tmp/phase3w-static.json python3 -B -u tests/peer-threads-static.py
```

`docs/450-phase3w-raw/manifest.json` inventories lossless compressed/raw records with SHA256 and expanded identities. Evidence JSON is recomputed from all eight archives. Final exact-head hosted workflow/job/step/artifact verification is PENDING in this committed publication snapshot and must be supplied in the final PR body/handoff; previous Phase 3V results are not substituted. To reproduce the supplementary write/queue analysis, first run tests/phase3w-report.py, then run docs/450-phase3w-raw/recompute-additional.py with three arguments: raw folder, basic summary JSON and destination JSON.

Migration067's INPLACE/LOCK=NONE clauses do not remove metadata-lock waits, concurrent-write cost, temporary/storage demand or rollback planning. Empty-schema migration speed and100k synthetic reads do not authorize its deployment. Dropping an index changes plans and may retain tablespace allocation. No production migration, Hostinger operation, merge or deployment occurred.

Rollback: leave the draft investigation unmerged. If test/workflow/docs are separately integrated later, revert those paths/commits to the accepted base. No application/schema/data rollback is needed outside the disposable fixtures. Do not restore either rejected query. Representative staging, production schema/index inventory, full integration/security gates and50–100-student certification remain BLOCKED or incomplete.
