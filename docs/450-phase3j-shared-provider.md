# Phase3J — shared local-provider execution boundary

Date:2026-10-23 Asia/Manila. No production actions, model load tests, database
migrations, deletion, service changes or merges. This is stacked on PR76, not on
the integration branch or PR74/75/R7.

## Verified parent and scope

PR76 is open/draft/unmerged at `2e65196fca7448155c19519fda61fdda82c6c80d`, based on
`ecollab-collabs` `c838359711a14e9b6eb3f345c4386d184a178b25`. Its five returned CI
workflows passed. A clean worktree was created from that exact parent. Phase3I
opt-out, safe-error, session-release, limiter and retention regressions are retained.

Affected production files: `API/admin/group-recommendations.php`,
`services/OllamaService.php`, new `services/AdminGroupRecommendationService.php`.
Frontend, user roles, CSRF implementation, database schema, quotas, saved messages,
grades, worker counts and deterministic facilitator grouping are unchanged.

## Confirmed findings and evidence

| Finding | Evidence | Correction / residual limit |
|---|---|---|
| P1 CONFIRMED: admin inference bypassed ordinary Jarred lock | Parent admin route raw curl_exec to /api/chat versus OllamaService::requestChat flock | Reproduced with two PHP processes and a held synthetic HTTP backend: admin produced a second backend call while normal Jarred held its lock. Route now uses the shared provider |
| P1 CONFIRMED: admin lacked local-endpoint/proxy/redirect restrictions and standard context/thread/output bounds | Parent raw cURL options omitted them | Common OllamaService validation/options now apply; JSON mode adds no concurrency increase |
| P1 CONFIRMED: grouping silently filtered unknown IDs and could omit candidates or duplicate membership | Parent loops accepted known IDs and dropped invalid/small groups | Dedicated parser rejects invented/string IDs, duplicates, missing candidates, invalid group sizes and incomplete output; canonical names/roles come from DB |
| P2 CONFIRMED: combined grouping input and shared provider response had no application byte ceiling | Parent prompt concatenation and CURLOPT_RETURNTRANSFER | Bounded request/model envelope and capped response callback; excess returns an error, no partial candidates/results |
| P1 DEPLOYMENT GATE: temp file lock is not shared across distinct PrivateTmp mounts | Parent sys_get_temp_dir path; unmerged worker template uses PrivateTmp=true | Explicit existing shared file outside private temp mounts can be selected; no fallback when configured path fails. Legacy unset path retained for compatibility, not certified across namespaces |
| P1 RESIDUAL: client cancellation does not prove model cancellation | PHP finally/OS release guard after HTTP timeout or killed client | Guard coordinates PHP HTTP requests only. Actual daemon parallelism/orphan execution must be verified; no claim of a guaranteed whole-daemon ceiling |

No new production exploit or capacity result is claimed. These fixtures execute
trusted PHP/test code, not student submissions or a language model.

## Provider and grouping architecture

Authenticated admin+CSRF -> bounded JSON request -> existing active-user query
(max80) -> canonical profiles -> AdminGroupRecommendationService::recommend ->
OllamaService::generateJson -> common requestChat guard -> local-only Ollama HTTP
-> completed JSON -> full membership validation -> existing frontend schema.

Ordinary generate(), warmUp() and chatWithTools() also call requestChat. Each HTTP
call is independently guarded; a tool-round loop is not one continuous lease.
No new DB queries, polling loop or database transaction is introduced. File guard
cost is constant per request and not on WebSocket heartbeats. This deliberately
reuses the existing coordination mechanism rather than creating a DB lease for
long-running inference.

`OLLAMA_INFERENCE_LOCK_FILE` selects a pre-provisioned private absolute regular
file, with no symlink, world access or world-writable parent. The opened and named
file must have matching device/inode, and flock acquisition is nonblocking. Bad
configuration or contention returns503 with no private path/error disclosure.
There is no configured-path fallback. All callers must see the same inode and
retain it across requests. Never replace/unlink it while work may be active.

Unset configuration retains the historical temporary path. Consequently merging
this PR alone does **not** close a deployed FPM/CLI PrivateTmp mismatch. The staging
checklist is `deploy/ai-provider/README.md`; its environment example is not an
installer and changes no running service. Multi-host coordination and actual FPM
pool/CLI service environment visibility remain deployment gates.

## Technical resource envelope and compatibility

| Boundary | Result |
|---|---|
| HTTP grouping request | Read at most32769 bytes; reject over32768; bounded JSON depth/type checks |
| Candidate selection | Original active student/facilitator DB query, schema-adaptive fields, latest80 maximum retained |
| Serialized JSON model messages | At most8192 bytes; no candidate/profile/topic truncation |
| Model context | Existing4096 setting retained; byte limit is not an exact token count |
| Output | Existing1024 maximum reused; compact member-ID JSON requested, reasons brief; no automatic splitting or extra model calls |
| Threads | Existing requested1-2 bound retained, not an OS CPU quota |
| Deadlines | Admin at most45s, ordinary default90s; test-only caller may lower but cannot exceed90s |
| Provider response | At most1MiB accumulated; malformed/deep/oversized bodies fail safely and release lock |
| HTTP routing | Fixed /api/chat on server-controlled loopback HTTP URL; no user URL, inherited proxy or redirect |
| JSON completion | format=json, truncate=false, shift=false; require done=true and done_reason=stop; invalid/truncated response rejected |
| Domain validation | Every supplied ID exactly once; each group2..requested maximum; canonical username/name/role; bounded reason/fit strings |
| Failure |422 input/envelope/impossible complete partition;502 malformed/incomplete model groups;503 busy, failed coordination or transport/provider failure; no internal retry |

These are per-operation resource bounds, not hourly quotas, paid access gates or
new retention policies. Input that does not fit fails explicitly instead of
quietly reducing80 candidates to a smaller cohort. A short80-candidate fixture
passes without dropping IDs; realistic long80-profile requests may be rejected.
Group size2 with an odd candidate count cannot completely partition into groups
of at least2 and is rejected, rather than silently excluding one student.

Success schema stays `success/source/model/topic/task/groups/candidate_count`;
each group retains reason/members and each member id/username/name/role/fit.
Legacy object members and compact integer IDs are validated. Compact results
have an empty optional fit string; canonical student information is never taken
from model-supplied names or roles. Existing frontend optional-fit handling remains
compatible. The fewer-than2-candidates database response is preserved without a
provider request. JSON validity/membership completeness does not prove the
model's reasons are true or that a grouping is pedagogically optimal.

Actual Ollama version is UNKNOWN here. Strict completion metadata and
truncate/shift support must be verified on authorized staging. Old servers may
reject or ignore unsupported options; local validation cannot certify that every
input token was processed. This release must not be represented as production
compatible until actual version/context behavior is tested. No context expansion,
concurrency increase or production FPM tuning was performed.

## Tests and namespace evidence

`tests/shared-ai-provider.cjs --baseline` loads the original parent route/provider
from Git into a disposable fixture. It reproduced two simultaneous backend calls:
normal Jarred held its inference lock and admin raw cURL still reached the backend.
The test uses synthetic DB/auth boundaries and a real loopback HTTP backend.

The patched fixture runs actual copied route, provider and grouping service:

- Normal request blocks concurrent admin; reverse order also blocks normal.
- Both requests use the same configured existing lock file despite different
  PHP TMPDIR directories. Invalid/missing configured path fails without fallback.
- Admin/student/anonymous/CSRF behavior is preserved in the synthetic auth boundary.
- All80 short candidates appear exactly once; compact IDs and legacy objects keep
  the response schema; model data cannot override canonical user names/roles.
- Unknown/string IDs, duplicates, empty/incomplete/oversized groups, malformed JSON,
  missing completion, length termination, redirects, oversized responses and
  provider errors fail with no additional model call or private marker disclosure.
- Oversized request/profile evidence is rejected before provider invocation.
- Shortened HTTP timeout and killed PHP worker release the file guard; next
  request works. Idle HTTP worker restart preserves the shared path/contract.
  This is not proof that a real model stopped after the client disappeared.
- Ordinary Jarred also rejects an oversized provider body and recovers afterward.

Local mount-namespace attempt: `unshare --mount --fork true` returned Operation not
permitted. Thus real local mount views are BLOCKED, not PASS. CI adds an explicit
required run with two genuine Linux mount namespaces and different bind-mounted
/tmp views, one HTTP PHP process and one CLI process, sharing the same file
outside /tmp. Namespace marker checks verify each mount view before PHP starts.
This is not an actual installed PHP-FPM/systemd deployment. Exact CI outcome must
be read from the draft PR; it is not inferred from parent CI.

Local ordinary fixture PASS; all Phase3I provider/route/lock/retention tests PASS;
settings-runtime and Code Lab scope policy PASS; existing real-cURL provider
regression PASS; Unit25tests/56assertions PASS; changed PHP syntax PASS.
No actual cloud/local-model inference, production CPU/RAM/latency, OS service
inventory or FPM memory/concurrency measurement was run. Namespace setup/cleanup
and one fixture cleanup defect were corrected before publication; they are not
production changes. All test artifacts contain synthetic data and are removed.

## Policy approval matrix — no decisions activated

| Area | Current behavior | Options requiring approval | Status |
|---|---|---|---|
| Aggregate user AI budget | Two routes share20/hour; DM/facilitator differ; document cooldown/session; Anthropic summary3/channel/hour | One aggregate budget, or documented separate budgets with explicit scopes/costs | BLOCKED |
| Facilitator/admin allowance | Role eligibility; no unified hourly allowances | Equal or explicitly justified role allowances, without paid-tier gates | BLOCKED |
| Failure charging/refunds | Existing admission charged before inference; no new refunds/retries | Keep admitted-attempt charging or bounded idempotent refunds | BLOCKED |
| Provider concurrency | Existing nonblocking local HTTP guard; admin now joins it | Shared path deployment, daemon-wide ceiling, cloud-provider limits and orphan handling | PARTIAL/BLOCKED |
| AI conversation retention | Explicit owner session delete; no reviewed automatic TTL | User-managed or institution-approved per-store expiry | BLOCKED |
| Saved-context retention | Notes persist until changed; memory/personalization govern injection, not erasure | Explicit deletion/expiry and backup behavior | BLOCKED |
| External-provider disclosure | Gemini conversation context; Anthropic channel transcript | Approved notice, processor terms and participant/data handling | BLOCKED |
| Protected assessments | Supplied surface/Referer classifier, not trusted assessment state | Institutional scope plus trusted attempt checks across admitted surfaces | BLOCKED |

No numerical quota, retention period, refund rule or academic content ban was
invented. Concept explanations, study help, existing conversations and Code Lab
scope restrictions remain. R7 private-resource/current-session work is untouched.

## Integration, rollout and rollback

Review stacked PR76+this head together. Preserve its opt-out/privacy tests and
messages/settings; do not cherry-pick route without provider/service dependencies.
No migrations or data changes are needed. Resolve code conflicts deliberately
with unmerged async JarredReplyGenerator/R7. Deterministic facilitator grouping
and external cloud/summary routes are not silently folded into this local guard.

On explicitly authorized staging, establish one shared private inode and effective
FPM/CLI configuration, drain requests during configuration/reboot handling, verify
both contention orders, actual model JSON/context compatibility, longest legitimate
profiles, failure/cancellation and canonical output. Verify no model request
retries after malformed or incomplete results. Keep worker counts unchanged.
Observe aggregate latency/busy/error outcomes without prompt/profile logging.

Rollback preserves a guarded provider boundary or pauses affected admissions;
never restore raw direct admin cURL as an automatic escape. Retain all conversation,
settings, submission and grading records. Lock-file replacement requires a full
drain, not atomic rename while a lease is held. Actual FPM namespace deployment,
daemon cancellation, model quality/latency and longest-cohort capacity remain
release gates even if disposable CI passes.

Verdicts: shared admin HTTP boundary testable; model/deployment readiness NOT
VERIFIED. Jarred governance PARTIAL/BLOCKED; Code Lab isolation BLOCKED;
R7 REQUEST CHANGES;450-user readiness UNVERIFIED. No deployment or merge.

Upstream references (protocol guidance, not deployed-version evidence):
https://github.com/ollama/ollama/blob/main/docs/api.md
https://github.com/ollama/ollama/blob/main/api/types.go
https://github.com/ollama/ollama/blob/main/server/prompt.go
