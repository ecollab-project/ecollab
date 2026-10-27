# Proposed Phase3X: full-schema integration contracts and remaining release evidence

This is a conditional proposal for the independent auditor to revise after reviewing Phase3W. It is not an acceptance verdict or permission to begin production work.

Repository: https://github.com/ecollab-project/ecollab. Use the exact independently accepted Phase3W publication head/tree from its final handoff, verify the draft PR and CI artifact digests, and create a separate stacked draft branch. The accepted application remained e818bbd84ae85d67aa71a38460fe154a5128a930 during3W. Do not assume the current ecollab-collabs branch has the same head as this immutable optimization stack; identify both before making any branch-wide claim. No merge, deployment, Hostinger access or production database operation is authorized.

Use the eCollab IT Orchestrator. QA should lead the integration-contract work with Backend and Security review perspectives, supported by Database/DevOps where needed. Do not claim agents or tests ran unless they actually did.

Read docs/450-phase3w-performance.md, evidence JSON, claim ledger, raw archives/manifest, and the final PR's exact-head CI/artifact update. Preserve adverse index/write observations and the distinction between minimal/write-ready and full-derived schemas. Neither rejected SQL join should be restored, and no production index deployment is implied by synthetic read gains.

## First priority: five inherited full-PHPUnit failures

Phase3W's actual full project command ran46 tests/114 assertions in a fresh fully migrated owned schema. Exact base and current reproduced the same five AuthServiceTest failures:

- testRegisterCreatesUserAndReturnsSuccess
- testRegisterRejectsDuplicateEmail
- testRegisterSeedsPmUserStudyPrefs
- testRegisterFallsBackToDefaultsWhenStyleGoalUnrecognized
- testLoginSucceedsWithCorrectCredentials

The fixtures expect registration before the verified-email session now required by the unchanged application. Reproduce each failure first. Trace registration/login UI → OTP/session API → OtpService/AuthService → session and database. Determine the intended current contract from inspected source and authorized product/security decisions. Do not weaken or bypass the production verified-email gate to make old tests green.

Repair test setup through a controlled, explicit email-verification fixture or the actual intended verification flow, with no external email delivery. Keep transport substitution in test utilities, not production behavior. Verify correct, absent, expired, mismatched and reused verification state; valid registration/login; duplicate emails; peer preference defaults; transaction/cleanup behavior; permissions and response types. Add focused tests before implementation changes. Any proposed application/auth/security behavior change requires separate explicit scope and audit; a performance phase is not blanket authorization.

Require the full Unit+Integration command on the full owned migrated schema, not only --testsuite Unit. Report every failure/error/skip and compare exact base/current. Preserve zero additional PHPStan findings while clearly retaining the15 inherited diagnostics unless a separately scoped remediation is authorized.

## Remaining measurement gates

Phase3W captures scalar execute-array/bindValue/bindParam inputs/types, changing references, native typed history EXPLAIN, query/exec and prepare errors. Resource/object/non-UTF8 inputs remain explicitly unreplayable; transaction-control/wire-protocol coverage is not exhaustive. Close a remaining gap only with a real failing boundary test and unchanged PDO behavior. Never infer missing parameters or consume a stream merely to log it. Benchmark recorder overhead separately before comparing timings across instrumentation versions.

The full-derived fixture establishes repository migration reproducibility, not production parity. Record applicable migration order, schema hashes, actual index state, client charset, constraints, data adjustments and feature flags. Do not treat the minimal write-ready fixture's added defaults/columns as production migrations.

If lock attribution remains unresolved, reproduce the old peer-containing workload separately with natural wait/trx/lock sampling and explicit sampling overhead. An induced messages.PRIMARY control validates the observer but does not explain the five historical Phase3V waits. Retain failed/unreproduced attempts; do not assign a table or cause without observed evidence.

Use Phase3W's exact source/harness identities and raw30-second read/write controls as scoped evidence. Compare index absent/present only within equivalent schema/data settings and retain every insert/update/cursor/latency/throughput regression. No capacity claim follows from25/100 request slots used by ten synthetic accounts. Broker/WebSocket traffic, production-equivalent configuration, target-VPS resources and representative sustained student sessions remain separate gates. If authorized staging is unavailable, say BLOCKED rather than extrapolating.

FPM queue freshness needs calibration: six Phase3W local cells reported queues above their in-flight caps. Compare status snapshots against actual request intervals and owned OS socket backlog observations before treating them as exact per-cell queues. Stale/cross-boundary reporting is plausible but not proved.

Native logical-empty cases used DELETE after populated tables. Separate fresh-empty physical state from deletion/purge history before interpreting those unusual latencies; keep the original observations.

## Deliverables and handoff

Produce docs/450-phase3x-performance.md (or clearly named integration-contract report), evidence JSON, claim ledger, lossless raw test/benchmark records, exact source/tree/schema identities, commands/exits, all failures and rollback instructions. Keep a draft PR stacked on the exact accepted base. Inspect final-head workflow jobs/steps and download/recompute relevant artifacts; never substitute prior-head CI.

End with an Independent Audit Handoff containing the exact PR URL/base/head/tree, modified files, application versus test-only scope, full-suite and native results, measured gains/regressions or negative outcomes, CI/artifact integrity, unresolved gates and a tailored conditional next-phase proposal. Production release and50–100-student capacity remain unapproved until independently established.
