# Phase 3I — Jarred governance and resource protection

Reviewed 2026-10-23 (Asia/Manila). No production inference, data access, deployment,
migration, service restart, quota-policy activation, deletion or merge.

## Exact scope and branch

Independent base `ecollab-collabs` fetched at
`c838359711a14e9b6eb3f345c4386d184a178b25`, tree
`3a90742e5012c8ecaac810e54d2ea496ccc66649`. This repair does not depend on PR74,
PR75 or unresolved R7. PR74 remains draft at aa9b6b35ba86a50024987a60400b13488a9acbb5;
PR75 remains draft at 2e7a3aafe91fb88673e16c0ad78c7c46212ec9b7. Neither was deployed.

Unmerged async implementation was separately inspected: PR67 head
e1fe79f7b2cdadf4719e9ef5f1bd82ae14db4b98; integration/security stack PR73 head
19a8845665dc72d8bf4d062f634b25410fad9961. Do not describe that queue as deployed
or as present in this independent base. Existing PR68 private-resource fixes and
R7 changes require deliberate integration; they are not duplicated here.

## Inference and expensive-work inventory

| Entry point | Existing admission and authority | Provider, context, persistence |
|---|---|---|
| API/ai/message.php | Auth+CSRF, Jarred preference, user-owned session; shared ai_assist/user 20 per3600s; nonblocking per-conversation file lock | Gemini15s then Ollama90s; prompt4000 characters; history20 rows trimmed to8000 characters; response220/400/700; successful exchange atomic in ai_messages/ai_sessions |
| API/chat/ai-assist.php | Auth+CSRF; same ai_assist/user counter, not a second allowance; prompt500/context2000 characters | Gemini45s, output300; response only. This patch enforces existing Jarred opt-out and releases the PHP session before inference |
| API/dm/send-message.php, ecollab_ai recipient | Auth+CSRF, conversation membership, Jarred preference and turn lock; no hourly admission counter | Ollama; source message persisted before generation; 12 recent messages, history trimmed to5000 characters plus prompt4000; deterministic actions/context run before model; replies in DM storage |
| API/facilitator/jarred.php | Auth+CSRF, platform role; selected server requires managed membership; Jarred preference; no hourly counter | Dashboard, capability and optional deterministic grouping evidence; prompt3000 characters; Ollama; no AI-session transcript persistence here |
| API/collaboration/document-ai.php | Auth+CSRF, feature flag, DocumentAccessService before and after inference; Jarred preference;20s cooldown per PHP session | Reads at most20000 request bytes; excerpt12000 bytes; fixed action allowlist; Ollama; suggestions only, not automatic document edits |
| API/admin/group-recommendations.php | Admin/super_admin+CSRF; no hourly counter, no shared Ollama lock | Raw cURL to configured Ollama,45s; up to80 active student/facilitator profiles; topic/task have no explicit length bound; JSON requested; no shared num_ctx/num_predict/num_thread protection |
| API/collab/collab-extra.php?tool=summary&action=generate | Auth+CSRF, channel_members membership; chat_summary/channel counter3/hour | Anthropic claude-haiku-4-5-20251001,20s,600 output tokens; up to200 messages, transcript8000 characters; persisted in collab_summaries and broadcast |
| API/ai/sessions.php, session.php, quick-prompts.php, dm-account.php; API/profile/settings.php | Session management/preferences/lookup, not inference; session IDs owner-scoped | Session create lacks an admission quota; listing limits are not storage limits. Settings accept saved context up to2000 characters |
| scripts/warm-jarred.php; scripts/benchmark-jarred.php | CLI-only; not public endpoints | Model load with no conversation / two fixed synthetic generations. Not executed during this audit |
| API/admin/service-status.php | Admin-only | GET /api/tags probe, not model inference; does not certify model health or capacity |
| JarredTools / JarredActionService / JarredGroupRecommendationService | Context/actions reached through DM/facilitator; each resource/action still needs backend authority | Matching, library HTTP lookup, server/message/resource queries and actions can be expensive before inference admission. CapabilityPolicy describes authority; it is not an authorization engine |

Repository call-site and raw provider-URL searches were used, not just endpoint
names. OllamaService::chatWithTools exists but has no production call site found
in the reviewed base. It permits up to5 rounds; that is not a total operation deadline.

## Findings

| ID / priority | Classification and exact evidence | Outcome |
|---|---|---|
| I1 / P1 | CONFIRMED: chat/ai-assist.php omitted UserPreferences::assertJarred; configured Gemini could execute despite jarred_enabled=0 | Patched using existing preference semantics, before quota/provider work. Baseline endpoint fixture fails; patched fixture passes |
| I2 / P2 | CONFIRMED: GeminiService::generate threw provider error.message verbatim; AI route catch blocks logged exception messages | Provider HTTP errors now contain status only; reviewed AI message/session/facilitator/DM catch logs use fixed markers. Synthetic private markers excluded from provider exceptions, suggestion responses and logs |
| I3 / P2 | CONFIRMED: chat/ai-assist.php held PHP session through Gemini generation | Patched session_write_close after identity/quota capture; provider fixture rejects execution if session remains active |
| I4 / P1 | CONFIRMED: admin/group-recommendations.php raw cURL bypasses OllamaService::requestChat's lock and bounds | Remaining resource gate. Move through a bounded shared provider path with explicit JSON compatibility and maximum candidate/output policy; do not silently truncate80-user recommendations |
| I5 / P1 | CONFIRMED coverage gap: only two inference routes use ai_assist/user; facilitator and DM can avoid that counter; document sessions have independent cooldowns | Cross-surface policy BLOCKED. Existing limits are real, but not an aggregate AI workload budget. No arbitrary new quota imposed |
| I6 / P1 | CONFIRMED: CodeLabIntegrityPolicy::isCodeLab only reads supplied surface/Referer. Forged chat/DM context with missing Referer does not establish an active assessment | Trusted assessment policy/enforcement BLOCKED. Existing self-reported scope protection is not a server-established assessment fence |
| I7 / P1 | CONFIRMED existing integration risk: base JarredTools::collaborationContext/searchMessages has the broader private-resource predicates already addressed in PR68 | Integrate and independently revalidate PR68/R7. Document-ai uses stricter DocumentAccessService; that does not certify DM context privacy |
| I8 / P2 | POTENTIAL: sys_get_temp_dir file locks are host/filesystem-namespace local. PR73 Jarred worker template uses PrivateTmp=true | Test actual FPM/worker namespaces; configured paths may not coordinate. Same-namespace contention test is not global proof. Provider-daemon concurrency and orphan inference after HTTP timeout remain UNKNOWN |
| I9 / P2 | CONFIRMED: conversation/session/summary storage and async terminal job metadata have no reviewed automatic expiry; limiter only deletes up to100 old rows for the active key | Policy/maintenance BLOCKED; inactive keys and old job metadata can accumulate. No cleanup performed |
| I10 / P2 | CONFIRMED visibility limit: Ollama/Gemini clients return complete response bodies into memory without an application response-byte cap | Define tested response/context envelopes, including provider error payloads; output-token targets are not a PHP response-memory ceiling. No speculative cap deployed |

These are source findings, not evidence that production was exploited. No new P0
was established. The minimal changes resolve I1-I3 and reduce I2 exposure; they do
not close I4-I10 or certify all logs/privacy paths.

## Existing quota/concurrency behavior

RateLimiter::attempt uses a database-specific, per-key GET_LOCK around count and
insert, releases in finally and uses database time. Both ai_assist routes hash the
same authenticated user ID into the same key; tabs/independent PHP sessions cannot
create another allowance on those routes. A lock timeout returns a bounded denial.
Current checks charge admission before provider work: provider failures consume
one admitted request. AI-session ownership is checked after rate admission, so a
well-formed request for an inaccessible session can consume the requester's quota.
No new refund policy was introduced. Document cooldown is per session, so separate
sessions do not share it; ordinary tabs sharing one session normally do.

OllamaService uses a nonblocking exclusive file lock for each HTTP call, local-only
URL validation, no redirects/proxies,90s total cURL timeout,4096 model context,
1-2 requested model threads, output1-1024 tokens, keep_alive10m. These are client
settings, not cgroup CPU/RAM limits. Gemini has no equivalent global lock. Direct
admin Ollama calls are outside this guard. Releasing PHP sessions does not free
FPM workers: synchronous routes still occupy workers for provider latency. The
AI-session fallback can consume roughly15+90 seconds of configured HTTP budgets;
tool loops can add multiple budgets. No measured production latency is asserted.

Historical2-vCPU/~8GiB and10-FPM-worker configuration is not a fresh runtime
inventory. Keep production worker/inference counts unchanged. Verify actual
provider-daemon concurrency, service namespaces, busy responses, CPU/RAM, queue
age and ordinary chat latency on authorized staging before sizing. Model
concurrency is not the number of students who may use the website.

Unmerged PR73 adds a singleton database-locked DM worker, one pending job per
conversation, a serialized global100-pending admission gate, up to3 attempts and
a30-minute maximum queue age. These are queue/recovery bounds, not hourly quotas
or message-retention periods. Its terminal rows have no reviewed purge or foreign
keys tying job metadata lifetime to account deletion. Other synchronous surfaces
remain outside that queue. This independent patch must be ported/reviewed against
the extracted JarredReplyGenerator path during integration.

## Privacy, deletion and provider disclosures

- AiSessionService scopes get/rename/delete/history operations to user_id. A
  successful exchange writes both messages and counters transactionally; failure
  rolls back. Explicit owner deletion relies on ai_sessions -> ai_messages
  ON DELETE CASCADE in migration002. Hard users deletion cascades through sessions.
  A deleted_at/status update is not a physical delete and does not invoke that FK.
- jarred_memory=0 means saved notes are not injected. It does not disable stored
  conversation history or delete existing messages. Personalization=0 suppresses
  saved notes; enabled notes are bounded2000 characters. API/profile/settings.php
  persists the notes until the user changes them; no approved TTL was found.
- Session retrieval returns at most200 messages; there is no complete paginated
  AI export path established. A complete account erasure/export workflow was not
  found in the reviewed API/services. Do not infer external operational deletion,
  backup expiry, provider retention or legal compliance from schema FKs.
- DM history excludes soft-deleted messages from model context but storage can
  remain. DM/source/replies, AI sessions, summaries, saved preferences, async
  job surface/action/usage metadata and backups need separate retention classes.
- Gemini receives bounded conversation history and opt-in saved notes from the
  session route. Anthropic receives other channel participants' transcript text
  and usernames. Requester authorization is not evidence of an approved external
  processing notice/consent/retention contract. Provider-side policy is BLOCKED.
- Facilitator evidence is fetched using managed server/channel queries; selected
  server is checked. Model guardrails against invented skill/assessment claims
  are prompts, not guarantees. Session role snapshots/account revocation remain
  part of unresolved R7; no role-change/revocation certification is made here.
- Do not log prompts, model replies, saved notes, excerpts or credentials. Fixed
  markers reduce disclosure but also reduce diagnostics. Approved aggregate
  counters may record surface/provider, outcome class, latency bucket and queue
  age, without source text, resource IDs or stable user identifiers. No new
  telemetry pipeline or production log change was installed.

## Policy decisions — proposed, not activated

| Decision | Options and effects | Gate |
|---|---|---|
| Shared budget | Preserve current20/hour meaning on two routes; alternatively approve one user-wide budget across named inference surfaces, with equal or explicit weighted costs and separate role allowances | BLOCKED: product owner must approve scope, values and fairness; do not resurrect paid/token-balance access gates from migration018 bookkeeping |
| Failure accounting | Charge admitted attempts as today; or allow bounded, idempotent provider-failure refunds with abuse prevention. Validation/auth/policy denials should precede reservation where feasible | BLOCKED: approve retry/refund semantics; no unlimited refunds |
| Concurrent work | Single shared local-provider lease plus explicit cloud-provider global limit and per-user in-flight slots; raw admin path must join it; bounded FIFO admission if accepted | BLOCKED: approved caps, JSON-output compatibility and actual shared lock/daemon boundary |
| Retention | User-managed retention; institution-approved automatic age expiry; or different message/content and audit-metadata lifetimes. State inactivity versus creation clock, opt-outs and recovery expectations | BLOCKED: no period invented. Include summaries, DM records, saved notes, jobs, backup copies and external processors |
| Assessment integrity | Trusted task/attempt/assessment policy checked at admission and before tool/action/result delivery; decide whether an active protected attempt restricts other surfaces and how facilitator authoring is exempted | BLOCKED: institution must define scope; no universal ban on code explanations outside protected assessments |

Configurable design: one validated policy record/version defining enabled scopes,
per-user/window costs, per-role allowances, provider in-flight limits, input and
context envelopes, operation deadlines, failure accounting and retention classes.
Unset policy must retain existing behavior rather than silently choosing new
limits. Invalid approved configuration must fail closed for affected admissions.
Use a database-atomic reservation/idempotency key so one logical request/fallback
is charged once; preserve existing counters during any cutover. Return429 with
retry_after/Retry-After for quota,503 for transient saturation/unavailable provider,
and403 for disabled/assessment/authorization policy. Keep database locks short;
never hold a quota transaction across inference. No heartbeat queries added.

## Academic integrity acceptance limits

CodeLabIntegrityPolicy is a scope classifier, not proof of where code originated.
The three existing routes call it; document/facilitator/admin/summary paths do not
establish a trusted assessment fence. Direct requests with forged surface='chat'
and no Referer are accepted as ordinary scope. Current tests intentionally allow
normal chat/documents. This is not a PASS for protected-assessment bypass testing.

Concept explanations, debugging guidance, study plans, practice quizzes, document
feedback and facilitator summaries remain available. No broad content ban was
added. Role transitions, direct endpoint access while an actual protected attempt
is active, and cross-surface assessment enforcement require isolated trusted-state
fixtures after the institutional rule is approved. Existing R7 and private-context
fixes must be integrated before claiming consistent revocation across surfaces.

## Regression evidence and limits

New tests were executed before/after the smallest fixes:

- Baseline actual suggestion-route fixture rejects the expected disabled-user
  invariant; patched fixture passes. Actual copied route, actual UserPreferences
  with SQLite, synthetic auth/RateLimiter/provider boundaries. It verifies auth,
  CSRF, opt-out before quota/provider, released session, success, existing20/hour
  admission including failures,429 and no private marker in errors/logs.
- Gemini client test loads production client code in a fixture namespace with
  stubbed cURL functions: success/TLS options preserved;400/429/503 upstream errors
  remain502 without echoed private markers. Baseline privacy invariant fails.
  This is not an actual cloud-provider/TLS integration test.
- Two PHP processes plus real loopback HTTP verify one Ollama request admitted
  and concurrent request rejected503 in one shared temporary namespace. No model
  was loaded; no production endpoint called.
- Retention fixture uses synthetic SQLite FK schema: owner isolation, explicit
  session cascade, unrelated records preserved, soft deletion retains records,
  hard deletion cascades, personalization off suppresses notes without deleting
  history. This does not certify production schema/backup/account deletion.
- Existing settings-runtime, Code Lab scope-policy and real-cURL provider tests
  pass. PHPUnit Unit passes25tests/56assertions; changed PHP syntax passes.

The existing MariaDB limiter concurrency suite is retained in CI:12 simultaneous
requests admit exactly3; DB-clock retry, lock release and migration rerun checks.
Local MariaDB concurrency was NOT RUN in this phase; exact reviewed-head CI must
be checked separately. Cross-route aggregate enforcement is BLOCKED on policy;
no test claims all routes share a limit. Private document/whiteboard isolation and
current-role revocation are NOT newly revalidated here; known PR68/R7 gates remain.
Real isolated Ollama inference, host CPU/RAM, FPM occupancy, cloud outages and mixed
workload measurements are NOT RUN. Exact-head workflow conclusions belong in the
draft PR after GitHub returns them, not inferred from a previous commit's CI.

## Rollout and rollback proposal

No deployment is authorized. Independently review these fixes and resolve policy
before broad governance rollout. Read settings through existing migration060;
verify migration061 limiter schema is actually deployed without running migrations
in this phase. Capture only aggregate baseline failures/busy/latency. Release
route and provider changes together; verify disabled Jarred produces403 without a
provider call and enabled study suggestions remain usable. No SQL/schema change,
quota value, concurrency increase or conversation cleanup is included.

Integrate with PR68/R7 and async PR67 deliberately: DM handler structure differs,
so reapply safe logging to the actual worker/generator boundaries and rerun
private-context/current-role/session tests. Review PR74/75 independently; this
branch does not establish their deployment prerequisites.

Rollback restores approved application files without deleting messages, sessions,
settings or jobs. Preserve the opt-out and private-error protections in any older
release used; do not roll back by disabling authorization/TLS. Keep existing
provider/worker counts, pause new admissions if needed, and retain durable work.
Retention deletion needs a separate approved dry-run/backup/recovery procedure.

Final decisions: minimal opt-out/logging/session-lock fixes testable; aggregate
quota, retention, assessment and global provider protection remain PARTIAL/BLOCKED.
Code Lab host isolation remains BLOCKED. R7: REQUEST CHANGES.450-user readiness:
UNVERIFIED. No production action or merge.
