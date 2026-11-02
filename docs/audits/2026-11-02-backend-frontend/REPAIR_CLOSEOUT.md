# Authorized repair closeout — November 2, 2026

Scope: the user authorized fixing the remaining backend/frontend defects after PR #106. This follows the initial `ecollab-collabs` audit at `c838359` and the first PR commit `ff3c34e`. The original evidence remains archived beside this file.

**Local result:** all defects described below have regression coverage and pass. PHPStan now reports **zero diagnostics**, down from 15. Full PHPUnit passes **50 tests / 171 assertions**, including isolated database integration. This result does not certify an entire deployed system, real multiuser WebSocket recovery, provider delivery or production capacity.

## Changes and evidence

| Problem | Repair | Executed validation |
| --- | --- | --- |
| WebSocket timer called private `persistDirtyCodeSessions()`, causing a fatal visibility error | Expose the maintenance method used by both existing timer entry points | Real ChatServer instance without its constructor, SQLite dirty-session persistence, due/early flush and repeat calls |
| Ratchet connection property warnings were hidden by an outdated count-based ignore | Centralize connection-ID access and document the actual IoServer/decorator contract using a narrow object-shape type | PHPStan against the installed Ratchet 0.4.4 source; existing socket/routing regressions |
| Code Lab score path was ambiguous; unknown queue kinds could run as grading work and finish successfully | Initialize score explicitly, reject unknown kinds, and write grades only after a score was computed | Existing execution/queue/privacy/manual-review tests plus an invalid-kind job referencing a valid submitted attempt (red before repair) |
| Optional WebPush classes were absent from root static analysis | Install the separately pinned worker dependency set in CI and load its real autoloader in PHPStan | PHPStan zero diagnostics without broad ignore rules; push regression fixtures |
| Unused legacy OTP route referenced undefined mail constants and issued session codes no verifier consumes | Retire `/API/auth/send-otp.php` with HTTP 410; current signup/login/reset routes are unchanged | No current repository callers found; GET/POST route test verifies 410 without generating codes |
| Authentication tests omitted the existing email-verification prerequisite and expected immediate password login | Add an optional OtpService constructor dependency, leaving the default transport unchanged; tests use in-memory mail, real email-proof verification and real login OTP verification | Full PHPUnit, including missing-proof rejection and failed-delivery cleanup; no email sent |
| OTP lifetime mixed PHP local time with database verification time | Compute expiry with `DATE_ADD(NOW(), INTERVAL :ttl SECOND)` at insertion | Reproduced 29,400-second validity for a 600-second code; UTC/Manila mismatch tests now verify TTL, expiry and single use |
| Fresh MariaDB migration 025 rejected a generated column depending on an `ON UPDATE CASCADE` FK | Add `024_coworkspace_server_scope_compat.sql` before 025: retain delete cascading, restrict immutable server-ID updates, and use an indexed VIRTUAL expression with an existing-STORED conversion path | All 66 migrations on a fresh MariaDB 10.11 fixture; repeat runner; active-workspace uniqueness, archiving/replacement and server-delete cascade tests |
| `migrate.php --status` and `--dry-run` wrote the registry on an empty database | Inspect registry presence and create it only for real apply mode | Regression reproduced `--status mutated the empty database`; both preview flags now leave it unchanged |
| A snippet load could overwrite the next channel; pending editor content could save to the wrong channel | Track the editor's channel/load generation, cancel queued saves on switching, and ignore stale loads/save responses | Two failing channel-switch DOM reproductions now pass; quiz/calendar/snippet contract regressions remain green |
| Student Code Snippet menu conflicted with the established Notes/Documents-only member policy and opened Notes instead | Hide the unavailable menu item for members and stop unsupported-tool fallback; preserve privileged-role access | Student/facilitator/admin menu and routing regressions |
| Main CI did not target `ecollab-collabs` | Include that base branch, optional analysis dependencies, read-only migration checks and real collaboration HTTP checks | Workflow YAML parsed locally; hosted status is tracked on PR #106 |

## Verification results

| Check | Result |
| --- | --- |
| Full PHPUnit with disposable MariaDB | 50 tests / 171 assertions PASS |
| New/extended collaboration DOM suites | 12 tests PASS (7 contracts, 2 private-member loading, 3 role-routing cases) |
| Collaboration pre-dispatch CSRF/method suite | 32 cases PASS |
| Real PHP collaboration HTTP handlers with isolated DB | 22 cases PASS: anonymous/nonmember rejection, token/method rejection, snippet persistence/version conflict/run log, quiz IDs/results and calendar range |
| Node regression entry points | 45 scripts PASS, including browser-push, loopback broker HTTP, Uppy upload HTTP, k6 mocks and frontend DOM suites |
| Standalone PHP regression entry points | 24 scripts PASS, including worker supervision, upload storage, queue isolation, preferences, notifications and WebSocket maintenance |
| Dedicated database suites | Collabora, whiteboard authorization, settings migrations, book events, partial-schema reruns and stored-to-virtual compatibility PASS |
| Migration lifecycle | Preview has no writes; 66 fresh migrations PASS; repeat apply has zero pending migrations |
| Production-directory syntax | 259 PHP and 67 JavaScript files PASS; modified/new tests executed or syntax checked |
| PHPStan level 2 | 0 errors / 0 file diagnostics; original baseline had 15 |
| Workflow syntax and whitespace | YAML parse and `git diff --check` PASS |

Machine-readable evidence: `repair-node-regressions.json`, `repair-php-regressions.json`, `repair-database-results.json`, `repair-syntax.json`, `phpstan-repaired.json`. The feature-capacity fixture initially exceeded a 55-second harness budget; it subsequently completed all 11 scenarios with a sufficient budget. Missing local PHP extensions/configuration were corrected in the temporary test runtime, not by changing application behavior. `code-lab-runner-wait.php` is an adapter helper requiring a loopback URL, not a standalone test entry point.

The k6 checks use tiny loopback mock workloads, not real-user capacity measurements. HTTP tests execute real PHP endpoints and SQL using synthetic sessions; they do not replace a real-browser login journey. No actual email, push, student message, live runner execution or VPS WebSocket delivery was performed.

## CI and environment boundaries

The first PR commit `ff3c34e` completed seven triggered workflows successfully. This follow-up broadens CI to include the full MySQL-based PHPUnit/static/HTTP checks and the additional regressions. Consult PR #106 for the current commit's hosted conclusions; older green runs do not certify newer source.

Only disposable local MariaDB/PHP/mock fixtures were used. Production, OTP070, VPS release directories, firewall policy and production data were not changed. The local browser package downloaded, but Chromium download responses were unavailable/truncated, so real Chromium tests could not run locally. Hosted browser workflows and unaffected prior browser evidence remain distinct from this local result. Live broker/media/Piston/JPlag and representative load certification remain outside this repair's evidence.

## Compatibility, rollout and rollback

- Apply the **complete** migration runner in a separately authorized release, rather than manually applying 025 before its new compatibility prerequisite. Existing deployments with 025 already recorded will still pick up the new pending compatibility migration. No migration was applied to production here.
- The FK repair preserves server deletion cascades and active-workspace uniqueness. Server primary-key renumbering is intentionally restricted; server IDs are treated as immutable. Rollback requires considering the generated-column dependency; blindly restoring cascade-on-update can recreate the migration failure.
- Existing unconsumed codes created under the old timezone logic are not rewritten. A production rollout should review/invalidate outstanding codes under an explicit data-change plan. New codes use the corrected lifetime.
- The legacy route intentionally returns 410. Any external caller not present in this repository must use the maintained auth forms/routes.
- Pending snippet autosaves are canceled when switching channels; the change prevents cross-channel writes and does not introduce draft persistence. Existing role restrictions are preserved, not expanded by frontend controls.
- Revert the follow-up source commit to undo code changes. No live deployment or data change needs rolling back in this task. Reverting also restores the defects documented above.

Coordinator: eCollab IT Orchestrator, Integrations lead with Backend, Security, Database and QA review lenses. Superpowers debugging, regression-first fixes and verification-before-completion were applied. Reviews were performed sequentially by one assistant; no independent agents were spawned. No claim of exhaustive system certification is made.

## Hosted MySQL compatibility follow-up

The first full MySQL run on `536419d` failed at migration 022 because MySQL 8.0 rejects MariaDB's `ADD COLUMN IF NOT EXISTS` syntax. Portable information_schema/PREPARE guards now replace these clauses in 022, 041, 047, 048 and 051, including the document-report index guard. The migration runner now drains result sets from EXECUTE as well as SELECT, preventing an observed PDO 2014 failure when a guarded no-op returns rows. Partial/complete schema reruns preserve attachment and bookmark data.

MySQL's [generated-column foreign-key restrictions](https://dev.mysql.com/doc/refman/8.0/en/create-table-foreign-keys.html) also require the active-server expression to use VIRTUAL storage if base-column delete cascading is retained. The compatibility migration upgrades a previously STORED expression without removing source rows; repeated upgrades, active uniqueness, archived replacements and delete cascades pass in the new dedicated fixture. Main CI checks fresh installs and existing-schema compatibility. Current MySQL conclusions remain attached to the current PR head, not the failed earlier commit.
