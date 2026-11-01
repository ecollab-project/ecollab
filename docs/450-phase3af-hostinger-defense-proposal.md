# Phase 3AF defense host proposal — selected Hostinger VPS

Status: PROPOSED / NOT EXECUTED. The owner selected a Hostinger VPS when asked which host would run the defense. The current independent handoff explicitly prohibits Hostinger operations and external deployment. Selecting the target identifies the preference; it does not safely authorize installation, database access or a reboot of an unknown server. No Hostinger session, SSH connection, server inventory or provider operation was performed during this continuation.

The accepted source remains PR105 head `f9e63ff750c0a6c6209810e1e37167105875d371`, tree `a8502d5da68184d3aa2504231e8083ad88df156f`. All steps below are planned. Provider-specific commands and actual paths must be finalized from an approved read-only inventory; guessed account IDs, credentials, ports and deployment paths are intentionally not supplied.

## Stage1: bounded read-only inventory to authorize next

Identify the owner's exact existing VPS. Record OS/kernel, PHP/FPM and MariaDB/nginx binary versions, CPU/RAM/free disk, boot ID hash/uptime, service names/states, listener ports and whether other workloads or real eCollab/student data are present. Inspect service/path metadata only. Do not print environment files, command-line credentials, tokens, private keys, mail settings or database content. Use the existing authorized connection; do not request secrets in chat.

This stage makes no service, firewall, DNS, TLS, file, database or package changes. It does not open a database connection, install a build, provision a VPS, reboot or restart anything. Its deliverable is a redacted host inventory, collision map and proposed exact isolated path/port/service manifest. If only a shared production service can be targeted, stop and return BLOCKED.

## Stage2: isolated installation proposal after inventory

Submit the exact service/path/port/configuration changes for separate owner approval before execution. Use a dedicated local OS identity and fresh owned directories on persistent disk for source, configuration, database, attachments, sessions, logs and backups. Bind test HTTP/FPM/database only to owned loopback listeners or private sockets. Demonstrate through an authenticated SSH tunnel from the presenter's browser. No public DNS, firewall opening, live mail or external inference is needed for this proposal.

Do not reuse an existing application root, FPM pool, database daemon/datadir, attachment store, credentials or production schema. The accepted provisioning/fixture controls deliberately require database name `classroom_perf_test` and a loopback connection. Use that name only inside the **separate owned MariaDB daemon and new datadir**, with a documented unused port; do not create it on an existing production daemon. Do not bypass those controls or migration069's fresh synthetic inventory guards.

Install the exact accepted Git archive without application substitutions. Install Composer dependencies from the accepted composer.lock; retain the source/lock/vendor hashes. Record all operational configuration separately from the immutable source. Preserve the tested native nginx scoped attachment rule before generic static handlers; no `^~ /uploads/` bypass. Keep avatars public within the isolated origin and protected root/thread upload URLs routed through the PHP download authorization handler. Store `CHAT_ATTACHMENT_STORAGE` and `CHAT_TUS_STORAGE` outside the web root and include their bytes/receipts in backup.

The fixture test bootstrap enforces `APP_ENV=testing`, debug disabled, a loopback synthetic DSN and local captured delivery. Existing `tests/phase3af-installed.py` is a bounded rehearsal driver: it creates/stops its own fixtures and is **not** a persistent host installer or service manager. Do not run it against the live VPS installation and call the result host persistence. Prepare explicit host service definitions only after paths and collisions are known. Keep mail local and credentials private; do not expose the public test fixture password on a public demonstration origin.

## Stage3: before/after restart and independent restore gates

1. Record accepted source/tree, lock/vendor manifests, installed service/configuration hashes with secrets redacted, isolated database/port/datadir and private upload roots. Record a boot ID hash and uptime. Confirm all data synthetic before any fixture write.
2. Create four synthetic human actors via the real registration/authentication paths, with student/facilitator/outsider cases, and provision a new machine identity through the accepted CLI-only control. Retain the seed/provisioning commands and IDs privately. Refuse existing-username adoption or unknown inventories. Do not run migration069 on any existing account database.
3. Use actual Chromium and the installed origin to run all11 accepted demonstration journeys, including code multiline edit/cancel/save/reload, attachment download filename+bytes, class creation, polls/private voting, matching, human DMs, threads and notifications. Record screenshots, network statuses, exact bytes/hashes and installed source identity. Test current access, anonymous/outsider/revoked/deleted/orphan/cross-destination denials against the actual nginx route, plus public avatar access.
4. Quiesce only the owned synthetic application/writers. Dump the isolated database with schema/data/triggers/routines, then stop the owned database before completing file snapshots. Preserve source/vendor, configuration, seed/provisioning metadata, legacy uploads, private receipts/bytes and any tus state. Preserve original pre-drill state independently. Record all table fingerprints and file/configuration manifests; do not publish secrets or backup contents.
5. An app-service restart proves only service recovery. A full VPS restart changes availability of **every workload on that VPS**. Submit the inventory, maintenance window, impact, recovery access and rollback plan for an explicit separate reboot decision. If the host carries other workloads or no recovery console is available, keep the full-reboot gate BLOCKED. No reboot is authorized by this document.
6. After an approved full-host restart, prove the boot ID changed and uptime reset; verify the same persistent directories, intended service identities, loopback listeners, source/configuration hashes and database/file fingerprints. Reconnect the presenter's tunnel, log in freshly and verify history, code and owned attachment bytes plus negative authorization. Keep unchanged-precheck fingerprints separate from later test mutations. Do not mistake a process PID change for a machine reboot.
7. Restore the independently retained backup into separate empty owned directories and a separate new synthetic MariaDB datadir. Rebind only recorded local paths/listeners. Compare every source/vendor/configuration/legacy/private file and database table before changing fixture data. Old live sessions must not be restored; authenticate freshly. Repeat five restored authorization cases with expected401/404/200/404/404, plus the installed browser/security/identity controls. Preserve the original installation and backup.
8. Record exact command exits, OS/PHP/MariaDB/nginx versions, boot IDs, service start/stop records and redacted environment identity. A failed security/identity/migration/essential demonstration blocks acceptance. Report each gate PASS, FAIL, BLOCKED or NOT RUN; passing CI cannot replace these host records.

## Frozen presentation flow and fallback

Verified candidate features for the host rehearsal: student/facilitator authentication and dashboards; classroom creation/navigation; channel text, native code and polls; private voting/access; peer matching; human DM; threads; notifications; owned attachment upload/download. Show the private-vs-public access control using synthetic actors only. Do not promise realtime broker, live Jarred inference, CodeLab/mTLS, Collabora/external media integrations, production mail or capacity that the installed configuration has not demonstrated.

Retain the existing verified hosted recordings as **CI evidence** if the host is unavailable. A local offline copy or separately restored demonstration machine remains only a proposed fallback until tested; do not present CI recordings as proof of VPS persistence. During the November2 Asia/Manila rehearsal, freeze the successful feature order, prepared synthetic accounts, evidence links, tunnel/reconnection steps and recovery responsibilities. The November3 defense must use only that verified list.

Rollback for an isolated installation: stop only the newly named synthetic services, preserve evidence, restore the matching complete backup to empty directories, compare all manifests and verify fresh authentication/download. Never overwrite existing production files/databases or roll back only source while retaining mismatched receipt storage. Removing new services/files is a later reviewed action; do not delete backups as cleanup.

## Acceptance and authorization ledger

| Gate | Current result |
|---|---|
| PR105 isolated software corrections | Independently accepted in uploaded audit |
| Source freeze / evidence reconciliation | Verifiable from published source/ledger |
| Target preference | Hostinger VPS selected by owner |
| Actual VPS identity/OS/workloads/configuration | NOT RUN |
| VPS read-only inventory | Requires explicit scope overriding current no-Hostinger restriction |
| Installation/service/schema/network changes | NOT AUTHORIZED |
| Full VPS reboot | NOT AUTHORIZED |
| Persistent host browser/security checks | NOT RUN |
| Host backup and independent restore | NOT RUN |
| Final environment / Phase3AG | BLOCKED |
| Merge, production OTP cutover, existing DB069, live mail, capacity | NOT AUTHORIZED / unresolved |

Next concrete decision: authorize **read-only inventory of the owner's existing Hostinger VPS only**, with no database content, secrets, modifications, installation, deployment or restart. Then review its results before authorizing a precise isolated synthetic installation. This staging of authorization follows the supplied handoff's explicit no-Hostinger restriction and the unknown impact on the chosen server.
