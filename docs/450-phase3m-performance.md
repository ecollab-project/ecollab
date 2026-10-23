# Phase 3M — application performance optimization

Review date: 2026-10-24 (Asia/Manila). Scope: application code, synthetic data and disposable local services. No production connection, configuration change, deployment, merge or capacity test.

## Source and verdict

Verified PR #79 is open/draft/unmerged at `9d5ded1af8ee55364fa87d9c9a05327ffb9193c9`, based on #78 `3b5983ba86ee1b0ba59b4cfa45d331b6f516a908`. The performance branch starts exactly at #79. Earlier private-delivery research is excluded completely. PR #73 remains a separate unmerged stack; no changes from it are imported.

**Focused performance changes: PASS within executed local coverage. Draft/staging review only.** CI at the new head is pending publication; the PR description will record its final exact-head result. No deployed performance improvement or student-capacity limit has been measured.

## Prioritized findings and implementation

| Priority | Exact source/function | Confirmed work | Minimal correction | Regression considerations |
|---|---|---|---|---|
| P1 | `StudentDashboardService::getStudentDashboardData`, `getNotifications`, `getUnreadNotificationCount`, `getActivityProgression` | Courses read3 times, unread counts3 times, repeated sessions/hours/message totals;35 SQL executions in fixture | Reuse local values within one response;25 executions | Fresh reads on later calls and different users; optional-table fallback and complete payload equality tested. No persistent or permission cache. |
| P1 | `StudentDashboardService::getChatUnreadCount` | Correlated channel scalar subquery inside message/member join; EXPLAIN reports DEPENDENT SUBQUERY | Equivalent explicit messages→channels→server_members join | Same unread count33,052 and entire dashboard payload; same membership, read/deleted/sender predicates and relationships |
| P1 | `PeerMatchingService::loadProfile`; `API/chat/peer-match.php` get_matches/search_users, `API/chat/get-matches.php`; `JarredGroupRecommendationService::recommend` | Four SQL reads per candidate (400 for100) | Add `loadProfiles`, at most100 IDs per batch, four reads per batch; preserve caller order and missing profiles |100 full profile equality,50 score/explanation comparisons, sparse/missing/duplicate IDs,201-ID bounds and complete group/pair evidence equality |
| P2 | `AdminDashboardService::getPlatformStats`, `FacilitatorDashboardService::getChannelStats` | DATE(column) daily predicates prevent date range access on MariaDB10.11 | Half-open current-day range using the same DB session calendar | Actual aggregation outputs and midnight/end/tomorrow/yesterday/NULL boundaries tested. No new index or migration. |
| P2 | `assets/js/student/dashboard.js`: renderLiveServers/Courses/Files, updateStudentLiveStats, refresh/startStudentDashboardLive | Unconditional list recreation/chart updates; hidden tabs still poll; repeated startup can register multiple visibility listeners | Per-list WeakMap signature, fragment replacement on change, chart comparison, hidden-tab skip, one visibility listener | Same list markup/actions, changed/empty data, focus/node retention, in-flight request guard and error handling tested. Visible cadence stays15s; visible transition refreshes. |

The profile loader changes data retrieval, not the scoring algorithm, semantic batch order, grouping algorithm or provider behavior. The existing single-profile method remains available. Batched tag rows use primary-key order; previous single queries had no explicit ordering. Tests compare complete fixture profiles and explanations, not merely final score numbers. No permission, candidate eligibility, AI preference, quota or retention rule changes.

## Measurements

Native PHP 8.3.6 and MariaDB 10.11.14-MariaDB-0ubuntu0.24.04.1 on owned loopback infrastructure. Synthetic fixture:900 users,20 servers,100 channels,100,005 messages,9,000 study sessions and10,000 scheduled sessions. Schema uses existing index definitions, not a proposed index. This dataset deliberately exercises unread/history work; it does not model ordinary production traffic or450 active students.

| Workload | Before | After | Scope |
|---|---:|---:|---|
| Student dashboard SQL executions |35|25|Same full service payload;12 samples/side|
| Dashboard median elapsed |889.56ms|132.46ms|Service call, not HTTP/FPM latency|
| Dashboard median PHP CPU |2.42ms|1.42ms|getrusage user+system; excludes DB CPU|
| Dashboard incremental PHP peak |114.09KiB|108.07KiB|memory_get_peak_usage−entry usage; excludes process/shared/native DB memory|
|100 profile loads SQL executions|400|4|15 samples/side|
|100 profiles median elapsed|31.43ms|2.36ms|Profile-loading portion only|
|100 profiles median PHP CPU|7.92ms|0.37ms|getrusage|
|100 profiles incremental PHP peak|440.98KiB|344.19KiB|No whole-request RAM claim|
|Unread channel count median|234.88ms|47.37ms|7 repeated actual SQL samples/side|
|Admin message daily count median|15.66ms|0.95ms|15 samples/side|
|Scheduled daily count median|1.50ms|0.07ms|15 samples/side|

Raw samples, min/max, SQL EXPLAIN rows and resource measurements: [evidence](450-phase3m-performance-evidence.json). Timings vary with host scheduling/cache state. Median reports use the upper middle observation for the even12-sample dashboard comparison; no latency threshold is enforced. Query budgets/equality are the deterministic regression gates. The before/after dashboard/profile order alternates. Daily SQL comparisons are warmed sequential repeats. These are instrumented in-process service comparisons, not independent production benchmarks.

EXPLAIN observations:

- Unread: original full messages scan + dependent channel subquery; new plan starts from the user's membership index and joins channels/messages through existing keys. Counts match.
- Daily messages: ALL with estimated99,800 rows → range on `idx_created`, estimated557 rows.
- Daily scheduled sessions: full index walk (estimated9,976) → range on `idx_start` (estimated100).
- Estimates are optimizer estimates, not actual rows visited. No EXPLAIN ANALYZE is executed on production. No database setting/index/schema change is included.

## Frontend evidence and limits

JSDOM/VM executes the actual live-dashboard functions against synthetic100 server cards,100 course cards and20 file rows. Twenty unchanged refresh renders produced **4,460 → 0 top-level mutation records**, with harness elapsed **761.29 → 1.77ms** for that sequence. This is one DOM-harness observation, not real browser layout/paint/mobile timing or a measured page-load speedup.

Four simulated hidden refresh intervals caused **4 → 0 requests**. Startup/visibility tests confirm one listener after repeated initialization. Unchanged chart data causes no further chart update. Existing inbox adapter dedup/reconnect/queued-read/stale-target tests also pass; the chat adapter is unchanged.

The WeakMap retains one serialized snapshot per existing list element, replacing it on change. Fixture snapshot UTF-8 length is 44,350bytes; this is not JavaScript heap size. Extra retained snapshot memory trades against repeated node/listener allocation. Actual browser heap, CPU, layout shift, mobile rendering and initial page load: **NOT MEASURED**. Changed data still rebuilds the affected list with the same controls; no virtualization or redesign is introduced.

## Review coverage beyond this patch

| Area inspected | Outcome | Remaining optimization opportunity |
|---|---|---|
| Student/facilitator/admin dashboards | Implemented measured reuse/join/date changes | Student unread still scans joined message history; measure realistic read-marker distributions before aggregate/index work. Large admin server/channel lists remain unpaginated; preserve consumers before pagination. |
| Chat/DM | Existing async admission, current browser in-flight/dedup and pagination inspected; no change | Profile high-traffic API mix before touching message/write transaction scopes. |
| Coworkspaces/documents/whiteboards | No justified measured patch | Dashboard workspace aggregate can be profiled separately; live document/render workload NOT RUN. |
| Jarred AI | Profile loading for deterministic facilitator grouping improved | Generator already caps history12/context budget; real model/inference/provider latency NOT RUN. Shared inference coordination and response behavior unchanged. |
| Background jobs/Code Lab | Queue claim/retry and idle loops inspected; no change | Jarred idle loop500ms, Code Lab idle loop1s. Actual idle DB/CPU and busy queue latency NOT MEASURED; do not change cadence/fairness from code inspection alone. |
| Frontend admin/facilitator/settings/threads/Code Lab | Read-only inspection; no numerical claim or behavior change | Page-specific long-task/API tracing and real mobile/browser layout measurement before further patches. |
| UI assets | No new dependency or asset bundle | Transfer/startup budgets and real cold-load baseline NOT RUN. |

This work does not claim to remove row-lock contention. No lock-wait metric was collected for a representative write workload, and historical FPM saturation is not proof of a current bottleneck. No worker count, polling interval for job processing or model concurrency changes.

## Validation

- PASS: actual isolated MariaDB payload equivalence, query budgets, no hidden SQL errors in successful dashboard samples; fresh-call/user isolation; missing notification-table fallback.
- PASS: full profile/grouping equivalence, score/explanation/semantic positional stability, batch bounds and missing IDs.
- PASS: actual admin/facilitator aggregate equivalence and date boundaries; EXPLAIN evidence above.
- PASS: focused DOM behavior/equivalence/request tests; existing chat-delivery browser adapter.
- PASS: PHP syntax, JavaScript syntax, existing peer-ML and learning-tools regressions, PHPUnit25 tests/56 assertions.
- FAIL — existing baseline: PHPStan15 diagnostics at both commits, identical after normalizing worktree paths; none introduced/suppressed. Initial128MiB static-analysis subprocesses ran out of memory;512MiB local analysis completed. No production memory setting changed.
- CI: new `Application performance regression` workflow reruns native SQL/DOM comparisons and uploads actual evidence. Exact-head CI pending at this committed snapshot; final PR description is authoritative for returned runs. Parent-head green results are not counted for this PR.
- NOT RUN locally: full Chromium page/mobile layout, complete HTTP peer-route rendering, real model inference, full mixed-workload/450 users. Existing async CI remains enabled to check #79 compatibility on the new head.

Reproduce locally with MariaDB10.11/PHP8.3 and Node dependencies:

```sh
PHP_BIN=php8.3 MARIADBD_BIN=mariadbd APPLICATION_PERF_EVIDENCE=/tmp/ecollab-perf.json python3 -B tests/application-performance.py
npm ci --prefix tools/chat-delivery --ignore-scripts
node tests/application-performance-browser.cjs
php8.3 tests/peer-ml-regression.php
php8.3 tests/learning-tools-regression.php
php8.3 vendor/bin/phpunit --testsuite Unit
```

The Python runner creates its own temporary data directory/database and extracts baseline classes from the immutable #79 commit using git. It accepts no external database DSN. Fetch that commit first when using a shallow checkout. Synthetic SQL fixtures live under `tests/fixtures`; no student content is involved. Benchmarks assert results/counts, not environment-dependent wall-time targets.

## Staging prerequisites and rollback

Keep the PR draft and unmerged. Before any separately authorized activation: integrate/review exact source, run relevant CI, verify current schema has the expected existing indexes, record representative50–100-user dashboard/peer workloads and client metrics, and check DB CPU/rows/locks plus FPM request queues and busy-worker memory. No PHP-FPM tuning is proposed from these fixture numbers.

No migrations, data transformations, configuration variables, worker services or packages are required. Rollback is code/assets only; deploy matching PHP/JS versions through the existing release process and invalidate static assets through its normal mechanism. No messages, grades, queue jobs or histories are deleted or replayed. The #79 async transactional path is untouched, with no synchronous fallback.

Separate prior decisions remain: **R7 REQUEST CHANGES; Jarred governance PARTIAL/BLOCKED; Code Lab independent-host isolation BLOCKED;450-user readiness UNVERIFIED.** They are outside this performance phase. Passing this patch's fixtures cannot approve the broader release.
