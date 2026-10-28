# Phase3AA — completed local decision validation; final-head CI handoff

**Ready for publication and independent scoped audit; not independent acceptance or a security repair.** The committed report/evidence/ledger are pre-CI snapshots. Final PR description supplies publication head/tree,completed exact-head CI,artifact digest and recomputation without changing tested head.

Accepted base: `bc1f1bc3cff91ce61cf531853e7220f1cfca590a`,tree `a9cc16ca1bee8ad51f96fa1bfe2c0504be09a515`. Branch `investigation/phase3aa-utc-decision-20261028`,stacked on `investigation/phase3z-otp-clock-20261028`. PR95 rechecked draft/open/unmerged at accepted head. No application/API/security/database/migration/configuration/Composer/asset/module/WebSocket source changes. Only tests,CI,proposal/docs and redacted evidence are retained.

## Completed scope and security disposition

| Claim | Reproduced local result | Disposition |
|---|---|---|
| UTC trial configured expiry |180 trial records in five cohorts:four configurations at600s and UTC control at120s;all retain configured interval across PHP/DB verification-zone changes |PASS for isolated trial only |
| Original expiry |180 paired original records retain600,-21000,600,29400s stable windows at nominal600;120 control retained |FAIL safety in adverse original configurations;unchanged application |
| Exact DB boundaries |Before expiry accepted;at/after rejected;configuredTTL+1 rejected by trial;real AuthService reset verification does not issue an expired-code token |PASS trial contract;PHP clock not advanced |
| Created/used fields |Trial raw created_at and successful used_at independently parsed as UTC and matched generation/verification epochs |PASS explicit service writes;table default decision remains open |
| Independent consumption |5 original+5 trial equal-start pairs each produced2 successful reset-token issuances |**FAIL single-use safety**,not repaired |
| Legacy transition |UTC trial reader accepts legacy PHP-local row at simulatedTTL+1;deletion of only owned actor rows prevents reuse;rollback restores unsafe rows;old writer reintroduces incompatibility |Transition decision required;no production action |
| Delivery failure |Real loopback transport deliberately offline;existing forgotPassword success/new unused row/prior invalidation behavior reproduced for both variants |Preserved,not repaired or newly approved |

Discovery and frozen runs each independently record360 clock observations and10 double-consumption rounds. Primary frozen evidence is used for the table;discovery is preserved separately,not substituted. The ten primary rounds contain20 real PHP service invocations,independent PDO connection IDs and distinct PHP sessions. A private equal-start gate occurs before the method call;no hook,pause,query replacement or extra lock exists inside application SELECT/bcrypt/UPDATE. Successful workers actually call AuthService::verifyOtp(reset_password) and obtain a reset token. Tokens/codes/session fingerprints stay private or are removed before recording. This demonstrates the competing-call interleaving,not its production incidence,HTTP exploitability or capacity. There is no atomic-consumption fix in the UTC trial.

Database time is simulated using SET SESSION timestamp. PHP time is unchanged;claims do not imply real waiting601s,extended PHP session intent,or an8-hour reset-token lifetime. The real reset service path matters because it lacks a pre-verification pending-login deadline. Existing pending-login/signup gates and PHP reset-token TTL are unchanged.

## Regression and exact-source validation

All23 local completion commands exit0. Underlying original-control and PHPStan analyzer failures remain explicit,not hidden by wrapper success.

| Check | Local outcome |
|---|---|
| Frozen native experiment |360 records =180 original+180 UTC trial;10 regeneration and60 purge controls;five cohort contract comparisons per variant |
| Native original inherited control |FAIL retained46 tests114 assertions,five original failures,child exit1,no errors/skips |
| Current default/reverse/random31028 |PASS60 tests280 assertions each,exit0,no errors/failures/skips |
| Accepted Phase3X control |PASS57/245;current60/280 |
| Accepted Phase3Y control |PASS60/280;current60/280 |
| Exact accepted Phase3Z control |PASS60/280;current60/280 |
| Actual original HTTP/security |192 Phase3Y +169 Phase3Z requests;102 independently checked rejection-state records and separate concurrency aggregates |
| Experiment fixture registration |20 extra original-application HTTP setup requests,4 per cohort. Combined381 direct requests;native calls/browser not counted as direct requests |
| Unit/client-IP/request-security |PASS25/56 unit;existing regressions and all new PHP syntax checks PASS |
| Full exact-base PHPStan |15 inherited baseline/current,0 added;both analyzers exit1,comparison0;full-clean FAIL |
| Local browser |NOT RUN:Chromium unavailable. New hosted workflow requires actual Chromium journey |
| Trial HTTP/browser |NOT RUN:trial is CLI-only;original application FPM/API/browser are unchanged |
| Hosted final head |PENDING in this committed snapshot;completed status and artifact verification belong to final PR description |

PHPStan's existing paths cover security/services/API/database/WebSocket and exclude tests;zero added diagnostics is an application comparison,not a claim that the experimental class was statically analyzed. New trial/helper PHP syntax and real native execution are checked. Every frozen raw application/experiment file hash matches final publication. Reporter was subsequently strengthened to independently assert UTC created_at/used_at against recorded epochs;it passed against unchanged frozen raw data. No experiment outcome or application logic changed.

Fresh fixture schemas use actual migrations:68 successful entries/160 initial tables;matching initial DDL hashes within cohort sets. Runtime lazy otp_codes DDL is distinct. Cleanup leaves0 synthetic users/0 orphan OTP rows in all cohorts. State-preservation checks cover nine relevant business tables plus OTP checksum,not all160 table rows;intentional audit/rate-counter writes are separate. Owned loopback provider only,APP_DEBUG=false,no real email/credentials/production data.

Local host metadata:PHP8.3/MariaDB10.11;affinity0–8,cgroupCPU quota800000/100000 (8CPU),memory8589934592 bytes (8GiB). FPM uses4workers for these bounded authentication fixtures. This is not the intended two-vCPU VPS or production TLS/proxy/session configuration. No performance improvement or50–100-student capacity is measured/certified.

## Provenance and previously unavailable checkpoint

The shell Git push failed because no shell GitHub credentials were available;no remote ref was changed by that attempt. Publication uses connected GitHub object/PR tools. Connector-created commit metadata differs from local Git,so it produces different commit IDs while preserving every blob and complete tree. Do not claim the original2416717 SHA itself is uploaded.

| Source | Local identity | Published equivalent | Exact matching tree |
|---|---|---|---|
| Original checkpoint |2416717080d08f10cd40bf79bf1032402d34581d |e6ba0ab00e8ff6d9f28272be0d26b2a39a3b49ee |850837d4c7cc6b2ebfdbeb6cc0fdbd5fb4f87531 |
| Frozen validation |1c5c4157e3b4f26043b46af97c78ab09fe33607c |a5bb3f5244e4c587e58ce1739f0992e894bc4367 |32c1ea35444227547e635c83ddc5136691e955f8 |
| Final reporter/decision |8a3699a177ae37e98bd31cae9bec0ace358ba9a5 |fe5f505d868dc5bb234dfd5e73bf0101c10de4bb |6dc472c9683178c325d99f2aa64216b64760bc4c |

The original incomplete checkpoint report/evidence and lossless discovery archive are preserved unchanged in published history. Current report explicitly supersedes its pending-suite status. Initial full-suite raw was executed during discovery;frozen-full is the acceptance run. Raw records identify actual/intended commit/tree and source hashes. A pre-existing unrelated untracked raw artifact is excluded from publication and is not loaded by any harness. No source overlays or application substitutions. Final hosted run revalidates combined publication and its merge-tree identity.

[Raw manifest](450-phase3aa-raw/manifest.json) records compressed/expanded SHA256 of every lossless redacted JSON/log archive,including discovery,frozen controls,commands,stderr and child failures. Committed runtime evidence excludes plaintext OTPs,passwords,reset tokens,cookies,CSRF and real identities. Semantic redaction plus response hashes is not an unredacted wire archive. Prior Phase3Y failed browser/static-skipped and later successful evidence remains in accepted Phase3Z history;no result is relabelled or discarded.

## Reproduction

See `450-phase3aa-evidence.json` and raw completion-commands for exact23 commands,environments,start-heads,exit codes,stdout/stderr and timestamps. New workflow `.github/workflows/phase3aa-otp-decision.yml` installs owned PHP8.3/MariaDB10.11/Chromium dependencies and runs source/security,native original/current,accepted3X/3Y/3Z,required original Chromium journey,original3Z controls,isolated trial and exact-base static comparison. Static and artifact upload use always() so failures remain available.

```sh
PHASE3AA_EVIDENCE=phase3aa-raw/experiments.json python3 -B -u tests/phase3aa-experiments.py
python3 -B tests/phase3aa-report.py phase3aa-raw/experiments.json phase3aa-raw/experiments-summary.json
CLASSROOM_BASE=827470217e6a6a86a165b14f4b6650a10e2c2395 CLASSROOM_PHASE3X=1 CLASSROOM_EVIDENCE=phase3aa-raw/full.json TZ=UTC python3 -B -u tests/classroom-performance.py
# Three accepted-control invocations use exact3X,3Y,3Z SHAs and CLASSROOM_PHASE3W_SCHEMA=1 PHASE3W_FULL_PHPUNIT=1.
PHASE3Y_EVIDENCE=phase3aa-raw/http.json python3 -B -u tests/phase3y-http.py
PHASE3Z_EVIDENCE=phase3aa-raw/timezone.json python3 -B -u tests/phase3z-timezone.py
PEER_THREADS_BASE=bc1f1bc3cff91ce61cf531853e7220f1cfca590a PEER_THREADS_STATIC_EVIDENCE=phase3aa-raw/static.json python3 -B -u tests/peer-threads-static.py
```

## Decision, authorization and rollback

Read [complete decision matrix,legacy retention/default choice,UX and rollout/rollback design](450-phase3aa-decision.md). Proposed UTC application file:OtpService.php only;no such change is implemented. No schema/default conversion,data invalidation,global timezone change,ordinary-login policy,marker TTL or atomic fix is authorized. Test-only UTC subclass is implemented solely for owned native experiments. A security patch requires separately approved representation/strict-boundary contract,exact files/actions,legacy unused/used/default treatment,retention and re-request UX,and coordinated rollout/rollback. Atomic consumption requires its own scope/approval and remains a high-priority FAIL.

Keep Phase3AA pending independent audit;do not advance3AB implementation. After audit acceptance,seek explicit decisions. The user's October30 safety gate for the November3 defense favors an independently validated baseline,not deadline-driven unvalidated patches. No demo build or feature restriction is changed by this phase.

Outstanding BLOCKED/NOT RUN:production clock,TLS/proxy/session parity;Phase3W independent acceptance;15 inherited diagnostics;natural-lock attribution;FPM queue calibration/saturation;physical-empty process/resource observations;transaction/instrumentation telemetry;broader security release;representative sustained capacity.

Rollback this phase:keep draft unmerged,or if separately integrated revert only tests/workflow/docs to exactbc1f1bc. No application/schema rollback required. Future clock/data rollback must never resurrect invalidated codes and cannot assume old writers safely read new UTC records. No merge,deployment,Hostinger,production credentials/database or real mail is authorized.
