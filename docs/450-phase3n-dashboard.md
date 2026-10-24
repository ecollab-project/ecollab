# Phase 3N — dashboard HTTP, unread SQL and current browser performance

Date: 2026-10-24 (Asia/Manila). Draft/staging review only. No production connection, deployment, configuration change, merge or load test.

## Source and routing correction

PR #80 was verified open, draft and unmerged at `87e82284ced2cee4858b673cc5a8619151c019bb`, based on PR #79 `9d5ded1af8ee55364fa87d9c9a05327ffb9193c9`. This branch starts exactly at #80.

The current `/modules/student/dashboard.php` renders `includes/layout/learning-workspace.php` and loads `assets/js/learning/workspace.js`; it calls `/API/student/workspace.php` → `LearningWorkspaceService`. It does **not** load `assets/js/student/dashboard.js`. Therefore Phase3M's legacy-list mutation benchmark does not prove the current workspace page renders faster. The current script is byte-identical between #79 and #80; the Chromium fixture asserts that identity and compares #80/current rather than inventing a distinct #79 browser improvement.

The25-query `StudentDashboardService` remains reachable through `/API/dashboard/live.php` and `/API/student/dashboard-data.php`. These compatibility endpoints are measured separately from the active workspace. The active workspace fixture executes11 SQL statements; its count is unchanged.

## Prioritized findings and changes

| Priority | Exact source/function | Evidence and correction |
|---|---|---|
| P1 | `StudentDashboardService::getChatUnreadCount` | The per-message join repeatedly evaluates read markers. Count per authorized channel instead, preserving membership/read-marker/deleted/sender predicates and exact totals. Still one prepared statement. |
| P1 | `messages` existing indexes; new migration `067_dashboard_unread_covering.sql` | Existing indexes cannot cover this exact count. Add `(channel_id,is_deleted,created_at,sender_id)` only after real MariaDB plans/distribution measurements. Indexed per-channel count is materially faster in populated cases. |
| P2 | `assets/js/learning/workspace.js::date` | Each rendered timestamp constructs an Intl.DateTimeFormat. Lazily reuse one formatter with the identical locale/options. Node's1000-format fixture preserved valid/invalid/empty outputs and observed87.37→2.29ms; this is not a browser paint measurement. |
| Next | `StudentDashboardService::getRecentChat` | Largest remaining query in the first profile (~64ms); retains existing implementation. A global ORDER BY across joined memberships still needs distribution-sensitive work. No unproven ORDER/index hint is shipped. |

The first profile's next largest query was unread counting (~52ms), then server recommendations (~7ms). All25 statements succeeded. Statements are not removed merely to lower the count. No shared cache, extra worker, quota change, prompt change, rendering redesign or deleted feature is introduced.

## Exact unread results and SQL plans

Owned MariaDB10.11/PHP8.3 fixture with900 synthetic users,20 servers,100 channels and100,005 messages. Seven query samples per side/state. All cases match exactly; zero/NULL markers and own/deleted messages are included.

| Distribution | #80 existing indexes median ms | New query + covering index median ms | Exact count |
|---|---:|---:|---:|
| no_markers | 52.96 | 8.28 | 33,052 |
| recent_markers | 44.60 | 9.15 | 420 |
| fully_read | 46.67 | 9.34 | 0 |
| mixed_markers | 51.50 | 9.08 | 15,373 |
| null_markers | 47.58 | 8.45 | 33,052 |
| many_memberships | 150.98 | 26.15 | 94,631 |
| deleted_and_own | 40.95 | 42.38 | 15,785 |
| few_memberships | 6.93 | 1.46 | 4,631 |
| no_memberships | 0.06 | 0.07 | 0 |

The report's [evidence](450-phase3n-evidence.json) includes every SQL plan and sample, including the intermediate query-only/index-only combinations. Baseline plan joins read markers per matching message; selected plan visits memberships/channels/read markers outside a dependent count using `dashboard_unread` with `Using index`. This is a deliberate dependent count with a covering access path, not a blanket claim that all correlated queries are slow. EXPLAIN row counts are optimizer estimates. No FORCE INDEX, approximate unread counter, additional permission cache or data pre-aggregation is used.

Query-only improvements were modest (roughly10ms in HTTP medians); the covering index provides the larger part. Without migration067, the application still runs correctly and uses existing indexes. Query execution count stays25 →25.

## Actual HTTP measurements

Seven sequential samples/state, same synthetic contents. Actual authenticated endpoint code is copied into the disposable harness solely to wrap `json_encode` with a timer; PHP source, UserService, auth/session, SQL and response shape otherwise execute unchanged. PDO execute timings include client/server result buffering; they are not MariaDB CPU measurements. Baseline is a detached #80 worktree; candidate is this tree. DB session time is frozen for result comparison. `generated_at` is excluded from equality; all dashboard data, notification/unread totals and encoded payload lengths match. New session/user and post-write fresh reads are asserted.

| Metric, median | #80 baseline | Query only | Query + index |
|---|---:|---:|---:|
| HTTP request ms | 135.58 | 125.55 | 99.13 |
| PHP instrumented endpoint ms | 133.25 | 122.97 | 96.82 |
| Summed PDO execute ms | 127.90 | 117.01 | 88.90 |
| PHP CPU ms | 3.27 | 3.79 | 3.78 |
| JSON serialization ms | 0.058 | 0.076 | 0.052 |
| Incremental PHP peak bytes | 314832 | 315184 | 315184 |
| JSON payload bytes | 19157 | 19157 | 19157 |

The fixture uses PHP's built-in HTTP server, not PHP-FPM. Setup/DSN injection happens before the endpoint timer; HTTP wall time includes that overhead. `php_ms − summed PDO ms − encode_ms` is only an approximate residual, not an isolated PHP phase. No PHP CPU/RAM reduction is established: CPU was slightly higher and incremental peak rose352bytes in this run. Host CPU, PSS, concurrent request queues and production percentiles are NOT MEASURED.

## Index cost, compatibility and deployment conditions

Reported secondary-index allocation: 13,156,352 → 17,874,944bytes, an additional 4.50MiB in this fixture. These are InnoDB metadata/page-allocation readings, not exact per-index physical file sizes. Median1000-row insert fixture: 5.26 → 5.93ms (five insert/rollback samples/state). Additional storage and write maintenance are real tradeoffs; this is not a universal throughput improvement.

Migration067 is additive, after066 in normal ordering, MariaDB10.11/InnoDB only. `ADD INDEX IF NOT EXISTS` supports rerun; local fixture executes the migration twice and rechecks full dashboard results. `ALGORITHM=INPLACE, LOCK=NONE` refuses a blocking copy fallback, but initial/final metadata locks, build CPU/I/O, temporary space and concurrent DDL still require an authorized staging plan. Verify the existing `dashboard_unread` name/column order before applying; IF NOT EXISTS alone does not validate a pre-existing index definition. Historical migrations/ledger entries are untouched.

No production migration is executed. For a later approved rollout, verify backup/restore, disk headroom, schema/engine and representative insert/update/read cost; apply separately under normal migration review. App code works before or after the index. Code rollback requires no data rollback; retaining the extra index is compatible. Any DROP INDEX is a separately approved schema operation; do not drop messages, read markers, grades, jobs or history.

## Real browser plan, evidence and limitations

Local Chromium: **BLOCKED**, because both full Chrome and headless-shell downloads were truncated. No local Chromium timing is claimed. New CI installs pinned Playwright1.58.2/Chromium and runs `tests/dashboard-browser.cjs` on the actual authenticated PHP-rendered current page at1440×900 and390×844. It measures navigation/DCL, script/task/layout/style durations, long tasks, layout shifts, node count, heap readings where available and20 note-pagination renders with layout/paint opportunities. Functional assertions cover search, refresh, pagination, navigation, slash shortcut, one search listener, activity bars and mobile menu/overflow.

The isolated browser uses the actual workspace API and local application assets; unrelated APIs/media and external CDN requests are stubbed/blocked and counted. This isolates dashboard work but excludes real provider/CDN/network delays and full call-service behavior. Mobile viewport is not mobile hardware. Heap readings are snapshots, not RSS/PSS or proof of a leak. Current workspace activity uses CSS bars; there is no chart canvas to initialize. The script-identity check prevents attributing #79→#80 legacy-list gains to this page.

CI will record raw browser and HTTP evidence as an artifact and log it. The final exact-head CI/browser results will be placed in the PR description; this committed report is the initial local snapshot. Timing thresholds are not test gates; equality/count/interaction assertions are.

## Regression status

- PASS locally: all unread distribution comparisons, plan collection, owned MariaDB HTTP comparisons, full dashboard data equality, exact notification totals,25-query budgets, subsequent-write freshness, second user, actual workspace API sources available, migration rerun.
- PASS locally: current student/facilitator UI regressions; learning workspace and learning tools; date-format output equivalence; peer ML regression; PHPUnit25 tests/56 assertions; PHP/JS syntax.
- BLOCKED locally: real Chromium download. CI test required before browser readiness can be evaluated.
- NOT RUN here: production/FPM,50–100-user or450-user mixed workload, actual mobile device, real inference, network/media testing. Existing async Jarred and peer tests remain enabled in CI; final results must belong to the new head.

Read-only profile/fixture commands:

```sh
PHP_BIN=php8.3 MARIADBD_BIN=mariadbd DASHBOARD_QUERY_EVIDENCE=/tmp/dashboard-perf.json python3 -B tests/dashboard-performance.py
# With test-only Playwright/Chromium installed:
DASHBOARD_RUN_BROWSER=1 PHP_BIN=php8.3 MARIADBD_BIN=mariadbd DASHBOARD_QUERY_EVIDENCE=/tmp/dashboard-browser.json python3 -B tests/dashboard-performance.py
node tests/dashboard-date-regression.cjs
```

Both runs own their temporary MariaDB datadir/database and loopback PHP processes; no external DB address is accepted. Synthetic fixtures use existing application-performance schema plus current workspace tables. A detached baseline worktree is removed in cleanup. New application changes are only the exact unread query, additive covering index and date-formatter reuse.

## Remaining opportunities and verdict

Profile recent-chat pagination/order with both sparse and busy channels, then recommended-server aggregation. The active workspace's11-query API is a different optimization target from the compatibility25-query endpoint. Measure actual cold page loading, repeated refreshes and CPU on representative50–100-user staging before tuning FPM or claiming capacity. No additional service/worker/network redesign is proposed.

**APPROVE WITH CONDITIONS for isolated performance review only:** exact-head CI including Chromium must pass; migration write/storage/build cost needs representative staging acceptance. Production release gates remain separate. **450-user readiness: UNVERIFIED.**
