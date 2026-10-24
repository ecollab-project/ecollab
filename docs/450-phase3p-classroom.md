# Phase 3P — bounded application validation and staging specification

2026-10-24, Asia/Manila. Baseline PR #82 `54228eb0f662bd8272dea2f32ab446ca6cc207c3`, verified open/draft/unmerged, based on #81 `687bd3ad2d60bf5c5e0627d640e627d7fbcc1d9d`; all ten returned workflows passed at that base. Branch `validation/phase3p-20261024` starts exactly there. The final PR description identifies the published head, exact-source CI and its artifacts; this committed report is the local snapshot.

## Authorization and environment

The supplied Phase3P instructions authorize bounded disposable fixtures and a staging specification when no external staging is available. No external staging origin, credentials or resource authorization was provided. **Representative two-tier staging is BLOCKED.** No production host was accessed, loaded, configured, restarted, migrated or deployed. No PR was merged. The runner creates its own temporary MariaDB datadir/database, free loopback ports, FPM pool and synthetic sessions, and removes only those resources. It has no external database/HTTP-target argument.

Local: PHP8.3.6 FPM-FCGI, MariaDB10.11.14; native FPM dynamic10/start6/min4/max6, 128MiB InnoDB buffer pool and32 connection limit in disposable configs. Local FPM OPcache was not loaded. The container reports a CPU quota of eight cores, affinity0–8 and8GiB memory limit. These are **not** the historical two-vCPU VPS or two isolated staging tiers. A small Python HTTP/FastCGI adapter serves actual PHP endpoints/assets; it does not reproduce Nginx, TLS, deployed routing, production storage, other services or network behavior. Synthetic session seeding substitutes login only; actual API auth/CSRF/session code runs thereafter. No actual model/compiler/media runs.

Schema: the existing synthetic900-user/100,005-message fixture plus100 DM conversations/20,000 DM messages,100 public threads, notes/settings and two sparse/tied test spaces (100,063 messages total). It approximates relevant schema shape/indexes, not a full migration/deployment rehearsal. Fixtures intentionally include nullable timestamps although the core schema is NOT NULL. No institution/student data was used.

## Protocol defined before concurrency execution

Local previews only:1 and10 nominal actor slots, maximum10 outstanding scheduled operations, global8 operations/s cap, one complete warm-up cycle, eight-second scheduling window, drain and one-second recovery per cell. Read, write and mixed cycles each run without/with067. An outstanding-cap event stops scheduling that cell; it is recorded separately and does not mean a full hold completed. Errors/failed SQL or p99>=5s stop the run. Host OOM/process failure cannot count as success. Raw operation counts/actor IDs, early stops and warm-up records are retained. Status requests add measurement overhead.

Read cycle: compatibility dashboard, current workspace, workspace-based navigation, actual channel history, DM conversations/unread, public threads, notifications, settings, peer matches and facilitator workspace. Write cycle: actual note creation, preference write, read marker and notification-read update. Mixed cycle: all ten reads plus extra history/DM and note/preference writes. Cycles are **synthetic design choices**, not observed usage percentages. Actor cohorts span busy/history, sparse memberships, <=8-visible, tied and absent memberships. Role900 performs facilitator operations. Chatless actors use DM history instead of inaccessible channel reads. Match computation is capped at eight calls/account in the test design, followed by the existing profile read, so the existing20/hour policy is not bypassed. Actual completed mix is in the evidence.

One/ten slots and an8RPS cap are not a claim that ten students simultaneously consumed a worker. Warm-ups and status polling are separate from successful application throughput. p50/p95/p99 are nearest-rank descriptive values, often64 observations or fewer; p99 is commonly the maximum. No confidence interval, production SLO or capacity conclusion follows from these small cells. No unfavorable/short cell is omitted.

## Confirmed correction

`services/LearningWorkspaceService.php::data`, facilitator `members` source: two dependent count/MAX scans ran for each candidate member before sorting/limiting. EXPLAIN showed dependent channel/message scans repeated per member. The new single server-filtered derived aggregate computes conditional30-day COUNT and all-time MAX once per sender, then LEFT JOINs current members. Query count remains11, permissions/owner exclusion/deleted-user filtering, integer count types, zero-message members, dates, ordering and500-row cap remain unchanged. No new index, cache, migration or query hint.

Eight native MariaDB fixtures compare complete workspace arrays and all source availability, alternating three samples per side. The unowned-space403 remains tested. These method measurements intentionally include adverse rollback/MVCC history; they are not ordinary HTTP latency.

| Case | Baseline median ms | Candidate median ms |
|---|---:|---:|
| populated | 2705.36 | 91.93 |
| second_server | 2759.61 | 91.16 |
| empty_members | 5.76 | 4.58 |
| deleted_users | 2384.79 | 90.89 |
| null_old_future_deleted | 3137.28 | 79.67 |
| nonmember_authors | 1606.87 | 106.01 |
| over_500_members | 10209.13 | 104.31 |
| empty_messages | 6105.45 | 11.10 |

The initial five-sample comparison exceeded its120s test budget: FAIL/timeout, not a PASS. The full matrix was retained, reduced to three samples with per-case progress and a180s bound; it passed. An early index harness asserted only stderr despite PHP writing the expected1205 error to stdout; diagnostic capture was corrected and the metadata-lock fixture passed. Neither failure was hidden or used as a production result. A local vendor symlink initially caused PHPUnit bootstrap failure; a separate worktree autoloader corrected that, and25 tests/56 assertions passed.

## Local HTTP observations

Same defined request cycles on fresh owned databases, source-original baseline worktree versus candidate, sequential runs. Host/cache and observer variation remain; these are not randomized independent replications. Both versions retain unfavorable samples. Initial equivalence tests ran separately; CI recreates the owned schema after equivalence mutations before HTTP previews. The baseline's database statistics and cache can influence latency strongly.

| Cell | Baseline→candidate completed ops | Baseline p50/p95/p99 ms | Candidate p50/p95/p99 ms |
|---|---:|---:|---:|
| unindexed-read-1 | 19→64 | 11.0/3220.6/3220.6 | 13.7/131.9/145.7 |
| unindexed-read-10 | 63→64 | 13.1/263.8/3661.4 | 11.7/129.5/190.1 |
| unindexed-write-1 | 64→64 | 8.9/21.1/38.3 | 6.7/13.2/18.3 |
| unindexed-write-10 | 64→64 | 7.1/20.2/26.1 | 7.8/17.6/38.4 |
| unindexed-mixed-1 | 64→64 | 8.6/120.2/152.5 | 10.0/114.6/240.4 |
| unindexed-mixed-10 | 51→64 | 50.5/960.9/2651.4 | 8.9/192.6/216.4 |
| indexed-read-1 | 64→64 | 12.4/281.2/414.3 | 9.6/37.7/54.6 |
| indexed-read-10 | 64→64 | 10.5/397.9/882.0 | 11.8/179.0/207.8 |
| indexed-write-1 | 61→64 | 8.2/95.6/471.4 | 10.0/27.1/240.9 |
| indexed-write-10 | 54→64 | 14.7/338.4/681.9 | 8.1/17.4/49.4 |
| indexed-mixed-1 | 54→64 | 17.9/271.3/644.9 | 9.2/34.7/42.5 |
| indexed-mixed-10 | 64→64 | 8.1/266.3/278.2 | 6.5/119.8/146.7 |

All completed application requests in these cells succeeded. Baseline early scheduling stops and unequal counts prevent treating the table as a universal speedup. The unindexed mixed single-slot candidate p99 and facilitator tail were worse; this remains an adverse observation. Other cohorts improved substantially. Endpoint-specific samples and SQL profiles identify the facilitator aggregate, not every interface, as the correction. Other write-only cells have no direct code change and differences can be noise/cache effects.

FPM status was polled at0.5s: active/idle, listen queue, worker state and max-children counts are recorded. The status request itself occupies a slot; brief peaks can be missed. PHP request CPU, allocator peaks and response bytes are recorded. Native local DB/worker CPU/PSS were inaccessible and are NULL/UNKNOWN, not zero. No system memory reduction is established. CI attempts process-specific counters and reports unavailable ones explicitly.

MariaDB status deltas cover warm-up+sample+observers and include transaction/control SQL; they are not isolated steady-state query rates. Per-request statement counts/timing and top SQL are separate. Row-lock wait/time, disk temporary tables, logical/physical buffer reads, wait-free and insert/update counters are retained. Zero short-interval row waits do not disprove contention under higher load. New CI also prints query timing distributions. Native DB CPU and disk latency remain unavailable locally.

## Recent chat

PR82 source and migration067 are unchanged. The1-slot read cells accepted7/7 recent probes, while10-slot read cells fell back7/7 in this deliberate cohort mix; mixed cells show0/5 and3/3 fallbacks respectively. Those are observed small synthetic samples, not real usage prevalence. Fallback frequency is plainly workload-dependent and not waived. The inherited22-case exact-output fixture covers busy/sparse/eight-or-fewer/tied/empty/deleted/legacy NULL distributions. Actual mixed writes add read-marker and note/settings churn, **not** sustained concurrent message-write stress. That stress test and representative legacy deployment behavior remain NOT RUN/BLOCKED for staging; no new recent-chat patch is justified here.

## Migration067 assessment (fixture only)

The additive migration file is unchanged. Candidate local build took155.6ms versus453.5ms in the separate baseline run; these are separate observations, not an application-code effect. New allocated index pages were7,651,328bytes (~7.30MiB) for100,063 rows. Temporary/datadir size was sampled every20ms; sampled maxima can miss short-lived files and do not establish worst-case temporary storage needs.

Candidate medians for1000-row rollback batches: insert5.99→8.31ms, indexed timestamp update9.89→22.57ms (five samples/side). The separate baseline insert samples paradoxically improved after adding the index, demonstrating cache/order noise; do not assert an insert speed benefit. Reads can improve, but the new index adds space and write maintenance.

An explicitly held read transaction blocked the online DDL until the configured one-second metadata-lock timeout returned1205. After releasing the owned transaction, build succeeded; message count was preserved. **Activation recommendation: CONDITIONAL / do not activate on production from this report.** Require representative staging cardinality, concurrent write/read and metadata-lock observation, disk headroom/temporary-file monitoring, backup restoration and separate approval. LOCK=NONE is not a guarantee of zero metadata waiting. No historical migration was edited.

## Workers and browser

Actual Jarred singleton and one supervised Code Lab slot run against separate fixture databases with synthetic HTTP model/runner replies. During a30s application overlap, each worker had a15s idle observation then three-job burst. All three jobs/results persisted and claim order1,2,3 held. Jarred burst~248.7ms, Code Lab~805.9ms; commit-ack→claim samples are in raw evidence, not an SLA. Global Questions/s208/226 include application/observers and must **not** be called idle worker cost; the prior isolated four-second observations (~8/5 per second) are a distinct experiment. CPU/PSS were unavailable locally. This short overlap is not a long soak or leak proof. Polling, concurrency, retries and actual worker code are unchanged; only the test fixture idle duration is configurable4–30s.

Local Chromium was NOT RUN. New CI runs actual student and facilitator PHP pages, owned FPM/workspace API and local assets at1440/390 widths, pagination/search/refresh/keyboard/mobile menu and overflow assertions. Unrelated APIs/CDN are stubbed/blocked and disclosed. Existing real Chromium DM/group sidebar and500+20-message history/order/duplicate/scroll tests are retained. Final PR CI record supersedes this local-browser status. No UI code/layout changed; browser heap or mobile-hardware performance is not inferred from headless snapshots.

## Regression status and readiness

PASS locally: PHP syntax, eight complete MariaDB workspace equivalence cases/ownership guard, twelve native FPM cells per variant, index metadata/build/write fixture, worker overlap and persistence, existing workspace/peer/Jarred/Code Lab checks, student/facilitator UI and PHPUnit25/56. New driver diagnostics and full browser workflow are pending exact-head CI at this committed snapshot. No tests were suppressed. PHPStan was not rerun in this phase; the prior15 baseline diagnostics are not a new exact-head PASS.

**Implementation: conditional staging-review candidate. Representative50–100-user two-tier readiness: BLOCKED. 450-user capacity: UNVERIFIED.** No production operation occurred. Code-only rollback restores the previous facilitator SELECT; no schema, queued result or user-data rollback is needed. The benchmark/bootstrap must never be placed into a production FPM pool.

The complete separately authorized staging protocol is in [450-phase3p-staging-plan.md](450-phase3p-staging-plan.md); local observations and per-operation samples are in [450-phase3p-evidence.json](450-phase3p-evidence.json). Remaining bottlenecks include up to315 statements for the actual peer endpoint (compatibility persistence), thread attachment N+1 reads, recent-chat fallbacks, absence of representative busy-worker memory/DB CPU/soak evidence and actual two-vCPU mixed service contention. They are findings, not additional patches in this phase.
