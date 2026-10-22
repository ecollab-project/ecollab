# Phase 3C: Centrifugo stale-JWT admission repair

2026-10-22. Isolated branch above verified PR #70 head `8679d81dd575f828f12f42cd4820808fe0066475`. PRs #68–#70 were open, draft and unmerged when checked. No production command, secret rotation, migration, restart, deployment, provisioning, merge or load test was performed. **450-user readiness remains UNVERIFIED. R7 is not fully closed.**

## Root cause and threat model

P1, CONFIRMED BY CODE AND ISOLATED RUNTIME — the previous `CentrifugoService::token()` issued five-minute connection and inbox-subscription JWTs. `RealtimeRevocationService::reconcile()` could disconnect an account, but the broker's HMAC verifier accepted its existing JWT again without consulting current account state. The baseline test deactivated a synthetic account in SQLite, disconnected the actual v6.9.6 broker client, then successfully reconnected and subscribed using the same connection and subscription tokens.

Attacker: a student retaining their own old JWTs, attempting reconnect after account revocation, injecting a different user/inbox through connect data or emulated headers, or reusing another account's subscription credential. Untrusted browser inputs never establish identity. A compromised trusted host/root process, broker API key, PHP session store or proxy secret is outside this credential-replay fix. The private callback still authenticates its caller even on loopback.

## Corrective implementation

- `services/CentrifugoProxyService.php::authorize()` derives the identity from the current PHP session, checks the exact configured Origin and current `AccessPolicy::account()` state, and assigns only `inbox:user<session-id>` server-side. Connect data, user headers, requested channels and metadata cannot grant access. Refresh compares the broker's authenticated user with that same session and rechecks current account state. Active admissions use one account-policy query.
- `API/internal/centrifugo-proxy.php` requires a non-HTTP internal FastCGI marker, loopback source, POST and a constant-time-checked independent proxy secret of at least 32 characters. It validates the real session cookie and releases the session lock before SQL. No emulated identity or cookie headers are accepted. Errors disclose no SQL, credentials or student content. Failed account/session refresh expires the connection; DB errors expire refresh and deny new admission.
- `deploy/centrifugo/config.json` enables connect/refresh callbacks, forwarding only transport Cookie/Origin headers. Emulated headers are empty. The broker has **no JWT verifier**, private inboxes have no history/recovery or client publish permissions, and subscriptions are assigned by the authenticated backend. The browser does not request connection or subscription tokens.
- `proxy-entrypoint.sh` launches Centrifugo with a clean environment, passing only PATH, the server API key and authenticated callback headers. Inherited HMAC/current/previous/RSA/ECDSA/JWKS verifier settings and insecure overrides cannot restore parallel JWT admission. Tests deliberately poison inherited HMAC/insecure settings. A deliberately modified configuration file remains an operator-controlled risk and must be reviewed.
- `deploy/centrifugo/compose.yaml` uses Linux host networking with the broker HTTP listener explicitly bound to `127.0.0.1:8001`, allowing access to private Nginx callbacks on `127.0.0.1:8002`. This changes network isolation and requires staging review; it does not authorize any production networking change. CPU/memory caps remain limits, not capacity measurements.
- `nginx-proxy.conf.example` is an uninstalled private callback server. It sets the internal FastCGI marker and event, forwards cookie/Origin, bounds request body and timeouts, and never exposes the callback through the public WebSocket location. Its sanitized access log records timestamp, path, HTTP status, auth outcome and timings; no arguments, cookie, secret, identity or message body. Raw error logging is disabled on this credential-bearing callback server.
- `tools/chat-delivery/index.js` consumes top-level server-side inbox events and preserves event-ID deduplication, visibility/online synchronization and authoritative HTTP catch-up. `modules/chat/chat.php` bumps the bundle version. `API/auth/realtime-token.php` now returns 410 after existing authentication/CSRF checks; `CentrifugoService::token()` cannot issue JWTs. A legacy constructor parameter is retained only for internal call compatibility and is never used for JWT authentication.
- `configure-centrifugo.php` prepares an independent proxy secret while retaining the existing server API key and leaving the old application JWT secret untouched. Broker environment output contains no JWT key. Disable works before migration without generating credentials. `deploy-centrifugo.sh` now fails before any mutation: the old automatic JWT installer cannot safely coordinate this migration. The old `CHAT_DELIVERY.md` instructions are explicitly historical.

## Version compatibility

PASS — actual Centrifugo **v6.9.6**, Go **1.26.8**, downloaded from the official release. Linux-amd64 archive SHA256: `af50fec73b5fba0630bcce6685ed096f5841fd428aa55f820108174c44a7b347`, verified against the release checksum before execution. `checkconfig` accepted the proposed configuration. The fixture exercises real connect/refresh proxies, transport Cookie/Origin forwarding, static callback headers, server-side subscriptions, disabled JWT verification and protocol ping/pong behavior on this exact version.

NOT VERIFIED — production image/digest/version. The supplied `centrifugo/centrifugo.9.6` string is malformed; the repository template uses `centrifugo/centrifugo:v6.9.6`. A template and an isolated release binary do not establish what production runs. Docker image/launcher operation is also covered separately by the Chat delivery CI workflow; final exact-head CI is recorded in the PR.

## Authorization and regression matrix

All runtime results below use synthetic fixtures and actual pinned broker, Nginx and a ten-worker PHP-FPM pool, with SQLite session/account/resource fixtures.

| Requirement | Result | Evidence / intended behavior |
|---|---|---|
| Reproduce old-token reconnect | PASS | Legacy configuration accepts both old connection and cached inbox JWT after deactivation + disconnect |
| Normal connect and private inbox | PASS | Real PHP session authenticates; backend assigns only that account's inbox |
| Old connection JWT after revocation | PASS | Rejected; also rejected while account is active, proving verifier retirement |
| Old subscription JWT / cross-user inbox | PASS | Cached token cannot authorize another inbox; an inactive account cannot establish the prerequisite connection |
| Direct unauthenticated connect | PASS | Missing session, forged connect user/meta and emulated cookies denied |
| Callback impersonation | PASS | Missing/wrong callback secret denied; public direct script/header spoof denied; refresh user/session mismatch expires |
| Optional delivery disabled | PASS | New session admission is denied when the backend delivery flag is false |
| Origin checks | PASS | Wrong/empty Origin denied; only real transport headers forwarded |
| Disabled/deactivated, deleted, suspended, global ban | PASS | Current account policy denies admission; active disconnect and account refresh recheck tested |
| Server ban / member removal / private grant removal | PASS | Own account inbox remains valid; actual outbox audience excludes private hints and authorized HTTP read denies resource |
| View-only suspension / chat mute | PASS | Own inbox/read preserved; existing R7/200-case access matrix retains read/voice/edit/write distinctions; moderators gain no private read |
| Browser publish | PASS | Real broker denies client publication |
| Active disconnect / reconnect | PASS | Terminal account disconnect works; subsequent session admission rechecks policy |
| Normal refresh and logout | PASS | Actual refresh uses original cookie/Origin; logout/invalid session cannot refresh or reconnect |
| Database failure | PASS, limited | Missing policy-table fixture: new admission denied; established connection expires through callback; recovery reconnect reauthorizes. MariaDB network delays/outages are NOT RUN |
| Broker callback timeout | PASS | Synthetic 1.5-second handler delay exceeds the configured one-second deadline; new admission denied |
| Proxy HTTP-503 failure | PASS, limited | New admission denied; existing connection was still connected after 6.5 seconds due broker refresh retries. No bounded eviction guarantee |
| Actual callback transport stopped | PASS, limited | Nginx stopped in fixture; new broker admission denied. No claim about maximum existing-connection lifetime |
| Private-hint replay / durable messages | PASS | No replay of missed hints; authorized HTTP catch-up returns the unchanged persisted message; browser queued/paginated reads and deduplication retained |
| Ordinary protocol heartbeats | PASS | Accelerated real ping/pong creates no additional proxy callback during the measured no-refresh window; existing Ratchet suite passes 1,850 SQL-free heartbeat/frame checks |
| Proxy logs | PASS | Fixture log records allow/deny/unavailable and excludes synthetic API/proxy secrets and session cookie |
| Production / representative 450-user workload | NOT RUN | Not authorized by this task |
| Real LiveKit media / origin isolation / eviction SLA | BLOCKED | Separate staging gate; not supplied by passing broker tests |

`tests/centrifugo-proxy-regression.py` owns all disposable processes and fixtures, verifies the pinned binary and executes `centrifugo-broker-regression.cjs`. `centrifugo-proxy.php` covers the backend matrix and query counts; `centrifugo-config.py` tests configuration migration in a temporary copy only. Existing token-endpoint, browser, fanout, R7, Phase 1 access, LiveKit admission and PHPUnit regressions also pass locally. PHP syntax passed for 311 files; PHPUnit passed 25 tests / 56 assertions. PHPStan retains exactly the same 13 existing baseline diagnostics with no introduced diagnostics or added suppressions. CI adds the real ingress/broker suite without suppressing existing tests.

## Reconnect performance observations

The bounded local sample uses **16 connections, concurrency 8, ten fixture FPM slots, one synthetic session and SQLite**. It is not a 450-user test and does not represent the two-vCPU VPS or MariaDB connection cost. Each admitted connection made exactly one account-policy query; no heartbeat SQL was added. Final local sample: connect p50 **11.367 ms**, p95 **14.384 ms**, mean callback handler **1.489 ms**, **16 queries**, **zero errors**. The single deadline probe was rejected at **1008.30 ms**. Exact values are recorded in `450-phase3c-centrifugo-evidence.json`. These small-sample percentiles are not stable capacity estimates. Handler timing excludes fixture PDO setup, Nginx queuing and broker work; end-to-end connect latency includes that path.

MODELED: at the default 60-second refresh interval, 450 active connections would average 7.5 session/account refresh checks per second, before reconnects. Required FPM occupancy is approximately admission rate multiplied by **measured staging service time in seconds**, plus ordinary application demand. The ten-worker limit can queue reconnect bursts. A broker timeout does not cancel PHP, session-lock waits or database work already running; configure/test those bounds separately on staging. Database connection establishment, real busy-worker memory, two-vCPU contention, queue peaks, sustained timeout rates and sustained 450-user behavior remain NOT RUN. Do not change production FPM limits or infer headroom from the small local sample. Client backoff remains in place.

## Future coordinated rollout and secure rollback — NOT EXECUTED

1. Obtain separate staging/deployment authorization. Validate backups/recovery, exact stack ancestry and deployment paths. Preserve all messages/outbox rows. No database migration is required by this patch. Do not merge the draft stack simply because CI is green.
2. On isolated staging, verify the actual image digest/version, session handler/path/name and account cookie settings. Callback FPM must use the same session storage and application environment as ordinary HTTP. Review Linux host networking, loopback listeners and the full effective Nginx/broker configuration. Verify the static callback secret is private and that public routes cannot reach broker API/callbacks.
3. Prepare backend code, generated browser bundle/version, private callback configuration and independent proxy secret. Retain the API key; do not casually rotate production secrets. The old JWT application secret may remain stored for controlled retirement, but must never enter the new broker verifier configuration. The legacy automatic installer intentionally refuses this migration.
4. During an approved coordinated cutover, first restrict the public broker WebSocket ingress and stop the old broker, closing its existing connections. Merely disabling new token issuance does not retire cached JWTs. Do not run old JWT admission and new proxy admission in parallel. Ordinary authorized HTTP/Ratchet behavior must already be verified as the temporary fallback; do not assume it.
5. Install reviewed backend/private Nginx/FPM configuration and prepared broker environment; start only the reviewed launcher/configuration. Reject any startup log showing a JWT verifier. Inspect effective command, mounted config, loopback bindings and overrides without printing secrets. Run actual staging probes proving cached connection and subscription JWT rejection, normal current-session admission, logout/refresh, origin/cross-user denial and no hint history.
6. Coordinate the PR #69 revocation worker with broker API access; verify singleton ownership, account cursor coverage and service health. The proposed 60-second refresh is a periodic check, not immediate revocation. Measure actual active-removal latency and stopped-worker/DB/proxy/broker failures before assigning an SLA. A backend 503 or network outage may leave established connections alive while the broker retries.
7. Enable the public WebSocket route and versioned browser only after those gates pass. Reload stale clients: old token-based tabs are intentionally incompatible and must not trigger restoration of the old verifier. Test multi-tab delivery, reconnect/restart recovery, authorized HTTP catch-up, queues draining and persisted-data correctness. Do not enable private hint history to recover missed events.
8. Monitor sanitized callback allow/deny/unavailable counts and p95/p99 timings, broker connect/disconnect/refresh errors, FPM queue/active workers, DB connections/latency, outbox backlog and revocation-worker health. Establish log rotation and access controls. Never log cookies, Authorization, connect data, student messages or raw callback bodies.
9. If cutover fails, close broker ingress, stop the new broker and disable optional broker delivery while retaining data and the reviewed R7 authorization code. Recover through verified authorized HTTP/Ratchet paths or keep the affected transport unavailable. Reopening JWT verification, restoring old private history or reverting authorization checks is **not** an acceptable rollback. Reattempt only with a reviewed forward correction.

## Remaining risks and recommendation

- Current session/account authorization and broker admission are not one atomic transaction with revocation; an administrative change can race an already executing check. Existing packets/queued hints cannot be retracted. Delivery boundaries continue to reauthorize, but no zero-exposure guarantee is claimed.
- Broker refresh transport errors can retain existing connections; the 6.5-second observation is not a measured upper bound. Active-revocation timing, outages and operational enforcement remain release gates. New admission never falls back to JWTs on proxy failure.
- Production session-storage compatibility, network exposure, effective configuration, coordinated migration and resource cost remain unverified. A root/operator changing the configuration or bypassing the reviewed launcher can defeat these safeguards.
- Real LiveKit admission/refresh/media and active eviction, migration 025, Code Lab isolation, AI quota/retention and representative 450-user staging tests remain separate outstanding workstreams.

**Recommendation: draft review of the focused stale-credential fix; REQUEST CHANGES for complete R7/integration deployment readiness until operational and LiveKit gates pass.**

Primary references: https://centrifugal.dev/docs/server/proxy ; https://centrifugal.dev/docs/server/server_subs ; https://github.com/centrifugal/centrifugo/releases/tag/v6.9.6 . Current web documentation can describe newer releases; compatibility conclusions above rely on the actual pinned release tests.
