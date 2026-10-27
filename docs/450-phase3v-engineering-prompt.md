# eCollab Phase 3V: Statistics-aware baselines and student-dashboard profiling

PROPOSED. Begin only after independent review of Phase 3U. Do not treat the rejected join hint as an accepted optimization.

Repository: https://github.com/ecollab-project/ecollab
Draft Phase 3U PR: https://github.com/ecollab-project/ecollab/pull/90
Accepted application base: f77506a874c4650ec6c69637461c368876fa178d
Rejected trial application: 5366f48f1a128aa40077bf01e938157202759911
Eight-run rejected trial/driver: 6873c086af51a4d8000cda8eda4155d415baedff
Final Phase 3U publication identity: resolve the full immutable head/tree in the PR90 independent-audit handoff, recheck current PR state, and reconcile any delta. Branch from the independently accepted final SHA rather than a moving name. Keep the next PR draft. No merge or deployment.

Evidence:
- docs/450-phase3u-performance.md
- docs/450-phase3u-evidence.json
- docs/450-phase3u-claim-ledger.json
- docs/450-phase3u-raw/
- tests/phase3u-plan-control.php

## Findings motivating this phase

Phase 3U found a plan-dependent early workspace bottleneck. The forced STRAIGHT_JOIN trial improved early serial timings but did not provide a consistent sustained benefit. Later baseline plans already used the same channels-first path. With refreshed statistics and messages concentrated in one channel, the trial regressed from about57 to95–98ms. The source change was rejected and restored. Do not reintroduce the hint without new evidence across distributions.

The existing sampler changes fixture data, then measures before it explicitly stabilizes optimizer statistics. SQL and buffer warmth do not establish stable plans. EXPLAIN collection occurs between serial surveys and load cells. The exact trigger of the observed plan switch remains unproven. Smaller unchanged-route variation and mixed-load regressions remain visible.

Student dashboard requests remain relatively expensive, approximately52ms in the large OPcache-on trial survey. services/StudentDashboardService.php, especially getChatUnreadCount(), is a diagnostic lead. No production bottleneck is established by these synthetic results.

## Objective and ordered work

1. Verify final PR90 source identity, draft status and exact-head checks/artifacts. Confirm services/API/database/assets retain the accepted application state. Record the inherited15 PHPStan diagnostics.
2. Reproduce the statistics/distribution control. Verify base/trial hashes, parameter values and actual plans before/after ANALYZE TABLE. Preserve all adverse results and avoid inferring the exact automatic-refresh trigger without a controlled test.
3. Establish an owned MariaDB10.11/PHP8.3-FPM baseline with deterministic seeding, timezone and clock. Explicitly record statistics/index definitions. Compare unrefreshed and refreshed statistics in separate labeled cohorts. Prepare analyzed statistics outside timed samples, verify plan stability across warmup and measurement, and record any plan transition. Do not silently ANALYZE a production database.
4. Include same-source control runs to estimate host/sampler variation. Isolate plan observation effects where practical. Preserve immutable application refs, driver hashes/diff, actual trees, absence of overlays and cache state. Record system/quota differences from prior evidence.
5. Profile StudentDashboardService SQL using actual query execution timings, bound parameters and MariaDB EXPLAIN/ANALYZE. Rank total cost across dashboard routes before selecting a change. Keep facilitator workspace and dashboard as unchanged controls.
6. If a defensible target emerges, choose the smallest safe improvement using existing indexes. Do not add speculative indexes, caches, migrations, query hints or broad refactors. Preserve unread semantics, memberships, private-channel behavior, deleted/NULL records, sender exclusions, read cursor timestamps, numeric types, responses, ordering, permissions and failure behavior. Raise product/security ambiguities separately without changing them in this performance phase.
7. Test sparse/dense servers, many channels, one hot channel, uneven message histories, empty data, varying memberships/roles, time boundaries, NULL/future timestamps, read cursors, nonmember/deleted authors and skew. Validate full API responses and stored data against the exact base. Stop on unexplained mismatch.
8. Use alternating base/candidate runs with separate OPcache cohorts and stable statistics, plus labeled initial-state controls. Repeat25/100-slot bursts and bounded fixed-arrival cells. Record actual offered/admitted/refused/completed work, scheduler lag, drain, p50/p95/p99, throughput, SQL count/time, PHP CPU/memory, FPM queue/occupancy, response bytes, database contention and errors. Report missing process telemetry accurately. Ten-second synthetic slots are not student-capacity certification.
9. Retain all slower workloads. Reject a candidate with a substantial unexplained regression rather than optimizing the headline statistic. If no safe improvement emerges, publish another negative result with evidence.
10. Run existing PHP/JS/unit/native regressions and full exact-base PHPStan comparison. Verify final-head hosted jobs/steps and raw artifacts, without substituting parent checks.

## Verified starting commands

Supply the required local runtime binary environment variables. Use isolated fixtures only.

```bash
TZ=UTC CLASSROOM_FIXED_CLOCK=1792800000 CLASSROOM_BASE=f77506a874c4650ec6c69637461c368876fa178d CLASSROOM_PHASE3U_PLAN_CONTROL=1 CLASSROOM_EVIDENCE=/tmp/phase3u-control.json python3 -B -u tests/classroom-performance.py
PHASE3U_OUTPUT=/tmp/phase3u-final-control python3 -B -u tests/phase3u-compare.py
PEER_THREADS_BASE=f77506a874c4650ec6c69637461c368876fa178d PEER_THREADS_STATIC_EVIDENCE=/tmp/phase3u-static.json python3 -B -u tests/peer-threads-static.py
```

Create Phase3V comparison commands using the independently accepted Phase3U final SHA. Do not accidentally keep the earlier hardcoded Phase3U base as the new comparison contract.

## Deliverables and acceptance gates

Produce docs/450-phase3v-performance.md, evidence JSON, claim ledger, lossless request/query/plan archives, exact base/head/tree identities, changed files, reproduction commands/exit codes, regression/resource results, final-head CI/artifact links, limitations and rollback. A validated implementation requires repeatable benefit under stable statistics, full functional/data parity, no additional PHPStan findings, and disclosure of every adverse case. Draft PR only. No production access, Hostinger change, schema migration, protected-branch merge or deployment. Preserve privacy, security, matching semantics and Code Lab isolation. Do not invent channel tie breakers or friendship-status precedence.

End with an Independent Audit Handoff and a conditional next-phase prompt justified by the findings. The separate auditor decides acceptance. Capacity and release gates remain separate.
