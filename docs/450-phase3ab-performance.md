# Phase 3AB — security decisions and defense baseline gate

**Decision package complete; integration, security release, merge, deployment and defense-build certification remain BLOCKED.** Phase 3AA was independently accepted as investigation. Its clock and consumption defects remain unresolved. This phase introduces no security fix or further optimization trial.

The accepted source is PR96 head `5a03656b27904498917c16cbfa320187c6da8c84`, tree `f75c1e88aac10d49b4b2d02d34812ea9d088ebce`. PR96 remains draft and unmerged. The integration comparison uses `ecollab-collabs` at `c838359711a14e9b6eb3f345c4386d184a178b25`, not a moving ref. Branch: `review/phase3ab-defense-gate-20261028`, stacked on Phase 3AA. Publication head/tree and completed hosted verification are pinned in the final PR description; this committed report is a pre-CI snapshot.

## Main findings

1. Reverting to the integration branch does not resolve OTP safety. AuthService, OtpService and config.php have identical Git blobs at both references. The timezone and independent-session double-consumption failures apply to the same inspected source.
2. The accepted stack is 52 commits ahead of the integration snapshot, with 589 changed paths, including 54 runtime/deployment paths and four added migration files. Much of the diff is documentation, tests and evidence; it is not 589 application edits.
3. PR74–84 and86–96 heads are ancestors of the accepted stack. PR66–73 and85 are separate heads. Their partial path overlap is recorded; neither ancestry nor overlap establishes combined integration acceptance. Do not merge all pending branches merely because individual workflows are green.
4. Current application paths are identical to Phase3T head `f77506a874c4650ec6c69637461c368876fa178d`. Rejected Phase3U/V query changes are absent from retained application source. Later telemetry/authentication investigations changed tests and evidence, not the application.
5. Scoped independent acceptance is available in the conversation for Phase3R/S and the later X/Y/Z/AA investigations. Earlier engineering reports and current CI do not independently approve every branch, integration dependency or release gate. Phase3W acceptance remains outstanding. The Phase3T report supplies engineering evidence; a separate independent acceptance document is not supplied in this handoff.

[Defense inventory and proposed demonstration boundaries](450-phase3ab-defense-baseline.md) explain each PR group, dependencies, rollback points and why neither source reference is automatically a security-safe defense build. Full per-PR metadata, path changes and exact blobs are in the raw inventory and snapshot.

## Explicit decisions prepared, not implemented

[Security approval matrix](450-phase3ab-security-decisions.md) separates:

- **A — UTC clock:** consistently UTC persisted generation, creation, expiry, invalidation, verification, consumption, counting and purge. Proposed application surface: OtpService.php only. Preserve configured TTL, strict expiry, bindings, hashing, API/session behavior and existing ordinary-login policy.
- **A2 — default:** the trial uses explicit UTC creation writes but inherits CURRENT_TIMESTAMP as the table default. Keeping an explicit-writer invariant, changing fresh defaults, or migrating existing defaults are distinct choices. Unknown/manual writers are not silently certified.
- **B — legacy records:** compare coordinated invalidation with provenance-backed versioned compatibility. Resolve unused and used rows, retention/forensic consequences, re-request UX, all readers/writers, cutover and rollback. No 600-second waiting assumption or timezone guessing.
- **C — atomic consumption:** recommend conditional consume-and-check after bcrypt, rechecking unused/expiry and accepting only affectedRows===1. Compare transactional row locking and broader durable-token designs. Address regeneration, expiry during hashing, DB errors, ambiguous timeouts, post-consume failure and no automatic unconsume/retry. This is a separate approval from UTC.

The existing race remains a high-priority FAIL. No conditional UPDATE, lock, new transaction, marker TTL, PHP equality boundary, schema/default conversion or data invalidation is implemented. A UTC-only patch cannot be called full OTP security while double consumption remains.

## Integration dependencies and baseline verdict

Four additions relative to the integration snapshot require release planning: the explicit MariaDB replacement for historical025, temporary-voice/pending-action tables in063, async Jarred jobs/queue gate in066, and unread covering index067. The migration runner supersedes historical025 rather than rewriting its source. No production schema, ledger, defaults, indexes or contents were inspected or changed. Full disposable fixtures are evidence about repository-derived schemas, not deployed state.

Runtime dependencies include the Jarred worker and DB singleton lock, a shared inference lock visible to FPM/CLI across mount/PrivateTmp boundaries, provider configuration, separate-host Code Lab/mTLS credentials and isolation, async frontend polling, and broker/media authorization gates. The inventory records variable names from tracked source/templates only. No live secret or environment file is read. Separate PR85 staging changes are not in this stack.

**Proposed defense boundary:** after an actual environment rehearsal and explicit baseline nomination, use controlled synthetic accounts and owned data for ordinary password login/session/logout and bounded read-only dashboard/history/resource views. Do not bypass authentication or CSRF. Exclude unresolved OTP-dependent account lifecycle demonstrations where practical. Do not rely on unapproved live AI/worker, voice/broker, external Code Lab or document integrations. These are proposed demo choices, not implemented feature restrictions or claims that all read-only features are universally safe.

The actual defense artifact/SHA, environment, data snapshot and approved feature list have not been nominated or certified. Retain immutable integration and investigated-stack references as rollback/source anchors, not as automatically deployable builds. Git source rollback does not roll back schema, worker jobs or credentials. Do not drop tables/indexes or restore invalidated OTPs automatically.

By October30, require explicit decisions and an independently validated chosen baseline. If a fix cannot safely pass that gate, do not force it into the November3 defense. If no stable candidate is established, the verdict remains BLOCKED rather than inventing one from CI.

## Verification and evidence

The new read-only inventory freezes all PR66–96 heads/bases and compares Git ancestry, net paths, runtime blobs, migration paths and tracked configuration-variable names. It does not merge any branch or execute a migration. Its source identities, command exit codes and raw outputs are retained. The primary frozen inventory is separate from discovery.

Local checks passed: inventory assertions, Python syntax, exact accepted-base application/config/deployment/Composer source equality, diff check, unit25/56, client-IP and request-security regressions. The inventory requires31 PR records and the observed auth/source identity checks. No new application behavior is tested because none is changed.

The Phase3AB hosted workflow re-executes the preserved full60/280 suite in three orderings; accepted3X57/245,3Y60/280,3Z60/280 controls; original46/114 five-failure baseline; actual HTTP signup/login/reset/CSRF/session regressions; required Chromium on the unchanged actual candidate application; original timezone and independent-session trial controls; and exact-base PHPStan. The trial remains CLI-only, not a browser candidate. Static clean remains FAIL with15 inherited diagnostics, and consumption safety remains FAIL even when characterization wrappers pass.

Hosted final-head status is PENDING in this committed snapshot. The final PR description will record all returned jobs/steps, artifact ZIP digest, source/merge-tree identity, raw recomputation and known failures without changing the tested commit. No Phase3AA result is substituted for a missing new-head run.

Raw evidence and manifest retain exact source refs, command exits, public PR snapshots and discovery/frozen differences. Runtime regression evidence remains redacted; no plaintext OTP, password, reset/session/CSRF token or real identity is added. This report makes no throughput, production parity, sustained50–100-user or security-release certification.

## Rollback and remaining gates

This phase changes only decision/evidence documentation, a read-only test inventory and CI. Keep the draft unmerged; reverting this phase to exact accepted5a03656 requires no application/schema rollback. A future approved fix must have its own compatibility-aware rollback and independent security review. No merge, deployment, Hostinger, real mail, production credential/database/config/schema operation is authorized.

Outstanding: explicit A/A2/B/C decisions; independent acceptance of this decision package; a nominated and rehearsed defense artifact; broader security release; Phase3W acceptance;15 inherited static findings; production timezone/TLS/proxy/session parity; natural-lock attribution; FPM queue/saturation calibration; physical-empty resource baseline; transaction/instrumentation telemetry; and representative sustained capacity.
