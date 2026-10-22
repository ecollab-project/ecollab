# Phase 3D — real media and revocation validation

Date: 2026-10-22. Scope: isolated synthetic fixtures and GitHub Actions only.
Production was not accessed, modified, restarted, migrated, deployed or load-tested.
No pull request was merged. No production-capacity claim follows from this work.

## Decision

**R7 remains REQUEST CHANGES for release.** Real SDK media and normal revocation now have integration evidence, and one confirmed RoomService defect is corrected. Existing connections/media can outlive revocation during enforcement outages. Origin isolation has not been verified on a deployment.

| Verdict | Result |
|---|---|
| Authorization correctness | PASS for tested current-policy ingress/resource cases; not a proof of every deployment route |
| Revocation timing | MEASURED in small fixtures; no finite outage exposure guarantee established |
| Production deployment readiness | BLOCKED by origin verification and an agreed, tested outage enforcement policy |
| 450-user capacity readiness | UNVERIFIED |

## Reviewed stack and evidence identity

GitHub metadata and local ancestry confirmed these open, draft, unmerged PRs:

| PR | Head | Base |
|---|---|---|
| #69 | `287d1192122ad8731f6bfe352729bebe85c2f5f9` | #68 head `5e2fa5b57226ae07b6a02560d89ac436019b8b28` |
| #70 | `8679d81dd575f828f12f42cd4820808fe0066475` | #69 head |
| #71 | `38900c09fbfd59599ed30905a8a5f9255b27dfe3` | #70 head |

PR #72 targets #71's branch, `security/r7-broker-reauthorization-20261022`.
Measured code commit, run/job identities, component versions, timing bases and raw sanitized measurements are in `450-phase3d-evidence.json`. The PR description records final-head CI separately. Earlier-head success is not substituted for current-head CI. GitHub's connector returns the first page of PR-triggered runs.

## Environment and limitations

The disposable native-process fixture uses pinned LiveKit **1.13.7**, application SDK **2.22.3**, actual Chromium through Playwright **1.56.1**, Nginx, PHP 8.3-FPM with **10 fixture slots**, and MariaDB 10.11.14. Exact observed versions are in the evidence. The real broker is **Centrifugo 6.9.6**, official checksummed binary, with Centrifuge JS **5.7.4** and actual Nginx/FPM session callbacks. The LiveKit archive SHA-256 is `6634aeeb2fb1366b6723708ae4320b9d5408106a4c63457c5e845ae3979c90e2`; broker archive SHA-256 is `af50fec73b5fba0630bcce6685ed096f5841fd428aa55f820108174c44a7b347`.

All database tables, accounts, messages, devices and credentials are synthetic. MariaDB runs from an explicitly expendable datadir, binds loopback, and the harness owns its lifecycle. The fixture does not load production migrations or production configuration/secrets. At most two media participants are active simultaneously. Fake camera/microphone inputs pass through **real browser WebRTC encoding, publication, SFU forwarding, subscription and decoding**; they are not mocked SDK calls or token-only benchmarks.

Local LiveKit startup was **BLOCKED** by `route ip+net: netlinkrib: operation not permitted`; Chromium itself launched. No sandbox permissions were relaxed. The actual LiveKit matrix therefore runs on disposable GitHub Actions infrastructure. Its CPU/memory readings are not VPS measurements. HTTP/WS loopback is used in the fixture; TLS, public network behavior, NAT and TURN are not validated.

## Findings and corrective patch

### F1 — P1: empty RoomService requests were malformed — FIXED

**CONFIRMED BY RUNTIME and CODE.** `services/LiveKitService.php::roomRequest()` encoded the empty PHP array passed by `RealtimeRevocationService::reconcile()` as `[]`. Actual LiveKit 1.13.7 rejected `ListRooms` with HTTP 400 / `malformed`; `{}` returned HTTP 200. Before correction the real worker logged incomplete reconciliation and the participant stayed connected. The room-count collector already passes `['names'=>[]]`, which encodes as a valid JSON object; it is not shown affected by this defect.

The minimal patch encodes only an empty request body as `new stdClass()`. Nonempty request bodies and JWT grants are preserved. `tests/livekit-room-api.py` verifies actual PHP cURL serialization against a mock receiver; the real server test independently reproduces both wire forms and then proves actual worker eviction. No schema or token format changes are introduced.

### F2 — P1: broker callback transport failures do not enforce a hard lease — OPEN

**CONFIRMED BY RUNTIME and upstream code.** In pinned Centrifugo `internal/proxy/refresh_handler.go::Handle`, a refresh transport error returns a new expiry 60 seconds in the future. Repeated errors can repeat this extension. Neither the configured refresh interval nor `client.expired_close_delay` proves a finite authorization lifetime in that path.

An established revoked connection remained connected for a 75-second observation with two failed refresh callbacks. Restoring the callback did not trigger immediate reauthorization; closure followed a later scheduled retry. The measured roughly 125-second total observation is **not a maximum lifetime**. Its timer starts after deactivation/control requests, not at the database commit.

The actual CLI singleton worker can disconnect the account through the broker API during callback outage. The test also confirms that broker API failure or inability to read policy prevents this independent enforcement. Manual `--once` dispatch timings are not a five-second polling SLA.

### F3 — P1: active media persists when enforcement cannot reach policy/API — OPEN

**CONFIRMED BY RUNTIME.** `services/RealtimeRevocationService.php::reconcile()` needs current database policy and RoomService calls; `scripts/realtime-revocation-worker.php` retries or exits after loss of its connection/lock. Current ingress checks affect new signaling requests; they do not reauthorize every RTP packet.

Stopped-worker, policy-table failure, actual MariaDB daemon failure, and RoomService HTTP-503 cases are distinguished in the matrix/evidence. Revoked participants can remain connected and receive increasing private media bytes while removal is unavailable. Recovery plus a functioning worker removes them. A short original JWT expiry does not terminate an already-established media connection; this is separately exercised with a three-second fixture token and supported SDK token refresh.

There is no finite exposure guarantee while the required enforcement dependencies remain unavailable. A healthy-worker timing sample must not be promoted to an outage SLA.

### F4 — P1 deployment gate: reachable origin bypasses the online gate — OPEN

**CONFIRMED in the fixture; production exposure NOT VERIFIED.** All four guarded routes reject a retained revoked token. The same token is accepted through the intentionally reachable native LiveKit origin, including an actual SDK join after removal. Reconciliation must evict that participant again. Host-header changes through the guarded ingress do not remove authorization.

Loopback binding is a local deployment choice, not independent proof that every external route is isolated. Production IPv4/IPv6 listeners, Docker publishing/host networking, alternate DNS names, reverse-proxy listeners and any direct/upstream ingress remain unverified. Do not infer them from the Nginx template or this native-process fixture.

### F5 — P2: delayed selected recipients are not an atomic delivery fence — OPEN

**CONFIRMED BY CODE and a controlled runtime reproduction.** `services/RealtimeOutbox.php::dispatch()` selects current recipients before calling `CentrifugoService::broadcast()`. Fresh recipient lookup after revocation returns zero in the test, and authorized HTTP reads deny access. A synthetic server broadcast representing a recipient selected before commit still reaches an established revoked inbox during callback outage.

The tested payload is a hint containing a synthetic resource ID, not student message contents or grades. This reproduces the broker delivery boundary; it is not a claim that the production outbox was paused at that instruction. It demonstrates why current recipient filtering does not establish an atomic post-commit metadata-delivery guarantee. Treat private activity/resource metadata as part of the policy decision; stronger confidentiality requirements require an explicit delivery fence or a less revealing notification design.

## Actual LiveKit/SDK compatibility matrix

PASS means the stated fixture behavior was observed, including negative cases; it does not mean an insecure behavior is acceptable for release.

| Case | Result / scope |
|---|---|
| Private voice join and audio subscription | PASS — actual received RTP |
| Bidirectional video/audio | PASS — decoded video frames and received bytes increase |
| Private-channel authorization / retained-token replay | PASS — guarded old-token admission denied after revocation |
| Server membership removal, account suspension, server ban, server suspension, deactivation | PASS — denied gated replay and actual removal |
| DM group / one-to-one call | PASS — actual media and current membership/call-state revocation |
| JWT expiry and refresh | PASS — original three-second token denied after expiry; established SDK resumes using refreshed credentials |
| Reconnect / network recovery | PASS — browser offline/online plus SDK signal/full reconnect injection; not a real WAN outage |
| Simultaneous same-account tabs | PASS — second tab takes over shared `user-ID` identity; first disconnects, preserving existing identity semantics |
| Four signaling paths | PASS — actual server source registers `/rtc`, `/rtc/validate`, `/rtc/v1`, `/rtc/v1/validate`; retained revoked token denied on each |
| Direct origin | BYPASS REPRODUCED — actual SDK retained-token join when origin is reachable |
| Periodic worker / multiple revoked participants | PASS — actual singleton CLI worker removes both |
| Worker stopped / missed cycles / restart | PASS characterization — old connection/media persists; recovery worker removes it |
| Policy-table failure | PASS characterization — admission fails closed; active media remains until enforcement recovers |
| Actual MariaDB daemon stop/restart | PASS characterization — live media persists; new signaling denied; old worker exits; explicitly restarted worker removes revoked participant |
| RoomService HTTP 503 and recovery | PASS characterization — old private media persists while API removal fails; retry after recovery removes participant |
| Public TLS / TURN / cross-host UDP / IPv6 media / cluster restart | NOT RUN |
| Production origin/Docker/alternate listener isolation | NOT VERIFIED; release BLOCKED |

Authorized ordinary HTTP GETs to signaling WebSocket paths may return 404 without a WebSocket upgrade; v1 validation without its required join request returns 400. Those responses are not admission failures. The actual SDK and denied-token matrix exercise the supported signaling behavior. The route audit records only URI and status, never query strings, tokens or bodies.

## Real Centrifugo outage/revocation matrix

| Case | Result / scope |
|---|---|
| Retired connection/subscription JWTs | PASS — old baseline replay reproduced; reviewed launcher rejects JWT admission |
| Current cookie/Origin and server-assigned inbox | PASS — cross-user subscriptions/publish and spoofed headers denied |
| Logout, deactivation, suspension, deletion, global ban | PASS — current callback policy and active disconnect exercised |
| Server/channel resource revocation | PASS — fresh outbox recipient exclusion and authorized HTTP denial; account inbox semantics preserved |
| Callback schema failure | PASS — explicit backend expiry removes old connection; recovery reauthorizes |
| HTTP-503 transport failure, baseline config | EXPOSURE CONFIRMED — connected after 75 seconds and multiple retries |
| Callback recovery | MEASURED — later refresh closes connection; no immediate recovery callback assumed |
| Actual CLI worker while callbacks fail | PASS — independent broker API disconnect succeeds when DB/API are available |
| CLI broker API unavailable / policy unavailable | ENFORCEMENT FAILURE CONFIRMED — existing connection survives; recovery permits removal |
| Delayed preselected private hint | DELIVERY CONFIRMED — fresh outbox filtering does not fence an earlier selected recipient |
| Supported `refresh.http.status_to_code_transforms` experiment | HTTP 503 closes old connection at next fixture refresh; SDK can enter `connecting` rather than terminal `disconnected` |
| Same status transform during a transport timeout | EXPOSURE CONFIRMED — still connected after 6.5 seconds; no HTTP status exists to transform |
| Callback Nginx actually stopped | PASS — new admission denied; no JWT fallback |
| Private history / unauthorized catch-up | PASS — no private hint history; authoritative HTTP rechecks access |

The HTTP-503 transform is an **isolated tested mitigation option**, not a production/default configuration change and not a solution to transport failure. Supported syntax:

```json
{"http":{"status_to_code_transforms":[{"status_code":503,"to_disconnect":{"code":4503,"reason":"Authorization unavailable"}}]}}
```

This belongs under `client.proxy.refresh`. A valid backend `result.expired=true` also closes the connection. Ping/pong and inactivity timeouts do not prove a hard authorization lifetime for an otherwise live revoked client. No retired JWT verifier or private history was restored.

## Measurements and performance safety

Measured source: `1e33bbc777be951fda0d00b01a252ac65949ec15`, [real integration run 39781596880](https://github.com/ecollab-project/ecollab/actions/runs/39781596880) — both `real-livekit` (job 119833962337) and `real-broker` (job 119833961721) passed. The media matrix contains 20 recorded completed scenarios. The runner exposed four logical CPUs; this is not the historical two-vCPU production host.

| Observation | Measured value | Basis |
|---|---:|---|
| Six manual media revocations | 53.19–85.43 ms | DB commit acknowledgement to browser disconnect; private membership/account/server cases |
| Two revoked media participants, running worker | 4,453.46 ms | Commit acknowledgement to last browser disconnect |
| Actual DB outage plus explicitly restarted worker | 6,908.95 ms | Controlled outage duration included; old worker exited 255 |
| Revoked broker connection during HTTP 503 | Still connected after 75,000.33 ms | Two failed refresh callbacks; not a maximum |
| Broker closure after callback restoration | 50,018.85 ms later | About 125,123.46 ms from observation start |
| CLI broker disconnect during callback outage | 48.66 ms | Manual dispatch through fixture to observed completion; not polling SLA |
| HTTP-503 transform experiment | 5,003.75 ms | Old connection closed; SDK entered `connecting` |
| Broker reconnect sample p50 / p95 | 9.36 / 10.81 ms | 16 connections, concurrency eight, one session |

Over the approximately 46.06-second media fixture, sampled daemon CPU core-seconds were LiveKit 1.11, MariaDB 0.10, Nginx 0.05 and FPM below accounting resolution. Sampled peak PSS was 119,452 / 89,708 / 2,670 / 38,582 KiB respectively. Browser and short-lived worker CPU are excluded. There were 209 logged prepared statements in the instrumented policy/worker fixture. These observations cannot size a 450-user VPS.

Exact values and measurement bases are in the evidence file. Healthy manual media cycles have commit-acknowledgement-to-browser-disconnect samples; periodic-worker timing includes polling delay. Broker outage timing starts after control calls; worker broker timing starts at manual dispatch. These are different clocks/scenarios and must not be combined into one SLA.

Worker log counts describe completed callback/request operations, not independent proof that a transport closed; the media fixture disables broker delivery, so its `broker_disconnects` field must not be read as broker evidence. Actual broker and browser state are checked separately.

CPU core-seconds and peak PSS are sampled at 250 ms for owned daemon processes. Short-lived CLI workers/browser CPU are not included in those daemon totals. A sampled zero is below the measurement's accounting resolution, not proof of zero work. Join latency includes SDK negotiation. The total logged prepared-statement count includes policy/worker operations and excludes fixture-control SQL; it is not database throughput.

The broker reconnect sample uses 16 connections, concurrency eight, one synthetic PHP session and ten FPM slots; one account query per admitted connection and zero sample errors are verified. This is not a 450-client workload or a two-vCPU benchmark. Existing 1,850 authenticated heartbeat regression frames remain SQL-free.

**MODELED only:** 450 continuously connected broker clients with a uniformly distributed 60-second production refresh interval imply about 7.5 refresh callbacks/second before reconnects, multiple tabs and retries. Normal channel/DM/group media admission uses two current-policy prepared reads per gated request; SDK validation/reconnects can generate more than one request per user. The worker scans at most 25 rooms and 100 blocked account IDs per cycle, and sleeps five seconds after work; RPC duration and cursor coverage add to the interval. No measured worker-memory, CPU or latency evidence supports changing the production ten-worker baseline here.

## Regression and CI accounting

The new workflow uses disposable Ubuntu runners, pinned/checksummed server/SDK downloads and an expendable MariaDB datadir. Existing tests remain enabled. Successful fixture runs and their exact SHAs/jobs are recorded in the evidence; final PR-head results are separately verified in the PR description.

Local PASS: PHP syntax for the changed service, PHP cURL shape regression, snapshot regression, signed admission suite, R7 authorization suite, 200-case Phase 1 permission matrix, 1,850 SQL-free heartbeat frames, and PHPUnit Unit **25 tests / 56 assertions**. PHPStan: **FAIL — existing baseline**, exactly the same 13 file diagnostics, no introduced finding or suppression. The unrestricted local PHPUnit invocation was refused by its database-name safety guard before DB access; the full DB-dependent PHPUnit suite is **NOT RUN locally**. Native MariaDB focused integration fixtures run in CI.

Initial real CI reproduced F1 before the correction. Test-harness corrections captured application-selected log output, recognized SDK `connecting` after broker expiry, sanitized RoomService records (which contain TURN credentials), and ensured disposable process cleanup. These are not test removals or production behavior changes. Local broker observations from a reused scratch datadir are not used as authoritative full-suite PASS evidence; CI creates fresh datadirs.

## Deployment prerequisites and rollback proposal — NOT EXECUTED

1. Keep the stack draft until the remaining authorization/outage policy and deployment gates are reviewed. Verify exact stack heads and integrate the one-line RoomService fix before relying on revocation enforcement. Preserve the count collector's already valid request object.
2. Validate backups/recovery and the separately required migration-025 compatibility work. This patch has no migration or schema change. Code Lab execution isolation remains a separate prerequisite for untrusted-code benchmarks.
3. Use separately authorized staging with synthetic accounts. Verify effective TLS/FastCGI paths, current session storage, exact broker launcher/config, SDK bundle, cookie/Origin forwarding and private server API credentials. No secret or full environment dump is needed.
4. Establish origin isolation from independent outside-client probes: IPv4, enabled IPv6, Docker publishing/host networking, alternate hostnames, listener inventory and every HTTP/signaling origin. Media/TURN ports require separate protocol-aware review. A browser on the same host can intentionally reach loopback; that is the fixture's negative control.
5. Keep current-state admission enabled on every supported signaling/reconnect path. Coordinate broker cookie callbacks, JWT-verifier retirement, browser rollout and authorized HTTP catch-up; no legacy fallback or private history.
6. Activate at most one supervised reconciliation worker per database only after real ListRooms/ListParticipants/RemoveParticipant and broker-disconnect health checks pass. Monitor last successful sweep, partial failures, cursor coverage, removal age, callback latency/errors and reconnect backpressure; do not infer health from process existence alone.
7. Agree an exposure requirement and enforce it where connections/media live. For a hard bound, require a broker/SFU-owned authorization lease that cannot extend on failed reauthorization, or an explicitly reviewed fail-stop design. The existing worker, an external watchdog, JWT expiry or Nginx read timeout alone is not such a guarantee. A supported upstream fail-closed option or separately reviewed component change must be tested before release; this phase does not introduce a speculative architecture rewrite.
8. Address the selected-recipient delivery race according to the privacy requirement. Consider broker-authorized delivery fencing or opaque, resource-free invalidations plus authoritative reads; client-side filtering is not a security boundary. Do not declare private metadata protected by the current race-prone sequence.
9. Run synthetic smoke tests for each resource/account policy, current and retained tokens, actual RTP, multiple tabs, callback/DB/API/worker failure, reconnect recovery, persisted message correctness and outbox draining. Measure the agreed bound under failure, not just healthy operation.
10. Roll back only to a reviewed secure combination. Preserve the empty-object fix if a rollback would otherwise break enforcement. Never remove the gate or restore JWT admission/history to regain availability. If authorization enforcement cannot be maintained, use the agreed fail-closed feature/service procedure under a separately authorized operational runbook. Preserve queues and data; no destructive drain is proposed.

## Remaining gates and next actions

The tested ordinary-path behavior is substantially better evidenced. The release blockers are **bounded enforcement during DB/API/callback/worker outages**, **verified deployment origin isolation**, and the **private hint delivery-fence requirement**. Public TLS/TURN and representative cross-host media remain untested. Production configuration, deployed commits and VPS resources were not remeasured.

Keep PRs #69–#72 draft; do not merge #67 because later CI is green. Resolve the outage enforcement design and deployment boundary first, then undertake the separate migration-025 repair and dedicated Code Lab isolation work. Only a separately authorized representative mixed-workload staging benchmark can address 450-user readiness.

Primary references: [pinned LiveKit routes](https://github.com/livekit/livekit/blob/v1.13.7/pkg/service/rtcservice.go), [pinned Centrifugo refresh handler](https://github.com/centrifugal/centrifugo/blob/v6.9.6/internal/proxy/refresh_handler.go), [HTTP error transforms](https://github.com/centrifugal/centrifugo/blob/v6.9.6/internal/proxy/transform.go), [official proxy contract](https://centrifugal.dev/docs/server/proxy).
