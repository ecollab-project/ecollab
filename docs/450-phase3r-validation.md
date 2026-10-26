# Phase 3R: exact-base peer CPU validation

26 October 2026. Scope: PR #86, https://github.com/ecollab-project/ecollab/pull/86.

Verdict: PASS within the executed functional coverage. Performance benefit is small and workload-dependent. Independent audit and representative capacity acceptance remain outstanding. Keep draft.

## Identity and changes

Repository: ecollab-project/ecollab. Tested application head: `d5cb54c6998e6e3b395d4358a23caca08c7c52d8`. Exact application base: `d3748527d77ac471ace8613f2a2bfb2a6c45627c`, PR #84. Validation branch: `optimization/phase3r-validation-20261026`, stacked on #86. The validation commit is available through the accompanying draft PR. No application code changed after testing; SHA-256 values for both application trees are in the evidence.

PR #86 removes the second rule-only score calculation for qualifying candidates, derives rule-only cache tags by removing the semantic-only explanation, and moves current-user tag mapping outside the loop. Scoring implementation emits fixed explanation strings, not profile labels. `Related study topics` only originates in the semantic branch. Thus removal preserves current ordered rule tags; future rule-tag changes must retain the regression gate.

The inherited benchmark and static checker pinned PR #83, mixing Phase 3Q and 3R effects. Both now accept `PEER_THREADS_BASE`; CI pins the actual #86 base. The fixture adds nine cases: dense profiles, absent candidate/current profiles, no candidates, score and role exclusions, and remaining sorting modes. Twelve measured alternating samples per side follow one unrecorded warm-up per side. Historical default base remains available for older comparisons.

Modified files:

- `tests/peer-threads-performance.py`: exact-base selection, warm-up/sample controls, identity metadata, nine cases and explicit semantic/cache/display-limit assertions.
- `tests/peer-threads-static.py`: configurable exact comparison base.
- `tests/peer-ml-regression.php`: semantic boundaries 59.99/60, missing/empty profiles, invalid semantic fallbacks and ordered rule-cache component/tag equivalence, including a profile label equal to the semantic explanation.
- `.github/workflows/peer-threads-performance.yml`: pinned exact base, expanded workload and scoring-source/test triggers.
- `docs/450-phase3r-*`: report, raw/summary measurements, regression/static results and fresh PR-stack identities.

## Results

PASS: 25 cases compare complete API payloads, sorting and all persisted cache columns across both trees, including computed_at under a fixed clock. Semantic-provider responses are deterministic loopback fixtures. All 100 eligible cache pairs persist despite the 50-card display limit. Missing-profile and filter cases validate empty/skipped results explicitly. Hybrid API explanations retain the semantic tag; rule-only cache explanations exclude it.

PASS: real InnoDB fresh/upsert/retry, caller rollback, induced statement failure atomicity, bounded lock timeout/retry, batch bounds and SQL-error propagation. PASS: settings migration repeatability and preservation.

PASS: PHPUnit 25 tests/56 assertions, peer ML, learning tools/workspace, settings runtime, phase2 Jarred, upload access, and jsdom student/facilitator UI regressions. Commands, exit codes and output: `450-phase3r-regressions.json`.

FAIL, existing baseline: full PHPStan reports 15 diagnostics on each tree. PASS: no additional normalized diagnostic. Full reports: `450-phase3r-static.json`.

An initial PHPUnit invocation returned exit 0 after CSRF aborted the suite because this locally extracted PHP lacked normal session/output-buffer settings. It is recorded INVALID, not PASS. Repeating with a writable session path and output_buffering=4096 completed all tests. An initial expanded benchmark selected missing candidate ID 2, which was outside the selected online cohort; its explicit 99-row expectation failed. The fixture now uses selected ID 3. The final full run passed. These were harness corrections, not application regressions.

## Paired local observations

PHP 8.3.6 CLI and owned MariaDB 10.11.14, 128 MiB buffer pool, maximum 16 DB connections, fixed synthetic application clock. Logical CPU/affinity/cgroup limits appear in the summary evidence. No FPM, Nginx, actual semantic inference or external targets. OPcache was not loaded. Full sample distributions, raw SQL/EXPLAIN and hashes accompany this report.

| Case | CPU median ms base → candidate | Endpoint median ms base → candidate | SQL statements |
|---|---:|---:|---:|
| 100 candidates | 8.683 → 7.667 | 27.270 → 25.934 | 118 → 118 |
| Dense: 20 subjects, 15 interests, 15 hobbies | 14.190 → 12.032 | 37.845 → 36.274 | 118 → 118 |
| Deterministic hybrid | 9.764 → 9.374 | 30.068 → 29.964 | 118 → 118 |
| 10 candidates | 3.188 → 3.510 | 8.806 → 9.923 | 28 → 28 |
| One missing candidate | 7.402 → 6.847 | 24.363 → 25.505 | 117 → 117 |

Dense profile data is synthetic and maximally populated under current save limits. Smaller-case and unchanged-thread regressions remain in the evidence. No universal latency benefit or memory reduction is claimed. Incremental PHP memory for ordinary peer100 grows by 360 bytes in this instrumentation. SQL count is unchanged because this patch removes PHP work, not queries.

The JSON summary includes interpolated p50/p95/p99 for transparency; twelve samples do not support reliable tail estimates. Samples run serially, without statistical confidence intervals. Native DB CPU uses 10ms /proc ticks and includes initialization/observer overhead. Instrumentation adds overhead. Throughput, real HTTP payload bytes, network delay, FPM saturation, concurrency, sustained write contention, long-soak resource growth and production performance are NOT RUN.

## Reproduction

With PHP/MariaDB installed on a disposable host:

```sh
PEER_THREADS_BASE=d3748527d77ac471ace8613f2a2bfb2a6c45627c PEER_THREADS_PHASE3R=1 PEER_THREADS_SAMPLES=12 PHP_BIN=php8.3 python3 -B -u tests/peer-threads-performance.py
PEER_THREADS_BASE=d3748527d77ac471ace8613f2a2bfb2a6c45627c PHP_BIN=php8.3 python3 -B -u tests/peer-threads-static.py
php8.3 tests/peer-ml-regression.php
php8.3 -d session.save_path=/tmp -d output_buffering=4096 vendor/bin/phpunit --testsuite Unit
```

Local dependencies were extracted under scratch, with explicit PHP extension paths and MariaDB basedir/LD_LIBRARY_PATH. No package service was installed or started. An ordinary apt install attempt failed on the package-cache directory before installation; download-only extraction supplied the test runtime. The harness owns and terminates its loopback DB and semantic server and removes its temporary database/worktree.

`450-phase3r-evidence.json` is a compact statistical summary. `450-phase3r-evidence.json.gz` decompresses to the full original per-run SQL and EXPLAIN evidence; both compressed and uncompressed hashes appear in the summary. Four application-file hashes prove the measured application trees. Working-tree metadata identifies the validation harness snapshot, not a new performance commit.

## Friendship follow-up

The schema's unique key covers the directed `(requester_id,addressee_id)` pair, so reciprocal rows with conflicting statuses are legal. The endpoint reads both directions with OR/LIMIT 1 and no ORDER BY. It therefore defines no stable status precedence. A batch reduction choosing first, accepted-first, blocked-first or highest ID would invent behavior. The fixture includes reciprocal pending/blocked rows. No friendship SQL or schema changes were made. A deterministic product rule and compatibility decision must precede batching.

## CI and outstanding acceptance

Fresh metadata for PRs #66–#86 appears in `450-phase3r-pr-stack.json`; all returned open/draft/unmerged. At application head d5cb54c, seven of eight returned PR-triggered workflows succeeded; the dashboard/Chromium workflow concluded CANCELLED. That cancelled workflow is not a pass. The inherited successful SQL workflow compared #83, not #84. New validation-branch CI must be reviewed independently; no claim substitutes parent CI for new-head evidence.

BLOCKED: representative authorized 50–100-user staging has not been identified in this session. 450-user readiness remains UNVERIFIED. Other full-system optimization phases remain outstanding; this report closes only focused local validation of #86.

Rollback for validation changes: revert the validation commit, retaining evidence as appropriate. For #86: revert its three performance commits together to d374852 application behavior; no migration or data repair is required. Existing rule-only cache format remains compatible. Keep related existing stack prerequisites and prior rollout requirements intact. No production access, database changes, deployment, merge or infrastructure changes occurred.
