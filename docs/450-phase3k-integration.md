# Phase 3K — isolated cross-branch integration rehearsal

Date: 2026-10-23 UTC. Scope: synthetic fixtures and repository/CI evidence only.
No production access, configuration, migrations, inference, service operations,
load tests, existing-PR merges or deployment were performed.

**Verdict: compatible for an isolated code/CI rehearsal within executed coverage;
BLOCKED for a release or production deployment.** This branch deliberately excludes
Phase 1/2 and R7. It is not a candidate complete optimization/security release.
Jarred governance **PARTIAL/BLOCKED**; Code Lab isolation **BLOCKED**;
R7 **REQUEST CHANGES**; 450 concurrent users **UNVERIFIED**.

## Exact source state and dependency graph

GitHub metadata was read before the rehearsal; git objects and ancestry checked.
All listed PRs were OPEN and UNMERGED. Only #66 was non-draft; the others were draft.
The integration branch remained `ecollab-collabs` at
`c838359711a14e9b6eb3f345c4386d184a178b25`.

| PR | Head SHA | Base branch / dependency |
|---|---|---|
| 66 | 8a2dcfd19db15c10d4711856835d68ef799660db | ecollab-collabs |
| 67 | e1fe79f7b2cdadf4719e9ef5f1bd82ae14db4b98 | optimization/450-users-phase1 (#66) |
| 68 | 5e2fa5b57226ae07b6a02560d89ac436019b8b28 | optimization/450-users-phase2 (#67) |
| 69 | 287d1192122ad8731f6bfe352729bebe85c2f5f9 | review/phase1-phase2-readiness-20261022 (#68) |
| 70 | 8679d81dd575f828f12f42cd4820808fe0066475 | security/r7-revocation-20261022 (#69) |
| 71 | 38900c09fbfd59599ed30905a8a5f9255b27dfe3 | security/r7-livekit-admission-20261022 (#70) |
| 72 | 127fd08231861cd62be72dbc16d31a473794511f | security/r7-broker-reauthorization-20261022 (#71) |
| 73 | 19a8845665dc72d8bf4d062f634b25410fad9961 | security/r7-real-media-validation-20261022 (#72) |
| 74 | aa9b6b35ba86a50024987a60400b13488a9acbb5 | ecollab-collabs |
| 75 | 2e7a3aafe91fb88673e16c0ad78c7c46212ec9b7 | ecollab-collabs |
| 76 | 2e65196fca7448155c19519fda61fdda82c6c80d | ecollab-collabs |
| 77 | 67a90c6cc94701521d4648ed29f8b21e5eea5683 | security/jarred-governance-20261023 (#76) |

The base of every stacked PR equals the preceding head above. #74, #75 and #76
are independent descendants of the integration base; #77 contains #76. #73 contains
#66–#72 transitively, including asynchronous Jarred and migrations 063–066. Including
only #69–#73 commits without their prerequisite chain is not a valid release plan.

Local rehearsal order: integration base -> merge #74 -> merge #75 -> merge #77
(includes all of #76). Original source branches and commits were preserved.
The published rehearsal commit retains #77, #74 and #75 as parents. Its source
file contents match the local union; only this report/evidence and a CI path
trigger are added. No application conflict resolution or speculative fix was needed.

## Complete changed-file overlap / conflict matrix

Sets were computed from full `git diff --name-only BASE HEAD` output, not titles.
#74 changes 8 files, #75 changes 16, #76 changes 14, #77 adds 9 relative to #76.
The selected union has 46 source/report/test/workflow files before rehearsal additions.

| Pair | Shared files | Result / proposed resolution |
|---|---|---|
| #74 / #75 | none | clean local merge |
| #74 / #76→#77 | none | clean local merge |
| #75 / #76→#77 | none | clean local merge |
| #76 / #77 | all 14 #76 changes are inherited by #77 | include full chain once; #77 extends settings-runtime workflow and Ollama API |
| #74 / full #73 ancestry | none | semantic coworkspace authorization/schema review still required |
| #75 / full #73 ancestry | none | CodeLabExecutionService and RealtimeOutbox integration still needs combined tests |
| #76→#77 / full #73 ancestry | API/dm/send-message.php | confirmed textual conflict in dry-run `git merge-tree --write-tree` |

The R7 dry-run did not alter the worktree. It returned conflict stages for the DM
endpoint. No R7 files, experimental broker patch or deployment config were included.

## Confirmed integration findings and boundaries

| ID / priority | Exact evidence | Decision and minimal resolution |
|---|---|---|
| K1 / P1 | API/dm/send-message.php AI and outer Throwable catches: #76 replaces raw exception logging with fixed markers. #67–#73 replace the synchronous inference body with JarredJobService reserve/enqueue/commit and retain a raw outer exception log. | CONFIRMED conflict and privacy regression risk if choosing the R7 version wholesale. Future reconciliation must retain atomic async enqueue/rollback and sanitize the surviving outer catch. Preserve safe client error semantics. Review JarredJobService::workOne and JarredReplyGenerator::generate, then rerun crash/permission/HTTP privacy tests. No auto-resolution here. |
| K2 / P1 | deploy/phase2/ecollab-jarred-worker.service has PrivateTmp=true; services/OllamaService::acquireInferenceLock uses configured OLLAMA_INFERENCE_LOCK_FILE or legacy temp fallback. | CONFIRMED deployment dependency, not a proven runtime failure. Provision one private shared inode outside temporary mount views; explicitly expose env/path to FPM and CLI; validate actual users/mounts. Do not claim file-lock safety from merging code alone. |
| K3 / P1 | services/CodeLabExecutionService::workOne in #73 adds RealtimeOutbox::codeLabState while #75 changes CodeLabRunner transport only. | No signature collision found: constructor and existing execution APIs retained. Runtime completion confidentiality still depends on unresolved current-policy delivery fencing; keep R7 excluded and test full async/transport/outbox combination later. |
| K4 / P1 | database/migrate.php historical-025 checksum contract and replacement; #73 CoworkspaceService uses server_id/archived, not old generated active_server_id. | No direct column/API collision found. Runner+replacement must ship together; preserve historical025 bytes/ledger. Inventory deployed schema; never assume full historical migration chain passes from targeted025 tests. |
| K5 / P2 | .github/workflows/chat-delivery.yml pull_request.paths excluded API/dm/** although push.paths included it. | CONFIRMED coverage gap. Rehearsal adds API/dm/** to PR trigger; existing assertions/jobs unchanged. This is the only extra implementation change. |
| K6 / P2 | PHPStan configured level2 on baseline and combined source | Same 15 diagnostics, including possible undefined score, missing WebPush classes and Ratchet/private-method issues. Existing baseline failure; no suppression or application changes. Fix separately before a clean static-analysis release gate. |

#77 adds optional third constructor timeout and generateJson without changing existing
OllamaService call signatures. #75 retains CodeLabRunner's legacy default and runtime
IDs; mTLS is opt-in. #74 introduces no dependency on runner or AI configuration.
No new heartbeat query or provider concurrency increase is introduced by the union.
The unchanged baseline already lacks the later R7 safeguards: passing its current
regressions must not be reported as closing private-access/revocation findings.

## Test evidence and limits

Run against the combined source in an isolated worktree. PHP 8.3.6; local MariaDB
10.11 disposable daemon; actual Nginx test gateway; Node 24.19; synthetic data.
The adjacent JSON lists individual attempts and fixture prerequisites. Initial
missing-fixture runs are retained, not hidden; successful reruns supersede them.

| Suite | Local result |
|---|---|
| Changed PHP syntax | PASS |
| PHPUnit Unit | PASS, 25 tests / 56 assertions |
| Migration025 fresh, legacy upgrade, partial, rerun, duplicate rejection, concurrent uniqueness, archive/reactivate, cascades, row preservation, registry checksum/supersession, daemon stop/restart | PASS; actual isolated MariaDB |
| Existing coworkspace/document/whiteboard authorization | PASS in migration suite and standalone MariaDB Collabora/whiteboard fixtures |
| Code Lab execution, server queue, worker pool, batch; private adapter; actual Nginx/PHP mTLS and queue outage/retry | PASS; local backend is synthetic, no student code executed |
| Jarred opt-out HTTP, retention/ownership, safe provider errors, shared lock both contention directions, grouping completeness/failure handling, provider/session races | PASS; real loopback HTTP fixtures, no actual model inference |
| MariaDB rate limiter | PASS, 12 concurrent attempts admit exactly 3; clear/error-lock-release/index migration |
| Settings migration, books, chat-read profiler, load-test fixture lifecycle | PASS on owned disposable databases |
| Learning tools/upgrades/workspace, notification, chat-read/upload, whiteboard scene/presence, Code Lab policy | PASS local regressions |
| JS learning workspace / Code Lab UI / timeline / notification routing / whiteboard socket / request security / realtime settings | PASS local fixture suites |
| PHPStan level2 | FAIL — EXISTING BASELINE: same 15 diagnostics after normalizing absolute worktree paths; first run exhausted128MiB, rerun1GiB completed |
| Broker-backed delivery local attempt | BLOCKED: missing test broker; DB portion reached retry assertion without endpoint. CI real broker required, not classified application failure |
| Browser/settings, Yjs, composer, broker JS local attempts | BLOCKED: missing Playwright/Yjs or tool-local npm dependencies; CI supplies them |
| Runner-wait standalone local attempt | BLOCKED: no designated test adapter env; protocol/CI tests cover supported fixture setup |
| PHPUnit Integration and full historical migration chain | NOT RUN; targeted fixtures do not imply full-chain success |
| Docker compiler/cgroup probes, Linux mount-namespace isolation, JPlag | NOT RUN locally; inspect combined CI results separately |
| Combined async Jarred/R7, real media/revocation outage/origin bypass | NOT RUN; excluded source chain; existing security decisions remain blockers |
| Actual Ollama, deployed FPM/CLI inode, 80 realistic profiles, daemon cancellation/orphans | NOT VERIFIED |

Source PR CI was reread: #74 6/6 returned runs successful; #75 7/7; #76 5/5;
#77 4/4. These results belong to those heads, not this combined tree. The connector
returns a page of PR-triggered workflows, not a guarantee that every repository
workflow ran. Combined-head CI must be recorded in the draft PR with immutable head,
merge SHA/tree and actual job results. Initial combined CI is pending publication.
No performance measurements or 450-user extrapolations are claimed.

## Unwaived security and policy gates

- R7 current-permission checks and active-session revocation/outage bounds remain
  REQUEST CHANGES. Upstream broker refresh errors may extend existing connections;
  API reconciliation cannot guarantee a bound when its dependencies fail.
- LiveKit direct-origin isolation requires actual IPv4/IPv6/listener/port/hostname
  evidence. An Nginx gate alone is insufficient. No new media tests were executed.
- RealtimeOutbox::dispatch recipient snapshot followed by broadcast is not a
  resource-level ordering fence. Private activity timing after revoke remains open.
- PR73 tests/proposals/centrifugo-6.9.6-fail-closed.patch remains research only;
  no approval to deploy a fork is implied or included.
- Code Lab requires an independent execution-host boundary, restricted network,
  certificate/CRL handling and frozen runtime package provenance. Privileged Docker
  on the application VPS is not a sufficient boundary. No staging host authorized.
- Jarred trusted assessment enforcement, aggregate quotas/accounting, retention and
  provider disclosure need explicit policy. Existing endpoint limits are preserved;
  this branch creates no new quota or retention period and deletes no conversations.
- Actual Ollama JSON/context support and daemon behavior after timeout/crash remain
  staging gates. A released PHP lock is not proof the daemon stopped inference.

## Proposed dependency-ordered staging rollout (NOT EXECUTED)

1. Approve exact release scope and security/policy decisions. R7 must be resolved
   before a student-facing combined release; otherwise restrict rehearsal to
   synthetic isolated fixtures. Inventory deployed commit, schema/ledger, services,
   runtime IDs and configuration without exposing content or secrets.
2. Validate recoverable DB backup by restoration into a separate database; record
   schema and ledger hashes, row counts and application compatibility. Rehearse the
   full historical chain separately; stop at unrelated failures rather than forcing.
3. Quiesce coworkspace writes in an approved staging window; deliver updated runner
   and025 replacement together. Check duplicates/shape, maintenance lock, metadata
   lock impact and migration order024 -> replacement025 -> subsequent migrations.
   If later adding Phase1/2, verify prerequisites then063 ->064 ->065 ->066 and the
   realtime outbox schema. Do not fake historical025 ledger success.
4. Establish dedicated execution host and private routing/ACLs before student-code
   tests. Freeze packages/provenance; provision separate server/client CAs, valid
   client/server certs and CRL; verify hostname, rejection paths and no public Piston
   or adapter ingress. Deliver runner-compatible app code first, then opt in mTLS
   with CODE_LAB_RUNNER_TRANSPORT/JUDGE0_URL/JUDGE0_TOKEN/TLS_CA/TLS_CERT/TLS_KEY.
   Preserve language IDs and worker concurrency. Test queue persistence on outage.
5. Drain AI work, provision OLLAMA_INFERENCE_LOCK_FILE outside PrivateTmp with
   private ownership/modes and stable inode visible to every caller. Confirm FPM
   clear_env/CLI environment and actual shared inode. Activate guarded code for all
   inference entry points together; validate model flags, context, completion and
   cancellation using isolated resources. Never replace/unlink an active lock.
6. If the R7 chain is independently approved, reconcile K1; deploy schema/backend,
   authorized outbox/status reads and compatible clients in a rehearsed order;
   configure supported Centrifugo callbacks and protected LiveKit ingress. Validate
   active connection/reconnect/outage revocation and private notification ordering
   before enabling real users. Do not install the experimental broker patch.
7. Enable collector/revocation/Jarred workers only after their schema/env/permissions
   and singleton locks pass. Drain old jobs/outbox on version transitions; retain
   persisted messages, submission snapshots and grading results. Reconcile stranded
   leases and prevent overlapping incompatible workers. No new concurrency tuning.
8. Verify health and synthetic smoke flows, p95/p99/errors, FPM queue/worker PSS,
   DB locks/query rates, queue ages/retries/leases, outbox lag, certificate expiry,
   private-denial/revocation cases and inference guard failures. Operational telemetry
   must exclude prompts, submissions, messages, credentials and private identifiers.
9. Only after functional/security gates pass, separately authorize representative
   staging/media tests using independent generators. Missing telemetry cannot pass.

## Rollback dependencies

-025 replacement is forward-compatible with old app server_id/archived predicates;
  retain compatible runner+replacement/ledger. Do not run the incompatible historical
  recipe or drop the uniqueness guard as a rollback. DDL rebuild/metadata-lock
  effects require a window; restore validated backups only under a separate approved
  recovery plan with reconciliation of writes after backup.
- Stop dispatch/claims, drain or preserve pending work before transport cutover or
  worker downgrade. Switch only to an already validated isolated backend; rollback
  must not reintroduce privileged execution on the application host. Preserve
  immutable submitted source, scores, runtime IDs and job retry semantics.
- Keep the guarded admin provider and common lock; reverting to raw cURL removes
  protection. If daemon/config incompatibility appears, pause affected AI entry
  points rather than replacing the lock inode or bypassing the guard. No data TTL
  or retention rollback is needed because none was introduced.
- Async rollback needs compatible DB/API/client/status/outbox handling; do not
  restore synchronous response clients while async jobs remain unmanaged. Never
  restore JWT admission or deprecated broker history as a security rollback.

Next action: review exact combined CI, then reconcile R7 privacy/async differences
in a separate narrowly tested security task. This rehearsal does not authorize a
staging deployment or certify independent execution isolation.
