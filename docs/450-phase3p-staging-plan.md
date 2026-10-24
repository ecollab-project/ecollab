# Phase 3P — representative staging specification (NOT EXECUTED)

Status: BLOCKED pending an explicitly authorized disposable/staging origin, hardware allocation, synthetic-account plan and limits. Production is not a fallback. The provided local runner accepts no external target; these instructions do not authorize provisioning, deploying or starting external tests.

## Required record before execution

Record owner approval, exact allowlisted staging origin, application/database host identifiers, two-vCPU/8GiB allocation and quotas, source HEAD/tree and tested merge identity, migration ledger, schema/index definitions, MariaDB/PHP-FPM/OPcache versions and settings, worker manifests, storage class, and every co-located service. Verify synthetic-only data and restoration on that environment. Run the independent generator on separately allocated resources; record its CPU/memory and dropped scheduling events. Keep timing clocks synchronized and record wall/monotonic time. Isolate outbound model/compiler/media dependencies. No actual Code Lab execution until the separately unresolved execution-host prerequisite is satisfied.

This is application performance validation: ordinary API, authenticated sessions, database work and browser interaction. Media, realtime transport capacity, TLS/network failure behavior, real inference and compiler execution are excluded and must not be certified by these results. Normal app background traffic is measured if available; any stubbed/excluded operation must be recorded.

## Data and workload

Use at least100 distinct synthetic student accounts and separate facilitator accounts, not one shared cookie. Keep authorization, CSRF, session locking, privacy settings and rate limits enabled. Provide deterministic seeds, per-user scripts and reset/recovery scripts restricted to the approved fixture database. Preserve grades/history/queue results. Restore the same snapshot for each comparison; record cold/warm statistics and alternate source-order across repetitions. Validate payloads before timing. Never disable an application check to obtain a benchmark PASS.

Suggested initial scripted cycles, adjustable only with documented rationale:

| Pattern | Ordered operations |
|---|---|
| Read-heavy | Open current student workspace; navigate space/channel; read50-message history; DM list/unread; notifications; threads/list/detail; settings; occasional compatibility dashboard; peer matches once per script |
| Write-heavy | Read channel; persist an ordinary synthetic message through the real endpoint; advance authorized read cursor; save a personal note with revision handling; update one preference; read back each change |
| Mixed | Interleave the read cycle with message/note/preference writes; separate facilitator script loads owned workspace/member participation and changes selected space |

These are proposed synthetic cycles, not measured student percentages. Apply seeded2–6s think times, explicit facilitator frequency and realistic history pagination. Report actual operation mix and achieved rate. Respect existing20/hour peer admission: spread calls across unique actors and use profile reads for subsequent views. Model request amplification and background polls separately; a nominal VU count alone is not evidence of activity.

Data cohorts must cover active rooms, sparse/old memberships, <=8 messages, equal timestamps, empty/no memberships, deleted recent rows, large histories, several server sizes including >500 members, deleted accounts, and authorized private resources. Only use nullable-date variants when the target schema supports them; do not change a staging copy's core schema and call it production-equivalent. Retain unsupported legacy/MVCC stress as a separate adverse experiment. Reproduce data skew and timestamp granularity; do not seed only the favorable distributed-message case.

## Levels and intervals

| Level | Ramp | Warm-up | Hold | Recovery |
|---|---:|---:|---:|---:|
| 1 |30s|60s|5min|60s|
| 10 |60s|60s|5min|60s|
| 25 |60s|60s|5min|60s|
| 50 |90s|60s|5min|120s|
| 75 |90s|60s|5min|120s|
| 100 |120s|60s|5min|120s|

All rows remain NOT RUN. Authorize maximum concurrency, offered request-rate cap, memory limits and total duration before starting. Do not continue to the next level after a stop without reviewing recovery. Repeat comparisons in alternating order at least three times. Require sufficient per-operation observations to characterize the chosen percentile and publish counts/confidence limits; extend an authorized hold if needed rather than reporting a stable p99 from a handful of samples. Add a separately approved30-minute soak at50 and100 only after those levels pass, with queue drain and data verification. No450/500-user stage is authorized by this plan.

## Telemetry and correctness

| Area | Required measurements and scope |
|---|---|
| Requests | Offered/completed operations, success/error categories, p50/p95/p99 by route/pattern/level, response bytes, skipped scheduling and generator saturation; keep warm-up separate |
| PHP | Actual FPM-SAPI CPU and busy/idle PSS/RSS distributions, worker active/idle, listen queue, max-children events, request wall/CPU/SQL/encoding breakdown, OPcache state; sample queue fast enough to see brief peaks |
| Database | Per-route queries and timing distribution, safe EXPLAIN on fixture SQL, DB process CPU/PSS and disk I/O, row-lock count/time deltas, deadlocks, connections, temp tables, buffer read/wait deltas and read/write throughput |
| Recent chat | Probe/fallback count by cohort and actual statement counts; compare both strategies on one repeatable-read snapshot during a bounded independently committing message writer; verify fresh reads after commit, deletion and membership changes |
| Index067 | Paired reads/inserts/indexed-column updates; allocated pages and disk size; online build wall/CPU/I/O, temporary-file high-water mark, bounded held-transaction metadata wait, concurrent writes, exact preserved data; test rollback/restore separately |
| Workers | Isolated idle15s minimum and longer steady samples, one-slot/singleton defaults, queue commit-to-claim and completion distributions, CPU/PSS slope, retries/results and drain; repeat under mixed PHP load. Shared GLOBAL Questions is aggregate traffic, not per-worker cost |
| Browser | Actual current student/facilitator pages and chat, desktop/mobile Chromium; startup and interaction tasks/script/layout, mutations, search/pagination/sidebar/history/scroll/focus/keyboard, data freshness and errors; disclose stubs. Actual mobile hardware remains a separate test |

Check exact dashboard/unread payloads, message/DM/group order, cursor monotonicity, no lost/duplicate persisted writes, matching-score equivalence, note revisions/settings and async Jarred persistence before/after every run. Use synthetic markers only; aggregate operational telemetry must not include raw prompts, student content, code or credentials.

## Stop and acceptance gates

Stop immediately on unexpected data/authorization outcomes, OOM/process termination, unbounded queue growth, SQL failures/deadlocks not expected by the script, missed persistence, exhausted disk allowance, generator saturation or resource limits agreed in the authorization record. Initially use the local harness's5s request-tail stop only as a protective ceiling, not a product SLO; stakeholders must choose staging error/tail/resource stop windows before execution. A short dip must be visible, not hidden by cumulative averages. Drain/recover without deleting pending work, then recheck data.

Functional equivalence and durable writes are mandatory. Establish source-original route/level baselines first, including uncertainty, then agree regression tolerance and product targets before assessing the candidate. No universal numerical speedup is required or presumed. Regressions must be investigated rather than omitted; latency/throughput, resource use and operational stability receive separate verdicts. Missing required telemetry means BLOCKED, not PASS. Do not tune PHP workers or MariaDB settings during the code comparison; any separately approved tuning experiment needs a new baseline.

## Index and release decision

067 remains undeployed in this work. Its owned-fixture read benefit is accompanied by allocated space/write overhead and demonstrated metadata waiting despite LOCK=NONE. Activation requires representative staging disk/write/build/lock acceptance, backup recovery and a separate approved rollout. This phase changes only the facilitator SELECT; code rollback does not require dropping an index or deleting data.

Final decision template: implementation correctness PASS/FAIL; local preview PASS/FAIL with actual scope; representative50/75/100 results by operation; resource/soak readiness PASS/FAIL/BLOCKED; migration activation APPROVE WITH CONDITIONS/REQUEST CHANGES/BLOCKED. Keep450-user readiness UNVERIFIED. No production deployment, merge or load is part of this specification.
