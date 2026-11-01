# Phase 3AF guest inventory review — conditional feasibility

Reviewed November1,2026 (Asia/Manila). Source: owner-uploaded terminal transcript `Pasted text(20261101-112438).txt`, SHA-256 `4e89abfaa1075204f3e1e9a162166617ad2dd8ffde7316bba827c4c6766d7dbe`. This is supplied execution evidence, not commands independently executed by the assistant. The transcript contains duplicated/garbled echoes, no trustworthy wall-clock capture timestamp and no per-command exit codes. Findings below use the clear output sections; no blanket successful-exit claim is made.

DevOps leads with Security, Database and QA review lenses. No new host connection, database access, service action or application change was performed during this review.

## Findings now supported by supplied output

| Check | Reported result | Qualification |
|---|---|---|
| Guest OS/kernel | Ubuntu24.04.5LTS;6.8.0-147-generic | Actual command output, beyond the earlier provider template |
| Processors | 2 | nproc result |
| RAM | 7940MiB total;5738MiB available, about5.6GiB | Snapshot;not sustained headroom or capacity certification |
| Swap | 2047MiB total;15MiB used | Snapshot |
| Root filesystem | ext4;96G total;28G used;69G available;29% used | df output, rounded human-readable values |
| /srv and /var/lib | Same /dev/sda1 filesystem reported | Separate directories would share the host disk/failure domain |
| /run | tmpfs;795M total;793M available | Runtime sockets/PIDs only;must not hold persistent database/uploads/backups |
| PHP CLI | 8.3.6;CLI OPcache reported | Does not prove exact FPM binary, pool configuration or FPM OPcache behavior |
| nginx binary | 1.24.0 Ubuntu | nginx -v output |
| MariaDB client | 10.11.14 | mariadb --version identifies client distribution;not a queried server version |
| Existing service states | nginx,php8.3-fpm,mariadb,docker loaded/active/running | No service was started or restarted |
| Proposed directories | All four stat calls report ENOENT | /srv/ecollab-phase3af,/etc/ecollab-phase3af,/var/lib/ecollab-phase3af,/run/ecollab-phase3af |
| Proposed TCP ports | 18083 and13383 absent from supplied socket snapshots | No port reservation or bind test;recheck before any separately approved change |

This closes most guest-inventory gaps and supports a conditional namespace proposal. It does not establish operational isolation or approve installation. Available RAM/free disk are observations, not a claim that50–100 students can use this host.

## Existing workloads and bindings

The uploaded Docker listing contains **eight** running containers, including `onlyoffice-documentserver` on127.0.0.1:8081. The earlier provider Compose-project API returned five projects/seven containers. These are different inventory scopes and capture times; the exact reason for the API omission is not established. Use the uploaded listing to supplement, not silently overwrite, the earlier seven-container record.

Additional supplied host socket metadata identifies existing MariaDB3306, Redis6379, Ollama11434, PHP8080, Python8091, nginx9183, Alloy12345 and monitoring-agent65529 listeners on loopback. Existing Docker publications include2358,2359,8001,9980 and8081. Do not reuse these listeners or their service/data directories.

nginx80/443 and SSH22 listen on IPv4/IPv6 wildcard bindings. LiveKit shows wildcard TCP7880/7881 and UDP3478, with UDP7882 on interface addresses. The previously empty LiveKit Docker port arrays did not establish absence of host listeners; this output supplies the missing host socket perspective.

UFW is reported active, with incoming/routed default deny and outgoing allow. Allow rules cover22/tcp,80/tcp,443/tcp,7881/tcp,7882/udp,3478/udp with IPv6 counterparts. nft table names are present. Thus the earlier provider-side absence of a firewall group does not mean the guest has no firewall. These listings do not establish effective external reachability through all Docker/NAT/proxy paths; no firewall remediation or exposure change is proposed here.

## Ownership and persistence gaps

The MariaDB unit explicitly reports `User=mysql` and `Group=mysql`. The other three units have blank User/Group properties. Blank unit fields do not identify nginx workers, FPM workers or FPM pool identities; do not describe the application workers as root solely from this output. Parent directory ownership, symlink boundaries, proposed account existence and proposed systemd unit-name collisions remain unverified.

Both supplied boot-ID digests are `30ef3e2a44277693b3c2871ac87845e2d31f27c854c8aaf3aa8fef05ca616003`. Reported uptime advances from15days13h27m to15days13h34m. This establishes a baseline with no observed changed boot ID; **it is not a completed reboot/persistence test**. No restart is authorized or requested by this review.

Whether the current VPS holds real student records, active users or only synthetic fixtures remains unknown. No database/schema/content query is needed or permitted to infer this; obtain the owner's classification and preserve any current workload.

## Exact proposal retained for separate review

Keep the configuration proposal in `450-phase3af-vps-inventory.md`: dedicated `ecollab-phase3af` identity, isolated service names `ecollab-phase3af-nginx.service`, `ecollab-phase3af-fpm.service`, `ecollab-phase3af-mariadb.service`;127.0.0.1:18083 HTTP,127.0.0.1:13383 separate MariaDB daemon, and `/run/ecollab-phase3af/php-fpm.sock`. Source belongs under `/srv/ecollab-phase3af/releases/f9e63ff750c0a6c6209810e1e37167105875d371/`; configuration under `/etc/ecollab-phase3af/`; persistent database/private receipts/upload bytes/parts/sessions under `/var/lib/ecollab-phase3af/`. The accepted CLI fixture requires `classroom_perf_test` only on the new, separately owned daemon/datadir—not an existing student database.

The observed absence of the proposed root directories and TCP ports removes visible direct collisions in this snapshot. Account, unit, worker, parent-path and shared-resource isolation remain review gates. Existing nginx/FPM/MariaDB/Docker must not be modified, stopped or reused as if this were a fresh machine. An SSH-tunneled loopback origin avoids proposing new public exposure. No service files or installation commands are approved by this document.

## Minimal remaining read-only metadata, if continued under the owner's scope

These are **proposed, not executed by the assistant**. They query account/unit/path/process metadata and version-only binary output; they do not connect to MariaDB or read application configuration. Do not install missing commands, create missing identities/paths or change permissions in response to errors.

```bash
id ecollab-phase3af
systemctl show ecollab-phase3af-nginx.service ecollab-phase3af-fpm.service ecollab-phase3af-mariadb.service --property=Id,LoadState,ActiveState,SubState,User,Group --no-pager
namei -l /srv/ecollab-phase3af /etc/ecollab-phase3af /var/lib/ecollab-phase3af /run/ecollab-phase3af
ps -eo user:20,group:20,pid,comm | awk 'NR==1 || $4 ~ /^(nginx|php-fpm8\.3|mariadbd)$/'
php-fpm8.3 -n -v
mariadbd --no-defaults --version
```

Missing user/unit/path results are evidence, not instructions to create anything. Binary versions identify the invoked executables, not proof of a running daemon's exact executable; correlate with permitted process/path metadata before finalizing an installation manifest. No process argument/environment dumps, nginx configuration dump, FPM pool dump, database connection or secret read is requested.

## Independent Audit Handoff

Accepted source remains PR105 head `f9e63ff750c0a6c6209810e1e37167105875d371`, tree `a8502d5da68184d3aa2504231e8083ad88df156f`; base `28132097544b555f71fc6b6023fd3dc2ef0f6616`. Current installed VPS source identity is NOT VERIFIED. Application, accepted OTP/identity/migrations and dependency locks are unchanged. Full application/CI suites were not rerun for this evidence-only update.

The redacted transcript is preserved at `450-phase3af-correction/guest-inventory/operator-transcript-redacted.txt`, with structured facts/provenance in `450-phase3af-vps-guest-inventory.json`. Original public addresses, hostname, owner username and sudo prompt are redacted; line endings are normalized to LF, and the original upload remains untouched. Its original SHA identifies the private supplied source; the redacted copy has a separate SHA. Neither is claimed to be agent-executed evidence.

Review verdict: **conditional feasibility supported; final environment BLOCKED**. Phase3AG must not begin. No installed exact-source browser/security demonstration, approved machine restart or independent installed backup/restore has been established on this VPS. Those remain essential later gates, not reasons to repeat the accepted optimization/security implementation.

Next bounded prompt: use this supplemented inventory to close only the remaining account/unit/worker/path metadata gaps and obtain the owner's synthetic-vs-real-data classification. Then finalize one exact isolated service/path/port/configuration manifest for separate owner approval. Preserve existing workloads; no database content, configuration secrets, package installs, file/service changes, public exposure, migration or restart. Do not treat the supplied free-space readings or absent paths as deployment authorization. No exploratory optimization phase.
