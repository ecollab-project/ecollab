# eCollab Phase 3T engineering prompt

Continue in the engineering chat after the separate auditor reviews Phase3S.

Repository: https://github.com/ecollab-project/ecollab
Phase3S branch: optimization/phase3s-http-20261026
Phase3S application commit: f49147c6bcff2570f34720548f14fd6299705342
Phase3S exact base:2716766ab71f27d32d0d435d555f19801d7d0aa7
Read docs/450-phase3s-performance.md, docs/450-phase3s-evidence.json and the raw evidence. Retrieve the Phase3S draft PR's latest full head and CI status before work. Do not assume the engineering report constitutes independent acceptance.

First resolve any failed/incomplete Phase3S CI or audit requirement. Keep previous performance observations distinct from new-head results. Parent #87's previously cancelled Jarred job passed on rerun121502248285 in run40299883725; inspect the current state instead of reusing that result for a different commit.

Phase3S found facilitator member-activity joins as the largest fixture cost. Channel-level aggregation preserved measured full output. Local 100,005-message serial facilitator dashboard p50 fell325.496→108.354ms. It did not eliminate FPM saturation:100-request-slot queue peaks remained around90. The25-slot large-history p95 regressed. These were finite synthetic bursts on an8CPU/8GiB host,10FPM workers, no OPcache, not production capacity.

Phase3T objective: profile and optimize the next dominant dashboard read, while building stronger repeat/steady-workload evidence.

1. Branch from the exact accepted Phase3S head. Keep the new PR draft. Use only owned synthetic local/CI/staging resources. No production, Hostinger, merge or schema migration is authorized.
2. Establish the current route/SQL ranking. Examine FacilitatorDashboardService::getFacilitatorPrimaryChannel: its message/file-count subqueries appear to execute for candidate channels before ORDER BY/LIMIT, and remained expensive after Phase3S. Confirm with real bound EXPLAIN and measured SQL time; do not optimize merely from source appearance.
3. Test fetching the chosen channel first and counting only for that channel if equivalence is defensible. Preserve member role filters, channel fields, file-count semantics including deleted-message behavior, no-channel responses, types and existing position ties. Do not silently invent a new tie breaker. If a tie ambiguity blocks safe equivalence, document it and choose another measured bottleneck.
4. Add native MariaDB and actual authenticated HTTP coverage for empty/single/many candidate channels, message/attachment volumes, roles, deleted messages, tied positions, missing membership and complete response equality. Preserve all authorization/privacy safeguards and historical data. Do not change reciprocal friendship status precedence.
5. Compare exact base/candidate with alternating runs, more independent repetitions and explicit warm/cold/OPcache conditions. Retain raw samples, regressions, full output comparisons, query plans, PHP CPU/memory, DB counters, response sizes, errors and FPM queues. Use the separate status listener. Distinguish request slots, distinct accounts, offered rate and sustained throughput.
6. Add bounded fixed-duration read/mixed workloads only when the owned environment supports them. Report early stops and generator saturation. Do not call the synthetic bursts real students or certify50–100 users from them. Do not tune production workers/indexes or add speculative caches.
7. If no safe change yields a defensible benefit, report that outcome without forcing an application patch.

Deliver docs/450-phase3t-performance.md, docs/450-phase3t-evidence.json, raw evidence, commands/exit codes, exact base/head/tree identities, regression results, exact-head CI, rollback and a draft PR when validation passes. Use PASS/FAIL/BLOCKED/NOT RUN accurately. End with an Independent Audit Handoff. Keep all deployment and capacity gates explicit.

Execute the available engineering work rather than stopping at a generic plan.
