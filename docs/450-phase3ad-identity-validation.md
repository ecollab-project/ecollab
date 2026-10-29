# Phase 3AD A-AD-1 identity implementation

Owner explicitly approved A-AD-1 on 2026-10-29 for a fresh synthetic defense database only. Security leads; migration safety, backend authentication/session and QA integration are the review lenses actually used through the eCollab IT Orchestrator. No independent agent/human review is claimed.

Base: `82b1192a8cb4bc943b57e42cbc3c0f4204389586` (accepted draft PR101). Branch: `integration/phase3ad-identity-20261029`. This is a stacked draft, not a merge into ecollab-collabs. Final head/tree are supplied in the draft PR and hosted raw source records; local prepublication runs honestly record the base HEAD plus their full working delta and frozen source hashes.

## Scope and implementation

The exact runtime list and its before-implementation hashes were recorded in `450-phase3ad-identity-scope.json`, including a pre-edit amendment for the independently inspected resend-signup OTP writer. Final runtime SHA256 values are pinned in `450-phase3ad-identity-hashes.json`. CI refuses missing, extra or changed runtime bytes. Accepted OTP, message service, send-message, vote-poll and migration068 bytes are unchanged from PR101.

New migration069 introduces constrained nonnull `users.is_system` with human default0. It requires a connection-local owned-fixture acknowledgement, the exact owned database name, successful canonical002 checksum and all ten exact seed identities. Unknown/additional accounts, changed seed identities, unverified prior columns/defaults/constraints or conflicting flags refuse classification. It never changes unknown rows. Normal production invocation lacks acknowledgement and refuses. This is intentionally not an existing-data/production upgrade.

A CLI-only provisioner checks the owned loopback database and verified migration checksum, takes an advisory lock and creates a new `ecollab_ai` account1 with no password, SSO link or remember token. Username/email collisions refuse adoption. Duplicate invocation refuses instead of silently claiming idempotent account adoption. Provisioning is transactional and issues no session/token/mail.

All four supported runtime human INSERT writers explicitly persist0. Reserved-name checks use the username column's database collation. No supported runtime username UPDATE/rename writer was found; this does not inventory external production SQL. Generated names select nonreserved suffixes; explicit reserved admin requests fail. OAuth rejects system/reserved identities before provider/email linking. Login, OTP verification, reset redemption, remember-token recovery and session creation require authoritative human flags. Common session startup clears machine identities, covering refresh and OAuth onboarding as well as requireAuth consumers. Resend-signup independently checks the flag. Missing/invalid flags do not become humans. OTP clocks, marker TTL, ordinary-human auth policy, CSRF and rate limits are unchanged.

Matching, DM and Jarred authorization queries are preserved. Tests exercise actual matching200, provisioned Jarred discovery, human DM roundtrip, outsider/CSRF denial, CodeLab and disabled-preference denial, and authorized Jarred202 queue admission. No external inference worker is started; a queued job is not a completed AI answer.

## Evidence and acceptance

At this publication checkpoint native message smoke passed27checks/61HTTP. Identity's expanded prefreeze run passed32HTTP plus direct DB/service assertions. Frozen reruns, three PHPUnit orderings, original/accepted controls, native180clock/8race observations, exact-base PHPStan, hosted Chromium and final-head CI are required and are not certified by this checkpoint document. Final outcomes and failures belong in the PR handoff and downloadable raw artifact.

The identity driver includes migration refusal and exact users-row/DDL preservation records. Deliberate failed migrations alter the runner's failure ledger; the test restores a previously captured successful ledger row only within its owned fixture after proving a conflicting-flag refusal. This is test restoration, not a production recovery procedure. Earlier test diagnostics are preserved locally: absent settings row made a disabled-preference test ineffective, and a deliberate migration failure left success=0. Both test setup errors were corrected without relaxing application checks.

Commands (all isolated):

```sh
PHASE3AD_OWNED_FRESH_IDENTITY=1 python3 tests/phase3ad-identity.py
PHASE3AD_OWNED_FRESH_IDENTITY=1 python3 tests/phase3ad-message-compatibility.py
PHASE3AD_REPAIR=1 PHASE3AD_IDENTITY=1 PHASE3AD_OWNED_FRESH_IDENTITY=1 CLASSROOM_PHASE3X=1 CLASSROOM_BASE=827470217e6a6a86a165b14f4b6650a10e2c2395 CLASSROOM_EVIDENCE=phase3ad-raw/full.json python3 tests/phase3ad-repair-suite.py
PEER_THREADS_BASE=82b1192a8cb4bc943b57e42cbc3c0f4204389586 python3 tests/peer-threads-static.py
```

Hosted workflow records every required command/exit; missing, skipped or failed required validation blocks readiness. Original unsafe controls remain frozen. Exact-head CI must be inspected, and the final hosted ZIP downloaded, SHA256 verified and raw results recomputed before independent acceptance.

## Rollback and defense boundary

Return source to exact PR101 base and recreate the owned synthetic database before reuse. Do not drop a populated identity column, classify machines as humans, narrow populated message enums, or revive OTP records. Partial DDL/provenance refusal requires recreating this fixture; no existing-data recovery authorization is inferred.

Production writer/trigger/job inventory, real identity classification/cutover, OTP legacy-history retention, TLS/proxy/clock/storage durability, external AI/broker/media/CodeLab integrations and capacity remain separate gates. No merge, deployment, production database/config changes, Hostinger or real mail was performed or authorized. Phase3AE may begin only after independent full3AD acceptance and its next authorization boundary.
