# Phase 3AD owner decision: authoritative system-account contract

Status: PROPOSED — NOT IMPLEMENTED. Task A in the owner's attached independent audit explicitly says “Define and obtain approval for an authoritative identity contract”. This document is the concrete approval target; approval authorizes isolated implementation/validation only.

Starting source: PR100 head cb94769a6af87a356a069a2c949b5bd7b5c73e6e. Accepted OTP blob7013114a3a7bc0d183c01fc92e0687f06202d3a7 remains pinned. Owner selected a fresh synthetic defense database. No existing or production account may be classified by this proposal.

## Proposed decision A-AD-1

1. Separate machine identity from site/server roles: users.is_system is a non-null boolean flag (0 human,1 system), constrained to0/1. New human account default is0. Existing roles.is_system has unrelated semantics and is never reused.
2. Supported human writers explicitly persist0: services/AuthService.php registration; services/OAuthService.php create-user; services/AdminWorkspaceService.php creation; API/admin/dashboard-data.php creation. Request bodies cannot set or promote the flag. Review all UPDATE/upsert paths for identity mutation and all supported username-generation/linking paths.
3. An authorized, CLI-only provisioning operation is the sole supported writer of1. For the synthetic defense fixture it creates a NEW Jarred identity with explicit flag1 and reserved username ecollab_ai. It must never promote an account merely because its name matches. The operation fails on any pre-existing conflicting username/email/ID instead of adopting it. A later explicit designation of an existing account requires a separate verified ID/identity decision and is excluded from this implementation.
4. Reserve ecollab_ai (case-insensitive under the database collation) in human account creation/rename/linking. Generated human usernames choose a nonreserved suffix; explicitly requested reserved names return a documented client error. Existing unrelated human usernames are not rewritten. Do not infer system identity from that reservation alone: Jarred authorization still requires both flag1 and the expected username, and all existing permissions/preferences/CodeLab restrictions remain.
5. Machine accounts cannot authenticate as human sessions: no usable password, remember token or SSO link; supported authentication/linking paths reject flag1 before session or reset-token issuance. Preserve ordinary-human login, OTP lifetime/marker TTL, CSRF, lockout and session contracts. The CLI does not mint sessions, reset tokens or mail.
6. Add a NEW migration only after approval. Do not change002 or other migration history. Initial applicability is fresh, fully controlled synthetic databases. Classify only the known canonical synthetic seed manifest by exact IDs and immutable seed identity/provenance; any additional/unknown accounts, conflicting flags or incompatible column/default cause a fail-closed stop BEFORE classification/DDL. Explicit fixture records are not authority for arbitrary existing databases. Preserve compatible existing flags only if their origin/identity is verified. All existing-data/prod upgrades remain BLOCKED pending a separate complete classification manifest and coordinated writer cutover.
7. Preserve the existing matching system-user exclusion, DM restrictions and Jarred recipient flag+name guard. No COALESCE-based absent-column fallback, blanket human backfill, username inference or privilege elevation. The fresh fixture tests real registered humans and explicitly provisioned machine accounts through actual endpoints.

Expected isolated implementation files: new identity migration and CLI provisioner, the four human writers, username/linking/authentication guards and narrowly scoped readers only if needed for fail-closed identity semantics. Final exact allowed-file list and SQL constraints must be recorded before implementation, then guarded in CI. This is NOT approval for those paths to change yet.

## Required validation after approval

Fresh migration and repeat; unexpected existing users/column/default/manifest fail closed; all four human writers persist0 (OAuth provider network remains local test input, no live provider); explicit CLI machine creation and duplicate/collision refusal; attempted HTTP promotion ignored/rejected; reserved-name/signup/admin/rename/SSO paths; machine login/reset/remember/SSO rejection; human matching eligibility and system exclusion; DM and Jarred flag/name/permissions/preferences/CodeLab controls; current full suites and actual auth/browser/OTP concurrency; exact-head hosted artifact recomputation.

Keep all historic failing controls. No claim that an inventory of source writers covers external production writers, triggers or jobs.

## Rollback and boundary

Destroy/recreate the owned fresh fixture, or return source to exact pinned base before any synthetic machine data is created. Never drop a populated identity column, reclassify machines as humans, restore invalidated/used OTP rows or reverse a real database without a separate approved transition/backup. No merge into ecollab-collabs, production migration/backfill, deployment, Hostinger, real email or capacity certification.

Approval wording: “Approve A-AD-1 for isolated Phase3AD implementation and validation on the fresh synthetic defense database only, preserving the listed fail-closed and authentication safeguards. No production transition or execution.”
