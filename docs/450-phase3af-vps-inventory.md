# Phase 3AF Hostinger VPS inventory — BLOCKED

Observed November1,2026, Asia/Manila. Hostinger VPS API access worked using the existing authenticated connection. No new connection configuration, credentials or server-side files were created. DevOps leads, with Security, Database and QA review perspectives; these are review lenses, not separately executed agents.

**Stop condition triggered:** the only returned VPS has five running Docker Compose projects and seven containers. It is an existing workload host. Production classification, student-data presence and isolation are unknown. Host inspection stopped immediately after the workload list; no further host operations were attempted.

The exact accepted application remains PR105 head `f9e63ff750c0a6c6209810e1e37167105875d371`, tree `a8502d5da68184d3aa2504231e8083ad88df156f`. This report does not certify that source as installed on the VPS. No source/deployment comparison was attempted there.

## Redacted inventory

Target alias: VPS-A. Provider IDs, public IPs, hostname, subscription ID, container IDs, command fields and the account username are omitted. Paths below contain a redacted owner component. No environment files, credentials, database/schema contents or private application data were requested.

| Field | Observed result / provenance |
|---|---|
| VPS state / plan | running /KVM2, provider metadata |
| CPU allocation | 2 vCPUs, provider metadata |
| RAM allocation | raw API `memory=8192`; units not declared in returned schema/response |
| Disk allocation | raw API `disk=102400`; units not declared in returned schema/response |
| OS template | Ubuntu24.04LTS, provider template; actual guest OS/kernel unverified |
| Live resource metrics | CPU/RAM/disk/uptime fields all null for requested15-minute window; no usage/free-capacity inference |
| Docker workloads | Five running projects, seven running containers |
| Host PHP-FPM/nginx/MariaDB versions and service states | NOT RUN |
| Complete local listening sockets | NOT RUN; only Docker port-publication metadata below was available |
| Boot ID hash /host uptime | NOT RUN /metric unavailable |
| File ownership/permissions | NOT RUN; project path names are metadata only |
| Real student data /production status | UNKNOWN; no database or private-content access |
| Existing source SHA /installed dependencies | NOT RUN |

The provider allocation fields are preserved as returned rather than silently labeling them MiB/MB. Provisioned allocation is not available capacity. The null metrics do not mean zero usage. Container status text reporting `Up10days` is not a measurement of host boot identity or host uptime.

## Existing workloads and occupied bindings

| Project | Containers | Reported image identity | Reported port metadata |
|---|---:|---|---|
| centrifugo | 1 | centrifugo/centrifugo:v6.9.6 | TCP127.0.0.1:8001 →container8000 |
| collabora | 1, healthy | collabora/code pinned image digest in JSON | TCP127.0.0.1:9980 →container9980 |
| ecollab-code-lab | 2 | python:3.12-slim-bookworm; pinned Piston digest | TCP127.0.0.1:2358 →adapter2358; Piston2000 exposed in container metadata only |
| ecollab-similarity | 1 | ecollab-similarity-comparison | TCP127.0.0.1:2359 →container2359 |
| livekit | 2 | livekit/livekit-server:latest; redis:7-alpine | No port entries returned; networking/listeners remain unknown |

Image tags/digests identify provider-reported container images, not independently queried runtime versions. Empty LiveKit/Redis port arrays do not prove absence of listeners, external exposure or host-network mode. No container shell, configuration, log, environment or database was accessed.

Four project Compose paths are under `/home/<owner>/ecollab-inspect/deploy/`; LiveKit is under `/opt/livekit/`. These paths identify existing consumers and must not be reused or overwritten. File ownership, mount boundaries, unit dependencies, reverse-proxy routes and database sharing remain unverified.

The list response incidentally reports no attached provider firewall group. Guest firewall rules and effective network exposure were not inspected. This does not establish that all ports are public and does not authorize firewall remediation.

## Workload-collision assessment

- Confirmed occupied host publications: TCP127.0.0.1 ports8001,9980,2358,2359. Do not select them for a synthetic environment.
- Confirmed existing application-related project paths and seven running containers on the selected VPS. Do not restart or alter these projects.
- Unknown: host PHP/FPM/nginx/MariaDB use, other sockets, systemd dependencies, usernames, persistent paths, resource headroom and production/student-data classification. A separate process name alone would not establish resource or failure isolation.
- A full VPS reboot would affect the entire machine; no reboot is authorized or scheduled.
- Result: **BLOCKED** for installed defense-environment acceptance. Neither synthetic installation nor production changes are safe to approve from this partial inventory.

## Exact conditional isolation proposal — not approved or collision-verified

These names retain the previously proposed namespace. They are precise candidates for a later authorized collision review, not claims of availability. No directories, users, databases, sockets, services or listeners below were created.

| Component | Proposed exact value |
|---|---|
| Dedicated OS identity | ecollab-phase3af |
| Immutable source root | /srv/ecollab-phase3af/releases/f9e63ff750c0a6c6209810e1e37167105875d371/ |
| Separate configuration | /etc/ecollab-phase3af/ |
| Persistent synthetic state | /var/lib/ecollab-phase3af/ |
| HTTP listener | 127.0.0.1:18083; presenter through authenticated SSH tunnel only |
| nginx service | ecollab-phase3af-nginx.service; independent master/configuration/PID |
| FPM service/socket | ecollab-phase3af-fpm.service; /run/ecollab-phase3af/php-fpm.sock |
| MariaDB service/listener | ecollab-phase3af-mariadb.service;127.0.0.1:13383 |
| MariaDB datadir/socket | /var/lib/ecollab-phase3af/mariadb/; /run/ecollab-phase3af/mysql.sock |
| Fresh synthetic database name | classroom_perf_test, only inside that separately owned daemon/datadir |
| Private attachment receipts/bytes | /var/lib/ecollab-phase3af/attachments/ |
| Resumable upload parts | /var/lib/ecollab-phase3af/upload-parts/ |
| Private PHP sessions | /var/lib/ecollab-phase3af/sessions/ |
| Private retained backups | /var/lib/ecollab-phase3af/backups/; independently preserved copy required before any recovery drill |

The proposed ports do not equal the four observed Docker publications, but that does **not** prove they are free: the host socket inventory was not performed. User, service, path and socket collisions remain unknown. PHP8.3-FPM/MariaDB10.11 are required by the accepted rehearsal; their availability on this host is unverified. No executable service definitions or installation commands are approved by this table.

Fresh-only migration069, CLI-only machine provisioning, corrected OTP behavior, private upload storage and the accepted nginx route must survive any later authorized environment composition. Existing schema/data cannot be adopted or backfilled. The known ws_tokens capacity-fixture issue, production OTP cutover/history retention, external dependencies and capacity certification remain excluded.

## Executed operations and QA gate

| Read-only API operation | Actual result |
|---|---|
| vps_virtual-machines_list | Existing connection works;one VPS returned |
| vps_virtual-machines_metrics | Response received;selected metric fields null |
| vps_docker_list | Five running projects/seven containers;stop triggered |

Operation discovery used read-only search schemas before execution. No mutation operation, database connection/query, script, SSH session, login setup, package install, secret read, file modification on the VPS, service change, firewall/DNS/TLS change or restart was executed. Reports are written outside the VPS; the target machine is untouched.

Raw provider responses were not published because they contain public host addressing and account identifiers beyond what the redacted audit needs. `450-phase3af-vps-inventory.json` preserves selected provider fields and all permitted workload/binding metadata with redaction; it is a redacted record, not a byte-identical original API response. No report digest is represented as a digest of the original response.

## Independent Audit Handoff /next bounded prompt

Review this report and its JSON against the owner's read-only authorization. Accept connection discovery and limited provider metadata only; do not certify complete guest inventory, host isolation, production readiness or capacity. The stop condition is positively established by existing workloads, not merely by missing tools.

Keep Phase3AF open and Phase3AG BLOCKED. Decide independently whether to select an already available dedicated synthetic host, or seek an explicitly revised read-only guest-metadata scope acknowledging this VPS's existing workloads and overriding the prior stop boundary. Do not continue host inspection under the old scope after this finding. Any later review must retain the exact accepted candidate and avoid databases/private contents; installation/restarts remain separately unauthorized. Do not provision or purchase an alternative host without owner authorization.

No operational rollback is needed: no target changes occurred. Evidence-only publication can be reverted on its own branch without touching the accepted application. No merge, deployment, migration, live mail or capacity certification.
