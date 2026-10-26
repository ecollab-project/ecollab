# Phase 3S: native FPM profiling and facilitator activity aggregation

26 October 2026. Engineering evidence, awaiting independent audit. Scoped local functional PASS; full system and production capacity remain UNVERIFIED.

## Exact source and scope

Repository: `ecollab-project/ecollab`. Branch: `optimization/phase3s-http-20261026`.

- Exact base: `2716766ab71f27d32d0d435d555f19801d7d0aa7`, PR #87.
- Application/fixture source commit: `f49147c6bcff2570f34720548f14fd6299705342`.
- Source tree: `7f516220ef9c3b4e5bb3d68bad4afec6cca68fb3`, verified equal to the local implementation tree.
- The final draft PR adds this evidence after the source commit. Its description identifies the full final head SHA. Application hashes in each raw run identify the measured source, despite measurements preceding publication.
- Application change: `services/FacilitatorDashboardService.php`, only `getChannelMemberActivity` SQL. No migration, index, cache, permission, friendship or provider change.

The inherited optimization stack includes #86 and #87; both were freshly verified open, draft and unmerged. The primary objective here is measured read-path optimization, not a release or capacity certification.

## Jarred prerequisite

Original exact-head workflow run `40299883725`, job `121494879249`, cancelled during browser/dependency installation. Later integration steps were skipped. Its first log timestamp was 09:29:04 UTC and cancellation 09:41:15 UTC; the job specifies timeout-minutes=12. A job timeout is the supported inference, not a conclusively reported cancellation reason. No manual/superseding cause was established.

A single rerun succeeded: job `121502248285` in the same run, for head `2716766ab71f27d32d0d435d555f19801d7d0aa7`. Actual checkout was PR merge `82c1f4a50d33547044fbab71877daa081bb75898`. The merge tree30e77454be191e759df4fcb869db8f34dedd90db equals the #87 head tree, verified by fetching Git objects and an empty full-tree diff. All dependency, syntax/permission/crash/privacy, browser, MariaDB/TCP-fault/HTTP/CLI recovery steps passed. It does not replace new Phase 3S CI. Source logs and step inspection establish the prerequisite result. The original cancellation remains recorded, not relabeled as success.

## Discovery and selected bottleneck

An initial native FPM survey covered student dashboard, learning workspace/navigation, chat history/unread data, DM conversations, threads/attachments, notifications, settings, peer matching, facilitator workspace and facilitator dashboard. The eleven routes have deliberately equal frequency; this is not observed student behavior. The discovery fixture retained 100,005 physical message rows while varying visible rows and found facilitator dashboard latency around 300ms, with its member activity join consuming about 200ms of SQL time. Discovery raw samples are retained separately and are not mixed into the final paired table-size benchmark.

The member query joins each server member to channel messages before grouping. Its base plan repeatedly uses sender lookups and channel rowid filtering. The candidate aggregates live messages for the selected channel once by sender, then joins those counts to current members. Existing `idx_channel` handles the derived read; no index or forced plan was added. Native EXPLAIN evidence shows the base sender/channel filtering and the candidate channel-index aggregation.

`COALESCE(MAX(activity.messages),0)` preserves zero-message counts and the existing grouped output. Membership/deleted-user restrictions, all-history counting, activity thresholds 5/20/50, profile fields, the existing grouping, descending count ordering and limit15 remain unchanged. This also retains existing unspecified tie behavior rather than defining a new tie breaker. Ten targeted native cases include a tied top15 comparison.

## Test environment and workload

Owned loopback MariaDB10.11.14, PHP8.3.6 FPM-FCGI and an HTTP-to-FastCGI Python fixture gateway. No Nginx, TLS, Hostinger or production connection. Native binaries were extracted under scratch, not installed as services.

Host cgroup: eight CPUs of quota, affinity CPUs0–8, 8GiB memory. This is not the intended two-vCPU VPS. FPM dynamic pool: maximum10 children, six initial workers, min/max spare4/6; OPcache absent. MariaDB:128MiB buffer pool,32 connection limit. Pool status uses a separate owned FPM status listener;50ms observations include visible native process CPU/PSS snapshots. Sampled peaks can miss shorter peaks. The generator, database, FPM, and observer share the host.

Final datasets contain 10,005 and100,005 physical message rows. Other cohorts stay fixed:900 synthetic users and existing fixture servers/channels,100 threads,20,000 DM messages. Each dataset is rebuilt from the same owned source table. SQL time is fixed to1792800000. PHP's generated_at envelope remains a real wall clock and is the only field normalized for comparison.

Each dataset has one serial11-route warm-up, a66-request serial survey, then bounded bursts at1/10/25/50/100 request slots, with33/33/75/150/300 operations respectively. Each slot makes one request at a time. Facilitator reads share owner900; student IDs vary1–100. Actual distinct accounts are2/11/26/51/101, including the shared facilitator, and the maximum in-flight request slots are as labeled. These are not100 active students. No rate-limit bypass or peer-profile substitution occurs; only owned fixture rate/cache tables reset between cells.

Four serial runs alternate base/candidate/candidate/base, each with a fresh owned database and FPM pool. Every run contains1,336 requests including warm-ups,5,344 total. All56 cells,raw per-request timing/CPU/peak memory/bytes/SQL/status samples, bound-query EXPLAIN and complete retained payloads appear in compressed evidence. No latency threshold is used to advertise capacity; errors/missing SQL metrics or requests approaching8s stop the fixture and preserve partial output.

## Final paired observations

Values below are medians of the two per-side serial-survey summaries, six requests per route per run. These short samples carry no confidence interval.

| Physical history | Facilitator HTTP p50 ms base → candidate | SQL mean/request ms base → candidate | PHP CPU mean/request ms base → candidate |
|---|---:|---:|---:|
|10,005 messages|35.986 →18.369|29.934 →14.375|2.179 →1.980|
|100,005 messages|325.496 →108.354|312.057 →104.382|2.832 →2.278|

Facilitator dashboard SQL statement count remains11. Most benefit comes from reduced database work, not fewer round trips. PHP peak/memory and response-byte distributions appear in the JSON; no memory-saving claim is made. Existing primary-channel count subqueries still cost substantial time and are a measured follow-up candidate.

Short mixed burst observations, median of two runs per side:

| Large-history request slots | Completed successful RPS base → candidate | Request p95 ms base → candidate |
|---|---:|---:|
|1|20.26 →36.03|325.15 →99.38|
|10|76.67 →94.88|258.89 →195.22|
|25|97.54 →96.95|375.86 →417.10|
|50|85.97 →96.04|891.58 →777.86|
|100|88.01 →93.04|1558.48 →1437.63|

The25-slot cell regressed. The small-history10-slot completed RPS also regressed278.50→261.14. All unfavorable route/cell results remain in the summary and raw data. Unchanged routes vary too, showing host/cache noise. Do not attribute their improvements to the one modified query.

At100 slots, large-history p50/p95/p99 are926.62/1558.48/1671.34ms base and896.25/1437.63/1512.86ms candidate. FPM queue peaks remain90–91 base and91–92 candidate. The pool still saturates; this is not a saturation fix. Small-history queue samples fell but are short-run observations. Recorded InnoDB row-lock wait increments were zero in final burst cells; this does not establish lock-free operation. Native DB process snapshots are retained, not presented as isolated per-query CPU.

RPS is completed operations divided by finite batch elapsed time, not sustained open-loop throughput. Two repeats, small per-route cells, same-host clients, synthetic distributions and no OPcache limit generalization. No real semantic inference, AI model, media, document collaboration, admin dashboard, actual browser rendering, long soak, WAN/TLS or production traffic was measured. Representative production-like50–100-user capacity acceptance remains BLOCKED.

## Functional and regression acceptance

PASS:660 distinct complete route/account/dataset payloads per run match across all four runs, including array order. Every recorded request's canonical hash set also matches for1,002 cell/route/account combinations. Only dashboard generated_at is normalized; no output lists are sorted or dropped by the comparator.

PASS: ten real MariaDB targeted cases: populated, no live messages, no members, deleted users, nonmember authors, NULL/old/future timestamps, zero/tied member counts, all activity thresholds, missing channel and channel0. Strict PHP equality covers values and types. The populated case also compares the complete dashboard service response. Tests retain query plans and output arrays in raw evidence.

PASS: PHPUnit25tests/56assertions; existing peer ML, learning tools/workspace, settings runtime, phase2 Jarred, chat upload access, and jsdom student/facilitator regressions. Commands, output and exit codes: `450-phase3s-raw/regressions.json`.

FAIL, existing baseline: full PHPStan has15 diagnostics on both exact trees. PASS: no additional diagnostic. Both full reports and exit codes are in `450-phase3s-raw/static.json`. Syntax and Python compilation checks passed.

The first cross-instance comparison failed because unchanged legacy membership ordering had equal member_count/position values, producing different ordering even outside the changed member query. Final synthetic fixtures use distinct server count/position sort values. No application order clause changed. `initial-fixture-ordering.json` retains the failure and exact membership payloads. Ties in the modified member query are still tested directly. An initial postprocessing glob also read partial JSON as a complete run; the corrected check excluded partial files and all final per-request hashes passed. Neither initial invocation is counted as a passing test.

## Changed files, reproduction and evidence

- `services/FacilitatorDashboardService.php`: channel-scoped activity aggregation.
- `tests/classroom-performance.py`: configurable exact base, separate status listener, Phase3S bounded sampler hook, fixed SQL clock and source hashes. Existing sampler remains the default.
- `tests/classroom-bootstrap.php`: records direct PDO queries as well as prepared executions, retains bound parameters and sets fixture clock.
- `tests/phase3s-sample.py`: serial survey, two physical histories, bounded bursts, status/process/counter measurements and complete payload retention.
- `tests/phase3s-regression.php`: executes the exact-base and candidate private method/full service on real MariaDB, with ten edge cases and plans.
- `tests/phase3s-compare.py`: alternating isolated runs, full payload and per-request hash comparisons, failure retention and compression.
- `.github/workflows/phase3s-performance.yml`: disposable native environment, targeted regressions, four runs, exact-base static comparison and always-uploaded evidence.
- `docs/450-phase3s-*`: report, statistical summary, all final raw runs, commands/static evidence, diagnostic failure and prerequisite CI record.

On a disposable host with PHP8.3/FPM, MariaDB10.11 and Composer dependencies:

```sh
PHP_BIN=php8.3 PHP_FPM_BIN=php-fpm8.3 PHASE3S_OUTPUT=phase3s-raw python3 -B -u tests/phase3s-compare.py
PEER_THREADS_BASE=2716766ab71f27d32d0d435d555f19801d7d0aa7 PHP_BIN=php8.3 PEER_THREADS_STATIC_EVIDENCE=phase3s-raw/static.json python3 -B -u tests/peer-threads-static.py
```

The comparison commands exited0. The local extracted runtime used explicit PHP wrapper, MariaDB install/daemon/basedir and LD_LIBRARY_PATH variables, all recorded by this session and described in Phase3R's runtime setup. The committed workflow reproduces with ordinary installed binaries. Complete per-test commands/exit codes and comparison exits are persisted. The per-request hash-set check was added after measurement and executed against every retained final request; future comparator runs include it directly. No application code changed after measurement.

`450-phase3s-evidence.json` includes per-cell p50/p95/p99, PHP CPU/peak distributions, SQL counts/time, response bytes, counters, queues, source hashes, fixtures and gzip/uncompressed evidence hashes. Decompress the four `.json.gz` files under `450-phase3s-raw` to inspect all requests, complete payloads, plans and targeted regressions. Generated counts are synthetic. Secrets and real student data were never used.

## Outstanding gates and rollback

The new draft PR's exact-head CI must finish and be independently reviewed. Successful parent-head Jarred results do not count as Phase3S-head CI. Keep this branch and earlier PRs draft and unmerged. No production merge, rollout, migration or infrastructure action is authorized.

Reciprocal friendship statuses remain untouched: directed unique keys permit conflicting reciprocal rows and the existing OR/LIMIT1 query has no deterministic precedence. A product decision is required before batching that read. Current read-session/privacy/access and Code Lab isolation behavior remain unchanged.

Rollback: revert the Phase3S application commit or restore `FacilitatorDashboardService.php` from exact base2716766. No schema or data rollback is needed. Tests/evidence are independent of application rollout. Representative sustained50–100-user staging, deployed configuration/index inventory, write/read churn and broader service capacity remain outstanding.450-user readiness remains UNVERIFIED.
