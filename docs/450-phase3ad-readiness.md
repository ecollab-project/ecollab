# Phase 3AD — isolated integration-readiness checkpoint

**Integration/defense readiness: BLOCKED.** This is a draft test candidate, not a merge, release or deployment. The actual full migrated-schema HTTP rehearsal exposes a missing users.is_system column. It is not appropriate to call the combined application ready because its isolated OTP and PHPUnit suites pass.

## Source and composition fixed before branch creation

Integration reference: ecollab-collabs at c838359711a14e9b6eb3f345c4386d184a178b25. Independently accepted Phase3AC source/base:0db2d57a85f33710d5bf46b9c7162e8aa39885f6,tree d1536a4a609111779dacbc43fb09bc7e811610ab. Dedicated branch:integration/phase3ad-defense-candidate-20261028. Final publication head/tree and exact CI/artifact are supplied in the PR's final-head update; this committed document is a pre-CI checkpoint.

The integration reference is an ancestor of accepted source. The accepted source already composes PR74–84,86–98; no further merge/cherry-pick or conflict resolution is necessary to create its test candidate. Composition order is retained exactly:74–77 via rehearsal78;79–84;86–89;90–97 evidence/negative experiments;98's accepted OTP correction wins. The precomposition plan was saved before creating the worktree and is committed as450-phase3ad-composition.json.

No application,security,API,configuration,migration,worker or dependency source is changed in this phase. All runtime blobs equal accepted Phase3AC. OtpService Gitblob7013114a3a7bc0d183c01fc92e0687f06202d3a7 must remain identical. Only new docs,evidence,owned tests andCI are added. PR targets accepted Phase3AC's branch as an explicitly stacked review; it is not a request to merge directly into ecollab-collabs. Separate immutable comparison against the actual integration reference records the entire existing stack delta.

Fresh GitHub snapshot reconciles33PRs66–98. Accepted source is62commits ahead of integration,643net paths,55runtime/deployment paths and4migration additions. PR66–73 and85 heads remain parallel,not ancestors. Per-path exact/different/absent hashes are in inventory.json. These heads are excluded; neither ancestry nor green individual CI supplies independent approval. PR74–84 and86–98 retained-source status is not blanket release acceptance. Existing independentR/S/AC scoped acceptances remain distinct from outstanding broader integration/security gates. Rejected3U/V application experiments are not reintroduced.

## Observed integration blocker: actual users schema versus consumers

Actual fresh migration runner succeeds, but users has no is_system column. 002_core_schema.sql's is_system belongs to roles,not users. No tracked migration or provisioning writer creates users.is_system. The actual peer-match get_matches route queries COALESCE(u.is_system,0), which does not tolerate a nonexistent column, and returns500. The owned FPM log records SQLSTATE42S22/error1054/Unknown column u.is_system. This is an application/schema integration failure on the real runner-derived schema,not an inference from a minimal performance fixture.

Consumers also include API/chat/get-matches.php,API/friendship/API_get-matches.php,API/dm/send-message.php,API/dm/server-users.php,API/dm/groups.php,API/ai/dm-account.php and JarredJobService/JarredTools/group recommendations/TemporaryVoiceService/BookRecommendationEvents. Their dependency is statically observed; they are not all claimed dynamically tested. DM,AI/account and related system-user flows remain BLOCKED until classification/schema compatibility is resolved.

Do not remove the system-user filter or silently add default0 and classify every historical row as human. The canonical ecollab_ai identifier is referenced by consumers,not proof of historical ownership. A completion proposal must define an authoritative system-account inventory,creation/provisioning and backfill contract,ambiguous-identity fail-closed behavior,additive schema compatibility and rollback. Unknown production accounts cannot be classified by a guessed username. No new migration,backfill or security-policy correction is implemented here. This is a specific Phase3AD completion gate,not another exploratory optimization phase.

## Other retained findings and fixture corrections

- A POST to send-message without content_type produces500/column content_type cannot be NULL and returns SQL error details. The real chat UI supplies content_type:text; that positive payload roundtrip passes. The omitted-field defect is retained as FAIL,not hidden by its positive control. MessageService is unchanged from accepted base; fix/default/error-contract review remains outstanding.
- Login rotates CSRF. The initial test reused its old token and correctly received403 without mutation. Refreshing through the actual CSRF endpoint fixed the test; no authentication/CSRF policy was changed.
- Outsider thread access intentionally returns404 rather than403. The test expectation was corrected after recording the rejected response; privacy behavior is preserved.
- Initial local025 rehearsal could not start a child contender because PHP_BINARY bypassed the local wrapper's custom INI. It is retained as a failed local run. Setting PHPRC to the same owned runtime INI lets native subprocesses load PDO and the complete unchanged rehearsal passes,including actual daemon stop/start. Hosted native PHP is separately required; no product config changes.

## Migration and runtime dependencies

| Addition | Inspected purpose and isolated evidence | Rollout gate |
|---|---|---|
|025_coworkspace_one_active_per_server_mariadb.sql|Historical025 unchanged; generated active_slot/unique(server_id,active_slot),named lock,fail-closed partial/duplicate handling. Native compatibility,concurrent commit/rollback,interrupted DDL,ledger preservation,runner status/dry-run/rerun and actual owned DB restart rehearsal|Actual target ledger/shape/data,metadata locks and approved maintenance/rollback unknown|
|063_temporary_voice.sql|Adds pending-action andtemporary-voice storage; included in actual full fresh runner|Historical partial-state/retention/privacy/service lifecycle not certified|
|066_jarred_jobs.sql|Adds async queue/gates; included in actual full fresh runner|Worker singleton,leases,in-flight jobs,permissions,recipient system-user schema,provider/shared-lock readiness|
|067_dashboard_unread_covering.sql|Additive covering index(channel_id,is_deleted,created_at,sender_id);INPLACE/LOCKNONE request. Included in full runner|Actual size/disk/metadata wait,target index state;LOCKNONE not zero-lock guarantee|

The full test runner creates160initialtables and applies68ledger entries. That is not evidence about an existing production database. Three non025 additions have fresh-chain coverage; no complete production-like historical/partial-state upgrade matrix is claimed.

Retained staging dependencies: scripts/jarred-worker.php,preprovisioned OLLAMA_INFERENCE_LOCK_FILE shared betweenFPM/CLI acrossPrivateTmp/mounts,provider/model/thread limits,CodeLab mTLS CA/CERT/KEY/token/isolated executionhost andlanguageIDs,async frontend polling,Centrifugo broker/outbox/session reauthorization,LiveKit media/revocation,andCollabora execution/storage. Only tracked variable names/source were inspected. No live credentials or production env was read. PR85's Node/Piston runtime remains excluded. No external provider/media/runtime integration is certified.

## Executed and required validation

Local actual FPM/MariaDB rehearsal creates four accounts through real signup and registration APIs,sets one owned fixture account's stored role tofacilitator,then logs all actors out and back in through real authentication so roles come fromtheDB. There is no session seed endpoint or API stub. Controlled resources:one private server,three channels(private/general/announcement),three server members,one outsider,seeded history,notification,thread andattachment metadata,peer subjects. All are synthetic in a newly created loopback DB.

Positive HTTP coverage passes:student/facilitator dashboard HTML andliveJSON,learning workspace reads,role denial,server outsider/private-channel denial,valid member read,CSRF rejection,explicit-text message send/history roundtrip,thread creation/attachment metadata,outsider hidden404,andnotification read. Matching against that exact schema is FAIL500. Attachment metadata is not actual upload/download; dedicated existing upload workflows remain separate evidence.

New required hosted Chromium uses real login,actual student/facilitator dashboard pages andworkspace APIs without unrelated application API stubs. Desktop1440x900 andmobile390x844 check role data,workspace navigation/refresh,mobile menu andoverflow/page errors. It is NOT RUN locally due unavailable browser runtime; final hosted result must be inspected rather than assumed.

Phase3AC controls are rerun at the composed final head:current60/280 default/reverse/random31028,original46/114/fiveFAILs,accepted3X57/245 and3Y/3Z/3AB60/280,exact accepted3AC60/280 comparison,207-case-family auth HTTP andactual reset Chromium,native180candidate clocks/8single-winner rounds,original360clocks/10unsafe rounds,legacy rehearsal andexact-basePHPStan. Counts from earlier Phase3AC are requirements,not a claim that new final-head runs already passed. CI runs each actual script and retains raw samples/exits.

The new integration HTTP command must exit1 while matching fails; the readiness reporter must returnBLOCKED/exit1. Green security tests do not override this failure. All final-head job/step statuses,including skipped downstream steps,are inspected. Artifacts upload even on failure. No error rate,real-user capacity or complete collaboration acceptance is inferred from bounded functional requests.

## Nominated isolated defense rehearsal candidate — not certified

Source:the final Phase3AD published head recorded in PR,with application blobs exactly accepted0db2d57. Environment:owned Ubuntu24.04/PHP8.3 nativeFPM(static4workers),MariaDB10.11 fresh runner-derived schema,real auth/CSRF,APP_DEBUGfalse,loopback mail sink,synthetic resources fromtests/phase3ad-fixture.php and real account API setup. Source/fixture hashes,resourceIDs,role transitions,raw requests andschema/ledger are evidence. No production snapshot or actual defense laptop/VPS is nominated or inspected.

Proposed demonstrated list:real signup/login/logout/OTP reset fromacceptedsecurityrehearsal;student/facilitator owned-dashboard/workspace navigation;ownedchannel text/history;facilitator announcement;thread creation/attachment metadata;notifications. Matching is excluded FAIL;DM/system-user dependent flows are BLOCKED;liveAI/worker,CodeLab,voice/broker/Collabora andactual attachment download are NOT certified. No authentication bypass or UI feature-disable is implemented. The actual November3 build/environment still needs a final nomination/rehearsal after blockers close. This candidate is not a substitute for that gate.

## Rollback and schedule

Discard this draft's test/docs/CI delta to exactaccepted0db2d57;no application/schema rollback is required for this phase. Fullstack source reversal to c838 is not DB/worker rollback and would remove the accepted OTP fix. For future schema/workers,stop/drain andreview leases,in-flight jobs,metadata/data compatibility andtargetstate separately. Never restore consumed/invalidated OTPs orclear used_at;production OTP retention/archive andcoordinated cutover remain unauthorized/unresolved. Prefer roll-forward whileOTPtraffic paused onincomplete transition.

October30 integration decision,October31 integrated testing,November1 defensebuild andNovember2 nonessential freeze remain target gates. Do not force migration/classification or claim release acceptance to meetNovember3. No merge,productionDB/config change,Hostinger,live mail,deployment orcapacity certification is authorized.
