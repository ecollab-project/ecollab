# Independent Audit Handoff — Phase 3AF environment gate

November1,2026 (Asia/Manila). Software corrections were independently accepted in the owner's uploaded audit. Final defense environment remains BLOCKED. Phase3AG has not begun.

| Source | Identity |
|---|---|
| Draft PR | https://github.com/ecollab-project/ecollab/pull/105 |
| PR base | 28132097544b555f71fc6b6023fd3dc2ef0f6616 |
| Accepted candidate head | f9e63ff750c0a6c6209810e1e37167105875d371 |
| Accepted candidate tree | a8502d5da68184d3aa2504231e8083ad88df156f |
| Prior evidence container | a4359f3e0b322e702a3ec71a8957ab63b40c90e6 |
| Evidence branch | evidence/phase3af-corrections-20261101 |

The accepted PR was rechecked open/draft/unmerged at the same head. This continuation adds only evidence/documentation and a read-only provenance verifier. No API/service/security/schema/configuration/dependency/runtime source differs from the accepted candidate. Full PHP/FPM/browser/security suites were not rerun for these evidence-only changes; their accepted final-head records remain the cited historical evidence, not newly executed host tests.

## Executed verification

`tests/phase3af-provenance-reconcile.py` exited0 against the original hosted ZIP and the previously published post-recomputation archive. The ZIP still matches GitHub's artifact digest. All original report reference chains match their respective ZIP records:17,21,22. Three summary reports differ only in hash-map fields, with equal non-hash payloads. Every shared source record is unchanged. Four stale references between later derived reports are preserved and classified explicitly. A corrupted ZIP was rejected before output; both real input archives remained unchanged. Command/exits, exact hashes, original summaries and independent manifests are published under `450-phase3af-correction/provenance/`.

See `450-phase3af-provenance-reconciliation.md` for the root cause and interpretation correction. The earlier local UTC exit255 remains unresolved; this task does not relabel it fixed.

## Environment decision

The owner selected a Hostinger VPS as the defense host. No server identity, session, runtime, workload, database, TLS, storage or reboot was inspected. The uploaded handoff explicitly prohibits Hostinger operations and external deployment. The target choice is recorded without treating it as permission for an unknown operational change.

`450-phase3af-hostinger-defense-proposal.md` is the concrete plan for review. The next requested scope is read-only inventory of the owner's existing VPS: OS/runtime versions, resource/listener/service/path metadata, workload presence and a redacted isolation/collision assessment. No secrets, database contents/connections, files, packages, service changes, public exposure, installation, deployment or restart. Actual connection details must come from the supported authorized service, not credentials pasted into chat.

After inventory, submit the exact isolated synthetic service/path/port/datadir/configuration manifest for owner approval. Preserve accepted source and fresh-only069/CLI identity rules. Use loopback/private listeners and an authenticated presenter tunnel; do not connect the accepted fixture scripts to an existing student database. A full VPS restart needs a separate impact/recovery decision; an app-service restart cannot substitute for a changed machine boot ID.

| Gate | Result |
|---|---|
| PR105 isolated corrections | Accepted per supplied independent audit |
| Evidence provenance reconciliation | PASS for executed archive/ledger checks |
| Corrupt-archive negative control | PASS — rejected |
| Accepted application preservation | PASS — runtime diff empty |
| Hostinger read-only inventory | BLOCKED by current authorization boundary |
| Persistent VPS installation/reboot | NOT RUN / NOT AUTHORIZED |
| Actual VPS nginx/browser/backup/restore | NOT RUN |
| Final defense environment / Phase3AG | BLOCKED |

## Next bounded prompt

Continue Phase3AF only. Recheck accepted candidate head/tree above and read the provenance ledger and VPS proposal. If the owner explicitly authorizes read-only Hostinger inventory, inspect only that scope and publish a redacted collision/isolation report. Do not install, migrate, connect to existing databases, change services/firewalls/DNS, expose endpoints or restart the VPS. Return exact proposed isolated service/path/port/configuration changes for review before any execution. Keep full-host reboot, production cutover and existing databases separately blocked. If authorization or access is unavailable, report BLOCKED accurately. No Phase3AG or exploratory optimization work.

Rollback for this evidence-only continuation is reverting the new evidence commit on its own branch; the accepted application branch is untouched. Future environment rollback must restore a matching complete synthetic source/configuration/database/legacy/private receipt+byte backup into empty owned directories and verify fresh authentication and authorization. No production rollback or cleanup is authorized.
