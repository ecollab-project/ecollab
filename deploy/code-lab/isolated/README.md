# Separate execution host — staging plan, not an installer

**BLOCKED until an independently isolated execution host and external network
policy are authorized and verified. Do not run this on the application VPS.**
A second Docker network/container on that VPS is not an independent kernel.

The review and threat model are in [the Phase3G report](../../../docs/450-phase3g-code-lab-isolation.md).
No resource was provisioned and no deployed configuration was changed.

Required components:

- Existing application host: authenticated Code Lab API, MariaDB queue/grades,
  similarity integration, existing CLI worker count. Only the worker needs the
  client private key. Submission persistence does not read TLS credential files.
- Separate execution VM/machine: pinned Piston, hardened adapter, bounded scratch,
  native Nginx gateway. No application .env, DB key, SSH private key, cloud/admin
  credentials, application storage or Docker socket mounted into containers.
- Private connectivity with **externally enforced** stateful ACL: application may
  initiate runner TLS; execution host cannot initiate access to application,
  DB, other internal services, metadata endpoints or the Internet. Overlay
  networking alone must not grant routes to the whole production network.
- Dedicated offline CAs. Gateway trusts only the runner-client CA and current CRL;
  application trusts only the server CA. CA private keys stay off both hosts.

Preparation gates (separate authorization required before doing any of this):

1. Record exact guest/kernel/provider isolation, private IPv4/IPv6 routing and
   effective firewall/security-group policy. Verify external denial from an
   independent machine; do not infer it from a hostname or Compose file.
2. Build approved images/runtime packages in a trusted staging builder. Review
   package provenance and immutable hashes. Preserve **exact** existing profile
   IDs, versions and filenames, including legacyJava10004 and compiledJava10006.
   A version string alone is not a package integrity proof. Do not silently run
   `prepare.py` against a live package index to replace old task runtimes.
3. Put only runtime artifacts and the scoped adapter profile/token on the
   execution host. Transfer secret credentials privately through the operator's
   normal mechanism. Never copy the application .env. Do not invoke the legacy
   `scripts/setup-code-lab-piston.sh`: it assumes a colocated app, changes app
   configuration and installs services. It is not this deployment procedure.
4. Render `nginx.conf.example` with a verified private listen address/port, local
   adapter address `CODE_LAB_ADAPTER_IPV4:2358` and a gateway-only TLS directory.
   Choose a nonoverlapping private subnet/address using `execution.env.example`;
   the native Linux gateway reaches the internal bridge address directly. Keep TLS keys out of
   container mounts. No public/alternate/IPv6 listener may bypass mTLS.
5. Review the **effective** configuration produced by both Compose files:
   `deploy/code-lab/piston/compose.yml` and `compose.isolated.yml`. Their shared
   execution network is internal; neither Piston nor the adapter publishes ports.
   Use a reviewed Compose2.24.4+ installation supporting the `!reset` merge tag
   to remove inherited adapter port publication. The overlay intentionally retains privilegedPiston and existing quotas.
   It adds a bounded512MiB isolate scratch tmpfs; this is defense in depth, not
   an escape-proof host boundary. Runtime artifact preparation precedes the
   offline network. Bootstrap egress must never be left enabled during execution.
6. Configure application-side values using `application.env.example`. Select
   `CODE_LAB_RUNNER_TRANSPORT=mtls`, a matching private HTTPS hostname, verified
   serverCA, client certificate/private key and the scoped bearer token. Keep the
   original runtime mapping and worker-slot setting. Client key should be0600
   (or deliberately restricted0640), readable by the CLI worker, never world
   accessible. Never disable peer/hostname verification to fix a connection.
7. Verify actual certificates/CRL expiry and rotation, server and client identity,
   missing/wrong/revoked credentials, private management routes, network denial,
   scratch/resource limits, all five runtimes and both Java IDs, compile-once
   grading and per-case cleanup. Test queue recovery and hidden-input filtering.
   These tests must use disposable accounts/data and independent network clients.
8. Record metrics and recovery evidence. The repository CI shares one disposable
   machine and cannot prove this separate-host deployment. Do not certify hostile
   execution or450 users from that CI result.

Cutover/rotation proposal: keep authenticated submission persistence available.
Pause new CLI claims through a reviewed drain procedure; let in-flight work finish
and verify completion before switching credentials/endpoint. Preserve queued jobs,
immutable submissions, manual grades and runtime mappings. Adapter state is in
memory: ambiguous submissions or interrupted polls may fail and require retry;
they must not be changed into zero marks. Do not claim exactly-once execution.
No automatic RPC resubmission/failover is introduced. Confirm orphaned adapter
jobs have ended; the adapter currently lacks a separate drain-status endpoint.

Certificate rotation: issue a new scoped client certificate, stage its trust,
replace worker files atomically, validate the new credential, revoke the old one,
update the CRL and reload the **staging** gateway. Disable TLS resumption as in
this template. Do not claim that existing in-flight requests disappear instantly;
measure the accepted rotation/revocation window. Bearer-token rotation requires
coordinated adapter/worker changes and the same drain/retry plan.

Rollback: retain the separate execution boundary. If it cannot be maintained,
stop claiming execution work while preserving submissions and queued/failed jobs.
Restore an approved remote release and frozen runtime artifacts, then retry
through existing permissions/deduplication. **Do not automatically fall back to a
privileged runner on the application VPS, clear the queue, delete grades or turn
off TLS checks.** Clean/rebuild a suspected compromised execution VM and rotate
its scoped credentials before trusting its results again.
