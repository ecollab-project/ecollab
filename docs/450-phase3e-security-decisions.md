# Phase 3E: R7 outage, origin and private-delivery decisions

Date: 2026-10-22. Decision: **REQUEST CHANGES** for R7 and production integration.
450-user capacity: **UNVERIFIED**. No production access or changes were performed.

This is a tested upstream proposal and a security decision record, not a release
that closes the three remaining blockers. It intentionally changes no application
payload, browser bundle, deployed broker configuration, database schema or media
server binary. The opaque-hint experiment is confined to a disposable test router.
An incomplete privacy mitigation must not be promoted as a confidentiality fix.

## Verified source and stack

GitHub was reread before editing. All four PRs were open, draft and unmerged, with
these exact relationships (base SHA equals preceding head):

| PR | Head SHA | Base |
|---|---|---|
| 69 | `287d1192122ad8731f6bfe352729bebe85c2f5f9` | 68, `5e2fa5b57226ae07b6a02560d89ac436019b8b28` |
| 70 | `8679d81dd575f828f12f42cd4820808fe0066475` | 69 |
| 71 | `38900c09fbfd59599ed30905a8a5f9255b27dfe3` | 70 |
| 72 | `127fd08231861cd62be72dbc16d31a473794511f` | 71 |

All eight returned PR-triggered workflows at the exact PR72 head passed:
39783185289 (UI), 39783185113 (delivery), 39783185146 (timeline),
39783185117 (notifications), 39783185065 (uploads), 39783185102 (real media),
39783185023 (backend), 39783185120 (Settings/Jarred). The connector returns only
the first page of PR-triggered runs. PR72's earlier description still said 7/8;
the current workflow result, rather than that stale description, is the evidence.

This branch starts exactly at PR72. New branch CI must be checked separately;
PR72 results cannot be presented as new-branch CI. The Actions run for this
branch records its own immutable head and both integration job logs.

Pinned upstream source inspected:

- Centrifugo 6.9.6: `f2e575ffe83c62d9b277602bfcce40cf0c2dc9fb`.
- LiveKit 1.13.7: `8d11efdfcd4220092b6ac7b8a21af28526da5a6b`.
- Custom broker experiment: Go 1.26.8, application client Centrifuge 5.7.4,
  real Nginx 1.24, PHP-FPM 8.3.6 with ten workers, synthetic SQLite policy data
  locally. The existing CI broker suite additionally uses disposable MariaDB.
- Previous real-media results use LiveKit 1.13.7, browser SDK 2.22.3,
  Chromium 141 and MariaDB 10.11.14. These are **prior evidence**, not fresh
  production observations. See `450-phase3d-realtime-validation.md` and its JSON.

## Findings and exact boundaries

| ID/severity | Evidence | Status |
|---|---|---|
| E1 / P1 | Upstream `internal/proxy/refresh_handler.go`, `RefreshHandler.Handle`: proxy errors return `ExpireAt = now + 60`, repeatedly. `deploy/centrifugo/config.json` has a one-second timeout but no transport-error fail-closed control. | CONFIRMED; application release UNRESOLVED |
| E2 / P1 | `RealtimeRevocationService::reconcile`, `scripts/realtime-revocation-worker.php`: policy, singleton DB lock, ListRooms/ListParticipants and removal/disconnect APIs are external dependencies. Failures are retried/logged; they do not stop existing SFU forwarding. | CONFIRMED; hard media bound UNRESOLVED |
| E3 / P1 | `LiveKitAdmissionService::authorize` checks before proxy acceptance; `deploy/phase2/livekit-admission.conf.example` protects four routes only at that ingress. Phase3D actual SDK replay bypassed that ingress using a reachable fixture origin. | CONFIRMED in fixture; deployment verification BLOCKED |
| E4 / P1 | `RealtimeOutbox::dispatch`: `recipients()` returns a snapshot; `CentrifugoService::broadcast` subsequently publishes `kind`, target/message/event IDs without current resource policy. A committed revoke between those operations is not fenced. | CONFIRMED; private delivery UNRESOLVED |
| E5 / P1 | Resource-free prototype still produces a post-revocation packet correlated with private activity. A stable global event ID would additionally permit cross-inbox correlation; the prototype uses per-recipient HMAC IDs. | Explicit IDs MITIGATED only in experiment; activity timing UNRESOLVED |

These are release blockers, not claims that production is publicly reachable or
that every ordinary request fails. No fresh runtime inventory was collected.

## ADR E1: require successful authorization renewal

The released broker's error branch is deliberately availability-oriented. Its
source comment says it gives the connection another minute and tries again.
Repeated failures allow indefinite renewal without a successful policy read.
New admission failing closed does not terminate an already accepted connection.

Supported HTTP status transforms were already tested in Phase3D: HTTP503 can
be converted to disconnect, but a callback transport timeout still extends the
connection. A response transform cannot handle the absence of a response.
Polling and API disconnect also fail when their DB/API/worker dependency fails.
Short JWTs are not a replacement for current policy or an established-session
lease. Do not restore the retired JWT verifier or private history.

**Tested proposal:** `tests/proposals/centrifugo-6.9.6-fail-closed.patch` changes
only the upstream refresh transport-error reply from `ExpireAt=now+60` to
`Expired=true`, and updates its corresponding upstream HTTP/gRPC regression.
It preserves cancellation handling, successful renewal and explicit denial.
`tests/centrifugo-fail-closed-proposal.py` checks the two original source hashes,
copies the explicitly supplied source to a temporary directory, applies the
patch, runs **all upstream proxy tests**, builds a distinctly versioned binary,
and tests actual SDK connections through actual application callbacks. The
production binary and configuration are never replaced. This is not a supported
configuration flag, upstream acceptance, or approval to maintain/deploy a fork.
The patch retains the upstream Apache-2.0 source attribution in the copied files.

Observed locally with a five-second fixture expiry and one-second deadline:
HTTP503 closes the old connection around five seconds; a transport timeout
closes it around six seconds. Exact final measurements are in the evidence JSON.
The client moves to `connecting`, not necessarily terminal `disconnected`.
Admission during outage remains denied; restored callback plus still-revoked
policy remains denied; restored authorization reconnects and HTTP retrieves the
original persisted message. A missing policy table also denies and recovers.
That table-failure test is **not** a database-daemon failure test.

**Limit:** this narrow experiment is not a complete policy-lease implementation.
A successful but stale/late allow response, unchecked excessive `expire_at`,
in-flight admission after a revoke, process scheduling pauses, publication
queues, and resource revocation within an otherwise valid account inbox remain
outside its guarantee. No universal elapsed-time maximum was established.
Broker API outage does not disable this renewal path, but this phase did not
repeat the entire worker/API fault matrix against the custom binary.

**Proposed release contract, requiring security/product approval:** authority
issues a bounded, resource/session/epoch-bound lease with a policy-check timestamp;
broker/SFU independently caps its deadline, never extends on error, rejects
replayed older epochs and late allow responses, and stops protected forwarding
at expiry. Every acceptance/renewal path, including reconnect, must obey it.
Candidate staging lease: 30 seconds, proposed enforcement tolerance two seconds;
these are requirements to approve and test, **not measured limits or an SLA**.
Measure database commit acknowledgement, lease issue/expiry, last server enqueue,
last received data/media, and disconnect separately on a consistent monotonic
clock. Clock uncertainty and pre-expiry network buffers must be reported.

Availability cost: an authorization outage disconnects legitimate users too.
Reconnect backoff/jitter, fail-closed admission, bounded callback concurrency and
recovery tests are mandatory. Do not silently switch back to cached allow/JWT.
A maintained upstream option with a capped lease is preferable to this unilateral
patch. A custom broker requires its own security/support/version-upgrade review.

## ADR E2: SFU enforcement must survive management-plane failure

LiveKit 1.13.7 `pkg/service/rtcservice.go`, `RTCService.RegisterRoutes` registers
`/rtc`, `/rtc/validate`, `/rtc/v1`, `/rtc/v1/validate`. `pkg/service/auth.go`
verifies issued credentials at admission. `RoomManager.refreshToken` uses the
participant's retained grants to issue refreshed tokens; its periodic token
refresh is not an eCollab policy callback. In the inspected deployment/code there
is no SFU-owned eCollab policy-lease deadline stopping RTP on failed DB/worker/API
access. This statement concerns the inspected paths, not all possible products.

Phase3D already demonstrated actual continuing encoded/decoded private media
while the worker stopped, the policy table failed, the real MariaDB daemon
stopped, or RoomService returned503. SDK token expiration and signaling timeouts
did not stop that media. Repeating eviction after recovery is useful mitigation,
but cannot establish a bound while the removal API is unavailable.

**Decision:** do not add a PHP watchdog, shorter JWT, or more frequent SQL polls
and call it a hard lease. Keep active-media bounded revocation **BLOCKED** pending
an SFU-supported hook/upstream implementation or an independently reviewed media
enforcement architecture. A watchdog that must call the same failed API is not
independent. Killing an SFU through an external supervisor requires availability,
permissions, partition/failure and restart/replay analysis; it is not implemented
or validated here and must not be an automatic production action.

Testable upstream requirement: actual publishers AND subscribers lose protected
forwarding after a capped policy lease without RoomService/DB/worker access;
loss/recovery, suspended/removed account, every room type, old SDK-refreshed tokens,
multiple tabs, signal reconnect/full reconnect and delayed renewal replies must
be tested with real decoded audio/video. Prohibit indefinite cached grants. Test
both send and receive; muted publishing alone does not revoke subscription.
Until implemented and measured on an actual SFU this requirement is NOT RUN.

## ADR E3: staging origin-isolation proof

The Nginx example is not evidence of effective deployed isolation. A valid token
is a bearer credential on an exposed LiveKit listener. CORS, DNS naming and an
Origin header do not isolate an origin; non-browser clients can connect directly.
No production DNS/listener/container/firewall/certificate state was inspected.

Prepare an explicitly authorized staging manifest recording commit, component
versions, full effective route map, bind addresses, network namespaces, container
ports/mode, security-group/firewall rules and TLS certificate names. Redact keys,
tokens and request arguments. Then collect the following **from independent
external IPv4 and enabled IPv6 clients**, plus an untrusted sibling/container
network. Do not point this procedure at production without separate authorization.

| Surface | Required staging proof |
|---|---|
| HTTPS/WSS ingress | Valid TLS, authorized actual SDK voice/video; revoked retained and SDK-refreshed tokens rejected before upstream on all four routes, initial/full/signal reconnect. No credential-bearing URI logs. |
| Direct IPv4 / direct host IP | Enumerated node/public/backend signaling addresses and ports cannot establish an SDK signaling connection from untrusted clients; retained valid JWT negative control must join a deliberately exposed disposable listener first, proving the probe works. |
| IPv6 | Enumerate binds and routed addresses separately; prove deny on enabled interfaces, or record disabled/non-routed evidence. IPv4 denial alone is insufficient. |
| Docker | Review actual published bindings, including IPv6 and automatic rule paths; test each host mapping. A private-looking compose file is not evidence of actual container configuration. |
| Host networking | No Docker NAT boundary exists; prove host listener binding and effective ingress restrictions. Loopback isolation does not protect against trusted-host compromise/SSRF. |
| Alternate DNS/SNI/backend | Enumerate every applicable hostname and proxy listener; no catch-all, default virtual host, alternate listener or backend route may forward signaling without admission. Unknown names must fail. |
| RoomService | Management endpoints only through an explicitly trusted management path with least-privilege JWTs; test unauthenticated/participant-token denial. The current Nginx example proxies RoomService, so do not claim management network isolation merely because LiveKit verifies its API token. |
| UDP / ICE / TURN | Test actual publication/subscription, UDP/TCP fallback and TURN relay separately. Restrict signaling/management without blindly blocking required media/TURN connectivity. These ports are not interchangeable. |
| Gate failure | DB down, FPM unavailable, callback timeout and invalid token fail closed before upstream; existing-media lease is a separate E2 gate. |

A failed HTTP probe or closed primary hostname is not full SDK/origin proof.
Record timestamp, source network, destination/surface, component configuration
hash, expected denial, actual connect result and sanitized server disposition.
Production readiness remains **BLOCKED** until this deployment evidence exists.
No staging network was provisioned and no firewall/DNS/host configuration changed.

## ADR E4: private metadata requires a delivery boundary

The new controlled fixture splits the actual `RealtimeOutbox::recipients` lookup
and actual `CentrifugoService::broadcast` call into two private test endpoints.
It retains the selected IDs, commits a channel-membership deletion via SQL,
then publishes that exact snapshot to a real broker and existing real client.
This is a deterministic test of the vulnerable boundary; it does not claim to
pause the production `dispatch()` method or exercise its whole transaction/ACK
loop. Existing durable-outbox rollback/retry/ACK tests remain in their suites.

For both variants, fresh recipient lookup returns zero and HTTP read returns403.
The retained snapshot nevertheless delivers. Restoring access retrieves the same
synthetic persisted message; neither notification experiment deletes it.

| Alternative | Benefit | Remaining problem / decision |
|---|---|---|
| A: opaque, resource-free invalidation | Prototype carries only constant type/version and per-user HMAC-SHA256 event ID; retries are stable, no resource/message/sequence IDs or message text. | Triggered delivery still reveals timing of private activity. Production client/load-runner require coordinated format and HTTP catch-up changes. Not adopted alone. |
| B: delivery authorization fence | Shared authoritative epoch/order at the broker enqueue boundary can reject revoked recipients and discard obsolete queued work. | Requires all revocation writers, all publishers, reconnects, retry queues and broker delivery paths to share the order. Another SQL check followed by publish has the same race. Not implemented. |
| C: combined | Reduce payload linkage plus enforce current resource authorization at enqueue. | Preferred direction, conditional on a demonstrated fence and an accepted definition of revocation timing; not a completed patch. |

The prototype uses a test-only key and a domain-separated event/recipient HMAC.
Its real publication retry is verified identical. It is not a browser codec
migration, a cross-recipient cryptographic audit, or a tested 450-recipient batch
implementation. Application JS still expects the existing format; deploying just
this prototype would break notifications. Do not copy the fixture route into an
application endpoint or reuse its fixed test key.

A DB lock around a remote publish cannot retract already queued socket data;
locks across every writer and slow remote calls add availability/deadlock risk.
A client filter cannot erase information already delivered. A private per-user
inbox authenticates the account, not the resource grant represented by each hint.
Server-disconnect is not sufficient for resource-only revocation when legitimate
account access and other channels must remain available.

A fenced design must specify linearization. Proposed property: after an accepted
revocation fence for resource epoch E, no server publication may enqueue a
resource-dependent packet to that recipient under E or an older epoch. Pre-fence
in-flight packets may arrive later and must be measured separately. If product
requires zero observable private activity immediately after database commit,
this stronger requirement needs a cross-system commit/enqueue design or traffic
independent cover notifications. Do not redefine the requirement silently.
Fixed-rate cover traffic can conceal timing only if independent of private events;
naively waking authorized HTTP fetch on every tick could overload FPM and is not
an acceptable unmeasured workaround.

Acceptance requires deterministic selection/revoke/publish tests for channel,
DM/group and private Code Lab events; worker retries and publish-before-ACK crash;
revocation-before-acceptance; event-order permutations; database and broker outages;
no private history or cross-user IDs; current HTTP authorization and preserved
messages/grades. The present channel test establishes the residual leak, not PASS
for this confidentiality requirement.

## Results and evidence limits

| Test | Result / evidence basis |
|---|---|
| Released real broker + Nginx/FPM + actual application callbacks | PASS locally; account/session/origin/inbox/publish/reconnect/HTTP recovery and SQL-free ordinary pings preserved. |
| Controlled channel revoke between selection/publication | Vulnerability reproduced locally, raw IDs exposed; opaque prototype removes IDs but still delivers activity-correlated packet. PASS as a regression reproducer, FAIL as a release confidentiality gate. |
| Upstream proxy package with experimental patch | PASS locally after correcting an unused test import; all package tests run. |
| Custom broker HTTP503 / transport timeout / schema failure | PASS locally for closing the old connection, denied re-admission and authorized recovery. Not proof of a universal lease/SLA. |
| Existing real MariaDB worker, API failures and actual DB-daemon/media outages | Prior Phase3D PASS for reproduction/recovery; CI suites retained. No new production observation. |
| Real media compatibility / actual origin SDK bypass | Prior Phase3D evidence; existing CI job remains. No new TLS/TURN/external/IPv6 proof. |
| PHPUnit Unit | PASS, 25 tests / 56 assertions. |
| PHPStan | FAIL — existing baseline: exact same 13 path/message diagnostics, zero introduced, no new suppressions. Application PHP is unchanged. |
| Full local DB PHPUnit | NOT RUN this phase; previous guard refusal documented in Phase3D. Focused native-MariaDB CI remains authoritative for those fixtures. |
| SFU hard lease, zero post-fence private timing leak, deployment origin isolation | BLOCKED / NOT VERIFIED, not PASS. |

Initial experimental runs failed on an unused Go import and inherited Node test
mode; both test defects were corrected and the complete proposal rerun. No test
was removed, and the original allow/deny/unavailable log assertions remain.
Machine-readable local final measurements and fixture hashes are recorded in
`450-phase3e-evidence.json`. New-branch CI is reported against its exact commit in
the PR and final handoff, without substituting earlier-head results.

## Performance and release gates

No SQL is added to heartbeats, no application polling is introduced, no additional
queue scans or media-packet checks are implemented. The upstream patch changes
only error handling. It adds no successful-renewal query, but outage disconnects
can amplify reconnection/callback load. The released-broker 16-client local
sample remains synthetic, not a VPS benchmark; log exact observations in JSON.
No production CPU, memory, network or media-capacity measurements were collected.

Modeled callback load at 450 connections is 7.5 renewals/s at60s or15/s at30s,
excluding sessions/tabs, joins, retries, resource policy and media lease work.
Those arithmetic means hide synchronized bursts, query cost and ten-worker FPM
saturation. They do not justify changing the existing ten-worker baseline.
Measure p95/p99 callback latency, query count, active/queued FPM, CPU/PSS, DB waits,
broker/SFU enqueue deadlines, outage retry rate and recovery under bounded,
separately authorized staging tests. Do not run a production load test.

Before release: approve confidentiality/timing semantics and lease targets;
resolve E1/E2 with supported, audited enforcement; prove E3 from actual topology;
implement/test E4 across every writer/publisher; retain private-history denial,
HttpOnly session admission and authoritative HTTP catch-up; rerun exact-head
PHP/database/messaging/security/media CI and review version-pinned artifacts.
No experimental fork or opaque codec may be deployed from this PR.

Rollout, only after separate authorization and gates: validated backups/recovery,
pinned mutually compatible app/client/broker/SFU artifacts, synthetic staging
policy matrix, failure/lease/origin checks, then coordinated cutover with health
and authorization telemetry. Preserve durable outbox rows and message data;
never drain by dropping records. On rollback, do not reinstate retired JWT
admission, private hint history or direct origin access. Disable the optional
broker path and retain authorized HTTP recovery where validated; stop accepting
protected media if its enforcement guarantee cannot be maintained, rather than
claiming an insecure older version is safe. Verify this operational choice and
client fallback in staging before using it. This report executes none of it.

Final decisions: authorization improvements are partially verified; bounded
revocation **UNRESOLVED**; origin isolation **BLOCKED**; private hint confidentiality
**UNRESOLVED**; production integration **REQUEST CHANGES**; 450 users **UNVERIFIED**.
Independent migration025, isolated Code Lab execution, and AI quota/retention
policy remain separate release blockers.
