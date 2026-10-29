# Phase 3AD schema repair checkpoint — Task A approval pending

Overall integration remains **BLOCKED**. This isolated draft continues accepted PR100 at cb94769a6af87a356a069a2c949b5bd7b5c73e6e. It implements Task B and repairs Task C's validation infrastructure. Task A's identity policy is a concrete proposal, not implemented or silently treated as authorized. See 450-phase3ad-identity-contract-proposal.md (A-AD-1).

Database leads; Backend, Security and QA provide the migration, transaction, API-contract and verification lenses under the eCollab IT Orchestrator. These are review perspectives applied in this session, not independent agents.

## Task B — preserve supported code and poll messages

Add NEW migration068_message_code_poll_types.sql. No historical migration is rewritten. Append code,poll after the existing text,image,file,system,ai_response enum entries; preserve enum ordinals, existing rows, non-null text default, charset and collation. Serialize the guarded change with a named lock; use INPLACE/LOCKNONE and refuse unexpected shapes. DDL is not transactional; the change is one ALTER, not a rollback promise.

MessageService retains PR100's missing/null-to-text normalization. Validate malformed content/question/options as400 before writes. Poll message/options/attachments/outbox now share an owned transaction even when broker support is off. Existing caller-owned transactions are not committed by this wrapper. No concurrent poll-voting safety or external-broker delivery claim is made.

The send endpoint preserves authentication, CSRF and permission checks. Scalar JSON payloads are400. Server-side runtime/SQL failures now return generic500 text and retain private diagnostics, rather than exposing SQLSTATE in the response. Existing client-error messages remain.

The runtime allowance is EXACTLY services/MessageService.php, API/chat/send-message.php, API/chat/vote-poll.php and the new068 migration, each pinned by SHA-256 in phase3ad-scope.py. OtpService bytes equal accepted Git blob7013114a3a7bc0d183c01fc92e0687f06202d3a7. No users flag, identity writer, production config or authentication-policy implementation is included.

A preserved pre-vote-fix control (PR100 vote-handler bytes plus the enum repair) returned private history403 but private vote200 for a parent-server-only member. The candidate vote query now requires public-channel access, existing owner/admin/creator management access, or explicit channel membership in addition to existing server membership. Valid private members can vote; parent-only members, outsiders and bad-CSRF requests are denied without business mutation. No new platform/admin bypass was added.

## Task C — strict, executable source/evidence gates

Replace outdated no-application-change assumptions only when PHASE3AD_REPAIR=1. Validate accepted PR100 ancestry, exact runtime path set, exact file hashes, active migration additions and immutable OTP bytes. Historical workflows keep their original guards without that opt-in. Negative controls rejected both an unrelated login-file edit and an unapproved edit inside an allowed file; both were restored before final validation.

Use Phase3AD-specific copies for suite/HTTP guard entry points, leaving the accepted Phase3AC drivers and workflows unchanged. Repair the current inventory guard and the dedicated message workflow. Record each actual required command and exit code in required-commands.jsonl. The readiness reporter blocks on missing/failed required commands or absent raw inputs; it no longer crashes on a missing inventory and leaves interpretation to a green label. It also rejects an expected-failure message case as full functional acceptance. An explicit Task A pending stop keeps this draft blocked even if a fixture accidentally masks matching; remove that stop only in the separately approved identity implementation with matching/DM/Jarred evidence.

The full workflow retains original46/five-failure controls, accepted3X/Y/Z/AB/AC comparisons, current60/280 three orderings, native OTP clocks/races, real auth/Chromium, original360clock/10race controls, exact-base PHPStan, existing migration recovery and actual role HTTP/browser integration. Message compatibility adds real code/poll persistence, malformed rejection, injected rollback, migration repeat and seven shape cases. A remaining matching failure must still make readiness fail.

## Completed local validation

Fresh owned native PHP8.3.6/FPM/MariaDB10.11 full migration runner and real API-created synthetic actors; no production target, session seed or application/schema overlay.

- Exact PR100 base:41HTTP requests/14checks; code and poll500 reproduced; omitted/null remain201text. Three full-suite orderings60/280, exit0 each.
- Frozen repaired candidate:61HTTP requests/27checks, PASS; code/poll201 with stored type/history; poll options/voting/readback and outsider vote denial; malformed poll/body/content400; access/CSRF denials unchanged.
- Injected option-insert SQL failure: generic500, message/poll/options/attachments/outbox checksums and counts restored. Tested with broker support off and supplemental on; this does not contact or certify an external broker.
- Actual forced068 migration repeat:exit0, existing rows/column unchanged. Seven separate SQL-shape cases: original+already-extended PASS; extra enum member, nullable, wrong default, case-mismatched enum and varchar safely refused with rows/DDL unchanged. Old values/ordinals and a nondefault column collation preserved.
- Full original control46/114 has five inherited failures, exit1; current/default/reverse/random60/280 all exit0 under the repaired harness.
- PHPStan exact-base differential: report all inherited findings; zero-added gate required. Final numerical result is in the evidence JSON and final PR description.
- Syntax, YAML/shell parse and whitespace checks:exit0. Readiness missing-input/command control:BLOCKED/exit1 as required, not accepted integration.

One parallel supplemental run timed out during MariaDB initialization before sending any request; its sequential retry passed. This is not a production capacity observation. Initial diagnostic runs and helper failures are not final application evidence. The helper's semicolon splitter, result-set handling and invalid case-default fixture were corrected. A pre-freeze outbox diagnostic is excluded from final claims. The final successful records and their exact working diffs/source hashes are explicitly identified in the raw manifest. Local samples use basecb plus recorded uncommitted delta; hosted final-head evidence must establish the publication tree independently.

Hosted CI is NOT RUN at this document checkpoint. The completed final-head result must be read from the PR description/artifacts; no skipped check may become PASS. Matching still depends on absent users.is_system and remains an expected integration blocker until owner-approved Task A is implemented and validated. No defense build is nominated or certified.

## Owner decision and rollback

Task A in the uploaded independent audit says “Define and obtain approval for an authoritative identity contract”. Review A-AD-1 before implementing a users flag, human/system writers, reserved-name policy or machine-session guards. It is scoped to the selected fresh synthetic defense database; existing/production classification and cutover remain separate.

Discard this draft/source back to exact PR100, and recreate owned synthetic databases for rollback. Do not narrow a populated enum while code/poll rows exist: old PHP can retain the extended schema; any real data/schema reversal requires separate review. Preserve the accepted OTP correction and never restore consumed/invalidated codes. Named DDL locks do not certify production TLS/proxy/clock/storage durability or production migration availability.

No merge into ecollab-collabs, deployment, Hostinger, production schema/data/configuration, real mail or capacity certification is authorized.

## Independent Audit Handoff / next work

Verify exact draft base/head/tree, runtime4-file hash allowance, immutable OTP, lossless manifests, base code/poll failures, candidate61HTTP checks, all full-suite orderings, rollback/repeat/seven-shape cases, static differential, required-command ledger and downloaded final-head CI artifacts. Phase3AD stays BLOCKED while Task A or any required gate is unresolved.

Next prompt: Obtain owner approval of A-AD-1, then implement only that bounded identity contract on the fresh synthetic defense candidate. Extend the exact allowed-diff manifest explicitly, validate all human/system writers and matching/DM/Jarred consumers, rerun every required security/integration/browser gate at the final composed head, then nominate the November3 defense build. No Phase3AE or exploratory optimization before independent Phase3AD acceptance.
