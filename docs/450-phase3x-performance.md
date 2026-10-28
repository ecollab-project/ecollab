# Phase 3X: full-schema authentication contracts

Scoped local result: PASS for test-fixture repair and service integration. Independent acceptance remains pending. No application/security/API/migration/configuration code changed. No merge/deployment/production access. No new performance or capacity claim.

## Source and implementation

Base PR92: `827470217e6a6a86a165b14f4b6650a10e2c2395`, tree `badbbf6346b60d21efa006452e6147aea390064b`.
Branch: `validation/phase3x-auth-contracts-20261028`.
Frozen final measurement harness: `db46a06fe512f263db7b66fbd2914719c4da194e`, tree `67cb7f8a5cdf71029304c7c1809aca89c07647ef`. Actual source hashes and empty harness/application diff are recorded in `450-phase3x-raw/final.json.gz`. Publication adds only this evidence/docs; exact final publication SHA/tree and hosted verification are supplied in the draft PR body. This report is explicitly a pre-CI snapshot.

The original AuthServiceTest tried to register before the application's verified-email requirement. The wrong-password test also never established an account, so it could pass for a missing user instead of checking a wrong password. Positive cases now send and verify actual generated OTPs through the real service, then register. Only OtpService::deliver is replaced by a test-only in-memory transport through ReflectionProperty; no test seam was added to production. Real generation, bcrypt hashes, session checks, SQL and consumption remain active. APP_DEBUG=false and external mail credentials empty; no email is sent.

The duplicate test restores a previously verified session to model a second session that verified before the first registration committed. It checks the duplicate-specific error, not the earlier generic verification rejection. Login mirrors API/auth/login.php's existing explicit requireOtp=false call; optional service-default OTP behavior is separately executed. This preserves the inspected current policy rather than inventing a new login requirement.

Added cases cover absent/unverified/wrong/expired/mismatched/reused verification, email/session binding, authenticated session creation, scalar response types, verified user state, preference enums/defaults, optional OTP wrong/correct/reuse and expired intent. A malformed nested service-level tag triggers a real exception after user/profile insertion; rollback must leave no account or transaction. This is a service boundary test, not an HTTP input-validation claim. Cleanup explicitly removes generated OTP rows because otp_codes has no cascading user FK.

The old Phase3W no-new-failures wrapper required equal baseline/current exit codes and initially rejected the improvement. It now also permits a truly clean current suite while retaining raw failed baseline outcomes. New Phase3X acceptance independently parses JUnit and requires current exit0, no failures/errors/skips, complete test counts and cleanup. Full bare Unit+Integration runs are used; no tests are excluded to obtain green.

## Executed evidence

| Command/scope | Result |
|---|---|
| Exact base bare PHPUnit, fresh migration-derived schema | FAIL:46 tests,114 assertions,5 failures,exit1 |
| Current bare PHPUnit, default ordering | PASS:57 tests,245 assertions,exit0 |
| Current bare PHPUnit, reverse ordering | PASS:57 tests,245 assertions,exit0 |
| Current bare PHPUnit, random seed31028 | PASS:57 tests,245 assertions,exit0 |
| Independent raw-JUnit/schema/source/cleanup recomputation | PASS,exit0 |
| Unit-only | PASS:25 tests,56 assertions,exit0 |
| Native scalar telemetry SQLite regression | PASS:12 cases,exit0 |
| PHP syntax (three new/changed PHP test files) and diff check | PASS,exit0 |
| Exact-base PHPStan |15 diagnostics each,underlying exits1;0 added,comparison exit0 |
| Old Phase3W wrapper compatibility (UTC fixture) | baseline FAIL/current PASS/comparison PASS,wrapper exit0 |

The five baseline failures are testRegisterCreatesUserAndReturnsSuccess, testRegisterRejectsDuplicateEmail, testRegisterSeedsPmUserStudyPrefs, testRegisterFallsBackToDefaultsWhenStyleGoalUnrecognized and testLoginSucceedsWithCorrectCredentials. Baseline FAIL remains visible in raw records and claim ledger.

Each ordering resets only the freshly owned loopback database, applies the repository's actual migration runner and records DDL/source hashes:70 SQL files inventoried,68 successful ledger entries plus registry setup,160 tables before the suite. Historical025 is explicitly superseded by the runner's replacement. Runtime AuthService/OtpService can create otp_codes afterward; the report does not pretend it is a migration table. All four initial schema identities match. Every current ordering leaves zero synthetic auth users and zero orphan OTP rows. The database is destroyed afterward.

## Clock mismatch discovery

Unconfigured local MariaDB SYSTEM timezone rendered NOW six hours ahead of UTC PHP even though UNIX_TIMESTAMP and PHP time agreed. A freshly generated OTP had UTC expires_at04:29 while MariaDB NOW was10:04 and was therefore rejected. `clocks.json.gz` retains the failure in all three orderings with exact clock fields. This is timezone interpretation, not observed epoch drift. No production configuration was inspected; deployment parity is UNKNOWN.

Phase3X's owned daemon explicitly starts with `--default-time-zone=+00:00`, and PHP commands explicitly set `date.timezone=UTC`. Preflight verifies UTC rendering and epoch alignment. Expiry boundaries remain active; expired-code and expired-login-intent cases still reject. No production database/connection/configuration was altered. Do not extrapolate the aligned-fixture success to an unverified deployment timezone. This environment prerequisite and the failed unconfigured runs are part of the findings, not discarded noise.

## Commands, provenance and raw records

Source the workspace runtime environment for the local extracted binaries. Main final command:

```sh
CLASSROOM_BASE=827470217e6a6a86a165b14f4b6650a10e2c2395 \
CLASSROOM_PHASE3X=1 \
PHASE3X_EXPECTED_HEAD=db46a06fe512f263db7b66fbd2914719c4da194e \
CLASSROOM_EVIDENCE=../phase3x-raw/final.json \
python3 -B -u tests/classroom-performance.py
python3 -B tests/phase3x-report.py ../phase3x-raw/final.json <summary.json>
PEER_THREADS_BASE=827470217e6a6a86a165b14f4b6650a10e2c2395 \
PEER_THREADS_STATIC_EVIDENCE=<static.json> python3 -B tests/peer-threads-static.py
```

All main frozen/summary/static commands exit0. Child base PHPUnit exits1 intentionally and retains the five failures. Every child command/exit/stdout/stderr and JUnit XML is embedded in the raw JSON. Each actual migration command is retained. The manifest supplies both compressed and expanded SHA256 digests. `before`, `repaired`, `full`, `full-fixed`, `diagnostic`, `clocks`, `utc` are discovery runs; they are not all clean or frozen. First wrapper error used an invalid PHP array union operator; corrected to array_replace. `repaired` showed a passing46-test current suite but the old wrapper rejected unequal exits. `frozen` is the first frozen harness; `final` is the later final harness including wrapper compatibility. Keep all adverse/error records.

Local runtime: PHP8.3.6 CLI and MariaDB10.11.14; native FPM is not started in Phase3X mode. System specifications and resource visibility are retained from the owned driver; no two-vCPU VPS parity, CPU improvement or load-capacity inference follows from test timing. Hosted is a separate environment; its exact-head outcome belongs in the PR body's completed CI update.

Changed implementation paths: tests/Integration/AuthServiceTest.php, tests/Support/LocalOtpTransport.php, tests/classroom-performance.py, tests/phase3x-suite.php, tests/phase3x-report.py, tests/phase3w-schema.php, .github/workflows/phase3x-contracts.yml. Additional files are plans/reports/claim ledger/lossless raw evidence. Full base/head diff is linked from the PR.

## Limits, rollback and next gate

HTTP method/CSRF/rate-limiter responses, browser signup/login, external delivery and full role/session end-to-end are NOT RUN by the new coverage. Existing hosted regression workflows must be inspected separately, not treated as proof of these new end-to-end journeys. Fifteen static findings remain; the full static-clean gate is FAIL. Production timezone parity, release/security acceptance and representative sustained50–100-student capacity remain BLOCKED. Prior queue freshness, historical waits, physical-empty state and transaction/resource telemetry gaps are carried forward; no new load benchmarks were run.

Rollback: leave the draft stack unmerged. If separately integrated later, revert only this phase's tests/workflow/docs to the exact base; no application/schema rollback is necessary. No merge, deployment, Hostinger or production operation is authorized.
