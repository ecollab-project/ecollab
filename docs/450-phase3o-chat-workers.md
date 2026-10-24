# Phase 3O — chat read paths and worker performance

Review date: 2026-10-24 (Asia/Manila). Baseline PR #81 `687bd3ad2d60bf5c5e0627d640e627d7fbcc1d9d`, verified open/draft/unmerged and based on #80 `87e82284ced2cee4858b673cc5a8619151c019bb`. New branch starts exactly at that head. No production connection, configuration change, migration, deployment, merge, real compiler/model execution or load test.

## Findings and selected changes

| Priority | Exact file/function | Measured finding | Decision |
|---|---|---|---|
| P1 | `services/StudentDashboardService.php::getRecentChat` | Full global ordering after joining the user's message history; about94.18ms in the distributed fixture | Add an exact, bounded-result probe with full-query fallback; no new index |
| P2 | `assets/js/chat/dm-notifications.js::_renderDmList`, `_renderGroupList` | Both sidebars recreate identical markup on refresh | Reuse unchanged rendered lists with per-container WeakMap state; observe external mutations so they cannot remain hidden |
| Next | `LearningWorkspaceService::data` facilitator member query | Two correlated message aggregates per member dominate the larger facilitator fixture | Record actual statement timings, leave query/result semantics unchanged pending broader distributions |
| Next | `API/dm/get-conversations.php` SQL | Correlated unread reads over50 conversations/25,000 messages | Record actual SQL profile; do not add a speculative cache/index |
| Measured, unchanged | `scripts/jarred-worker.php`, `scripts/code-lab-worker.php`, queue services | Short idle/burst samples do not establish harmful polling cost | Keep500ms/1s waits, worker counts, fairness, retry/recovery and inference concurrency unchanged |

Application changes are confined to two files. Migration067 is byte-for-byte unchanged. There is no schema/write-path change in this PR.

## Exact recent-chat algorithm

The original full query remains intact as fallback, including its five output fields, membership/channel/server joins, soft-deletion filter and `ORDER BY m.created_at DESC LIMIT 8`. No new visibility rule or tie-breaker is introduced.

The probe selects at most256 globally newest message IDs/timestamps, joins only those candidates using the same current user/membership filters, and fetches at most9 matches. An existence gate avoids materializing the global head when no live accessible message exists.

Return the first8 only if all9 timestamps are non-NULL and strictly descending. Proof: the ninth match is strictly older than the eighth; every omitted global-head/tail row is no newer than that ninth match, so it cannot displace the first8. Any tie, fewer matches, NULL timestamp or probe exception invokes the original full query. Thus256 is a work-window candidate size, not a result cap or approximate feed. There is no persistent cache; subsequent changes/users are read afresh.

The LIMIT bounds logical candidate rows, **not physical engine effort**. MVCC versions, tombstones and an unhelpful/missing existing index can still add work. Empty/sparse/tied workloads may incur a second round trip. The fixture deliberately records those costs rather than claiming a universal speedup.

## Recent-chat results and SQL plans

Native PHP 8.3/MariaDB 10.11,900 synthetic users,20 servers,100 channels and100,005 messages. Seven alternating samples per side/case; exact complete output arrays match on every sample. Tests run with and without existing optional migration067, and also verify a later inserted message, another user and removed membership on the same service instance.

| Distribution, no067 | Original median ms | Candidate median ms | Probe accepted |
|---|---:|---:|---:|
| distributed | 94.18 | 0.83 | 7/7 |
| sparse_membership | 9.85 | 0.69 | 7/7 |
| no_membership | 0.46 | 0.94 | 0/7 |
| recent_busy_channel | 66.69 | 0.97 | 7/7 |
| old_accessible_only | 85.66 | 77.47 | 0/7 |
| deleted_recent | 61.78 | 0.86 | 7/7 |
| all_timestamps_tied | 75.73 | 75.63 | 0/7 |
| only_eight_visible | 40.05 | 42.60 | 0/7 |
| null_dates | 60.56 | 137.91 | 0/7 |
| empty_messages | 3.04 | 6.39 | 0/7 |
| many_memberships | 166.59 | 0.85 | 7/7 |

The NULL-only fixture bulk-updates all dates inside an uncommitted transaction. It models an unsupported/legacy nullable schema plus heavy MVCC churn (the core schema declares timestamps NOT NULL) and exhibits a significant adverse probe cost. Empty-after-delete also has extra cost. Do not approve this optimization as a universal latency bound. Representative staging must include sparse feeds, ties and write/MVCC churn.

Distributed PHP CPU: 0.192→0.148ms. Incremental PHP peak: 45688→46856bytes. These measure PHP user/system CPU and PHP allocator peak above call entry, not MariaDB CPU, native buffers or FPM PSS. At most9 output rows are temporarily loaded rather than8; large bodies may therefore add transient memory.

Query count:1 on accepted probes,2 on fallback, versus1 originally. A normal full dashboard can stay at25 or rise to26 when the recent-chat probe falls back. Do not weaken the older dashboard query-count tests merely to hide a failure; common-fixture budgets remain checked by inherited CI.

EXPLAIN records are included for every case in [raw evidence](450-phase3o-evidence.json). The original plan orders a potentially large joined history. The probe materializes256 IDs through existing `idx_created`, then uses primary-key/channel/membership lookups and a small final ordering. Optimizer `rows` figures are estimates, not observed physical scan counts. No FORCE INDEX, new migration, approximate aggregation or original-index removal is used.

## Other read-path profiles

Diagnostic single samples, not before/after optimizations or latency distributions. Actual MessageService pagination and LearningWorkspaceService methods run; DM list SQL is extracted from the real endpoint and executed with its actual parameter bindings. No full HTTP/auth/serialization timing is claimed for these profiles.

| Read path | Elapsed ms | Executed statements |
|---|---:|---:|
| history_first | 1.81 | 5 |
| history_before | 1.50 | 5 |
| history_after | 1.36 | 5 |
| workspace_student | 9.28 | 11 |
| workspace_facilitator | 248.66 | 11 |
| dm_conversation_list | 25.11 | 1 |

History first/before/after queries use the existing ID pagination and batched reactions/attachments. No redundant per-message PHP query loop was identified in these paths. Current workspace samples include100 channels,200 resources/notes and a300-member selected facilitator server; the student and facilitator sources are checked available. The real current workspace is distinct from the compatibility StudentDashboardService endpoint; do not attribute recent-chat feed gains to its11-query API.

The facilitator member aggregate and DM conversation unread SQL are remaining measured candidates. Wider role/data/pagination equivalence fixtures are needed before rewriting them. JSON serialization was not identified as a bottleneck here and is not separately measured in this phase.

## Worker idle/burst measurements

Actual CLI worker scripts run on separately created fixture databases. Code Lab runs one real supervised slot; Jarred runs its existing singleton. A loopback HTTP responder supplies3 synthetic model/compiler results; no student code, actual model, Piston or production endpoint is invoked. Admission/reservation/persistence logic is retained for Jarred; Code Lab receives synthetic stored queue jobs across two servers. All3 jobs finish and claim order is1,2,3; existing regression suites cover broader lifecycle/grading behavior.

| Metric | Jarred | One Code Lab slot |
|---|---:|---:|
| Observed idle window |4.02s|4.04s|
| Approx. DB Questions/s |8.20|5.20|
| Three-job burst to done |228.03ms|652.31ms|
| Commit-ack→claim observations |74.8, 87.4, 99.2ms|570.7, 547.9, 522.3ms|

Question rates use an isolated server-wide counter delta, subtracting one observer SHOW; counts include transaction/control statements, not merely SELECT executions. Four seconds is a short sample with boundary/startup/observer uncertainty, not a load or stability measurement. Commit acknowledgment is sampled client-side; a claim may precede that acknowledgment slightly, so this is not an absolute queue SLA. Burst wall time includes polling, HTTP fixture handling and test-observer overhead.

Code Lab's own final summary reports approximately 3.02ms average application overhead per completed job, excluding approximately 1.45ms runner HTTP time in this local run. These are fixture observations, not actual compilation/AI cost. Individual database transaction durations were NOT MEASURED; no transaction-scope change is justified. The first local attempts exposed `/proc` permission/PID-namespace limits; idle/busy CPU and PSS are unavailable locally, recorded as null rather than zero. CI is set up to capture them where available. There is no memory-leak/stable-soak claim from these short samples.

Code Lab exits cleanly after SIGTERM; Jarred's existing default SIGTERM termination remains unchanged. All synthetic jobs complete before stopping. No new graceful-shutdown behavior, recovery guarantee or exactly-once inference claim is made.

## Frontend performance and compatibility

Both sidebars still generate the same complete HTML. `_setDmListHTML` skips only an identical render, stores one string per container and observes external child/attribute/text changes. Pending observer records are inspected synchronously before a skip; own-render records are drained. This avoids hiding changes made by another component while retaining nodes/scroll on genuinely unchanged updates. Browsers without MutationObserver use the original unconditional assignment.

Local JSDOM/VM with 50 DM+50 group rows and 20 unchanged refreshes observed40→0 mutation records and 1782.49→7.30ms in the final local harness run. This is a DOM-harness measurement, not Chromium layout/paint. Changed unread/active state, external removal, empty lists and exact HTML equality are tested. Retained HTML strings and two observers have a memory cost; actual browser heap savings are NOT CLAIMED.

New CI runs real Chromium at 1440×900 and 390×844 with the actual renderer/utilities/timeline code. The focused synthetic shell exercises 50+50 sidebar rows, 500 accumulated messages, 20 appended messages, duplicate suppression, message order, scrolled-away position, input focus and click actions. It measures script/task/layout/style durations and DOM mutations. It is not the complete authenticated chat shell or a real network test. The final PR description will record exact-head browser results; local Chromium is NOT RUN after runtime restoration.

## Validation and final gates

- PASS locally: 11 recent-chat distributions ×2 index states, complete output equality, current-data/user changes, PHP/JS syntax and DOM component tests.
- PASS locally: PHPUnit 25 tests/56 assertions after restoring a missing local session directory; the initial environment failure was not an application defect. Existing peer, learning workspace/UI and Code Lab execution regressions pass.
- PHPStan: 15 diagnostics identical to #81 after normalizing paths and line numbers; none introduced or suppressed. No ignore/suppression change.
- NOT RUN locally: real Chromium, actual model/compiler, full HTTP DM/auth flow, FPM/mixed concurrency, long soak, individual transaction timing, usable local worker CPU/PSS.
- CI pending publication at this committed snapshot. New worker/read/browser workflow and inherited chat/dashboard/Jarred suites must pass at the exact new head. Only returned runs will be reported; source-tree identity is checked.

Reproduce with PHP 8.3/MariaDB 10.11 and test-only Node dependencies:

```sh
PHP_BIN=php8.3 MARIADBD_BIN=mariadbd CHAT_PERF_EVIDENCE=/tmp/chat-perf.json python3 -B tests/chat-performance.py
node tests/chat-list-performance.cjs
CHAT_BROWSER_EVIDENCE=/tmp/chat-browser.json node tests/chat-list-browser.cjs
```

The runner owns its temporary datadir/databases, ports, lock files and HTTP responder. It accepts no external database DSN. There is no production operation. Synthetic migrations are used only to construct disposable fixtures; 067 is unchanged and is not activated on production.

**Verdict: staging-only performance candidate, conditional on exact-head CI and representative workload acceptance.** No new schema/write maintenance is added; rollback is code/assets only and preserves jobs/history/read markers. Remaining opportunities are the facilitator member aggregate, DM unread SQL and sustained CPU/memory/queue behavior. The next meaningful capacity milestone is an authorized 50–100-user staging workload. **450-user readiness remains UNVERIFIED.**
