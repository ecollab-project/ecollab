# Phase 3AF installed defense verification

Status: IN PROGRESS; full defense certification BLOCKED pending hosted browser and final-head evidence.

Accepted source: f319fe16ca30b3157a0ca567fa879bec89c75f19. Application baseline: c65d2f994b10dfa7890f6509a2342e58a064571e. Integration reference c838359711a14e9b6eb3f345c4386d184a178b25 remains unchanged.

## Scope and frozen manifest
Only tests/phase3af-*, docs/450-phase3af* and .github/workflows/phase3af-* may change. No application, migration, dependency lock or production configuration changes are authorized. Source gate verifies the accepted runtime, OTP blob and exact identities.

## Installed candidate
Install a tracked archive with locked Composer dependencies into an owned private directory; run native PHP 8.3 FPM, MariaDB, local capture mail and synthetic accounts. Enforce fresh-only migration 069 and CLI-only machine provisioning. All services use loopback. Browser uses actual UI and actual application requests, with external origins blocked. API controls are distinguished from UI journeys.

Snapshot source, vendor, uploads, explicit synthetic configuration and a consistent database dump after quiescing services. Restore into an independent source/data root, compare every file and schema/data fingerprint, then perform fresh authentication and history reads. Private backups containing synthetic secrets are not published. Persistence is limited to workspace/runner disk lifetime; no stable public defense host is certified.

## Preliminary local observations
The third native run passed actual multipart upload, byte-identical download and complete cold backup/restore. Local Chromium was NOT RUN, so overall result remains FAIL/BLOCKED. Two earlier backup-harness failures are preserved in raw evidence; they do not establish application failures. Final hosted runs must have zero test-file overlays and identify their actual commit/tree.

The accepted Code Snippet UI advertises a coming-soon editor. The browser checks that actual limitation rather than treating backend code-message tests as UI success. Any essential journey failure blocks certification. Static upload download behavior is mirrored; no artificial gateway authorization is added. Anonymous behavior, if observed, is a bounded test configuration finding, not production certification.

## Commands and evidence
python3 -B tests/phase3af-source.py; python3 -B tests/phase3af-installed.py; python3 -B tests/phase3af-report.py phase3ad-raw. The dedicated workflow repeats the complete accepted regression/security/integration controls before installed-candidate verification and always uploads redacted raw records. Exact exits, hashes, browser cases and restore fingerprints are recorded in JSON.

## Outstanding gates and rollback
Expanded hosted Chromium, final-head CI/artifact verification, independent audit and a stable defense environment remain outstanding. Preserve ws_tokens cleanup and excluded external integrations. No capacity claim. Rollback means discard only this isolated verification branch/environment; accepted application and integration refs remain unchanged. No merge, deployment, Hostinger, production transition or live mail.
