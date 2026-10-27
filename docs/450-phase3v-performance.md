# eCollab Phase 3V: statistics-aware Student Dashboard investigation

**Engineering decision: retain the accepted application source. The experimental flat unread-count join fails the performance gate.** This phase adds controlled baselines, profiling, native regressions and evidence, with no application optimization. Independent audit remains outstanding.

## Source and routing contract

Repository: https://github.com/ecollab-project/ecollab. Branch: `optimization/phase3v-statistics-dashboard-20261027`, stacked on `optimization/phase3u-learning-workspace-20261026`.

Accepted application/base commit: `e6f864dcda680f8239da089b9b3bc97f0d0c0cde`, tree `710c24f99a2d12f184cc38a87f120c1ddcbed0b9`. PR90 was rechecked open, draft and unmerged at that exact SHA. Both control labels use this same application commit, with no overlays or uncommitted substitutions. StudentDashboardService SHA256: `c35eb3fbb1bacf4352866ec2f40b5288ffac5ce9acb5d0e1fc1390fc9f49053e`.

Frozen corrected local harness: `5ccbf8e50eb52b2a7d97bcc980c514a550cb5802`, tree `c4edd0987408530f4d9b36557e70d57de06a7796`. The final publication commit adds documentation/evidence; its exact SHA/tree and subsequent CI are recorded in the PR body and final handoff, avoiding a self-referential Git hash. Hosted CI records its own driver identity and expected PR-head tree.

TASK: stabilize performance evidence and profile the Student Dashboard. PRIMARY DEPARTMENT: Database. SUPPORT: Backend, DevOps and QA. EXPERT LENSES: SQL performance lead, seed fixtures, API contract, runtime capacity; QA integration/load gate. These are applied review perspectives, not parallel agents. Risk is limited to isolated test tooling. No production operation is authorized.

KNOWN FLOW: `modules/student/dashboard.php` → `assets/js/student/dashboard.js` → authenticated GET `/API/dashboard/live.php` → AuthMiddleware/server-derived identity → `UserService::getStudentDashboardData()` → `StudentDashboardService`. The fixture exercises actual PHP endpoints through its owned HTTP/FastCGI gateway and native PHP-FPM. It is not browser rendering, Nginx/TLS or a deployed VPS.

## Measured target and rejected rewrite

The native profiler ranks every dashboard statement by measured execute wall time. It captures SQL, execute-array parameters, EXPLAIN FORMAT=JSON, and actual ANALYZE FORMAT=JSON plans for unread-count variants. Diagnostic distributions are small, large, all channels in one server, all messages in one hot channel, and empty messages. Actors include student1, student899 and facilitator900; actor900 is a query-shape control, not student HTTP traffic.

`getChatUnreadCount()` performs independent direct-message and channel counts, preserving partial-failure behavior. The channel count sums correlated message counts for channels reached through server membership. Its existing predicates are `is_deleted=0`, sender exclusion, and strict `created_at > COALESCE(last_read_at, '1970-01-01')`. NULL and timestamp behavior must remain exact.

The experimental rewrite replaces the correlated SUM/COUNT with a plain COUNT over channels, membership, cursors and messages. It remains only in `tests/phase3v-profile.php`. It is never substituted into an application worktree. Although tested scalar counts match, hot-channel and nonmember distributions produce major slowdowns. No safe application optimization is retained. No forced hints, speculative indexes, caches or new schema migrations are introduced.

Private-channel visibility is an existing semantic limitation: this unread query does not filter `channel_members` or `is_private`. Tests preserve and expose that behavior with and without explicit channel membership; they do not certify it as the intended product/privacy policy. Any change needs a separate authorized decision. Friendship-status precedence remains untouched.

## Statistics and schema controls

Each of eight runs creates a fresh owned loopback MariaDB fixture and FPM pool. Within each separate OPcache block the schema order is legacy, existing067, existing067, legacy. Application code is identical across all runs; control labels are not different implementations. Two independent pools per schema/cache cell measure ordinary variability, not statistical significance.

The legacy schema is inherited from the earlier performance fixture. The other schema applies the repository's existing `database/migrations/067_dashboard_unread_covering.sql` only to this owned test database, adding its existing `(channel_id,is_deleted,created_at,sender_id)` index. This is an explicit test-schema variation, not a new migration or a deployment recommendation. Production index/migration inventory remains UNKNOWN.

For each dataset, the harness records actual row counts and index definitions, persistent table/index estimates, initial first-API samples and a three-cycle initial survey. It then executes and checks ANALYZE TABLE on every owned table outside measured windows. A ten-account warm corpus precedes a six-cycle serial survey, repeated 25/100-slot bursts, and large-dataset fixed-arrival cells. Index/statistics snapshots remain before/after. The native profile records persistent statistics=1, automatic recalculation=1 and sample pages=20. Stats auto-recalculation is not disabled; stability is observed within the stated checks, not assumed from warmed buffers.

After warm-up and each analyzed cell, EXPLAIN checkpoints track the unread count, facilitator/workspace member aggregation, recommendations, and recent-chat statement shapes for fixed actors1/900. Topology signatures exclude row estimates/timings and retain access paths, indexes, join/sort structure and references. A within-run signature transition stops that cohort and retains partial evidence. Cross-pool same-schema comparisons are reported separately. These checkpoints do not prove every executed plan stayed fixed.

Initial plans are captured after initial samples, and EXPLAIN/statistics queries may themselves influence later optimizer state. Consequently the initial-versus-analyzed contrast does not isolate an ANALYZE-only causal effect. The trigger of the earlier Phase3U transition remains UNPROVEN.

## Corrected local results

All eight corrected controls exited0: **31,421 HTTP requests**, 0 recorded HTTP errors, 0 SQL errors, 0 missing metrics. Each retained678 complete payloads and35 native cases; all strict response comparisons and named-table checksums passed.

There were 112 analyzed plan checkpoints and 448 tracked observations, with zero within-run transitions. All four same-schema/cache cross-pool topology comparisons matched. This is a bounded observation, not proof of optimizer determinism.

Analyzed serial Student Dashboard HTTP p50: medians of two independent pool-level medians, six timed requests/route/pool. The two columns differ in owned test-schema state, not application code.

| OPcache | Physical messages | Legacy ms | Existing067 ms | Observed change |
|---|---|---|---|---|
| off | 10063 | 22.137 | 19.023 | -14.1% |
| off | 100063 | 59.240 | 23.901 | -59.7% |
| on | 10063 | 18.880 | 15.346 | -18.7% |
| on | 100063 | 58.770 | 23.755 | -59.6% |

Native analyzed large-service ranking (third diagnostic request; complete rankings for all distributions/phases are in evidence JSON):

| Schema | Service median ms | Statement prefix | Execute ms |
|---|---|---|---|
| legacy | 52.631 | SELECT COALESCE(SUM((SELECT COUNT(*) FROM messages m WHERE m.channel_id=c.id AND m.is_deleted=0 AND m.sen | 39.799 |
| legacy | 52.631 | SELECT s.id,s.name,s.description,s.icon_emoji,s.member_count,s.category,s.type, COUNT(DISTINCT CASE WHEN  | 3.793 |
| legacy | 52.631 | SELECT s.id, s.name, s.name AS course_code, s.description, s.icon_emoji, s.type, s.owner_id, CASE WHEN s. | 0.632 |
| legacy | 52.631 | SELECT m.id,m.content,m.created_at,c.name AS channel_name,s.name AS server_name FROM (SELECT id,created_a | 0.449 |
| existing_067 | 19.922 | SELECT COALESCE(SUM((SELECT COUNT(*) FROM messages m WHERE m.channel_id=c.id AND m.is_deleted=0 AND m.sen | 8.361 |
| existing_067 | 19.922 | SELECT s.id,s.name,s.description,s.icon_emoji,s.member_count,s.category,s.type, COUNT(DISTINCT CASE WHEN  | 4.349 |
| existing_067 | 19.922 | SELECT s.id, s.name, s.name AS course_code, s.description, s.icon_emoji, s.type, s.owner_id, CASE WHEN s. | 0.572 |
| existing_067 | 19.922 | SELECT u.id,u.username,u.full_name,u.avatar_color_gradient,u.status, COALESCE(u.current_activity,'Online' | 0.414 |

Rejected flat-join adverse controls: four alternating observations per side, native query wall-time medians. These are microbenchmarks, not HTTP optimization claims. Actor900 is a facilitator query-shape control.

| Schema / distribution / actor | Accepted ms | Rejected ms | Change |
|---|---|---|---|
| legacy / hot_channel / 1 | 74.661 | 242.608 | +224.9% |
| legacy / hot_channel / 899 | 0.354 | 233.143 | +65692.6% |
| existing_067 / hot_channel / 900 | 21.337 | 49.912 | +133.9% |

Every mixed-load schema comparison follows. p95 is the median of four burst cells or two fixed-arrival cells per schema/cache; all per-cell ranges/samples remain in JSON. Negative throughput changes and higher p95 are retained; other routes and sampling variation prevent a universal speedup claim.

| Cache / workload | Legacy p95 ms | 067 p95 ms | Change | Legacy success RPS | 067 success RPS |
|---|---|---|---|---|---|
| off / small-burst-25 | 109.82 | 115.90 | +5.5% | 259.84 | 254.44 |
| off / small-burst-100 | 338.26 | 332.36 | -1.7% | 298.43 | 314.71 |
| off / large-burst-25 | 203.32 | 173.15 | -14.8% | 185.34 | 206.69 |
| off / large-burst-100 | 363.46 | 362.52 | -0.3% | 284.06 | 294.93 |
| off / large-steady-25 | 137.62 | 88.99 | -35.3% | 99.26 | 99.62 |
| off / large-steady-100 | 136.66 | 89.13 | -34.8% | 98.80 | 99.07 |
| on / small-burst-25 | 102.46 | 97.54 | -4.8% | 281.93 | 312.70 |
| on / small-burst-100 | 287.60 | 288.16 | +0.2% | 350.75 | 354.83 |
| on / large-burst-25 | 185.18 | 160.27 | -13.4% | 193.12 | 235.86 |
| on / large-burst-100 | 348.68 | 314.41 | -9.8% | 308.35 | 335.59 |
| on / large-steady-25 | 135.39 | 90.70 | -33.0% | 98.47 | 99.58 |
| on / large-steady-100 | 134.57 | 99.45 | -26.1% | 98.92 | 98.99 |

Every unfavorable analyzed serial-route schema contrast follows. These are observations, including small workloads; no causation or dismissal as noise is asserted. Initial-phase and favorable contrasts remain in evidence JSON.

| Cache / dataset / route | Legacy ms | 067 ms | Change |
|---|---|---|---|
| off / small / dm | 4.131 | 4.160 | +0.7% |
| on / large / workspace | 7.965 | 8.344 | +4.8% |
| on / large / navigation | 7.221 | 8.098 | +12.1% |
| on / large / threads | 2.325 | 2.364 | +1.7% |
| on / large / notifications | 2.236 | 2.351 | +5.2% |
| on / large / settings | 1.510 | 1.629 | +7.9% |
| on / large / peer | 21.373 | 22.790 | +6.6% |

Maximum sampled FPM listen queue: **85**. Row-lock wait counter increments summed across cells: 5. Four waits and1,588ms cumulative wait time occurred in the off/existing067 small25-slot repeat in one pool, with one further wait in the other pool. These observed waits remain unexplained at the lock-object level; operational cache/rate writes are possible and this run does not attribute the contention. Cap-refused arrivals by cohort: `{"off/existing_067": 0, "off/legacy": 0, "on/existing_067": 0, "on/legacy": 0}`. Cap refusals are not HTTP errors; all offered/admitted/completed accounting remains separate. Resource cost, SQL/request, CPU/request, peak allocated bytes, DB deltas and response bytes are retained per cell. No worker/database CPU or PSS value is inferred from unavailable /proc samples.

PASS: final thirteen PHP/JS/lint command records, including PHPUnit25 tests/56 assertions. PASS: full PHPStan exact-base regression comparison, zero added diagnostics. **FAIL: clean PHPStan gate**, with15 inherited findings on both sides and underlying exit1; the comparison wrapper exits0. Application-directory parity exits0; exact native profiling exits0; corrected eight-run comparison and report recomputation exit0. Commands/stdout/stderr/exits and source provenance are archived. Final hosted CI is verified separately at the publication head.


## Method, raw evidence and telemetry limits

The physical small/large datasets have 10,063/100,063 messages, including inherited tiny-server rows. Every request retains latency, bytes, response hash, status and SQL metrics. Whole representative payloads are retained; finite-run hashes and every fixed-arrival response are checked across controls, normalizing only the dashboard envelope `generated_at`. Twelve named application-table checksums must remain equal. Rate-limit/cache fixtures are intentionally reset, so this is not a claim of no writes to every table.

Serial samples, two repetitions per burst level and per-run percentiles remain separate. Fixed-arrival cells offer nominal100/s for ten seconds, cap in-flight reads at25/100, and retain offered/admitted/cap-refused/completed accounting, scheduler lag and drain/observer duration. Actual offered arrivals may be below1,000 because the scheduler does not catch up missed slots. Completion throughput includes drain and observer shutdown. These are short read-focused tests, not a long soak or continuous-write simulation; operational cache/rate-limit writes can still occur.

Local host: Linux6.18.55/glibc2.39, virtual AMD EPYC9V74; affinity CPUs0–8, CPU quota800000/100000 (eight equivalents), cgroup memory8GiB. PHP8.3.6 native FPM, MariaDB10.11.14, buffer pool128MiB, max connections32; FPM10 workers, six start and four/six spare. See per-run exact runtime configuration and hashes. This differs from the intended two-vCPU VPS.

PHP getrusage CPU and peak allocated memory are measured. SQL timing is instrumented execute wall time, not database CPU. FPM status records sampled occupancy/queue; the observer can affect those observations and miss short peaks. Database deltas include row-lock waits/time, buffer-pool reads and disk temporary tables. Local per-process CPU/PSS counters are BLOCKED by process namespace visibility; unavailable data is not zero. Whole-process memory, database CPU and full contention attribution remain incomplete.

OPcache is explicitly set and its enabled state asserted in each FPM pool. On/off cohorts run in separate time blocks; no causal cross-mode speedup is claimed. First API passes follow session/auth seeding and are not fully cold OS/database starts.

The inherited PDO instrumentation captures execute-array parameters, not earlier bindValue calls. A slow history query can therefore have unrecorded parameters. The corrected sampler retains its SQL/time but marks its generic EXPLAIN **NOT RUN**, with the missing-input reason. It never guesses bindings. The dominant dashboard/workspace checkpoint statements have actual recorded parameters and plans. Full bindValue instrumentation is a remaining evidence improvement.

## Correctness and failures retained

Each main run executes35 native MariaDB cases:32 data scenarios and three injected partial-failure paths. Strict full dashboard equality compares the accepted-base class and unchanged current source; an independent unread oracle checks counts. Cases cover empty/no channels, sparse/dense/hot distributions, nonmembership, deleted flags/authors, own/nonmember senders, NULLs, historical/future/boundary cursors, private channels with/without membership, inactive/archived servers, direct messages and four server roles. Stored-data checksums must remain equal. These tests strengthen the unchanged baseline; they are not evidence that an unimplemented candidate passed.

The first native-profile run briefly overlapped summary recomputation. Both records are retained in `checks/`; the reported native figures use the subsequent standalone `profile-exact.json.gz` run.

The first complete eight-run attempt at harness `88bda3e29e0697ac01e08618d9c7e8500f9617e9` stopped on its fourth run with exit1: generic EXPLAIN received unbound placeholders for a bindValue history query. Three preceding runs passed; the failed run's HTTP cells had no recorded errors before collection stopped. This was an evidence-capture failure, not a passed test or an application SQL failure. Complete/partial records and logs remain in `initial-failed/`. The corrected harness restarts all eight runs from scratch; no selective replacement is used.

Earlier discovery-only failures include a PHP test syntax error and a channel_members fixture-column mismatch. They are retained under `discovery/` with the exploratory, sometimes uncommitted driver identities. They are excluded from frozen performance comparisons. Successful smoke records are likewise provisional diagnostics, not additional independent frozen runs.

## Reproduction, publication and rollback

Run from the final checkout with PHP8.3, native FPM, MariaDB10.11, Composer dependencies and Node/jsdom available. Environment overrides may select local binaries; do not supply any production DSN. The driver constructs and guards its own ephemeral database.

```bash
TZ=UTC CLASSROOM_FIXED_CLOCK=1792800000 CLASSROOM_BASE=e6f864dcda680f8239da089b9b3bc97f0d0c0cde CLASSROOM_PHASE3V_PROFILE=1 CLASSROOM_EVIDENCE=/tmp/phase3v-profile.json python3 -B -u tests/classroom-performance.py
PHASE3V_OUTPUT=/tmp/phase3v-controls python3 -B -u tests/phase3v-compare.py
python3 -B tests/phase3v-report.py /tmp/phase3v-controls /tmp/phase3v-summary.json
PEER_THREADS_BASE=e6f864dcda680f8239da089b9b3bc97f0d0c0cde PEER_THREADS_STATIC_EVIDENCE=/tmp/phase3v-static.json python3 -B -u tests/peer-threads-static.py
git diff --exit-code e6f864dcda680f8239da089b9b3bc97f0d0c0cde -- services API database assets
```

`docs/450-phase3v-raw/manifest.json` records each archived file's size and SHA256. Each `.json.gz` losslessly expands to its original complete JSON. No request samples are replaced by summary averages. `450-phase3v-evidence.json` is recomputed from the eight corrected archives; the claim ledger separates executed PASS/FAIL/BLOCKED/NOT RUN claims. The workflow installs an owned runtime, repeats profiling/controls/regressions/static checks and uploads its raw artifact. Final publication-head CI is PENDING in this committed report; the PR body/final handoff records fresh workflow/job/step/artifact verification, not parent-head results.

Source/document whitespace checks pass when raw evidence is excluded. The unfiltered staged whitespace check reports trailing spaces and a terminal blank line in two retained failed-run logs; those traceback bytes are deliberately preserved and are not source-code whitespace defects.

Changed paths are measurement tooling, native tests, CI and documents/evidence. `git diff --name-status e6f864dcda680f8239da089b9b3bc97f0d0c0cde <final-head>` gives the complete inventory. Application/API/database/assets have no net diff. Rollback: leave the draft investigation unmerged. If test/documentation changes are separately integrated later, revert those commits/paths to the accepted base. No application/data/schema rollback is needed. Do not deploy migration067 or restore either rejected query based on this report.

Independent audit, representative schema/statistics parity, continuous writes, deployment configuration, integration/security release gates and 50–100-user capacity remain outstanding. No merge, deployment, Hostinger access or production database operation occurred. The conditional next-phase proposal is `docs/450-phase3w-engineering-prompt.md`; it is not independent acceptance or deployment authorization.
