# Phase3AA decision package — review required, no production authorization

## Proposed contract, not approved behavior

Use the database UTC clock for every shared persisted OTP timestamp: `DATE_ADD(UTC_TIMESTAMP(),INTERVAL configured_OTP_EXPIRY SECOND)` for expiry; `UTC_TIMESTAMP()` for created_at,used_at,invalidation,verification/count and 24-hour purge comparisons. Preserve strict `expires_at > UTC_TIMESTAMP()`; exact expiry is invalid. Maintain configured OTP_LENGTH, bcrypt cost10, trim behavior,uid/action selection,error strings,current regeneration/delivery/transaction semantics and API responses. Do not change PHP epoch intent/token boundaries,ordinary password-login OTP policy,CSRF/rate limits,session rotation or verified-signup marker lifetime.

One proposed application file: `security/forgot-password/OtpService.php`, methods generate,verify,countValid,purgeExpired. No application file was modified. The isolated implementation is `tests/experiments/phase3aa/UtcOtpTrial.php`: a CLI/testing/owned-DSN guarded subclass explicitly required only by test helpers and injected into real AuthService for native experiments. It is not in application bootstrap,API/FPM routes or Composer configuration. Application HTTP/browser tests use unchanged original service,not the trial. It deliberately preserves SELECT-then-UPDATE consumption; there is no atomic fix.

The trial inherits current lazy table creation: created_at default CURRENT_TIMESTAMP remains session-local if a writer omits explicit creation time. All inventoried service writes explicitly supply created_at. The proposal does not silently approve unidentified/manual writers using that default. Before an application patch,choose whether to leave the default with an explicit-writer invariant,change it for fresh tables only,or separately migrate existing defaults. The trial establishes explicit service-write semantics only; no schema/default conversion is performed. A fresh-table default change without changing existing tables would create parity differences and must not be claimed complete.

## Compatibility and transition choices

| Decision | Coordinated invalidation | Versioned compatibility |
|---|---|---|
| Unknown old DATETIME zone | Cannot safely reinterpret; remove from active verification after approved cutover | Cannot preserve unknown provenance merely by adding a version column |
| Required scope | Every persisted action:reset_password,2fa,legacy verify_email; all writers/readers and reconnection paths | Reliable origin/version metadata,old/new reader-writer isolation,explicit legacy reader semantics |
| Existing expires/created/used | Resolve all three,including already-used retention records | Assign valid provenance before reading/cleanup; unknown rows remain ineligible or separately handled |
| User consequence | Outstanding persisted codes require re-request; no silent extension | Proven compatible codes might survive,but unsupported rows still require re-request |
| Schema | UTC DATETIME service change alone needs no column migration; data transition still required | Additional version/epoch/provenance columns or isolated stores/readers require separately approved migration |
| Mixed deployment | Stop every OTP writer and verifier during coordinated switch; old writer can reintroduce unsafe rows | A shared table with old readers is unsafe without proven isolation; no blanket rolling-release claim |
| Recovery | Pause traffic on failure;do not restore invalidated codes to service | Version-aware rollback/removal required;do not resurrect used codes |
| Present decision | Recommended planning path,awaiting explicit scope/retention approval | BLOCKED until trustworthy provenance and operational design exist |

**Concrete option for review:** with all OTP writers/verifiers stopped,clear the entire active otp_codes table (including already-used rows) at a coordinated UTC writer/read cutover. This avoids guessing all historic expires_at/created_at/used_at representations and purge ages. This is a proposed OTP-only data action,not an authorized SQL instruction or an executed operation. It deletes OTP hash/metadata history and therefore requires explicit retention/forensic approval. An alternative must preserve historical rows outside active lookup/purge with separately approved storage/access/retention and schema design. The private test deletes only one disposable actor's rows; it does not execute or certify this table-wide cutover.

Do not infer origin zone from a current connection,created_at date,server default or assumed location. Waiting600s does not retire the observed29400s legacy comparison window. The private experiment shows the UTC trial reader still regards an old Manila-format row as valid at simulatedTTL+1,that transaction rollback restores it,and an old writer after cutover recreates incompatibility.

Pre-signup session-only codes,verified signup markers,authenticated sessions and already-issued PHP reset tokens are separate state. This proposed persisted-table invalidation leaves their existing lifetimes unchanged; do not silently revoke or extend them. Pending login/legacy signup intents may remain but cannot make removed codes valid. Review the actual originating re-request UX for each caller:forgot-password can request again;legacy resend requires its existing valid pending intent;ordinary login API currently disables OTP and must not gain a new policy merely to implement re-request. Internal optional-login callers need their own verified re-entry procedure. This phase does not certify such UX or production traffic topology.

## Failure, rollout and rollback design

1. Before any future implementation,approve exact clock/strict-boundary semantics,affected file/actions,created_at-default choice,legacy active/used record treatment,retention impact,re-request UX and writer/reader coordination. Inventory actual web/CLI/worker processes and deployment controls from separately authorized environment evidence; production topology is unknown here.
2. A future isolated application patch must retain original defect controls alongside corrected UTC matrix. Require matching schema/code hashes and tests under PHP/database zone changes,configured TTLs,wrong user/action/hash,consumed codes,cleanup,NULLs and delivery errors. A global application timezone/configuration change is excluded.
3. Planned operational sequence,not execution:stop every OTP issuer/verifier,confirm no in-flight consumption or old writers,perform approved transition,install all UTC readers/writers,verify real clock/schema/default behavior,then resume. Any DB error or partial rollout keeps OTP traffic paused; do not expose mixed old/new readers or automatically reinterpret rows.
4. Before transition failure,keep traffic paused and diagnose. After invalidation,do not restore old rows to serve codes. After new issuance,a rollback to old clock code can misinterpret UTC rows and reintroduce faulty generation:pause traffic,choose approved fresh invalidation/version isolation and roll-forward or reviewed rollback. Restoration for forensic purposes must remain outside active verification. Do not promise reversible issuance or resurrect invalidated/used OTPs.
5. Aggregate monitoring:clock/UTC/zone parity,issuance,destination delivery outcome,expired/wrong/replayed attempts,reset-token issuance and cleanup counts; never record OTPs,tokens,session IDs,cookies,CSRF or identities. Operational alert thresholds,clock-step tolerances and productionTLS/proxy checks need separately approved acceptance criteria; none are invented here.

Existing failed-delivery behavior is preserved:forgotPassword can report success despite transport failure;old code is invalidated and new unused row persists. Native comparisons reproduce the behavior for original and trial. It is not fixed or newly accepted as a security/UX contract. Generation invalidation+insert and verification SELECT+UPDATE remain separate operations; changing their transaction semantics requires a separately reviewed design.

## Separate high-priority atomic-consumption decision

The original and UTC trial were exercised by two real PHP processes,independent PDO connections and distinct sessions. A file start gate releases both before the method call;there is no pause,query replacement or lock inserted inside SELECT/hash/UPDATE. AuthService reset verification actually executes in each child. Worker outputs retain only booleans,connection IDs,timing and error contracts;codes/tokens/session fingerprints stay private or are removed before evidence output.

Observed multiple successful reset-token issuances in a round are **FAIL for single-use safety**,not a passing security test. Equal-start fixtures demonstrate a feasible competing-call outcome,not production incidence,HTTP exploitability or defense readiness. They do not estimate load capacity. UTC clock changes intentionally do not resolve consumption atomicity. Any conditional UPDATE/row-count check,transaction/locking redesign or reset-token issuance change needs separate explicit authorization and its own concurrent/malicious-session tests. Do not fold it into the clock patch silently.

Verified signup marker TTL remains a separate product/security choice. No new TTL or PHP equality change is proposed for implementation here.

## Approval and defense-baseline gate

No application correction,transition,atomic fix,production configuration,merge,deployment or Hostinger operation is authorized. This is a reviewable decision package only. Required separate approval must explicitly cover clock representation/semantics,exact file/actions,legacy unused/used/default treatment,UX/retention consequences,and rollout/rollback. Atomic consumption needs its own approved scope. Phase3AA independent acceptance alone grants none of these permissions.

For the November3 defense,seek an independently accepted baseline by the user's October30 gate. If a fix cannot be implemented and independently validated safely by that date,exclude unresolved OTP-dependent demonstrations where practical and document the limitation. Do not force an unvalidated patch into the demo. No configuration/functionality exclusion has been implemented by this phase;such demo selection is a separate decision. Broader security release,capacity and production parity remain unapproved.
