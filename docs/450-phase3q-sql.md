# Phase 3Q — peer matching and thread SQL performance

## Source and scope

Base: PR #83, `051cae2ee1005ab063675e8054c2ff5b1801294b`, tree `7ade2694209866b2f29bc0894ca423a50fb06349`; verified open, draft, unmerged before work. Isolated branch `optimization/phase3q-sql-20261024`. Candidate application SHA-256 values are in the evidence. Exact published-head CI is recorded in the draft PR after execution; previous-head workflows are not counted.

Performance only. No production access/change, schema/index/migration edits (including 067), deployment, merge, worker changes, scoring/ranking/UI redesign or model concurrency changes. Representative 50–100-user staging remains BLOCKED; 450-user readiness UNVERIFIED.

## Confirmed bottlenecks and implementation

1. **P1: `API/chat/peer-match.php`, `get_matches`**: at 100 passing candidates, 316 statements: 214 reads and 102 writes. Of these, 100 compatibility upserts, 100 outbound request-status reads and 100 reciprocal friendship reads were per candidate. `services/PeerMatchBatch.php::requestStatuses` replaces outbound status reads with one bounded query using the existing unique requester/addressee pair. It loads lazily only when an eligible match survives filtering. `persistCompatibility` writes the same rule scores and rule-only tags for every passing candidate (before display slicing), in one at-most-100-row upsert. No redundant updates are skipped: existing computed-at refresh semantics are retained at batch completion.

2. **P1: `API/threads/index.php`, GET list**: one selected-page query plus one attachment read per thread. `services/ThreadAttachmentService.php::forThreads` fetches only already-selected page IDs, at most 50, in one query; groups by thread ID and retains attachment ID ascending order, including reply associations. Empty pages make no attachment query. Thread sorting, ownership flags, scope checks, limit, response keys and detail route remain unchanged. Existing authors are joined by the main select; `get-server-members.php` and upload routes were inspected but not changed.

3. **Deferred:** reciprocal friendship lookups remain per candidate. The schema allows both directional rows and the existing OR/LIMIT 1 does not define precedence. Merging those into an arbitrary map would risk changing accepted/pending/blocked behavior. The fixture contains conflicting reciprocal rows and confirms the existing selected result remains unchanged. Algorithm/profile/semantic scoring code and the rate limiter are untouched. The duplicate rule-only scoring call for persisted tags is deliberately retained.

## Method and results

Owned temporary MariaDB `10.11.14-MariaDB-0ubuntu0.24.04.1` and `PHP 8.3.6 (cli) (built: Oct 19 2026 15:16:58) (NTS)`. Real API PHP scripts executed through CLI with real session middleware and seeded synthetic sessions; not HTTP/FPM/network/staging. Each case uses four alternating runs per side (candidate/base then base/candidate). Fixed DB clock makes cache timestamps comparable. Each run clears only owned rate/cache fixture tables; writes are committed normally. No timing threshold is used as correctness acceptance. Timings cover instrumented endpoint execution, excluding process startup/bootstrap; SQL times cover execute, not prepare. The logger records prepared execute plus direct PDO::query calls; an initial inherited execute-only logger omitted one SELECT DATABASE() (315→117 instead of the complete 316→118 peer100 counts). This omission was corrected before final publication. Query logging adds overhead proportional to statement count, so CPU/memory/timing are descriptive instrumented observations. Raw run data are retained in CI artifacts; this committed local snapshot is summarized. Visible local CPU affinity: `[0, 1, 2, 3, 4, 5, 6, 7, 8]`; not a 2-vCPU reproduction.

| Case | Statements base→new | Endpoint median ms base→new | SQL median ms base→new | PHP CPU median ms base→new | Incremental peak bytes base→new |
|---|---:|---:|---:|---:|---:|
| peer100 | 316→118 | 102.268→27.096 | 68.824→14.424 | 21.855→8.595 | 1257056→1341552 |
| threads1 | 2→2 | 2.166→2.285 | 0.726→0.656 | 0.905→0.976 | 326488→326992 |
| threads10 | 11→2 | 3.272→2.033 | 1.489→0.651 | 1.249→1.036 | 326488→326992 |
| threads50 | 51→2 | 11.269→3.149 | 5.838→1.097 | 4.186→1.733 | 484664→462888 |
| peer1 | 19→19 | 7.326→7.546 | 3.501→4.010 | 2.571→2.408 | 513800→521248 |
| peer10 | 46→28 | 16.052→9.035 | 10.294→4.622 | 4.012→3.510 | 575896→535256 |
| peer_semantic | 316→118 | 96.541→31.073 | 64.498→15.063 | 22.085→9.823 | 1261216→1341616 |
| peer_filtered | 118→52 | 48.091→20.395 | 31.224→11.028 | 11.165→6.512 | 941576→954288 |
| peer_no_matches | 16→16 | 7.872→7.801 | 4.174→4.244 | 2.800→2.650 | 762984→770432 |
| thread_detail | 3→3 | 1.768→1.934 | 0.587→0.612 | 0.801→0.849 | 326520→327024 |
| threads_empty | 1→1 | 1.387→1.611 | 0.339→0.336 | 0.760→0.997 | 326488→326992 |
| peer_disabled | 1→1 | 1.797→2.020 | 0.168→0.175 | 1.531→1.748 | 389168→387888 |
| peer_eligibility | 316→118 | 80.488→25.456 | 54.845→13.424 | 15.807→7.846 | 1257024→1341584 |
| peer_large_history | 316→118 | 96.694→30.510 | 66.162→18.061 | 21.197→8.315 | 1257056→1341552 |
| threads_large_history | 51→2 | 10.087→3.104 | 5.105→1.544 | 2.527→1.263 | 484664→462888 |
| threads_many_attachments | 51→2 | 33.251→19.419 | 15.747→9.016 | 13.316→9.289 | 5978712→5954976 |

All 16 cases compared complete decoded responses and all persisted cache columns, with identical ordering and values. Cases cover 1/10/100 candidates, filtered sort/min-score, no eligible matches, matching disabled, deleted/banned/system/opt-out exclusions, deterministic loopback semantic scores aligned to candidate position, empty/1/10/50-thread pages, thread detail, zero/one/multiple files and reply attachments. Larger fixtures add 50,000 unrelated outbound requests, 1,000 older threads/50,000 unrelated attachments, then 5,000 selected-page attachments. Each variant sees identical data. These sizes are synthetic, not observed production distributions.

Peer100 writes drop 102→3: 100→1 cache statements plus the unchanged two rate-limit writes. Reads drop 214→115. All 100 compatibility rows are still refreshed, not only the displayed 50. Some small-case endpoint/CPU observations regress and remain in the table. A singleton still uses 19 statements; no universal latency or memory saving is claimed. A 50-ID attachment batch does not impose a new attachment-count/content cap: the 5,000-attachment fixture still needs roughly 6 MB of instrumented incremental PHP memory. Native MariaDB CPU counters were inaccessible in this local process namespace and are null, not zero; CI records them where measurable. No production CPU/RAM estimate follows.

## Plans and transaction/write implications

Complete EXPLAIN outputs, measured SQL shapes and per-case timings are in the evidence. On the small 100-request/175-attachment fixture MariaDB chooses full scans for the batch reads; repeated attachment reads use `ref`. On 50,100 match requests the batch uses `range` on existing `uk_pair`, estimated 100 rows. On the larger attachment fixture it uses `range` on the existing thread-ID index, estimated 146 rows (5,145 in the many-attachment case). No new index, optimizer hint or forced plan is added. Existing reciprocal friendship query uses its pair index; its semantics remain unchanged.

Old compatibility writes were separate autocommit statements. The new bounded multi-row InnoDB upsert is one atomic statement, ordered by pair primary key to reduce lock-order conflicts. No transaction spans profile loading, semantic service work or scoring. Under a caller transaction it participates normally. Successful stored values are unchanged; on statement failure the cache batch now commits no partial rows, whereas the old loop could leave earlier rows. All batch computed_at values use the completion statement time; old long requests could stamp different seconds per row. Cache freshness remains refreshed; this is not an unchanged-row write-skipping optimization.

Per-request sum of cache execute times includes server work and implicit commit; it is not a measurement of exact server lock-hold duration. The single statement can hold locks on up to 100 pairs until completion instead of releasing per row. No exactly-once or contention-free guarantee is claimed. Tests verify fresh/upsert/retry, caller rollback, a CHECK-induced mid-batch error with zero persisted rows, concurrent conflicting transaction timeout 1205, commit/retry without duplicates, database error propagation, and batch bounds. Concurrent scale, crash/commit-ack ambiguity, sustained write contention, exact transaction/lock-hold distribution, binlog/redo/fsync bytes and production write amplification remain NOT MEASURED. Row count/values are preserved; commit/roundtrip count is reduced.

## Regression and static results

- PASS: new real-MariaDB alternating API/equality/EXPLAIN matrix and bounded batch transaction regression; isolated settings migrations (baseline, repeat additions and saved preference preservation).
- PASS locally: PHPUnit 25 tests/56 assertions; peer ML, learning tools, workspace/facilitator, settings runtime, phase2 Jarred, upload access and student/facilitator jsdom regressions. No frontend source changes.
- PHPStan full base and candidate both return failure with the same 15 existing diagnostics after root-path normalization; zero added diagnostics, none in the changed application files. This is **FAIL — existing baseline**, not a clean static-analysis pass. Both original JSON reports are emitted by the new CI job, which fails on additional diagnostics.
- A direct settings-migration invocation and standalone async-contract invocation initially lacked their required test DSNs (not passes). Settings migrations subsequently passed in the owned harness schema. The existing full async integration workflow owns its proper schema and is expected to run at publication; its result must be reported separately, not inferred from prior PRs.
- Actual browser/network/FPM, real semantic inference, representative 50–100 users, sustained concurrency and long soak: NOT RUN in this phase. The loopback semantic fixture tests protocol/order preservation, not model quality or resource use. Existing unchanged API response rendering is covered by equality and existing UI tests; no new browser speed claim.

## Rollout, rollback and remaining work

Staging-review draft only. Ship API files and new services together in an eventual approved release; no DB migration or settings change. Code rollback restores the previous endpoint loops; cache schema/data remain readable, no user data deletion or rollback required. Keep whole PR stack unmerged. Do not install measurement prepends in an application pool.

Next useful evidence is authorized two-tier staging with realistic profile breadth, attachment size/count distributions and write contention, not indefinitely larger synthetic claims. Other candidates are reciprocal friendship lookup policy clarification, peer cache invalidation/write frequency, larger-history facilitator aggregation and recent-chat fallbacks. Migration 067 activation remains conditional and unchanged. R7, Code Lab independent-host and Jarred governance decisions are unaffected.

Verdict: targeted SQL reduction and fixture equivalence demonstrated. Staging review is conditional on exact-head CI; production release is not approved. Representative 50–100 staging BLOCKED; 450 capacity UNVERIFIED.
