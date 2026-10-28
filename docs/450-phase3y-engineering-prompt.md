# Conditional Phase 3Y: authentication HTTP journeys and runtime timezone evidence

Begin only after independent Phase3X review and use its exact accepted publication commit/tree, not a moving branch. Keep a separate stacked draft PR. Use the eCollab IT Orchestrator, QA lead with authentication/integrations review perspectives. No production access, deployment or merge.

Read Phase3X report/evidence/claim ledger, raw JUnit/schema records and final-head hosted artifact verification. Reproduce the current full57-test suite and retain the known46-test/five-failure original baseline. Preserve the test-only delivery transport and verified-signup requirement; never weaken authentication to make tests green.

Primary objective: exercise actual HTTP signup-email send/verify/signup/login routes in an owned PHP8.3-FPM/MariaDB10.11 environment with a test-only transport below the delivery boundary, no external mail and APP_DEBUG=false. Preserve real CSRF, method, rate-limit and session middleware. Test absent/wrong/expired/reused/mismatched code, session rotation/binding, duplicate registration, password login and no unauthorized state creation. Identify the actual UI-loaded scripts and, when available, run a browser journey; distinguish service, HTTP and browser evidence.

Explicitly test aligned UTC and a deliberately mismatched isolated PHP/DB timezone configuration. Phase3X observed equal epochs but six-hour wall-clock mismatch causing optional service OTP rejection. Do not infer deployment settings or silently change application configuration. Establish intended timezone policy from inspected source and authorized decisions; any application/security change needs its own explicit scope and independent review. Keep failed controls and real expiry semantics.

Prior performance acceptance gates remain separate: queue freshness calibration, original peer-containing natural lock attribution, physical-empty state, instrumentation overhead/transaction-resource gaps, production-equivalent configuration and representative sustained capacity. Do not claim these were resolved by auth tests. Keep all adverse prior measurements.

Deliver report, evidence JSON, claim ledger, raw HTTP/session/test records without OTP plaintext or secrets, commands/exits, exact base/head/tree/file hashes, full-suite/static comparisons, draft PR and verified final-head hosted artifact. End with Independent Audit Handoff and a tailored next-phase proposal. No real50–100-student capacity or production readiness claim without representative evidence.
