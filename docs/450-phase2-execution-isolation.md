# Phase 2 A1 — Dedicated Code Lab execution boundary

Status: implementation-ready design; no host provisioned, configuration applied, runner restarted, or production test performed. Reviewed against Phase 1 head `8a2dcfd19db15c10d4711856835d68ef799660db`. This document is a deployment prerequisite, not evidence that hostile code is safely isolated today.

## Repository evidence and decision

| Evidence | Consequence |
| --- | --- |
| `deploy/code-lab/piston/compose.yml` sets `privileged: true` for Piston | Treat a Piston/container escape as compromise of the entire execution VM. Container quotas and namespaces are defense in depth, not the boundary protecting the application host. |
| Piston image already uses a digest; adapter uses `python:3.12-slim-bookworm` | Retain the Piston digest pending compatibility testing. Resolve, review, and record an immutable adapter digest before provisioning; do not invent a digest. |
| Adapter binds only `127.0.0.1:2358`, authenticates `X-Auth-Token`, and does not expose package-management routes | Preserve the adapter contract behind a private gateway. Never publish Piston port 2000 or adapter port 2358 publicly. |
| `CodeLabRunner` supports HTTPS, verifies TLS by default, disables redirects/proxies, and bounds responses to 1 MiB | A private HTTPS gateway can be introduced through the server-owned URL without replacing the runner protocol. Custom mTLS client credentials are not currently supported by this class. |
| `CodeLabExecutionService` owns durable queue claims, immutable submission source, expected-output comparison, weighted scoring, and grade commits | Keep this trusted worker and database on the application side. Do not move the PHP worker or application `.env` to the execution host. |
| Adapter jobs/results live in memory, with bounded queues and retention | Restart loses remote execution tokens/results; database jobs remain authoritative. A restart is not transparent failover. |
| `scripts/setup-code-lab-piston.sh` requires the application `.env`, modifies application settings, and restarts its worker | Do not run this installer on the execution VM or copy its full state directory there. That directory can contain backed-up application credentials. Use a dedicated provisioning procedure below. |

Use a separate disposable VM on a distinct network boundary. The VM contains only Piston, the adapter, pinned runtime packages, a private HTTPS gateway, and narrowly scoped runner credentials. Keep the existing application queue and one or two bounded worker slots. Start staging with one slot; preserve the measured baseline when comparing deployments, and increase nothing based on the 450-student target alone.

## Trust and data flow

1. Browser sends authenticated Code Lab requests to the application. Existing membership, CSRF, quotas, immutable submission and result-owner checks remain on the application.
2. The application CLI worker claims a durable job and sends source plus required stdin to the private gateway. It uses the current `CodeLabRunner` API and a runner-only secret.
3. The gateway permits the application worker network identity, terminates verified TLS, and forwards only allowed adapter routes. Adapter authentication remains required.
4. Adapter selects a frozen runtime and fixed resource limits, then calls Piston over the execution VM's internal container network.
5. Piston runs untrusted source. The adapter returns bounded results. The application validates result structure, computes grades against expected outputs, atomically commits the job/grade, and delivers the existing authorized notification/result response.

The runner necessarily sees hidden stdin during execution. Expected outputs, task ownership, user profiles, database credentials, and grading weights remain application-side. A compromised runner can read submitted code/inputs and forge execution output; a separate VM cannot provide proof of correct execution. Do not claim grade integrity or hidden-input secrecy against runner-host compromise. Limit the blast radius, quarantine suspect grades, and regrade affected submissions on a rebuilt trusted runner with facilitator review. Preserve manual-review scores.

## Network policy

Allocate dedicated private addresses and record them in an operator-only inventory. Example roles below are symbols, not deployable IP addresses. Use a private network or a narrowly routed WireGuard tunnel; do not route the production subnet to the runner. TLS still applies inside the tunnel. The application verifies the private gateway hostname against a trusted CA; install a scoped private CA through normal OS trust management if needed. Never disable certificate or hostname verification.

Enforce these rules at a provider/network firewall outside the execution VM as well as on the guest. Root inside a compromised privileged container may alter guest firewall rules. If the hosting arrangement cannot enforce external restrictions, use a different isolation arrangement before accepting hostile workloads.

| Source → destination | Permitted traffic | All other traffic |
| --- | --- | --- |
| Application worker → runner private gateway | TCP 443; established replies | Denied |
| Admin management network → runner | Private SSH through managed bastion; no student access | Denied |
| Gateway → adapter | Loopback TCP 2358 | Denied |
| Adapter → Piston | Internal container TCP 2000 | Denied |
| Runner → application, database, caches, LiveKit, Centrifugo, metadata endpoints | Established replies for worker-initiated connections only; no newly initiated connections | Denied |
| Submitted program → any network | None, including DNS, IPv4/IPv6, loopback services and metadata | Denied |
| Runner → internet | None during execution service operation | Denied |
| Runner → dedicated infrastructure | Only explicitly approved time/DNS/log destinations if required, separately documented | Denied |

Permit temporary registry/package access only in an isolated image-build/provisioning window with student execution disabled. Prefer importing verified package/image artifacts. Remove that access before acceptance testing. Do not expose Docker, Piston, package installation, host metrics, or gateway diagnostics publicly. Test both IPv4 and IPv6; a rule set for only one is insufficient.

Gateway specification:

- Bind only to the private execution interface; provider firewall also denies public 443.
- Require the application source identity/address and adapter's constant-time token authentication. A stolen runner token alone must not allow internet access.
- Allow only `GET /languages`, `POST /submissions`, `POST /grade-submissions`, and `GET /submissions/<token>`; restrict token path syntax to the existing alphanumeric/hyphen contract. Preserve the runner's existing query parameters, including `wait_ms=1000`.
- Reject other routes/methods, callbacks, redirects and arbitrary upstreams. Never proxy `/api/v2/packages` or other raw Piston APIs.
- Limit request body to 256 KiB; bound headers and header/body read time. Adapter already rejects oversized body/source/inputs; the gateway must reject before buffering large bodies.
- Limit active upstream requests to 8 for the initial one/two-worker deployment, with bounded waiting or immediate 429/503. No automatic retry of POST requests: the present protocol has no idempotency key and a retry could execute twice.
- Use a response timeout above the adapter's 1-second result wait, below PHP's 12-second request timeout; 5 seconds is an initial staging setting to validate. Execution continues asynchronously behind the adapter.
- Disable request/response-body and token-header logging. Log only aggregate method/route-class/status/latency, with no submission token in URLs. Disable proxy caching and response disk spooling; return `Cache-Control: no-store`.
- Keep the runner-only secret in protected configuration, never source control. Rotate by draining jobs, atomically replacing adapter credentials and worker configuration, then restarting only the relevant staging components. The current adapter supports one token, so do not assume zero-downtime dual-token rotation.

## Resource envelope and disk protection

These are existing repository limits or proposed staging controls, not measured capacity estimates.

| Layer | Existing limit to preserve / staging requirement |
| --- | --- |
| PHP workers | Supported slot count is 1 or 2, with database advisory locks per slot; use the same count in worker, adapter and Piston. |
| Piston container | 1 CPU, 1536 MiB RAM, 256 PIDs; retain for initial comparison. Dedicated VM needs additional capacity for OS, gateway and adapter; choose its size only after profiling. |
| Adapter container | 0.25 CPU, 128 MiB RAM, 32 PIDs; read-only root, dropped capabilities, no-new-privileges. |
| Adapter backlog | 32 pending executions; at most 256 result entries, completed-result byte budget 16 MiB and 900-second pruning on submission. Not a second durable job queue. |
| Adapter HTTP | At most 16 request threads; 15-second socket timeout; gateway bound must be smaller. |
| Compilation | 10-second CPU / 30-second wall limit, 512 MiB. |
| Each execution | 2-second CPU / 8-second wall limit, 256 MiB; 64 sandbox processes configured. |
| Source/input | Source ≤100,000 bytes; run stdin ≤4,096 bytes; grade inputs 1–10, each ≤2,048 bytes. |
| Outputs | 16 KiB per decoded stream; adapter and PHP also cap aggregate transport responses. Preserve infrastructure-error classification rather than recording a zero grade for invalid/oversized transport. |
| Grading artifacts | Compile-once implementation accepts bounded regular files/directories, rejects hardlinks/special files, and bounds copied artifact bytes to 8 MiB. Verify all path/count restrictions with existing tests. |
| Temporary filesystem | Existing `/tmp` tmpfs is 512 MiB. This alone is not evidence that every Piston working directory is on bounded tmpfs. |
| Docker logs | Existing JSON logs capped at 10 MiB × 2 files per container; gateway/host journal also require bounded retention. |

Before activation, inspect actual Piston/isolate working-directory paths in the pinned image. Put all writable job and Docker runtime state on an isolated, finite disposable volume; keep OS boot space separate and reserve at least 20% free space. Apply host filesystem/project quotas or verified bounded mounts to the actual sandbox work paths. Freeze runtime-package storage read-only during normal operation if the pinned image passes compatibility tests; otherwise give it a separate capacity-limited volume and audit mutations.

The PHP request's `max_file_size=64` field is not itself proof of a Piston-enforced per-file limit: the adapter builds a fixed Piston payload and does not forward that Judge0 field. Validate the actual isolate/Piston file limit and enforce an explicit tested file/workspace limit before launch. Test disk and inode exhaustion, sparse files, fork storms and cleanup failure on the disposable staging VM. When disk/inode free space falls below the admission threshold, fail new executions closed and alert; do not repeatedly retry or delete authoritative submissions to recover space.

## Reproducible build and provisioning checklist

1. Create a new isolated staging VM from an approved patched OS image; record image identity, kernel, Docker/Compose versions, CPU/RAM and network policy. Give its management identity no access to production secrets or provider-wide administration.
2. Export only `deploy/code-lab/piston/` from the reviewed commit into a runner-only release artifact. Do not clone the application, copy `.env`, copy database dumps, mount the application tree, mount Docker socket, or copy `/var/lib/ecollab-code-lab` wholesale.
3. Record the existing Piston digest from Compose in the release manifest. Resolve/review an immutable adapter image digest and pin the staging copy. Record the gateway/OS image identities and hashes of bridge, preparation, compiled-Java and grading-patch files. Preserve `privileged: true` pending compatibility testing; its risk is contained by the disposable VM boundary.
4. Provision exact runtime versions matching the application task IDs. Produce a sanitized runtime manifest with profile ID, language, exact version, package/artifact checksum, and compile/run-script hashes. Keep the profile token in a separate protected distribution path; never include it in reports. Verify legacy IDs 10001–10005 and optional compiled Java 10006 exactly; never reinterpret an old task ID using a new compiler.
5. Build/import runtimes with no student traffic. Verify package hashes and compile-once patch installation, then create an immutable clean image/snapshot. Snapshot contains no student source, hidden inputs, production secrets, or application backups. Generate fresh per-host runner credentials on first boot after cloning.
6. Configure external/private-network rules, guest rules and gateway. Verify route allowlist, TLS trust, body/connection/time limits and redacted logs before running any student fixtures.
7. Keep application workers unchanged until staging validation passes. In staging only, set `CODE_LAB_JUDGE0_URL` to the verified private HTTPS gateway and `CODE_LAB_JUDGE0_TOKEN` to its scoped adapter token. Preserve `CODE_LAB_LANGUAGE_IDS` and existing task runtime IDs. Do not run the all-in-one local installer to make this change.
8. Run correctness, isolation, recovery and load gates below using synthetic source and synthetic hidden inputs only. Capture sanitized results and exact manifests.
9. A separate production rollout requires review of the evidence, a maintenance/admission plan, and explicit deployment authorization. This source change authorizes none of those actions.

## Lifecycle, recovery and grade integrity

The application queue is the durable authority. Runner tokens are temporary and may disappear after adapter restart; never replace queue state with the runner's cache. Existing abandoned-claim maintenance marks jobs running over ten minutes as failed, and failed grading can be retried explicitly. Do not claim transparent automatic retries or exactly-once execution. Reexecuting a pure isolated program is acceptable only through the existing job/grade guards; duplicate grade commits, new tool side effects, or overwriting manual review are not.

For planned rebuilds, stop new job claims, let existing workers finish, verify no running jobs, and then replace the runner. The existing `--queue-idle` check is a snapshot, not an admission lock. An operator must prevent competing worker starts/claims and account for submissions arriving during the window. Prefer retaining queued database jobs while workers are stopped; do not change student deadlines or manually mark queued work successful.

For unexpected VM failure, stop claims, investigate and replace the VM from the verified clean image. Preserve queued jobs and immutable submissions. Let existing failure/recovery behavior mark interrupted work as unavailable; no automatic zero grades. Explicitly retry affected failed grading after verifying the new runner. Preserve source/runtime identity and review status. An old/new worker race or stale claimant must not overwrite a completed result; exercise the existing conditional completion guard.

For suspected compromise, revoke runner token and tunnel/management credentials, deny the VM externally, stop claims, and retain a protected forensic snapshot if authorized. Do not reuse its packages or disk as the clean image. Identify potentially affected grading windows privately, quarantine those grades from further automatic publication under an operator procedure, and arrange reviewed regrading from immutable submissions. Do not expose hidden test inputs in incident reports. For adversarial assessments requiring stronger isolation, consider fresh VMs/microVMs per job and a separately evaluated execution stack; this design does not assert Piston is compatible with such a switch.

## Acceptance gates

All execution-host gates below are **NOT RUN in this Phase 2 documentation work**. Existing test files are references, not newly verified passes. Run destructive/escape-oriented tests only on the disposable fixture host.

| Gate | Procedure and required evidence |
| --- | --- |
| API compatibility | Run existing `scripts/check-code-lab-runner.php --languages`, normal smoke and `--grading` through staging gateway; preserve runtime map and compile-once behavior. Supply secrets via protected environment, never command arguments/logs. |
| Adapter behavior | Run `tests/code-lab-piston-test.py`, existing batch tests and PHP runner-wait tests; verify auth, schema bounds, wait capability, failure translation and legacy fallback. |
| Real sandbox | Adapt the local-address harness in `tests/code-lab-piston-live.py` to the isolated gateway under an explicit test configuration; do not assume it already tests a remote host. Verify CPU/wall/output/network limits, concurrent workspace isolation, Java compatibility and fresh per-case files. |
| External boundary | From an untrusted host, prove gateway, adapter, Piston, Docker and diagnostics are unavailable. From runner guest and a sandbox, test blocked new connections to production DB/app/admin, metadata and internet, including IPv6. Verify external rules remain effective independently of guest rules. |
| TLS/auth | Wrong/missing/rotated token fails; untrusted/expired/wrong-host certificate fails; old token cannot access new VM. Gateway never redirects authenticated requests. |
| Confidentiality | Canary secrets on application side never appear on runner. Synthetic hidden stdin may reach runner but never student results/logs. Expected outputs and grading weights never leave the trusted application. No source/input/token appears in gateway or worker logs. |
| Grade parity | Frozen known sources and cases produce identical weighted grades, errors and legacy-runtime behavior before/after. Infrastructure faults produce failed/retryable work, never a fabricated zero. Manual grades remain intact. |
| Recovery | Kill adapter/runner mid-compile, mid-case and after execution before commit; restart worker; verify no duplicate grade write or overwritten review, durable queue survival and explicit failure/retry behavior. |
| Exhaustion | Test output/file/inode/memory/process bounds and cleanup failure. Prove application host stays unaffected, new work fails boundedly and no hidden outputs enter responses. |
| Capacity | Run identical one-slot baseline and new-host mixed run/grade fixtures; record actual queue age, completion percentiles, failure rate, CPU throttling, RSS, disk/inodes, network latency and worker occupancy. Then evaluate two slots separately. 450 queued users are not 450 simultaneous sandbox executions. |

Record test commit, image/runtime manifests, host specifications, slot count, fixture sizes, durations, exact failures and sanitized metrics. No results in this document establish 450-user readiness.

## Safe rollback

- Before rollout, retain a verified runner-only previous release and immutable runtime manifest, separate from application backups.
- Stop claims and drain running work before changing URL/credentials or restarting an adapter. Keep database jobs, immutable source, attempts and grades.
- Revert to the previous verified **isolated** execution VM and its matching credentials/runtime map. Validate smoke and grade parity, then resume bounded workers.
- If no trusted isolated runner is available, leave execution workers stopped and surface runner unavailability while preserving drafts/submissions. Do not use rollback as permission to restore privileged execution on the application host for hostile workloads.
- Never delete package volumes used by existing assessments, rewrite task runtime IDs, reset grade data, or silently downgrade limits. Restore infrastructure only through separately authorized procedures.

## Deliverable boundary

Changed file: this design document only. No existing Compose, installer, gateway, firewall, application configuration, worker count, database schema or grade was changed by this workstream. Required follow-up implementation includes a runner-only provisioner, digest/runtime manifest, private gateway configuration, external network rules, bounded workspace storage, admission/maintenance procedure, and execution of every acceptance gate. A1 remains a deployment blocker until those controls are implemented and verified.
