# Phase3AB security approval matrix — proposals only

Accepted investigation reference:5a03656b27904498917c16cbfa320187c6da8c84. No application/security fix,legacy transition,merge or deployment is authorized. This document specifies reviewable choices;it does not infer approval from investigation acceptance or a green workflow.

| Decision | Bounded recommendation | Exact proposed surface | Required separate approval / consequence |
|---|---|---|---|
| A. Persisted OTP clock |Explicit UTC_TIMESTAMP for generation/expiry,created/used,invalidation,verify/count/purge;configuredOTP_EXPIRY;strict expiry |security/forgot-password/OtpService.php generate,verify,countValid,purgeExpired;tests/docs |Approve UTC DATETIME representation,strict `expires_at > UTC_TIMESTAMP()` and explicit creation writes. Preserve bcrypt,uid/action/error/session/API policy;no global timezone change |
| A2. created_at default |Keep existing default for this narrow explicit-writer patch,document that all supported generators must supply UTC;reject reliance on default as a UTC guarantee |Same service INSERT;current ensureTable default CURRENT_TIMESTAMP unchanged |Approve explicit-writer invariant only after verifying every writer. Changing fresh-table default alone creates old/new parity gap;existing-default migration is separate and remains unapproved |
| B. Legacy active and used rows |Coordinated invalidation at a stopped-writer/verifier cutover;exact retention option must be chosen |Proposed OTP-table-only data transition,not an executed script or migration |Approve outstanding-code re-request,used-history/forensic retention,all readers/writers,cutover and recovery. Unknown DATETIME cannot be converted safely.600s waiting is insufficient |
| B2. Versioned compatibility |Alternative only with proven origin/version and isolated readers/writers |Potential version/epoch columns or isolated active/legacy stores |Schema migration,dual reader/writer protocol and retention/removal approval required. No provenance currently established;unknown legacy rows cannot be preserved as valid by guessing |
| C. Single-use consumption |Conditional consume-and-check after bcrypt;recheck unused and expiry atomically;only affected-row winner returns success |OtpService::verify;AuthService contract reviewed but no change presently proposed |Separately approve single-use behavior and failure/retry policy. UTC-only approval is not atomic-fix approval. Original andUTC-trial10/10 pairs issued two reset tokens |
| D. Signup marker/PHP boundaries |No change in this scope |AuthService andmiddleware unchanged |Independent verified-marker age policy/PHP epoch equality changes require separate product/security approval |

## A — narrow clock contract

The isolated trial keeps600/120-second validity under the four tested timezone combinations and PHP/database timezone changes. It is CLI-only and not installed into FPM/API;its syntax/native execution passed while existing PHPStan excludes tests. Those facts do not approve a production patch.

Every persisted timestamp consumer must share UTC semantics. Expiry remains strict at the second boundary;session pending-login/signup and reset-token PHP epoch lifetimes remain as currently implemented. Ordinary password login's current OTP-disabled API policy,CSRF,rate limits,authorization,hashing,roles andAPI/error strings remain unchanged. No forced new marker TTL or whole-app/DB connection timezone configuration change.

The current created_at DATETIME default is CURRENT_TIMESTAMP. Explicit UTC generation makes service-written rows UTC,not omitted-column/manual/unknown writes. The proposed minimum choice keeps the default unchanged and documents an explicit supported-writer invariant;it cannot be approved until all actual writers are known. If an invariant cannot be established,review a separately versioned/default-migration design. No production inventory was inspected here.

## B — legacy record scope, retention and UX

| Option | Existing unused expires_at | Existing created_at/used_at | UX / rollout / rollback |
|---|---|---|---|
| Coordinated clearing of active otp_codes |All old codes become ineligible and require re-request |Clearing the entire table also deletes used hash/metadata history;explicit retention/forensic approval required |Stop every issuer/verifier anddrain in-flight calls;transition andall UTC readers/writers together. Do not restore invalidated rows on rollback |
| Coordinated inactivation plus preserved history |Remove old rows from every active lookup/count/purge |Separate approved history store/access/retention;do not reinterpret unknown timestamps |Additional schema/storage/isolation review;ensure old processes cannot read history as active. No storage/migration choice is implemented |
| Versioned compatibility |Preserve only origin/version whose time interpretation is proven |Version-aware retention/cleanup must handle both formats correctly |Cannot infer origin from current server zone. Old readers of a shared table can still misread newUTC rows. Unknown rows remain ineligible or require approved invalidation |

Phase3AA private experiments show:UTC reader still accepts old Manila-format expiry beyond nominalTTL;owned-actor deletion prevents reuse;transaction rollback restores unsafe rows;an old writer after cutover reintroduces incompatibility. These are disposable fixtures,not an operational invalidation rehearsal or table-wide production execution.

Separate state remains separate:already-issued PHP reset tokens keep their current600s policy;authenticated sessions,session-only pre-signup codes and verified signup markers are not silently revoked or extended by this proposal. Pending optional-login/legacy-signup intents do not resurrect removed codes. Re-request UX must follow actual caller policy:forgot-password can begin again;legacy resend requires a valid pending intent;internal optional-login callers need a verified re-entry path. Ordinary API login must not gain an OTP rule merely to provide a new UX.

Before cutover,approve scope,retention choice,stop/drain controls,reader/writer inventory,clock-skew/clock-step policy and fail-closed criteria. On failure keep OTP traffic paused. Do not assume DDL/data/code rollback is one transaction. After any code invalidation,never restore those codes as active. If rolling back new writers,prevent old clock writers from issuing incompatible rows;use separately approved fresh invalidation/version isolation or roll-forward. No transition command is executed or authorized here.

## C — atomic consumption alternatives and proposed guarantee

Current path:SELECT valid unused uid/action row → bcrypt verify → UPDATE used_at byid →success. Two independent connections can select/hash before either updates. The same-session test cannot establish cross-session single use;ten independent-session native rounds reproduced two reset-token issuances,includingUTC trial.

| Approach | Guarantee / costs | Failure / retry / scope |
|---|---|---|
| Conditional consume-and-check |After correct bcrypt,UPDATE selectedid with uid/action,used_at IS NULL,andcurrent strict expiry. Only affectedRows===1 may return success;rechecks expiry if bcrypt crossed deadline |Minimal method change,no lock held while hashing. A loser returns existing generic expired/not-found error and receives no reset token. DB error throws/returns existing safe failure,no token. No automatic retry or unconsume |
| Transactional SELECT FOR UPDATE |Serialize row lookup/hash/consume in a transaction with expiry recheck |Holds DB lock during bcrypt;must handle deadlocks,timeouts,isolation,existing transactions and retries. Broader coupling and contention;not required to establish narrow single-use property |
| Dedicated consume/token transaction |Bind consumption to a durable reset-token record oridempotent receipt |Potential schema/API/token-storage/lifetime redesign;requires broader explicit security and rollback approval,not an incidental clock change |

Recommended bounded guarantee for separate approval:for a single valid stored OTP id,at most one competing verification may report successful consumption and reach AuthService reset-token issuance. Perform bcrypt on selected hash,then a conditional UPDATE with uid/action/id,unused and strict current expiry predicates;check actual affected-row count exactly1 before returning success. If UTC approval exists use its UTC clock consistently;an atomic-only patch using the old clock would still retain the timezone defect and cannot be called fully OTP-safe. No SQL is implemented by this document.

Regeneration that invalidates the old row between SELECT andconsume must cause the old consume to lose. Wrong uid/action/hash must not mutate state. A code expiring during hash verification must not succeed. Losers must not alter used_at or clear a different actor's intent. Replays remain rejected. PDO affectedRows behavior must be verified with native/emulated settings used by actual deployment;NULL→timestamp is a real change for the winner. Conditional updates with native prepares/typed bindings must not invent a new row-order tie policy.

Existing AuthService can fail after a winning consumption due to user checks,DB updates,audit/session operations or process failure. Minimum single-use proposal is fail-closed:do not unconsume or automatically retry the same code;user requests a new code under approved UX. It does not guarantee exactly-once completed login/token delivery across process crashes. Ambiguous timeout/disconnect after consume is failure,not permission to mint another token. A durable/idempotent issuance redesign would be separate scope. Avoid wrapping unrelated API/session logic or nested transactions without review.

Required future candidate tests:equal-start original/trial failures retained;authorized atomic candidate must produce at most1consumption and1reset-token issuance for two/many distinct sessions/connections;same-session behavior;sequential replay;wrong user/action/hash;exact/after expiry;expiry during bcrypt;regeneration during verification;DB errors/locktimeout/deadlock/ambiguous connection loss;failure after successfulconsume;and unchanged session/API/error contracts. No new concurrency capacity claim.

## Approvals and exclusions

A,A2,B andC are distinct decisions. Approval of one does not approve another,migration or production execution. No schema/default/data change,authentication policy change,merge,deployment,real mail,Hostinger or capacity certification is authorized. The recommended sequence after independent review is explicit decisions first,then only approved isolated correction(s),then exact-base regression and actual-candidate HTTP/browser/concurrency validation and independent security acceptance. Do not merge aUTC-only patch and call OTP secure while consumption FAIL remains.
