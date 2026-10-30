# Phase 3AE — cumulative merge plan and isolated defense rehearsal

Authorization: independent Phase3AD scoped acceptance permits planning and disposable rehearsal only. No integration-branch merge, production migration/data/config change, deployment, Hostinger, live mail or capacity certification.

Integration leads; Database migration safety, Security authentication/session and QA integration/browser are supporting review lenses executed through the eCollab IT Orchestrator. No independent agents or human specialists are claimed.

## Exact source and cumulative review

- Integration reference: `c838359711a14e9b6eb3f345c4386d184a178b25` (`ecollab-collabs`, freshly rechecked).
- Independently accepted PR102: `c65d2f994b10dfa7890f6509a2342e58a064571e`, tree `7f6686f3d382f8764e84851411f2f92af840077c`.
- Rehearsal branch: `integration/phase3ae-defense-rehearsal-20261030`. No runtime edits; only Phase3AE tests/docs/workflow may differ from accepted source.
- Accepted source is70commits ahead:725 cumulative paths,69 runtime/deployment paths,6 migration additions. The machine inventory retains every path's original/final blob, SHA256, review class and all37 current PR snapshots66–102.

Git ancestry is not blanket feature or production approval. The retained source is accepted only for the independently tested fresh synthetic integration scope.

| PR group | Review disposition |
| --- | --- |
|66–73;85|Parallel,not ancestors;excluded. No implicit broker/media/revocation or Node/Piston integration approval. Per-file overlap/difference recorded.|
|74–79|Retained migration,mTLS,governance/shared inference and async privacy source. External execution/worker/runtime configuration remains gated.|
|80–84|Retained bounded dashboard/chat/peer/thread optimization stack;production/capacity not certified.|
|86–89|Retained scoped peer/facilitator optimizations,with later accepted corrections.|
|90–91|Rejected optimization trials must not be cherry-picked. Their final runtime deltas against preceding accepted application are empty;negative evidence retained.|
|92–97|Telemetry,regression,OTP clock/race investigations and decision records;not independent production approval.|
|98|Accepted UTC/durable single-use OTP correction must win.|
|99–102|Composed-candidate validation,optional message defaults,code/poll and private-poll correction,authoritative identity fixes;accepted bounded scope.|

Runtime review follows auth page/JS→API→Auth/OAuth/SystemIdentity→users/OTP/session, chat→send/vote→MessageService→messages/poll/options/outbox, dashboard/workspace→services→membership-scoped SQL, matching→batch/profile/cache SQL, DM→Jarred queue→worker/shared lock, and threads→attachment batching. No runtime delta is introduced in this phase. The cumulative binary diff is retained as a lossless gzip with the source inventory. Deployment templates are staging material,not activated features.

## Proposed order and conflicts — not executed

Use a single future reviewed cumulative integration change based on the exact current integration reference and the exact accepted source tree. Do not separately merge37 PRs or replay rejected intermediate commits. If owner later authorizes an actual merge, freeze the target, recheck its SHA,repeat the merge simulation and source/test gates,and stop if either endpoint moved.

Topological dependency checkpoints:

1. PR74–77 inherited source,rehearsal78,async79 and read-path80–84.
2. Scoped86–89 optimizations;retain90–97 final reverted/test/decision state.
3. Accepted PR98 OTP correction.
4. PR99 evidence,PR100 normalized optional message type,PR101 enum/poll/privacy correction,PR102 identity contract and fixture writer correction.
5. Phase3AE evidence-only descendant;nominate final rehearsal head only after its exact-head CI/artifact pass.

`git merge-tree --write-tree c838359711a14e9b6eb3f345c4386d184a178b25 c65d2f994b10dfa7890f6509a2342e58a064571e` returns exit0 and tree `7f6686f3d382f8764e84851411f2f92af840077c`. No conflict resolution is needed while that ancestry holds. No target ref or worktree merge was performed. Preserve accepted files byte-for-byte;never resolve conflicts by restoring older OtpService,MessageService or identity writers.

## Schema and configuration decision

The six additions relative to integration are025MariaDB replacement,063temporary voice,066Jarred jobs,067unread covering index,068code/poll enum and069identity. Run the complete sorted migration chain only in an owned fresh fixture;do not apply just these six to an unknown database. Historical025 stays unchanged and the runner explicitly supersedes it. Preserve active-row uniqueness,queue gate/indexes and existing enum ordinals.

068 appends code/poll without narrowing original values and refuses unexpected shape.069 deliberately requires the owned database name,explicit connection acknowledgement,canonical002 checksum and exact ten seed identities. It is not an existing/production-account upgrade. Normal source integration and database release therefore remain separate gates: an existing-data transition cannot be authorized by this conflict-free Git plan.

Human accounts are registered through real endpoints;OAuth/admin writers are exercised in isolated contracts. Jarred is created only by the authorized CLI provisioner as a NEW system account,never adopted by username. No production defaults,writer inventory or account classification is inferred.

Required external dependency decisions: Ollama provider/model and one preprovisioned common inference lock visible toFPM/CLI;Jarred worker lifecycle and queue recovery;CodeLabmTLS certificates/language IDs/separate execution-host networking;Centrifugo outbox/broker;LiveKit media/revocation;Collabora and storage. Templates and mock providers do not prove real deployed availability. They remain excluded unless separately rehearsed and authorized.

## Defense runbook and evidence boundary

Use owned loopback PHP8.3/PHP-FPM/MariaDB10.11,local nonproduction mail and synthetic data. Rehearsal scripts enforce the target DSN and block external browser hosts. No production credentials or real student data are required.

Sequence: create fresh DB→full migration chain→verify068/069→real signup/email verification→human login/logout/reset→student/facilitator dashboard/workspace→classroom membership/announcement→channel messaging/code/poll/private-vote→matchinghuman/system→humanDM/Jarred restrictions→threads/attachment metadata→notifications→security/regression/browser and artifact recomputation.

The accepted HTTP fixture creates owned classrooms/memberships directly in the fixture,then exercises actual role/API operations;it does not certify a complete classroom-creation UI journey. Real Chromium covers signup/login/logout/reset and desktop/mobile role dashboard navigation/refresh;other listed functions have actualHTTP/DB evidence,not a claim of complete browser click-through for every feature. Full composed upload/download and external services remain separate gates.

Required hosted rehearsal: current60/280 three orderings,accepted controls and original failures,current180clock/eight independent-session race rounds,360/ten unsafe original controls,actual auth/role Chromium,identity32HTTP/22checks/nine migration cases,message/poll persistence/privacy,exact accepted-base PHPStan,all16 required command exits and final-head source/hash/artifact verification. Until completed,those new-head gates are NOT RUN/RUNNING,not inherited PASS.

Nominate the exact successful rehearsal head and artifact digest in final handoff. The runtime must remain byte-identical to accepted PR102. Demonstration scope: synthetic auth lifecycle,role dashboards/workspace,classroom announcement,channel messages/polls,matching,humanDM,threads/metadata,notifications. Jarred queue/authorization is verifiable;live inference is excluded.

Backup demonstration: retain a clearly labelled static walkthrough or recording of the same accepted synthetic scenario;no backup recording was generated by this phase,and it cannot substitute for live validation. If an external feature is unavailable,use its documented limitation rather than simulated live output.

## Blockers,rollback and schedule

Merge execution and production readiness remain BLOCKED/NOT AUTHORIZED pending explicit owner authorization and separate existing-database identity/OTP cutover,retention,TLS/proxy/clock/durability decisions. Existing capacity-fixture cleanup is BLOCKED by canonical missing ws_tokens;excluded. No50–100-user certification. Fifteen inherited PHPStan findings remain.

Rollback before any authorized source merge: discard this test/docs branch,keep integrationc838 unchanged,and recreate owned fixtures. Future source rollback checkpoints are c838,accepted PR98,PR101 andPR102;none is automatically a safe database downgrade. Do not drop populated columns/tables,narrow code/poll enums,reclassify machines as humans or resurrect OTPs. Pending worker jobs/locks/schema compatibility need separate operational rollback approval.

October30:review plan/rehearsal. October31:bounded Phase3AF candidate testing after audit. November1:defense build acceptance. November2:nonessential code freeze and rehearsal. November3:defense. No exploratory optimization.
