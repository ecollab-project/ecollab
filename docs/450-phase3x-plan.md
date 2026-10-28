# Phase 3X integration-contract plan

Task: repair inherited authentication test setup without application/security behavior changes. User continued the engineering chat after Phase 3W's handoff; independent acceptance of Phase 3W is still pending, not presumed.

Source/base: PR92 publication 827470217e6a6a86a165b14f4b6650a10e2c2395, tree badbbf6346b60d21efa006452e6147aea390064b. Separate branch validation/phase3x-auth-contracts-20261028. All application/API/security/migration/configuration directories must remain identical to base.

Primary department: QA. Lead integration-test specialist; supporting authentication/session and backend contract perspectives; QA acceptance gate. These are review perspectives, not spawned agents.

Known flow: modules/auth/signup.php loads assets/js/auth/signup.js → API/auth/send-signup-email-otp.php / verify-signup-email-otp.php / signup.php → AuthService / OtpService → session-bound email verification → transactional users/profile/preferences → authenticated session. API/auth/login.php explicitly calls login(..., false) for requireOtp; the service default offers optional OTP. CSRF/rate-limit and browser code are inspected but this phase's new execution coverage is service integration, not HTTP/browser certification.

Acceptance:
- Reproduce exact five baseline AuthServiceTest failures in a fresh actual migration-derived schema.
- Test transport substitutes delivery only; real random OTP generation, hash validation, consumption and SQL stay active, with APP_DEBUG=false and external credentials empty.
- Require absent/pending/expired/wrong/mismatched/reused verification to stay unauthenticated; positive registration, preferences/defaults, duplicate race, password login and optional OTP login; rollback and cleanup.
- Run complete Unit+Integration suite, default/reverse/random order on independently reset owned schemas. Baseline remains FAIL; repaired current must be genuinely clean, not merely no-new-failure parity.
- Record runtime timezones, raw JUnit/stdout/stderr, commands/exits, exact source/file hashes; compare static diagnostics.
- Keep the clock mismatch and any other failed discovery runs. Explicitly align only the owned test environment; do not silently alter production timezone behavior.
- Freeze harness before final local validation; publish raw results and draft stacked PR; verify exact-head hosted CI and artifact.

Risk: low test-only impact, sensitive authentication contract. No production access, deployment, merge, security remediation or schema migration deployment. Rollback by leaving draft unmerged or reverting only its tests/workflow/docs. Real capacity, queue freshness, old lock attribution, telemetry resource/transaction coverage and physical empty-table state are carried forward, not retested or resolved by this work.
