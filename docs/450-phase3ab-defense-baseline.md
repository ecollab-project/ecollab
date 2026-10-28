# Phase3AB defense baseline and integration verdict

**Integration/merge/deployment/security readiness:BLOCKED.** This is a source/evidence decision inventory,not an approved defense build. November3 target andOctober30 safety gate are user requirements;they do not justify unvalidated fixes.

## Immutable references

- ecollab-collabs snapshot:c838359711a14e9b6eb3f345c4386d184a178b25.
- Accepted investigation stack:5a03656b27904498917c16cbfa320187c6da8c84,treef75c1e88aac10d49b4b2d02d34812ea9d088ebce,PR96.
- Phase3T application-reference head:f77506a874c4650ec6c69637461c368876fa178d.

Read machine inventory/raw for full31PR metadata,589net changed paths,54runtime/deployment paths,4migration additions,per-path Gitblobs and ancestry/equality checks. Integration snapshot is an ancestor of acceptedstack,52commits ahead. Docs/test/CI evidence dominates total diff;do not interpret589 paths as589application changes. Git ancestry does not approve changes;blob matches do not certify combined runtime/security behavior.

## What is and is not in the stack

PR74–84 and86–96 heads are ancestors of the acceptedstack. PR66–73 and85 are not. Their changed runtime paths have partial overlap/differences/missing paths in the stack;details are recorded rather than treating a non-ancestor as proof that every feature is absent. The early backend/realtime security branches and separate Node/Piston staging work cannot be assumed jointly integrated or accepted merely because their own workflows passed. No merge rehearsal of all pending PRs is performed here.

| Group | Source status | Acceptance / integration disposition |
|---|---|---|
| PR66–73 early query/realtime/session/broker/media stack |Parallel heads,not ancestors of current investigated stack |Separate security/integration gates;do not silently combine or declare R7 resolved |
| PR74 migration replacement |Retained ancestor |Migration shape/duplicates/rollback andactual targetstate need release review |
| PR75–79 CodeLabmTLS,governance/shared inference,rehearsal,async privacy |Retained ancestors;somepaths changed further in laterstack |Externalhost/worker/sharedlock/privacy/runtime compatibility gates remain;current tests do not approve deployment |
| PR80–84 dashboard/chat/peer/thread optimizations |Retained ancestors,withlateroverlapping files |Engineering reports/test evidence exist;current handoff does not supply independent release acceptance for every early phase |
| PR85 Node/Piston staging |Separate head not ancestor |Not part of this candidate;no promotion or inferred security remediation |
| PR86/87 Phase3R |Retained;peer CPU application change |Independent scoped acceptance supplied in conversation;local improvement notproductioncapacity guarantee |
| PR88 Phase3S |Retained;facilitator member aggregation changed further by3T in samefile |Independent scoped acceptance supplied;25-slot variability/queue/sustainedcapacity unresolved |
| PR89 Phase3T |Retained primary-channel materialization/count deferral |Source/report functional evidence;independent acceptance document not supplied in this current handoff. Preserve tie/NULLsemantics;no blanket release approval |
| PR90/91 Phase3U/V |No net application change from accepted3T;rejected joins only in tests/history |Do not cherry-pick5366f48 forcedjoin ortrial unread rewrite. Negative experiments retained |
| PR92 Phase3W |Test/telemetry/schemafixtures only |Independent acceptance remains explicitly outstanding |
| PR93–96 Phase3X/Y/Z/AA |Test-only acceptance/contracts/HTTP/browser/clock/race/decision work |Scoped investigation acceptance supplied;not application security remediation |

**Critical baseline limitation:**AuthService.php,OtpService.php andconfig.php are blob-identical between ecollab-collabs andacceptedstack. Reverting performance work to that branch does notremove the observed OTP clock/consumption defects. Neither reference is called security-safe solely because it is older oraccepted as research. Currentapplication paths are identical toPhase3T;3U–3AA did not leave rejected optimization orOTP trial code inapplication.

## Dependencies requiring explicit integration planning

| Delta | Required dependency / gate |
|---|---|
| database/migrate.php +025_coworkspace_one_active_per_server_mariadb.sql |Historical025 unchanged/superseded byrunner;MariaDB replacement uses generated active_slot,unique(server_id,active_slot),DBlock andoneALTER;duplicate/unexpectedshape failsclosed. Inspect current targetledger/DDL before anyapply. No productionapply/reversal approved |
| 063_temporary_voice.sql |jarred_pending_actions andtemporary_voice_rooms tables;service lifetime/privacy/config andolder runtimeDDL interactions need targetinventory |
| 066_jarred_jobs.sql +scripts/jarred-worker.php |Queue/gate schema,worker lifecycle/DBsingletonlock/current permissions/error handling. systemdtemplate isstaging-only,notinstallation |
| 067_dashboard_unread_covering.sql |Additive(channel_id,is_deleted,created_at,sender_id)index,INPLACE/LOCKNONE request stillneedsmetadata-lock/disk/targetshape review. Testedindexvariants are notdeploymentapproval |
| Shared local AI |OLLAMA_URL/model/thread andonepreprovisioned OLLAMA_INFERENCE_LOCK_FILE visible toFPM andCLI acrossPrivateTmp/mounts;workerrefusesinvalidsharedlock. Existing settings mustbe inventoried,notsilently changed |
| CodeLabmTLS/isolated bridge |CODE_LAB_JUDGE0_URL/TOKEN,transportmode,TLS_CA/CERT/KEY,languageIDs andseparateexecutionhost/network/isolation gates. Readonlyenvnames inventoryincludesdeploymenttemplates,no live credentials |
| AsyncJarred/frontendintegration |DMjobendpoint,pollingJS,transaction/privacy/error handling,provider/workeravailability andsharedinferenceconfig mustberehearsed together |
| Broker/outbox/temporaryvoice |CENTRIFUGO_ENABLED andbroker/LiveKit/routing/sessionauthorization/realmedia gates are notcertified bythe owned auth tests |

Four migration files are added relative tointegration;thisdoesnotmeanonlyfourmigrationsmustapply toanyactualdatabase. The full ownedfixture runnerapplies68ledgerentries andcreates160initialtables;productionledger/defaults/indexes/content areUNKNOWN. No productionconfig/schema inspection,migration,workeractivation ordeployment wasperformed.

## Bounded demonstration proposal — not implemented or certified

| Feature/workflow | Evidence boundary | Proposed defense handling until outstanding gates close |
|---|---|---|
| Ordinarypasswordlogin/session/logout |ActualoriginalHTTP/Chromium andCSRF/rotation/protecteddenial tested |Onlycontrolled synthetic pre-existing account inisolated/trusted rehearsal;keepreal auth/CSRF. NoauthseedAPI orprotectionbypass. Actualdefenseenvironment rehearsal stillNOTRUN |
| Readonlystudent/facilitator dashboards |Scopedperformance/equivalence/nativepermissions evidence |Syntheticowneddata,no capacityclaim;rehearseactualUI/env beforechoosingdemocandidate |
| Readonlythreads/resources/matching |Targetedregressions andSQL/functionalevidence |Syntheticownedresources only;do notclaimallprivacy/friendship/tiebreakerpolicyresolved;actualUIrehearsalneeded |
| Existingmessagehistory/notificationread |Currentread/APIregressions |Preferreadonlysynthetichistory;avoidtriggeringunverifiedasync/provider/externalbranches |
| OTP-dependentregistration,forgot/reset,optionalOTP,legacyemailverify |Clock andsingle-use securityFAIL;signupmarkerpolicy separate |Exclude liveaccount-lifecycledemonstration wherepractical untilapprovedfixes areindependentlyvalidated. NoUI/configdisable implemented here |
| LiveAI/admininference/asyncJarred |Schema,worker,provider,mount/privacyintegration gates |Do notrelyonliveexternalavailability orimplyruntimeapproval;pre-recorded clearlylabelled walkthrough canbefallback subjectuserdecision |
| Livevoice/broker,reconnect/revocation,CodeLabexecution,Collabora |Separatebranch/externalhost/media/isolation/deployment gates |Exclude liveunapprovedintegration claims;requiresitsownactualcandidate/environment acceptance |

This is a proposed scopeforreview,not a claim thatreadonlyfeatures are universallysafe oralready rehearsed. Noactualdefensebuild,productionconfiguration,switch,featuregate,accountorUIchange wasmade. Do not weakenauthentication toavoid OTP blockers. A recording is notevidence ofliveintegration orsecurityacceptance.

## Rollback/source freeze and deadline gate

Keep ecollab-collabs c838... asunchangedintegrationreference andacceptedstack5a036... asengineering/evidencefreeze. Neitherisautomaticallythelaststabledeployeddefensebuild. Theactualdefenseartifact/sourceSHA,environment,datastate,authorizedfeaturelist andowner signoff mustbenominatedandrecorded beforecertification. Do notmerge31PRs asonebundle orchoose“newestgreen”asbaseline.

This phase rollback:revert onlyitsdocs/test/workflow toexactaccepted5a036...,noapp/schema rollback. A futureapplication rollbackmustconsiderall4schemachanges,asyncjobs/in-flightwork,sharedinferenceandserviceconfig;do notdropcolumns/tables orrestoreinvalidatedOTPcodes automatically. For narrowperformancechanges,keep exactearlier-source rollbackpoints butrevalidateoverlappingservicequeries andAPI/data equivalence. SourceGitreversion isnot a database/worker rollback.

ByOctober30,2026:requireexplicitsecuritydecisions and anindependentlyvalidated chosenbaseline. Ifsafeapprovedfixescannotbeimplementedandvalidated,retainthenominatedstablecandidate anddocument/excludeunresolvedfunctionality ratherthanforcepatches intoNovember3. Ifnostablecandidate hasbeenidentified,theverdict staysBLOCKED;do notinventonefromCI alone.

No merge,productionDB/configoperation,Hostinger,realmail,deployment or50–100-studentcapacitycertification isauthorized. Broadersecurityrelease,15inheritedPHPStanfindings,Phase3Wacceptance,productiontimezone/TLS/proxyparity,lockattribution,FPMsaturation/queues,physicalemptyandtransactiontelemetry remainseparateunresolvedgates.
