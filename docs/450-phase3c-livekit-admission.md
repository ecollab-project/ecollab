# Phase 3C follow-up: online LiveKit admission

2026-10-22. Isolated branch above PR #69 head `287d1192122ad8731f6bfe352729bebe85c2f5f9`. No production changes, deployment, migrations, restarts, provisioning, load tests or merges. **R7 remains partially resolved; integration/deployment REQUEST CHANGES; 450-user readiness UNVERIFIED.**

## Confirmed defect and corrective boundary

P1 — `services/RealtimeRevocationService.php::reconcile()` removes unauthorized participants but cannot invalidate their issued self-hosted LiveKit tokens. `LiveKitService::issueRoomToken()` and `issueNamedRoomToken()` issue ten-minute signed join tokens. Removing membership after issuance therefore needs an admission check as well as removal of existing participants. This is documented in PR #69 and LiveKit's token-revocation reference; no production exploit was executed.

`LiveKitAdmissionService::authorize()` verifies canonical JWT encoding, HS256 signature, issuer, integer expiry/not-before, participant identity, room-join grant and current database room policy. It reuses PR #69's account/channel/group/call checks; chat mute retains voice access, while server suspension/ban and inactive/deleted accounts deny access. The existing CLI synthetic-media fixture namespace is retained only for signed fixture metadata, matching synthetic student identity/email, current private fixture-server membership and voice policy. Unknown room namespaces fail closed.

`requestToken()` matches LiveKit's Authorization-header precedence over query credentials; duplicate query tokens are rejected. A token is never trusted merely because PHP can decode it. No session, student content or browser-supplied user ID supplies identity.

`API/internal/livekit-admission.php` accepts only a non-HTTP FastCGI parameter set by the internal Nginx location. Spoofing an HTTP header with the same name cannot set it. Denied tokens return 403; database/configuration failures return 503 without exception details. Nginx auth_request maps unexpected upstream status/transport errors to a closed 500 response.

`deploy/phase2/livekit-admission.conf.example` is **uninstalled staging configuration** for a dedicated LiveKit HTTPS server block. It gates `/rtc`, `/rtc/validate`, `/rtc/v1` and `/rtc/v1/validate`, including WebSocket upgrades and reconnects. It forwards original arguments through a separate variable to the internal FastCGI subrequest. RoomService server API calls retain LiveKit's own server-grant checks. Other paths deny by default. Media/TURN ports are separate from the signaling HTTP origin.

## Validation actually performed

| Check | Result / scope |
|---|---|
| PHP syntax, three added PHP files | PASS |
| `tests/livekit-admission.php`, SQLite | PASS: issuer-generated tokens, header precedence, duplicate/forged/malformed/expired/wrong-user/wrong-room tokens, channel/server/group/call/account revocation, moderation semantics, synthetic fixtures, database failure |
| Same admission test, isolated local MariaDB 10.11 | PASS with native prepared statements; empty disposable schema guard |
| `tests/livekit-admission-ingress.py` | PASS: actual Nginx 1.24 and PHP-FPM 8.3, all four routes, synthetic upstream WebSocket 101, revoked reconnect denied before upstream, internal location and HTTP-header spoof denial, database/FPM outages fail closed |
| Existing R7 authorization suite | PASS, including active-connection removal and inference fencing |
| Existing Phase 1 access matrix | PASS, 200 role/membership combinations |
| Existing Phase 2 LiveKit snapshot suite | PASS; simulated snapshot-reader tests are not capacity measurements |
| PHPUnit Unit suite | PASS, 25 tests / 56 assertions |
| PHPStan | FAIL — existing baseline: same 13 diagnostics; no added diagnostics/suppressions |
| Real LiveKit engine, SDK media reconnect and token refresh | NOT RUN; required on a pinned isolated staging version |
| Public/direct origin bypass, network boundary | NOT VERIFIED; cannot establish from repository code |
| Production runtime | NOT RUN; no production access used |
| New-head GitHub CI | Pending publication; report exact head separately in PR |

The ingress fixture uses a synthetic HTTP/WebSocket media upstream, not LiveKit, WebRTC publishing/subscribing or TURN. It establishes Nginx/FastCGI admission behavior only. CI now runs SQLite/MariaDB policy tests and a separate disposable Nginx/PHP-FPM ingress job without skipping failures.

## Mandatory staging and deployment gates

1. Pin actual LiveKit server/SDK versions and compare supported signaling, validation, refresh and reconnect paths with this allowlist. Source reviewed from upstream `pkg/service/auth.go` (blob `d6122dde7081f1cdc476b6d95c240dd90ac76907`) and `rtcservice.go` (blob `bff1151d7420554ba1b8702bab547193ceaaf106`); mutable upstream source is not evidence of the deployed version.
2. Review this server block against the full effective Nginx configuration. Replace application path/FPM socket. Verify auth_request support, application environment and separate media/TURN connectivity. Do not paste it into production during this review.
3. Prove that **all** public signaling paths use the gate: direct IP/port, IPv4/IPv6, alternate names, Docker port publishing, load balancer backends and future listeners must not expose an ungated origin. Bind or network-isolate signaling before any rollout; no firewall change is authorized here. A bypassable gate does not resolve token replay.
4. Run actual supported browser/SDK joins, both generations of signaling where supported, token refresh beyond the original token lifetime, reconnect, multi-tab, DM/group and private-channel cases. Test already-issued/refreshed tokens after each revocation. Strict expiry may require SDK refresh compatibility work; do not relax current-policy checks to hide a reconnect failure.
5. Pair admission with PR #69's healthy singleton participant-removal worker. Existing streams do not invoke this gate until reconnect. Measure removal latency, missed scans, stopped-worker detection and media/broker/DB outage behavior. No instantaneous or five-second revocation guarantee is established.
6. A revocation can commit between the admission SQL read and LiveKit accepting the participant. This gate is not an atomic revocation barrier. Define and validate the permitted exposure/SLA; stricter requirements need engine-side enforcement. Database outage denies new joins, but does not automatically terminate existing media.
7. Exercise reconnect storms only on separately authorized staging. Each admission adds current-policy queries and a PHP-FPM request; this uses the existing ten-slot pool and has no measured 450-user performance result. Heartbeats and media packets acquire no new SQL checks.
8. Do not log query tokens/Authorization headers. This sample disables access and error logs on credential-bearing ingress locations because Nginx error context may include request URIs. Before rollout provide sanitized status, latency and rejection-count metrics, FPM/DB health alerts, and proxy failure monitoring without raw arguments; do not enable debug/request logging as a workaround.
9. Retain authoritative HTTP catch-up and existing durable data. No database migration or data deletion is involved. Roll back an unsuccessful gate deployment by restricting affected media admission under an approved operational plan; removing the gate while leaving an exposed signaling origin reopens stale-token replay. No such rollout/rollback is executed here.

## Remaining broker admission work and recommendation

Centrifugo's old connection JWT still permits manual reconnection. A connect proxy is called only for connections without a JWT; adding one while retaining the old JWT verification key is insufficient. A separate minimal integration must coordinate browser connect credentials, broker verification configuration, authenticated backend connect/refresh proxies, reconnect HTTP catch-up, outage handling and rollback. Test a malicious client supplying an old JWT directly, not only compliant browser reconnects. Avoid introducing a new proxy architecture without real-broker tests.

**Recommendation:** retain the draft stack and REQUEST CHANGES for release readiness. This patch supplies a tested ingress implementation, not verified production enforcement or closure of all R7 risks. Migration-025 compatibility, Code Lab execution isolation, R6 policy and R8/R9 remain separate workstreams.

Primary protocol references: https://docs.livekit.io/frontends/reference/tokens-grants/ ; https://github.com/livekit/livekit/blob/master/pkg/service/auth.go ; https://github.com/livekit/livekit/blob/master/pkg/service/rtcservice.go ; https://centrifugal.dev/docs/server/proxy .
