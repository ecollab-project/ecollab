# Phase 3Y: HTTP authentication and timezone evidence

PASS for completed local HTTP/native validation on unchanged application source. Local browser NOT RUN (Chromium unavailable); hosted browser/CI verification is PENDING in this committed snapshot. Independent acceptance remains pending. The deliberate mismatched-timezone fresh OTP outcome remains FAIL, not silently normalized.

## Source and scope

Accepted Phase3X base:928a3b8912ef60ab374270c1efd3a24c0433c8aa; tree ad2fb0ab5a0aeec8317ed8ea525241619c8686b1; PR93 was open/draft/unmerged at this SHA and all13 returned CI runs passed on recheck. Phase3X scoped acceptance does not accept Phase3W or authorize release.

Branch: validation/phase3y-http-auth-20261028. Final local HTTP harness:6eacc8cb931bb9f67463d96c2321d0fa84ac7f2d, tree3ad0cd7a118e16a1fed61181561d19f942a6b843. Local full-suite/accepted-base/static harness:0094d38e2fdba18585837e1f228b2d0daa030e05, tree9534891d22a47e09f6f924ce5ccdb207c2e71c4c. The later delta adds startup diagnostics and a clearer reporter only; test-suite/native dependency diff is exactly empty, verified exit0. Each raw run records its actual identity; no underlying baseline HEAD is presented as the implementation. Final publication adds evidence/docs; its full head/tree and exact-head hosted update are in the draft PR body. No application, API, service, security, migration, asset or configuration changes are retained.

The owned environment uses native PHP8.3-FPM and MariaDB10.11 in fresh temporary datadirs. No external targets are accepted. Application source, real middleware and SQL run unchanged. Existing BREVO_API_URL points the real OtpService delivery code at an owned loopback mail receiver using fake, non-production settings. Actual generation, hashing, email construction, cURL, provider-response handling, verification and consumption remain active with APP_DEBUG=false. The receiver keeps codes in memory; no real Brevo credentials or external email are used. There is no HTTP authentication seed/bypass endpoint and no application overlay.

A private CLI helper reads state and creates explicit expiry/SQL-error preconditions. Optional login intent is created by the real AuthService::login(requireOtp=true); real API/auth/verify-otp.php performs HTTP verification. Ordinary API/auth/login.php retains its existing requireOtp=false policy. This setup does not claim the ordinary password-login UI offers OTP. The current signup UI resends via send-signup-email-otp; legacy resend-signup-otp is separately tested as requiring its different pending-signup state.

## Direct HTTP results

| Cohort | Direct cases | Requests | Fresh persisted OTP | Browser |
|---|---:|---:|---|---|
| PHP UTC / DB+00:00 |21 PASS |137 |Accepted |NOT RUN locally |
| PHP UTC / DB+06:00 |2 controls reproduced |20 |FAIL: fresh code rejected |NOT RUN |
| PHP Asia/Manila / DB+08:00 |4 PASS |35 |Accepted |NOT RUN |

Total:192 controlled direct HTTP requests,27 direct cases. Expected rejection statuses and success=false responses are retained, not counted as unexpected faults. An independent recomputation checks74 rejection records for unchanged relevant business-table and OTP checksums, and no newly authenticated actor. The concurrent rejection is checked through the aggregate:two overlapping request intervals,one200/one422,exactly one new stored account. This is two synthetic submissions, not capacity evidence.

Executed real sequence:anonymous session → real csrf-token endpoint → signup-code request → email verification in the original session → registration → student session and protected settings access → logout → ordinary password-login API → protected access. Real anonymous session IDs rotate on registration and login. Old anonymous/authenticated handles fail protected requests. Cookie HttpOnly and SameSite=Lax are checked; Secure=false is the explicit owned HTTP fixture, not a production recommendation. Strict mode rejects an attacker-chosen unissued session ID. The expired-storage control backdates only the owned session file, invokes real PHP session_gc, confirms deletion, then requires401 for that cookie. It does not establish a separate logged_in_at idle-timeout policy.

Seven auth routes reject GET with405 and missing/invalid CSRF with403. Other cases cover missing/pending verification, wrong code, another email/session, expired signup state, consumed signup code, verified-email binding, duplicate preverified sessions, wrong password against an existing account, nonexistent account, real mail/verification/login quotas and429 responses, bounded concurrent registration, and rollback after an owned SQL trigger raises a real persistence exception. Middleware is not bypassed to obtain green. Intentional audit/rate-counter writes are outside the immutable business-data comparison; the nine checked business tables and OTP checksum are explicitly recorded. This is not a claim of complete equality for every row of all160 tables.

## Independent expiry and timezone controls

In UTC and aligned Manila cohorts, the correct generated DB code initially remains valid while its login intent remains valid. Expire only otp_codes.expires_at with a disposable UPDATE:the real HTTP endpoint must reject while intent is still valid. Separately expire only pending_login_expires:the DB code must remain valid while HTTP verification rejects. Both return the existing API's HTTP200/success=false contract. Native OtpExpiryTest additionally exercises the underlying persisted used_at predicate after successful consumption, independent of session routing. HTTP replay preserves only the existing authenticated actor and creates no new authorization.

Actual PHP/DB epochs, wall times, connection zones, expires_at, remaining seconds, intent predicates and verification outcomes are captured at the boundary. The mismatched control has matching epochs but DB wall time six hours ahead; the freshly generated code has approximately -21000 seconds remaining according to the DB predicate despite a valid session intent. UTC and aligned Manila have approximately600 seconds. The mismatch reproduces the audited application/configuration assumption:OtpService::generate formats a PHP-local DATETIME, while verify compares it to MariaDB NOW. It is not established epoch drift. No source or deployed timezone is changed, and production settings remain UNKNOWN.

A separate reviewed proposal is required before correcting application or deployment behavior. Options include an explicitly approved consistent timezone configuration or generating expiry from the same DB clock used for validation; existing DATETIME interpretation, compatibility and all OTP actions must be assessed before any correction. No such option is implemented or approved here. The already-verified signup marker's lifetime remains a separate product/security decision.

## Native/regression evidence

| Check | Actual result |
|---|---|
| Original Phase3X failing control8274702 |FAIL:46 tests,114 assertions,five inherited failures,exit1 |
| Accepted Phase3X base928a3b8 |PASS:57 tests,245 assertions,exit0 |
| Current default/reverse/random31028 |PASS:60 tests,280 assertions each,exit0,no errors/skips/failures |
| New native MariaDB contracts |3 independent persisted-expiry/intent-expiry/used-row tests,included in every current full suite |
| Unit-only |PASS:25 tests,56 assertions |
| Existing client-IP and request-security regressions |PASS |
| PHP/JS syntax,source and dependency parity,diff check |PASS:13 recorded command checks |
| Exact-base PHPStan |15 findings each,0 added;underlying exits1,comparison exit0;full clean remains FAIL |
| HTTP/clock/state evidence recomputation |PASS,192 direct requests;incomplete first frozen attempt separately rejected exit1 |

Fresh migration-derived schemas have68 successful ledger entries and160 initial tables; all three HTTP cohorts have identical initial DDL identities. The runtime creates otp_codes lazily, so it is not pretended to be among the160 initial migration tables. The original full-suite control retains its five failed cases; wrappers do not call it clean. Native full suites are bare Unit+Integration,not filtered. Cleanup records zero remaining synthetic HTTP auth users and zero orphan OTP rows per cohort; default/reverse/random full-suite cleanup is independently checked as well. Temporary databases,FPM pools and HTTP/mail servers are stopped and removed.

## Browser boundary

Actual modules/auth/signup.php loads signup.js plus signup-network-fix.js; login.php loads login.js. The browser script uses real form controls,modal verification,wrong-code/password errors,real cookies/CSRF/API,registration and login navigation,logout and protected access. It blocks external fonts/CDN and does not stub application APIs. The source is syntax-checked. Local Playwright1.62.1 exists but its Chromium binary is absent,so local browser is explicitly NOT RUN. The hosted workflow installs pinned Playwright/Chromium and requires execution. Do not infer a browser PASS from the192 direct requests. The final PR body's downloaded-artifact check will supply the actual hosted browser outcome.

## Commands, failures and provenance

After sourcing the workspace's extracted runtime environment:

```sh
PHASE3Y_EXPECTED_HEAD=6eacc8cb931bb9f67463d96c2321d0fa84ac7f2d PHASE3Y_EVIDENCE=<http.json> python3 -B -u tests/phase3y-http.py
python3 -B tests/phase3y-report.py <http.json> <summary.json>
CLASSROOM_BASE=827470217e6a6a86a165b14f4b6650a10e2c2395 CLASSROOM_PHASE3X=1 CLASSROOM_EVIDENCE=<full.json> python3 -B -u tests/classroom-performance.py
TZ=UTC CLASSROOM_BASE=928a3b8912ef60ab374270c1efd3a24c0433c8aa CLASSROOM_PHASE3W_SCHEMA=1 PHASE3W_FULL_PHPUNIT=1 CLASSROOM_EVIDENCE=<accepted-base.json> python3 -B -u tests/classroom-performance.py
PEER_THREADS_BASE=928a3b8912ef60ab374270c1efd3a24c0433c8aa PEER_THREADS_STATIC_EVIDENCE=<static.json> python3 -B tests/peer-threads-static.py
```

Final completed commands exit0 except the deliberately retained original PHPUnit child(exit1),both analyzers(exit1),and the intentional incomplete-evidence checker test(exit1). Raw children include commands/stdout/stderr/JUnit. HTTP controls record command templates with private stdin omitted. Responses retain status,redacted semantic body,original response SHA256,cookie attributes,opaque-handle aliases and before/after state. OTP/password/email/name/cookie/CSRF values are not persisted. Browser records metadata/hashes,not screenshots,traces or entered credentials. Lossless gzip archives preserve the redacted JSON/log bytes; this is not an unredacted wire replay archive. Both expanded and compressed SHA256 digests are in the manifest.

All failed discovery attempts remain. Initial startup/autoload/session-inspector errors preceded HTTP acceptance; a syntax error and a fixture that accidentally discarded the real CSRF token were corrected in test utilities. The corrected helper preserves the token acquired through the actual endpoint. The first frozen HTTP attempt failed in MariaDB installation before UTC requests,while other cohorts and parallel native checks completed. That attempt's exception was truncated and the installer log was not retained; the cause remains UNKNOWN. Diagnostic capture was improved. A serial diagnostic run and the final clean frozen6eacc8c run completed192 requests. The temporal association with parallel execution does not prove resource contention. The earlier failed artifact stays FAIL/incomplete; it is not substituted for the passing run.

Runtime inventory records actual PHP8.3-FPM/SAPI, MariaDB10.11,cohort timezone,cookie/debug configuration and source hashes. This is an owned loopback gateway,not Nginx/Apache/TLS/proxy parity or the intended two-vCPU VPS. No throughput/latency improvement or50–100-student capacity claim follows from these functional tests.

## Rollback and remaining gates

Keep draft/unmerged. If separately integrated later,revert only this phase's tests/workflow/docs to accepted928a3b8. No application/schema rollback or production operation is required. Full PHPStan clean remains FAIL. Hosted browser/CI exact-head evidence is pending in this snapshot. Production timezone/parity,release/security approval,Phase3W independent acceptance,old natural-lock attribution,queue freshness/saturation,physical-empty and instrumentation/resource gaps,and representative sustained capacity remain separate gates. No merge,deployment,Hostinger/live DB access or new OTP/security policy is authorized.
