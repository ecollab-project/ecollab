# Backend–frontend consistency audit — November 2, 2026

> This is the initial audit snapshot. The authorized follow-up resolves its PHPStan and database-validation gaps; see [REPAIR_CLOSEOUT.md](REPAIR_CLOSEOUT.md) for the current results.

**Source:** `ecollab-project/ecollab`, branch `ecollab-collabs`, commit `c838359711a14e9b6eb3f345c4386d184a178b25` (merge of PR #65). The remote branch was rechecked during this audit and still pointed to this commit. Later phase reports are not evidence that those changes exist on this branch.

**Decision:** scoped fixes pass their local contract tests. This is not a full-system security certification or production release approval. Changes are proposed in a separate branch; no merge, deployment, production database operation, service activation, firewall change, or message delivery was performed.

## Coordination and review scope

The eCollab IT Orchestrator routed the work to Integrations, with Backend, Frontend, QA and targeted Security review. Review lenses: full-stack integration lead, API response contract engineer, file-path auditor, CSRF request-integrity specialist and release-acceptance QA. These were sequential instructional reviews by one assistant, not independent agents.

Skills actually read and applied: eCollab IT Orchestrator and its integration playbooks, API Response Contract Engineer, CSRF Request Integrity Specialist, Release Acceptance QA Lead; Superpowers Using Superpowers, Systematic Debugging, Test-Driven Development and Verification Before Completion.

The scan covered PHP and JavaScript syntax across API, assets/js, includes, modules, security, services and websocket; literal API file references from assets/js, includes and modules; current dashboard entry points; and collaboration request/response consumers. It also ran the available local regression scripts described below. It did not manually review every endpoint, SQL query, CSS rule or authorization decision.

## Findings and changes

| Severity | Finding and evidence | Result |
| --- | --- | --- |
| High | Both `API/collab/collab.php` and `collab-extra.php` excluded DELETE from `CSRF::verify()`. Their action dispatchers also accepted GET for write actions, e.g. timer reset and summary generation. | Verify CSRF on POST/PUT/PATCH/DELETE; reject unsupported methods and GET actions outside each tool's explicit read-action list with 405. Authentication and membership checks remain in place. |
| Medium | `assets/js/chat/collab-tools.js::collabFetch()` dropped its parameter object for GET. `openQuiz()` and `loadQuizResults()` supplied `quiz_id` there, while `collab_quiz()` expects that ID in query/body. | Serialize GET parameters with URLSearchParams. Pass calendar date ranges through the same parameter object so encoding does not change calendar behavior. |
| Medium | `saveCode()` consumed the returned version but ignored the API's `snippet_id`. `runCode()` reads the ID from the toolbar, so a newly created snippet could not log runs until reloading. | Retain the returned ID on the code toolbar after saving. |
| Medium | `_loadPrivateMemberSelector()` in `assets/js/chat/chat-features.js` first fetched nonexistent `/API/active-now.php`, discarded its response, then fetched the real member API. A network rejection on the unused call prevented the real request. | Remove the unused request and use `/API/threads/get-server-members.php` directly. Reject failed HTTP responses before rendering members. |
| Low | `collabFetch()` trusted JSON `ok` without checking HTTP success. | Require both successful HTTP status and the existing `ok` contract. The other APIs' `success` convention is preserved. |
| Validation | `tests/chat-navigation-regression.cjs` extracted startup code without `defaultChatChannel()` or `document.getElementById()`, causing ReferenceError in the fallback scenario. | Load the real helper and supply the missing DOM method in the test harness; no navigation production code changed. |

A new `Collaboration API contracts` workflow runs syntax checks, the two new DOM contract suites, request-security gates and navigation regression for matching pull requests and pushes to `ecollab-collabs`.

## Producer–consumer map

| UI / caller | API / consumer | Contract checked |
| --- | --- | --- |
| Chat quiz details/results → `collabFetch()` | `collab.php`, `collab_quiz()` get/results | GET includes quiz_id and channel_id; JSON uses ok |
| Chat calendar → `loadCalendar()` | `collab.php`, calendar list | GET action=list, from/to stay separate query values |
| Code editor save → run | `collab.php`, code save/log_run | Save returns snippet_id/version; next run submits snippet_id |
| Private-channel selector | `threads/get-server-members.php` | Existing server_id request, successful HTTP + success/members response |
| Collaboration write requests | Both collaboration dispatch gates → CSRF → membership → tool handler | Every write method verifies session token; GET write actions rejected |
| Student/facilitator dashboards | `includes/layout/learning-workspace.php` → `assets/js/learning/workspace.js` → `API/student/workspace.php` | Current UI and service regressions; failure states and CSRF |

The old `assets/js/student/dashboard.js` has a notification CSRF/persistence mismatch, but current student dashboard PHP does not load it. That dormant finding was not patched or presented as a current dashboard failure. The newer workspace flow already sends the token and persists read state.

## Verification and evidence

| Check | Result |
| --- | --- |
| PHP syntax, 256 files in the listed production directories | PASS |
| JavaScript syntax, 67 files in those directories | PASS |
| `node --test tests/collab-contract-regression.cjs tests/private-channel-members-regression.cjs` | 7 tests PASS |
| `php tests/collab-request-security-regression.php` | 32 method/CSRF cases PASS |
| `php vendor/bin/phpunit --testsuite Unit` | 25 tests / 56 assertions PASS |
| Broader Node regression run | 37 scripts PASS; `load-test-mock.cjs` blocked because k6 is absent |
| Selected PHP regression run | 11 scripts PASS; 4 dedicated-database suites refused to run, listed below |
| PHPStan level 2, untouched base vs candidate | Same 15 diagnostics; zero additions, zero removals |
| `git diff --check` | PASS |

New behavior tests were run before the corresponding production fixes. They reproduced missing quiz IDs, missing first-save run logging, ignored HTTP failures and the broken member-selector request. Request-gate tests reproduced tokenless DELETE acceptance and GET summary generation. All pass after the patches. Calendar date-range preservation is a compatibility test that already passed before the change.

The request-security test evaluates each endpoint's actual pre-dispatch PHP gate in subprocesses with real sessions and CSRF code. It does not execute database-backed tool handlers or certify full HTTP authorization. DOM tests execute production JavaScript in jsdom with controlled HTTP fixtures; they do not prove live delivery or database persistence.

Machine-readable results are adjacent: `syntax.json`, `node-regressions.json`, `php-regressions.json`, `phpstan-baseline.json`, `phpstan-final.json`. PHPStan paths were normalized for comparison. PHPStan needed a 1 GB memory limit after its initial 128 MB worker run exhausted memory.

Guarded database suites: `whiteboard-realtime-regression.php` and `collabora-regression.php` need a dedicated collabora_test database; `book-events-regression.php` needs BOOK_EVENTS_TEST_DSN; `settings-migration-regression.php` needs SETTINGS_TEST_DSN. PHPUnit Integration was not invoked because no isolated MySQL test database was configured. Browser/Playwright, live Piston/JPlag, broker/HTTP service and load scenarios were not included in this local run. No claim of hosted CI success is made here.

## Remaining risks and next work

1. Reconcile the later phase/release work with this branch before selecting a defense or production baseline. Do not assume the branch contains OTP070/WS071 or later fixes.
2. Use an isolated MySQL/MariaDB test database for the full integration suite and the guarded tests. Verify real quiz retrieval/results, first-save run persistence and legitimate/unauthorized collaboration requests over HTTP.
3. Complete real-browser smoke checks for permitted roles, channel changes, errors and reconnect behavior. Student tool restrictions in functionality-overrides.js are an existing product rule and were not broadened.
4. Address the unchanged PHPStan findings separately. They include missing MAIL_FROM, possible undefined CodeLabExecutionService score, unresolved optional WebPush classes, Ratchet resourceId baseline drift and calls to private persistDirtyCodeSessions(). These have not been newly fixed or runtime-certified by this change.
5. Legacy read handlers can initialize missing note/board/mindmap/timer records. The new GET allowlist preserves those existing initialization semantics; it does not make those handlers strictly side-effect-free. Channel-switch races and concurrent edit/version handling need separate focused testing.

## Acceptance and rollback

Local acceptance: quiz IDs reach the PHP contract; calendar range survives encoding; saved snippet ID reaches subsequent run logging; member selector makes only its supported request; HTTP failures do not become success; write methods cannot skip CSRF; GET write actions are blocked; no new PHPStan diagnostics.

Full release acceptance remains pending isolated DB-backed HTTP testing, browser smoke checks and hosted CI review. Current production and release directories were not used.

Rollback the source change by reverting its commit on the review branch. No schema or data migration is included. Reverting also restores the CSRF/method weaknesses described above, so release owners should review that tradeoff before any deployed rollback.
