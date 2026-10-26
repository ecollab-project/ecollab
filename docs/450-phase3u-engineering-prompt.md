# eCollab Phase3U: profile remaining learning-workspace read cost

Use in the Sol Medium engineering chat only after the separate auditor accepts Phase3T. First correct any failed audit or incomplete final-head CI gate.

Project: ecollab-project/ecollab, https://github.com/ecollab-project/ecollab.
Phase3T branch: optimization/phase3t-primary-channel-20261026.
Phase3T exact base: d161f3d23e8e0e66cfcc45483002a1ea7be42442.
Phase3T application commit: b8bd653f07fd8bb1cf218cbc26b3a013cda1b457.
Main local immutable benchmark head: c61201e7aff9de087867e6cbade8772c39e37499.
Historical-control harness snapshot:6e6eec29541aec459f0d8d291f906b9da81d36e8.
Read docs/450-phase3t-performance.md, docs/450-phase3t-evidence.json and raw/claim-ledger evidence. Retrieve the Phase3T draft PR, exact independently accepted final head/tree and CI state before branching. Do not mistake the above local snapshot for a later final CI head. Reconcile any new delta. This prompt is an engineering proposal, not independent acceptance.

Observed Phase3T local improvement: large-history warm facilitator HTTP p50 about109→20ms with OPcache off,106→20ms on. Native plans count100 candidate channels in the base versus one selected channel after the rewrite. Tied selection and counts matched41 native cases; no new tie breaker was introduced. All eight main HTTP sequences and four historical controls passed their scoped comparisons. Capacity remains unverified. Admission refusals were explicitly measured; do not turn completed request counts into all-arrivals success claims.

The next measured bottleneck is the facilitator learning workspace, about84ms off/76ms on in the candidate large-history serial survey. Student dashboard remains about61/60ms. Do not assume the same ranking holds on a different host or dataset.

Objective: identify and reduce the largest remaining safe learning-workspace database cost, with stronger correctness and resource evidence. Do not force a patch if the benefit is not defensible.

1. Branch from the exact accepted Phase3T head. Keep a new PR draft. Use only owned synthetic local/CI resources. No production, Hostinger, merge, deployment, schema migration, worker-count or provider-concurrency change is authorized.
2. Profile /API/student/workspace.php?mode=facilitator&server_id=1 and services/LearningWorkspaceService.php. Identify which query dominates actual SQL time, including member-activity aggregation and unrelated notes/sessions/resources reads. Use native EXPLAIN/ANALYZE with real bound parameters. Inspect source migrations and fixture/deployed-index differences before recommending an index.
3. Choose the smallest measured optimization. Preserve current members/ownership/deleted-user rules, per-server counts, all-history last-message behavior,30-day counts, NULL/future/old timestamps, limits, ordering, date conversion, complete API responses and failure behavior. Do not invent tie/status precedence. Keep auth/privacy/AI/Code Lab behavior unchanged.
4. Add native and authenticated HTTP coverage for empty/small/large servers, nonmember authors, deleted data, tied values, missing history, account roles and complete payload/type equality. Confirm data preservation. Stop on a semantic mismatch rather than changing expected output.
5. Use immutable app worktrees. Record intended base/head, actual application commit/tree, driver identity/hashes, config/cache state, overlays and fixture definitions. No underlying-baseline HEAD labeled as candidate.
6. Alternate exact-base/candidate runs in each verified OPcache state. Retain first-API/warm samples, repeat25/100-slot cases and bounded fixed-arrival reads. Report actual offered/admitted/completed counts, cap refusals, scheduler lag, errors, p50/p95/p99, PHP CPU/memory, SQL time/counts/plans, DB counters and FPM queues. Keep host/cache modes separate and report slower cells. Use additional independent repeats if variance blocks a conclusion.
7. Keep historical Phase3S control evidence intact: the earlier25-slot slowdown was not consistently reproduced, but environmental causation was not proven. Do not relabel old observations or substitute synthetic slots for active students. No representative production capacity verdict without its own defined workload/environment/acceptance evidence.
8. Run appropriate existing regressions and full exact-base static comparison. Report existing15-diagnostic baseline separately from any introduced finding. Inspect final-head CI jobs, skips and artifact identity; do not count cancelled/pending checks as passes.

Deliver docs/450-phase3u-performance.md, docs/450-phase3u-evidence.json, a claim ledger, raw lossless measurements/SQL plans, commands/exits, exact revisions/trees, regressions and limits, rollback instructions and a draft PR if the change passes. If access, deterministic semantics or evidence fails, finish independent checks, report PARTIAL/BLOCKED and make recovery the next phase.

End with an Independent Audit Handoff containing the final PR, full base/head/tree, changes, measured gains and regressions, test/CI results, artifacts and outstanding gates. Keep production unchanged. Begin executing available work after audit acceptance.
