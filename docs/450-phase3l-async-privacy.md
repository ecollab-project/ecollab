# Phase 3L — K1 asynchronous Jarred/privacy reconciliation

2026-10-23. Synthetic local/CI work only. No production access, migration,
deployment, service operation, load test, existing-PR merge or deletion of existing
user data. **K1 source reconciliation implemented; final exact-head CI is recorded
in the draft PR. This is a staging-only compatibility change, not release approval.**
R7 **REQUEST CHANGES**; Jarred governance **PARTIAL/BLOCKED**;
Code Lab independent-host isolation **BLOCKED**; 450 users **UNVERIFIED**.

## Exact source and conflict reproduction

GitHub metadata reread before work: #78 OPEN/DRAFT/UNMERGED at
`3b5983ba86ee1b0ba59b4cfa45d331b6f516a908`, base `ecollab-collabs`
`c838359711a14e9b6eb3f345c4386d184a178b25`; #73 OPEN/DRAFT/UNMERGED at
`19a8845665dc72d8bf4d062f634b25410fad9961`, base #72
`127fd08231861cd62be72dbc16d31a473794511f`. #78 and #73 share the integration
base, neither contains the other. #73 includes #66–#72 transitively.
The new branch starts at the exact #78 head and targets its branch for review.

`git merge-tree --write-tree HEAD78 HEAD73` in an isolated worktree reproduced
conflicts in `API/dm/send-message.php` and `.github/workflows/chat-delivery.yml`.
The latter now conflicts too because #78 added a DM PR path trigger. No full
merge was applied. DM conflict stages:

| Stage | Blob |
|---|---|
| Common base | a9c1d564ac8e1b6133bb9e1f53b1064f4a3a93cd |
| #78 | bc087bb9c24ec45ca2a4f43d65a36de697754fb3 |
| #73 | 79e7af0bc9ea51ff36ecde3cd1124a97c6f04247 |

#78 runs inference/actions synchronously after storing a source and sanitizes two
outer error logs. #73 reserves the conversation, stores source/outbox and enqueues
in one transaction, commits before202, and rolls back on failure; it retains raw
outer/secondary error logs and passes some Throwable text to clients. Choosing
either file wholesale loses a required property. This branch retains the async
transaction contract and replaces relevant errors with fixed safe classifications.

## Explicit dependency boundary

Selectively imported from #73, then reviewed against #78:

- Queue, reply generator, status/cancel endpoint, singleton CLI worker, DM async
  endpoint and browser watcher; migration066 (unchanged) and service template.
- AccessPolicy and ChannelAccessService; JarredTools current-resource checks,
  authorization stamp and private search correction; JarredActionService checks.
- CoworkspaceService/DocumentAccessService current server/moderation policy, needed
  by private resource checks. Shared helpers retain #73 restrictions; no moderator
  private-channel bypass is added. Existing fixtures gained policy metadata tables
  and status columns; assertions were not removed.
- DDL-free TemporaryVoiceService and migration063 (unchanged). The baseline
  constructor executed CREATE TABLE during action construction: MariaDB DDL can
  implicitly commit the worker transaction. The imported constructor removes that
  hazard, and room creation joins the existing transaction. Its previously reviewed
  locking and policy helpers are retained rather than reverting them.
- Existing queue/crash/resource/client/access tests, with a new real MariaDB/TCP/
  PHP HTTP/CLI privacy suite and a Chromium controller fixture.

Not imported: full R7 stack, AuthMiddleware global changes, LiveKit/Centrifugo
admission/reauthorization/revocation, broker config or binary, experimental patch,
LiveKit count collector, search/polling optimizations, Code Lab realtime changes,
migrations064–065. Existing #78 guarded Ollama/admin and Code Lab transport remain.
RealtimeOutbox receives only DM current-account audience filtering and identifier-free
retry logging; it does not import the R7 Code Lab event kind or claim delivery fencing.

## Transaction and authorization invariants

| Invariant | Owner / evidence | Guarantee and limit |
|---|---|---|
| Session and CSRF | DM send / AuthMiddleware | Existing gates retained; no provider in request path |
| Current account | DM explicit AccessPolicy::assertAccount; queue/status checks | Current DB status/deletion/global ban, not session role alone |
| Conversation owner | reserveConversation/status/authorize | Recheck membership; forged other-conversation status denied |
| Jarred preference | send before begin; worker before action and reply | Opt-out prevents admission/persistence; no deletion of history |
| Existing assessment restriction | CodeLabIntegrityPolicy before admission | Existing surface policy retained; trusted active-assessment fence remains unresolved |
| Serialized admission | reserveConversation: queue gate FOR UPDATE then conversation lock | One queued/running turn per conversation; existing100-pending bound |
| Source/job/outbox atomicity | send outer transaction; RealtimeOutbox::record joins it | All persist together or roll back; no HTTP202 before commit |
| Commit acknowledgment lost | fixed commit-outcome error | Entire set may already exist; no automatic resubmit or sync fallback |
| Queue dedup | source-message unique key, row claim/lease, singleton DB lock | One job per source; terminal job not reclaimed; no HTTP request-id dedup after a completed turn |
| Worker recovery | advisory lock + recover at startup | Requeue running claims only after owning singleton lock |
| Actions | workOne transaction around action, authorization and checkpoint/reply | Transactional DB effects/checkpoint/reply together; not a guarantee for future external side effects |
| Inference | outside transaction; PR77 guarded provider | May execute again after crash/timeout; no exactly-once execution claim |
| Reply persistence | owned lease row + authorize + context stamp + outbox in transaction | Cancellation, source deletion, permission changes observed before save reject reply; not a universal revocation linearization fence |
| Status/cancel | user+conversation+job selection; done reauthorization | No cross-user content; repeated polls do not insert replies |
| Realtime | durable outbox + authorized status/history fallback | Current account audience added for DMs; snapshot-to-publish race remains R7 blocker |

No inference/action service is loaded in the DM HTTP route. Human DM200 response,
attachments, notifications and browser WebSocket sending remain. AI returns202 with
job identity after successful commit, and the single-request watcher handles pending,
cancel, completed/failed states and wake events. Source history remains durable.

## Confirmed fixes beyond the textual conflict

| Priority | Exact file/function | Corrective behavior |
|---|---|---|
| P1 | API/dm/send-message.php outer, read-cursor and notification catches | No Throwable text in logs/client responses; safe rollback catch; explicit unknown-commit response |
| P1 | API/dm/jarred-job.php catch | Fixed status/client error allowlist; bounded typed request parsing |
| P1 | scripts/jarred-worker.php top-level / finally | Fixed worker/lock-release failures, no uncaught DB exception detail; requires a pre-provisioned common lock, no temp fallback |
| P1 | TemporaryVoiceService constructor/createRoom | No action-time DDL; preserve outer action transaction ownership and current voice policy |
| P2 | JarredReplyGenerator::generate / OllamaService::generate | Worker opts into complete, stop-terminated string output; partial/malformed replies retry/fail instead of persistence; other existing callers keep default behavior |
| P2 | TemporaryVoiceService notification catch; NotificationService catches; RealtimeOutbox::dispatch retry | Fixed error markers, no raw exception text or private event IDs in operational errors |

JarredErrorResponse maps400/403/409/503 to fixed application messages; other
exceptions become fixed500. Expected code alone never makes Throwable text safe.
Ambiguous commit becomes503 with an explicit instruction to check the conversation
before retrying. Invalid JSON/types cannot produce a raw PHP type-error response.
Queue metrics contain only age, attempt and duration. This is a review of the
reconciled path, not a certification of every unrelated repository log.

The worker optionally accepts OLLAMA_WORKER_TIMEOUT (integer1–90 seconds,
default90), bounded by the existing provider maximum; fixtures use1 second to test
actual cURL timeout. No concurrency increase, new quota, retention period or purge.
Strict completion compatibility with the installed real daemon remains NOT VERIFIED.

## Tests and evidence

Local environment: PHP8.3.6, owned MariaDB10.11 fixture, actual PHP HTTP and CLI,
actual loopback TCP fault proxy, synthetic local HTTP model. No real Ollama/model
or deployed infrastructure. The JSON records local results; the draft PR records
final immutable head/merge/tree identities, workflow runs and actual CI logs.

| Test | Local result |
|---|---|
| Auth, CSRF, protected surface, disabled preference, malformed request | PASS actual HTTP/MariaDB |
| Current account suspension/soft-deletion and removed conversation | PASS, no source insertion |
| Two simultaneous requests | PASS: one202 and one409, one source/job/outbox |
| Queue saturation | PASS existing100-pending bound, no source inserted |
| Drop TCP before enqueue | PASS native prepare fault, no source/job/outbox |
| Drop actual COMMIT before forwarding | PASS rollback after connection loss |
| Drop actual successful COMMIT acknowledgment | PASS non202 safe error, complete persisted source/job/outbox; unknown outcome disclosed |
| SQL exception containing synthetic private marker | PASS marker absent HTTP and operational errors |
| Client closes after complete request | PASS admitted source/job/outbox retained |
| SIGKILL real CLI during mock HTTP inference, restart | PASS fresh singleton recovery, one persisted assistant reply; model call can repeat |
| Provider504, incomplete output, malformed content and real cURL timeout | PASS actual CLI/provider; three bounded attempts end failed, no partial assistant message |
| Cancel/source deletion/conversation change/account/preference/server/channel/document revocation during generation | PASS actual MariaDB workOne fixtures, no reply persisted |
| Context authorization stamp change | PASS reply discarded |
| Action effect rollback/retry/checkpoint and duplicate status/worker calls | PASS one committed DB effect and reply |
| Actual temporary-voice confirmation/create through production CLI action router | PASS: one room, no inference; duplicate worker does not create another room |
| DMs current account outbox audience | PASS suspended recipient excluded; no delivery-fence claim |
| Missing worker common lock | PASS startup failure, no temp fallback |
| Existing queue tests incl. non-UTC database clock | PASS SQLite and actual MariaDB |
| Eight abrupt crash transition fixtures | PASS SQLite process-exit fixtures; no claim all used MariaDB |
| Private resource navigation/context/search, access matrix, temporary voice | PASS local fixtures; moderator cannot search unjoined private channel |
| Migration025 fresh/upgrade/partial/rerun/concurrency/recovery with imported policies | PASS actual MariaDB; historical bytes/ledger contract preserved |
| Existing AI guard/grouping/privacy/session, Code Lab protocol/mTLS, settings/learning/notification/DM regression suites | PASS within executed local coverage |
| PHPUnit Unit | PASS25 tests /56 assertions |
| PHPStan | FAIL—EXISTING BASELINE15 diagnostics, no new normalized diagnostics; no suppressions |
| Chromium phone/desktop controller, actual broker, cross-mount lock, full browser suites | NOT RUN locally due dependencies; inspect final combined CI individually |
| PHPUnit Integration / entire historical migration chain | NOT RUN |
| Actual model, installed FPM/CLI inode, daemon orphan/cancellation behavior | NOT VERIFIED |

Initial harness fault: the proxy first intercepted only text queries and did not
inject a failure into native prepared enqueue. It was corrected to recognize both
COM_QUERY and COM_STMT_PREPARE; all actual fault assertions then passed. Static
analysis also caught a misplaced optional completion check during editing; corrected
before publication and full provider regressions rerun. No failing assertion was
removed to obtain success. The separate legacy queue fixture also required its
original realtime-disabled environment; the new integration fixture independently
tests enabled outbox atomicity. Missing local fixture/browser dependencies remain labeled
BLOCKED until the corresponding CI job actually runs.

The Chromium fixture executes production apiFetch, full send controller and watcher
against synthetic HTTP202/status/cancel responses at390/1440 widths. Its renderer is
synthetic; it is component/API compatibility evidence, not a full deployed page or
mobile-layout certification. Existing full browser regression CI remains separate.

## Unresolved gates and operational effects

- R7 current-session revocation during callback/SFU outages, private notification
  ordering/timing and origin isolation are not solved. Existing connection behavior
  is unchanged; no JWT fallback or experimental broker fork is introduced.
- Account/resource reads and stamps are policy checks, not an atomic transaction
  spanning every future revocation and broker/SFU delivery. A later revoke can still
  race publication; retain R7 REQUEST CHANGES.
- Aggregate quotas, retention/provider disclosure and trusted assessment decisions
  remain policy gates. Queue expiry is not conversation/job retention. Terminal job
  metadata has no approved purge policy; missing failed timestamps remain an R9 item.
- One DB worker and the common file lock do not bound inference tasks still running
  in Ollama after a PHP timeout/crash. Real daemon compatibility/cancellation and
  FPM/CLI inode tests are required before activation.
- Full Phase1 voice-admission/locking wiring is not imported; retaining the temporary
  voice helper does not certify all existing voice writers or media revocation.
- No heartbeat queries added; policy/stamp reads occur at admission/status/worker
  boundaries. One worker, existing queue bounds and polling backoff retained.
  Query/CPU/latency impact under representative traffic is NOT MEASURED.
- Code Lab remote-host, mTLS/provenance and450-user benchmark prerequisites remain
  independently BLOCKED/UNVERIFIED. Synthetic regression results are not capacity.

## Staging rollout and rollback requirements (not executed)

1. Review exact source/CI and unresolved security gates before any separately
   authorized staging deployment. Use synthetic accounts and validated backup/restore.
2. Inventory schema/ledger and ship migration063/066 unchanged with compatible app
   code. Run only in approved staging; do not forge064/065 ledger entries. Those
   separate optimizations remain absent and need their own future integration.
3. Provision one private stable inference-lock inode outside PrivateTmp, identical
   path/dev/inode visibility to every FPM/CLI caller, proper owner/mode and effective
   environment (including FPM clear_env). Do not replace/unlink an active lock.
   Configure existing local provider URL/model and optional bounded worker timeout.
   The template alone does not provision or verify any of this.
4. Deliver the new browser controller before switching the DM backend (it retains
   legacy synchronous response handling), or use an atomic versioned asset/backend
   cutover. Old clients do not know the async job status contract. Avoid stale caches.
5. Keep worker disabled until migrations, shared lock and actual daemon checks pass;
   then activate one DB-locked worker. Verify source/job/outbox, auth/CSRF, preferences,
   safe errors, authorized status/cancel, source/reply history and delivery recovery.
   No unsafe synchronous inference fallback when queue/schema/provider fails.
6. Monitor admitted/failed work, queue age, retries, lock failures, safe error classes,
   response/outbox lag and FPM occupancy without content or private IDs. Do not tune
   worker count/concurrency on the basis of these fixtures.
7. Rollback: pause admissions, stop new claims and drain accepted jobs with a compatible
   worker, or preserve them explicitly for recovery. Keep source messages, replies,
   outbox and063/066 ledger/tables. Do not downgrade to synchronous processing while
   pending async jobs can still run, and do not replay an ambiguous admission blindly.
   Retain privacy fixes and guarded provider. No schema drop or data purge is required.

**Final scope decision:** K1 is a narrow source/privacy reconciliation with executable
transaction/compatibility evidence; final exact-head CI determines its staging-only
review status. Overall release readiness remains BLOCKED independently of K1.
