# eCollab Phase 3U: learning-workspace SQL investigation

**Result: PASS for the investigation and executed correctness checks. FAIL for the proposed join-order optimization. No application optimization is retained.**

The forced channel-first join improved the early serial survey, but the accepted query selected the same plan later in each workload. A controlled, statistics-refreshed one-channel fixture then exposed a repeatable regression: 56.819 → 97.891 ms, 72.3% slower. The final change restores the accepted application source. PR #90 contains tests, measurement tools and evidence. It is draft, with independent audit outstanding.

## Source contract

- Repository: https://github.com/ecollab-project/ecollab
- Draft PR: https://github.com/ecollab-project/ecollab/pull/90
- Branch: `optimization/phase3u-learning-workspace-20261026`
- Accepted base: `f77506a874c4650ec6c69637461c368876fa178d`, tree `b0590e26e3008a79906dd2d21935e2cc127092cb`.
- Rejected application commit: `5366f48f1a128aa40077bf01e938157202759911`, tree `543960df3fab6520259e749bf667ccaf41a34a79`.
- Eight local trial runs used immutable candidate `6873c086af51a4d8000cda8eda4155d415baedff`, tree `21496d436cb9558a31e62a4c530cd4271e4508c3`, versus the exact accepted base.
- Final workspace service Git blob: `f97d55e39627042a25b3733e61ed2dfbe8b1b364`, identical to the accepted base. Trial blob: `0ed1933d975a8ebafb8bdd3830055669db6f4f9b`.
- Final publication head/tree and subsequent CI live in the PR body and independent-audit handoff. A report cannot contain its own final Git tree identity. Every archived trial records its actual application commit/tree, intended refs, hashes, clean application diff and driver identity. No application overlays.

## Query and measured cause

`LearningWorkspaceService::data()` aggregates member activity using a messages/channels inner join, with `server_id`, `is_deleted=0`, a 30-day lower-bound count and all-history MAX timestamp. Initial MariaDB plans scanned 100,063 message rows and performed 94,800 channel lookups. The rejected SQL changed only this inner join to `FROM channels c STRAIGHT_JOIN messages m ON m.channel_id=c.id`. Its initial plan found five server channels and used the existing message-channel index, approximately 1,001 rows per channel. All predicates, grouping, output types, membership/ownership filters, ordering, limits and date conversion stayed intact.

Plain IN and EXISTS alternatives did not remove the early scan. A materialized-IN experiment reduced some scans but added more complexity and was slower on sparse servers. None is retained. No index, schema, cache, security or production configuration change was made.

Existing schema definitions provide channel server and message channel indexes. `database/migrations/067_dashboard_unread_covering.sql` defines an additional channel-leading covering index, but the inherited Phase 3T fixture does not apply every production migration. This is an inherited isolated test schema, not proof of production query plans. The recorded SHOW INDEX and migration source remain reviewable.

The key disconfirming observation is in every main run: the post-workload native baseline plan already begins with channels and uses the same indexes. Baseline member-query execution falls from roughly 60–70 ms during the early large serial survey to roughly 8–9 ms during fixed-arrival load. The plan switch is observed. Automatic statistics refresh or plan-estimate changes are plausible causes, but the experiment does not isolate the exact trigger. SQL/database buffers being warm does not guarantee stable optimizer statistics.

## Controlled statistics and distribution test

Two standalone controls retained all samples, using alternating B,C,C,B order, six observations per side/phase, fixed clock and identical tables. Each tests sparse small, sparse large, all channels in one server, all messages in one channel, and no eligible channels. Explicit `ANALYZE TABLE messages,channels,server_members,users` precedes the analyzed phase. All returned rows/types/order match. SQL, parameters, ANALYZE FORMAT=JSON plans, and the second control’s immutable base/trial file hashes are archived.

| Distribution, analyzed statistics | Base median ms | Rejected trial ms | Change |
|---|---:|---:|---:|
| sparse_small | 1.108 | 1.083 | -2.3% |
| sparse_large | 5.991 | 6.016 | +0.4% |
| dense_large | 101.695 | 102.633 | +0.9% |
| one_channel_large | 56.819 | 97.891 | +72.3% |
| empty_large | 0.603 | 0.569 | -5.6% |

The first independent control also measured the one-channel case at 57.297 → 94.864 ms, about 65.6% slower. The repeat measured approximately 72.3% slower. Both raw records remain. The hint constrains MariaDB when another plan is cheaper. This fails the safe-optimization gate even though early sparse-server figures look favorable.

## Rejected trial: complete HTTP results

All figures in this section refer to the rejected immutable trial, not a retained final application improvement. Eight runs use B,C,C,B in each separate OPcache block. Each has a fresh MariaDB instance, immutable application worktree and FPM pool.

| OPcache | Physical messages | Base warm serial p50 ms | Rejected trial p50 ms |
|---|---:|---:|---:|
| off | 10,063 | 15.062 | 8.984 |
| off | 100,063 | 74.067 | 16.260 |
| on | 10,063 | 13.557 | 7.883 |
| on | 100,063 | 72.024 | 14.861 |

These are medians of two run-level p50 values per side, six timed serial requests per route/run. They measure the early post-seeding plan state and do not establish a steady-state benefit. The physical datasets include the inherited 58 tiny-server messages in addition to the main 10,005/100,005 seed. Older phase shorthand must not replace the actual row counts in these records.

30,901 executed requests, zero recorded HTTP failures, SQL failures or missing metrics. Both sides recorded zero rejected-at-cap arrivals. Every run retained 678 complete route/account payloads. Finite samples and all fixed-arrival hashes match across runs, normalizing only the dashboard envelope generated_at. Twelve named application-table checksums remain unchanged. The harness intentionally resets rate-limit/cache fixtures and preserves existing application behavior, so this is not a claim that every database table receives no writes.

| Cache / large workload | Base p95 median ms | Trial p95 median ms | Base success RPS | Trial success RPS |
|---|---:|---:|---:|---:|
| off / large-burst-25 | 178.01 | 173.06 | 206.93 | 206.87 |
| off / large-burst-100 | 339.38 | 333.52 | 309.86 | 295.90 |
| off / large-steady-25 | 117.78 | 119.73 | 99.56 | 99.50 |
| off / large-steady-100 | 115.94 | 121.96 | 98.66 | 98.80 |
| on / large-burst-25 | 193.12 | 183.25 | 200.98 | 210.78 |
| on / large-burst-100 | 320.41 | 322.04 | 325.09 | 314.41 |
| on / large-steady-25 | 114.61 | 120.34 | 99.52 | 99.36 |
| on / large-steady-100 | 114.69 | 122.32 | 98.74 | 98.62 |

Mixed load does not establish a consistent improvement. Large 100-slot burst throughput fell in both cache modes. Candidate fixed-arrival p95 rose in all four cache/slot cells, with overlapping ranges in some cells. The on/100 burst includes a 438.10 ms candidate p95, above every baseline value in that cell. All ranges and smaller-load results remain in the evidence JSON. Do not discard these because the target serial survey improved.

Fixed-arrival cells run for ten seconds at a nominal 100 arrivals/s with 25/100 in-flight caps, followed by drain and observer shutdown. They use ten synthetic student accounts and shared facilitator account900. All offered, admitted, cap-refused and completed requests, scheduler lag and actual duration remain in each archive. The scheduler does not catch up missed nominal slots, so offered arrivals are not assumed to equal 1,000. Reported completion RPS includes drain/observer shutdown. These short tests are not a long soak or representative student sessions.

## Every unfavorable serial-route median

These observations belong to the rejected trial. Causation is unestablished. No universal speedup or environmental-noise explanation is asserted.

| Cache / dataset / route | Base ms | Trial ms | Change |
|---|---:|---:|---:|
| off / small / dashboard | 17.464 | 17.621 | +0.9% |
| off / small / navigation | 8.433 | 8.553 | +1.4% |
| off / small / dm | 2.795 | 2.846 | +1.8% |
| off / small / threads | 3.434 | 3.484 | +1.5% |
| off / small / settings | 2.508 | 2.552 | +1.7% |
| off / small / peer | 22.742 | 23.911 | +5.1% |
| on / small / dashboard | 16.223 | 16.447 | +1.4% |
| on / small / workspace | 7.128 | 7.382 | +3.6% |
| on / small / navigation | 6.458 | 7.472 | +15.7% |
| on / small / history | 3.694 | 3.783 | +2.4% |
| on / small / notifications | 1.918 | 2.087 | +8.8% |
| on / small / peer | 20.548 | 20.554 | +0.0% |
| on / small / facilitator_dashboard | 9.358 | 9.594 | +2.5% |
| on / large / dashboard | 51.443 | 52.245 | +1.6% |
| on / large / workspace | 7.070 | 7.434 | +5.2% |
| on / large / navigation | 7.110 | 7.420 | +4.4% |
| on / large / history | 5.104 | 5.286 | +3.6% |
| on / large / dm | 2.165 | 2.364 | +9.2% |
| on / large / notifications | 2.033 | 2.070 | +1.9% |
| on / large / settings | 1.374 | 1.568 | +14.1% |
| on / large / peer | 19.778 | 22.960 | +16.1% |

## Resource evidence and limits

Local host: Linux6.18.55/glibc2.39, AMD EPYC9V74 virtual host, nine affinity CPUs, cgroup CPU quota800000/100000 (eight CPU equivalents), memory limit8GiB. PHP8.3.6 native FPM, MariaDB10.11.14, 128MiB InnoDB buffer pool, 32 DB connections, ten dynamic FPM workers (six start, four/six spare). The owned Python HTTP/FastCGI gateway uses loopback. This is not Nginx/TLS or the intended two-vCPU VPS. System details and per-run FPM configuration/hashes are retained.

Each request records elapsed latency, response bytes, SQL statement count/time/bound parameters, PHP getrusage CPU and peak allocated PHP memory. Each cell records database counter deltas, FPM active/idle/queue observations and process-counter attempts. SQL time is instrumented execute wall time, not isolated database CPU. p50/p95/p99 and all request samples remain.

Local /proc process CPU/PSS is BLOCKED by process namespace visibility. Do not infer worker/database CPU or PSS from those unavailable samples. PHP CPU and peak allocated memory are available. Large mixed burst PHP peaks are essentially unchanged, approximately1.91MB off and1.48MB on. There is no memory-saving claim. Both sides recorded zero InnoDB row-lock wait/time deltas and zero buffer-pool read deltas in the large workload cells. Disk temporary-table counts remain substantial and equal for equivalent finite mixes, including510 in each 100-slot burst. These counters do not prove absence of other contention.

Sampled FPM queues remain variable, reaching64 on the baseline and47 on the trial in large bursts. The observed data does not support a saturation-elimination claim. Raw observations include any observer/status effects. No sustained production throughput or 50–100-student capacity certification follows from these slots.

OPcache is explicitly set at startup and in the FPM pool, with actual enabled state asserted. Cache modes run in separate time blocks, so no cross-mode OPcache speedup is claimed. First API passes occur after authentication/session seeding and do not represent fully cold OS/database state.

## Correctness, checks and CI

The rejected trial passed36 native MariaDB cases per main run. Cases cover complete workspace output, aggregate oracles, deleted/missing users and messages, nonmember authors, roles, ownership failures, private channels, empty/small/dense servers, limits, NULLs, time-window boundaries, future timestamps, count ties and three session time zones. Strict baseline-versus-trial equality checks values, types, field/row order and failure responses. The independent numeric oracle compares numeric count values separately; strict response equality still enforces API types. No new tie-break rule is introduced.

PASS locally:25 unit tests/56 assertions, peer ML, learning tools/workspace, settings runtime, Jarred, upload access, workspace UI, dashboard UI/date regressions and PHP syntax. Thirteen command records include exact arguments, output and exit0. Final-source reruns follow restoration of the base application file. Full PHPStan remains FAIL with15 existing diagnostics on each side and zero additional findings, both before and after restoring the source.

Initial local test-only failures include a syntax error and timestamp-oracle mistakes, including a resumed host with a different database SYSTEM timezone. They did not establish a baseline/trial output mismatch. Corrected native cases explicitly cover UTC,+08:00,-07:00. Failed diagnostics remain archived and excluded from paired performance claims. Paired HTTP fixtures use TZ=UTC and the fixed clock.

The initial trial-head Phase3U hosted workflow passed all unit/native/HTTP/static steps and uploaded evidence. That is parent/trial evidence, not final-head certification. Final publication CI is PENDING in this committed snapshot. The final PR body/handoff must record fresh exact-head workflow/job/step/artifact verification and distinguish initial trial artifacts from restored final-source controls. No parent check is substituted. Final CI also replays the immutable rejected trial in the distribution control and verifies no application-directory diff from the accepted base.

## Reproduction and command records

Runtime paths are supplied by the environment. Use isolated local MariaDB only. Dependencies are Composer, PHP8.3 with PDO mysql/sqlite and native FPM, Python3, MariaDB10.11 and Node/jsdom. No production DSN or credentials enter these commands.

```bash
git worktree add --detach /tmp/ecollab-phase3u-trial 6873c086af51a4d8000cda8eda4155d415baedff
cd /tmp/ecollab-phase3u-trial
PHASE3U_HEAD=6873c086af51a4d8000cda8eda4155d415baedff PHASE3U_OUTPUT=/tmp/phase3u-trial-evidence python3 -B -u tests/phase3u-compare.py
```

On the final PR checkout, reproduce the statistics/distribution control:

```bash
TZ=UTC CLASSROOM_FIXED_CLOCK=1792800000 CLASSROOM_BASE=f77506a874c4650ec6c69637461c368876fa178d CLASSROOM_PHASE3U_PLAN_CONTROL=1 CLASSROOM_EVIDENCE=/tmp/phase3u-plan-control.json python3 -B -u tests/classroom-performance.py
PHASE3U_OUTPUT=/tmp/phase3u-final-control python3 -B -u tests/phase3u-compare.py
PEER_THREADS_BASE=f77506a874c4650ec6c69637461c368876fa178d PEER_THREADS_STATIC_EVIDENCE=/tmp/phase3u-static.json python3 -B -u tests/peer-threads-static.py
```

All eight local trial fixture commands exited0, complete comparisons exited0, both standalone controls exited0, final focused checks exited0 and static comparison exited0 while retaining underlying PHPStan exit1 reports. Raw comparison JSON and regression/static records retain these distinctions. Inspect `.github/workflows/phase3u-performance.yml` for the exact final CI sequence and runtime setup.

## Deliverables, rollback and remaining gates

Changed final scope: shared fixture dispatch/provenance, Phase3U native/discovery/control/compare/report tools, Phase3U workflow, this report, evidence JSON, claim ledger, lossless raw archives/manifest, and a conditional next-phase prompt. Application services/API/database/assets have no net diff from the accepted base. Use `git diff --name-status f77506a874c4650ec6c69637461c368876fa178d <final-head>` for the complete publication inventory.

Raw records: `docs/450-phase3u-raw/`. Each .json.gz decompresses to the complete original JSON. The manifest records bytes/SHA256. Archives contain complete request-level samples, payloads, SQL/parameters/plans, native outputs, FPM status/queue data, source identity and failures. No archive is an average-only replacement.

Rollback: no application, data or schema rollback is needed because the trial is already reverted. To discard this engineering investigation, leave draft PR90 unmerged. If its test/documentation changes are later integrated under separate authorization, revert the relevant commits or restore only those test/workflow/documentation paths from the accepted base. Do not restore the rejected trial SQL.

Independent audit, production-equivalent configuration/workload, statistics/index parity, representative capacity, privacy/security and broader release gates remain separate. No merge, deployment, migration or Hostinger access occurred.

Conditional next phase: `docs/450-phase3v-engineering-prompt.md`, requiring stable-statistics baselines before another SQL optimization.
