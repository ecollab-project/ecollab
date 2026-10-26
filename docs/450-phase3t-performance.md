# Phase 3T: count only the selected facilitator channel

26 October 2026. eCollab engineering handoff, awaiting independent audit.

Verdict: local functional PASS and observed synthetic performance benefit. Hosted CI is pending publication. Production capacity and deployment readiness are not established.

## Source identity

Repository: ecollab-project/ecollab. Branch: optimization/phase3t-primary-channel-20261026.

| Identity | Full SHA |
|---|---|
| Accepted exact base, PR88 | d161f3d23e8e0e66cfcc45483002a1ea7be42442 |
| Application implementation | b8bd653f07fd8bb1cf218cbc26b3a013cda1b457 |
| Immutable application/harness used for eight local runs | c61201e7aff9de087867e6cbade8772c39e37499 |
| Later historical-control harness, unchanged application | 6e6eec29541aec459f0d8d291f906b9da81d36e8 |
| Historical Phase3S comparison base | 2716766ab71f27d32d0d435d555f19801d7d0aa7 |

The final PR adds evidence after the implementation/harness commits. Its description records the final full head and tree. The application file is unchanged after b8bd653f. Local and CI source identities must not be conflated: each raw run records the actual application commit/tree, intended base/head, application diff, driver commit/tree, driver hashes and application content hashes.

Fresh GitHub inspection verified PR88 open, draft and unmerged at the expected head, with all nine returned workflows successful. These parent results do not substitute for new-head CI.

## Root cause and implementation

`FacilitatorDashboardService::getFacilitatorPrimaryChannel()` selects the first eligible channel by position, but its message/attachment scalar counts execute for candidate channels before the top-one sort finishes. Native MariaDB ANALYZE confirmed unnecessary work, rather than relying on SQL appearance.

In the populated native regression plan, each scalar subquery executes100 times in the base and once in the candidate. The base measured query time was84.454ms in that ANALYZE execution; the candidate1.271ms. Those are diagnostic query observations, separate from HTTP performance. The initial discovery's eight-sample unique-position medians were103.200→1.433ms; discovery was not an alternating benchmark and is not used as capacity evidence.

The candidate materializes the same eligible-channel selection and ORDER BY position/LIMIT1 in a derived table. Counts then execute for the chosen channel only. It remains one SQL statement and preserves the statement's consistent read. No additional round trip, transaction, cache, index, migration, provider call or permission change.

The output fields and their order/types remain the same. Message count excludes deleted messages. File count still counts attachments joined to messages even when those messages are deleted. Missing eligible channels still return null. Membership roles remain owner/admin/moderator; no new owner bypass or server-status filter was introduced.

### Tied positions

No secondary ordering rule was added. Native tests compare exact selected rows/counts/types for equal and two-minimum positions, reversed positions, NULL fixture positions, varying field widths and24 role/position permutations. All matched. Native populated plans also retain the same selection join order and priority-queue sort; counts move outside it.

The source schema does not promise a deterministic secondary order for equal positions. This phase does not create such a promise or certify arbitrary future optimizer versions. Acceptance is scoped to the executed MariaDB10.11.14 cases and complete HTTP comparisons. The native tests stop on any tied-selection mismatch. A product requirement for a stable tie breaker remains a separate decision; no arbitrary ID precedence is introduced here.

## Correctness and stored data

PASS:41 focused native MariaDB cases per main run, including complete facilitator service equality. They cover empty/single/multiple channels, tied and NULL positions/fields, all eligible roles and ineligible membership, nonmember channels, deleted/NULL message fields, duplicate attachments on a deleted message, orphan attachment NULLs and24 tie permutations. PHP strict equality checks values, array order and numeric types. SQL failures cannot pass through the service's catch-and-null behavior because the logger independently asserts execution success. Raw cases include queries, bound parameters, outputs and selected ANALYZE plans.

PASS: all eight main FPM runs compare678 complete route/account/dataset payloads and recorded finite-sample response hash sets. Every fixed-arrival response is checked against the deterministic retained payload corpus. Only the dashboard generated_at envelope is normalized. Lists are not sorted or removed by the comparator.

PASS:12 application-table checksums match before and after each workload, including users, memberships, channels, messages, attachments, notifications, read markers, DM data and threads. This is a scoped data-preservation check, not a claim that no DB operation occurs. The owned rate-limit and compatibility fixture tables reset between cells, and ordinary peer GET cache writes remain intact. No production database was accessed.

PASS:25 PHPUnit tests/56 assertions, peer ML, learning tools/workspace, settings runtime, phase2 Jarred, upload-access and student/facilitator jsdom regressions. Commands, exit codes and output: `450-phase3t-raw/regressions.json`.

Full PHPStan: FAIL, existing baseline. Both exact trees retain15 diagnostics, zero additional diagnostics. Full reports and exit codes are retained in `450-phase3t-raw/static.json`. Syntax and Python compilation checks passed.

## Environment, cache states and provenance

Local native PHP8.3.6 FPM-FCGI/MariaDB10.11.14. Owned Python HTTP/FastCGI gateway, not Nginx/TLS. Host:8CPU quota, affinity0–8,8GiB memory. FPM dynamic pool: maximum10 children, six initial workers, min/max spare4/6. MariaDB:128MiB buffer pool,32 connection limit. These are fixture settings, not production tuning or a two-vCPU VPS reproduction.

Both cache modes load the OPcache extension; disabled/enabled states are explicitly asserted. Each side has two independent fresh DB/FPM runs in base/candidate/candidate/base order, first with OPcache off, then on. Cross-mode differences are time-confounded and are not used to claim an OPcache speedup. Fresh-pool first API passes are retained, followed by a110-request warm corpus and66-request serial survey. Session seeding already warms bootstrap/auth code, and OS/DB caches are not cold: the first API pass is not a fully cold-machine benchmark. File-update protection is disabled only in the owned fixture pool to permit caching freshly checked-out immutable files.

Every main run uses a separate clean application worktree at exact base d161f3d or candidate c61201e7. `head`/`tree` identify that worktree, not the driver's underlying HEAD. `source_provenance` also records intended refs, application diff, driver commit/tree/hashes and uncommitted application substitutions (empty). No application overlay was used. The historical control separately names2716766 as base andd161f3d as head, with driver6e6eec29. This directly addresses the previous provenance finding.

## Workloads and observed results

Datasets:10,005 and100,005 physical message rows,900 synthetic accounts and the established chat/thread/workspace fixtures. Physical messages are rebuilt from the same owned source table. SQL clock1792800000; PHP wall clock remains real. Synthetic distinct server counts/channel positions remove unrelated membership ordering ties in cross-instance comparisons; target-channel ties remain tested independently.

All eight main runs have sixteen cells: first API pass, warm corpus, serial survey and two repeats each of25/100-slot bursts per dataset, plus large-history fixed-arrival cases at25/100 maximum request slots. The burst mix rotates eleven authenticated read routes; facilitator requests share owner900 and student IDs vary1–100. Slot caps are not active student counts.

The fixed-arrival cases run for10 scheduled seconds, nominal100 arrivals/s, then drain accepted work. They use a declared ten-route mix excluding peer requests, ten student accounts plus shared facilitator900. This avoids changing production peer rate limits. The generator records actual offered arrivals, scheduling lag and arrivals not admitted at the in-flight cap. RPS uses completed accepted work divided by elapsed time including drain, not nominal offered rate. These are short controlled tests, not sustained production certification or a long soak.

Executed main HTTP requests:29,823. Historical control:6,748. Recorded executed-request errors, SQL failures and missing metrics:zero. Admission refusals are reported separately below and must not be described as completed successful requests.

### Warm serial facilitator dashboard HTTP p50

Values are medians of two per-side six-request route-survey summaries, not a statistical confidence interval.

| OPcache | Messages | Base ms | Candidate ms |
|---|---:|---:|---:|
|Off|10,005|20.411|13.561|
|Off|100,005|108.579|20.326|
|On|10,005|17.425|11.099|
|On|100,005|105.704|19.520|

The large-history observation is about81% lower in both cache modes. Dashboard SQL statement count remains11. Existing channel-count indexes serve the reads; a one-row derived materialization replaces repeated counts, with no new index.

### Repeated large-history mixed bursts

Each range contains four p95 observations per side/cache state, two repeats in each of two independent pools.

| Mode/slots | Base p95 range ms | Candidate p95 range ms | Median completed RPS base→candidate |
|---|---:|---:|---:|
|Off/25|360.38–528.73|203.13–259.61|93.89→169.32|
|Off/100|1353.84–1645.91|375.17–540.73|89.77→247.03|
|On/25|445.81–503.77|190.53–235.64|90.49→184.80|
|On/100|1457.67–2062.41|338.98–393.30|83.22→296.81|

These finite-batch RPS values are not sustained throughput. Full p50/p95/p99, route distributions, PHP CPU/peak memory, SQL time/counts, bytes, native process snapshots, DB counters and FPM samples are in the summary/raw data. PHP CPU denotes per-request getrusage deltas; SQL elapsed time includes client/server round trips and waits, not isolated server CPU. Native process counters/PSS are sampled and can be unavailable or miss peaks. Instrumentation and the generator share the host. No universal speedup, memory-saving or contention-free claim.

### Fixed-arrival read cases

| Mode/slot cap | Median completed RPS base→candidate | Not admitted at cap, two base runs | Two candidate runs |
|---|---:|---|---|
|Off/25|82.28→97.95|98,157|0,4|
|Off/100|82.79→97.71|22,75|0,0|
|On/25|73.85→98.43|190,222|0,0|
|On/100|75.53→98.53|37,206|0,0|

Across main fixed-arrival cells, the base refused1,007 arrivals at its caps; the candidate refused4. These are generator admission limits, not HTTP responses. Actual offered counts differ slightly because of scheduling lag. The candidate still has one adverse four-arrival cell. Candidate queue peaks are not universally zero: the off/100 steady observations reached11 and0, and off/25 reached1 and5. Baseline steady queue observations remain substantial, up to90. Successful tests do not erase admission refusal or transient saturation.

Unchanged routes also fluctuate. For example, candidate large-history serial facilitator workspace medians remain83.591ms off and76.372ms on, while student dashboard remains60.886ms off and59.655ms on. They are measured follow-up targets, not improvements caused by this query. Do not infer causal benefits from every favorable cell or compare different cache blocks as a controlled cache experiment.

## Historical Phase3S concurrency control

A separate four-run control repeats the original2716766→d161f3d code comparison with OPcache off, large history and four repeats each of25/100-slot bursts per pool. Actual source refs and all6,748 HTTP responses are retained. Native Phase3T method tests are explicitly NOT RUN within this historical control; the exact historical HTTP refs are compared instead. The main eight runs contain the41-case native coverage.

| Slots | Original Phase3S-base p95 median/range ms | Accepted Phase3S-head p95 median/range ms |
|---|---:|---:|
|25|494.73 /388.49–582.87|452.99 /407.96–487.56|
|100|1664.12 /1456.85–2123.33|1472.27 /1353.56–1556.02|

The previous25-slot slowdown was not reproduced as a consistent effect. Repeated medians favor the accepted Phase3S head and ranges overlap. This supports treating the earlier two-run result cautiously; it does not prove a specific environmental cause. This repeat uses the documented stronger warm corpus and current fixture runtime, so historical absolute375.86→417.10ms values remain valid records of that earlier experiment, not directly interchangeable observations. Variance and real-workload consistency remain acceptance limits.

## Failed/limited evidence retained

An initial OPcache state assertion stopped before workload sampling: configuring a boolean as a pool value left OPcache enabled when disabled mode was requested. The fixture now uses a proper pool flag plus startup setting and reads the status's enabled field/INI value. The failed run is retained as diagnostic evidence, not counted as a benchmark pass. A corrected configuration-only smoke run deliberately used the base application and verified disabled state, application checksums and native cases before the full eight-run sequence. Its legacy variant label is not used as a candidate identity; actual application commit/tree fields name the base. It is excluded from all paired performance figures.

The discovery measurements preceded the application patch and were exploratory, not alternating. Their exact SQL, parameters and ANALYZE output remain in the discovery archive. The committed discovery helper now loads the explicitly selected baseline source so repeating it does not accidentally call the optimized method as its old side.

Tests use an approximate owned schema. In particular, NULL-position/NULL-field cases intentionally exercise permissive fixture shapes; the source migration declares position NOT NULL. Other MariaDB/MySQL versions and deployed custom indexes/schemas are not certified. Model inference, media, browser paint, document collaboration, production writes/churn, WAN, Nginx/TLS, production config and long soak are NOT RUN here. Existing security/stack release gates remain separate.

## Files, commands and reproduction

Application change: only the SQL in `services/FacilitatorDashboardService.php::getFacilitatorPrimaryChannel`.

Harness changes: `tests/classroom-performance.py` and `tests/classroom-bootstrap.php` add immutable app worktrees, explicit application/driver identities, cache control/status and the Phase3T hooks. Existing Phase3S/default entrypoints remain available. New `phase3t-compare.py`, `phase3t-history.py`, `phase3t-sample.py`, `phase3t-regression.php` and `phase3t-discovery.php` provide the comparisons, workload, native contracts and diagnostic. `.github/workflows/phase3t-performance.yml` installs owned dependencies, runs main/control comparisons, checks regressions/static diagnostics and uploads lossless raw archives, preserving expanded failure evidence when needed.

```sh
PHASE3T_HEAD=c61201e7aff9de087867e6cbade8772c39e37499 PHASE3T_OUTPUT=phase3t-raw PHP_BIN=php8.3 PHP_FPM_BIN=php-fpm8.3 python3 -B -u tests/phase3t-compare.py
PHASE3T_OUTPUT=phase3t-history PHP_BIN=php8.3 PHP_FPM_BIN=php-fpm8.3 python3 -B -u tests/phase3t-history.py
PEER_THREADS_BASE=d161f3d23e8e0e66cfcc45483002a1ea7be42442 PHP_BIN=php8.3 PEER_THREADS_STATIC_EVIDENCE=phase3t-raw/static.json python3 -B -u tests/peer-threads-static.py
```

If OPcache is not already loaded by the FPM configuration, explicitly supply `PHASE3T_OPCACHE_EXTENSION` with its installed module path. The runner refuses a mislabeled state. Local extracted binaries also used explicit MariaDB install/daemon/basedir and library-path variables. No system service or production configuration was changed.

Both main and historical comparison commands exited0. Per-run commands/exits are in the comparison JSONs; standalone regression commands/exits are retained separately. Native regression stdout records41 cases in each main archive. Query plans include actual parameters, not invented EXPLAIN observations. Gzip/uncompressed SHA256 hashes in `450-phase3t-evidence.json` verify all twelve full raw runs. Decompress the `450-phase3t-raw/*.json.gz` files to inspect requests, complete payloads, plans and native results. Optional discovery/guard/smoke archives are labeled separately.

## Outstanding gates and rollback

Keep the PR draft and unmerged. Review final-head hosted CI and artifact identities separately; parent nine-workflow success does not substitute for current results. An independent reviewer must assess the tie contract and bounded evidence before accepting Phase3T. No merge, deployment or production migration is authorized.

Rollback restores `FacilitatorDashboardService.php` from exact base d161f3d. There is no schema or stored-data rollback. Helpers/evidence are independent of application activation. All auth, privacy, peer scoring/cache behavior, ambiguous reciprocal friendship status, Code Lab isolation and provider behavior are unchanged.

Representative50–100 concurrent student capacity remains BLOCKED/UNVERIFIED. Four short fixed-rate repeats per cache/slot combination, two independent pools per side/cache, shared local hardware, one shared facilitator and an artificial route mix cannot establish it. The next justified engineering target is the remaining measured facilitator learning-workspace read cost, conditional on audit acceptance and profiling its exact SQL.
