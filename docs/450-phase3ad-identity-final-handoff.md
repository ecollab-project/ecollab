# Phase 3AD — final isolated integration handoff

Engineering validation: PASS for the tested isolated candidate. Independent Phase 3AD acceptance remains pending. This is not permission to merge, transition a production database or deploy.

PR: https://github.com/ecollab-project/ecollab/pull/102 — open, draft, unmerged.

| Identity | Exact value |
| --- | --- |
| Branch | `integration/phase3ad-identity-20261029` |
| Base, accepted PR101 | `82b1192a8cb4bc943b57e42cbc3c0f4204389586` |
| Candidate head | `c65d2f994b10dfa7890f6509a2342e58a064571e` |
| Candidate tree | `7f6686f3d382f8764e84851411f2f92af840077c` |
| Hosted synthetic merge checkout | `63046eeb95faaa5305bad48862e41fd9020bdd2c` |
| Hosted tree | `7f6686f3d382f8764e84851411f2f92af840077c` |
| Preserved OTP blob | `7013114a3a7bc0d183c01fc92e0687f06202d3a7` |

The hosted checkout has the accepted base and candidate as parents and the identical candidate tree. Working overlays are empty. The final evidence JSON records every changed path, all ten runtime SHA256 values, source preservation assertions, workflow/job/step results, required-command exits, artifact digests and recomputation commands.

## Implemented scope

Owner-approved A-AD-1: guarded additive fresh-synthetic identity migration; explicit human0 in four interactive writers and the dynamically constructed CLI fixture writer; CLI-only creation of a new machine1 account; reserved-name protection under actual username collation; machine denial across password, SSO linking, OTP/reset, remember-token and session paths. Unknown account classification and existing-account adoption are refused.

The exact allowed-file manifest and pre-edit amendments are in `450-phase3ad-identity-scope.json`; final hashes are in `450-phase3ad-identity-hashes.json`. Accepted OTP, message service, send-message, private-poll and migration 068 files remain byte-identical to PR101. No matching friendship-precedence or unrelated authentication policy change was introduced.

Broad regression caught an unintended401 response for a deleted human where the existing Jarred authorization contract requires403. The identity helper was corrected to inspect identity only; original deletion/status policy remains with its consumers. Final native and hosted Jarred tests passed after that correction. Historical failed heads are retained in the evidence ledger and are not substituted for final results.

## Verified final-head results

| Gate | Result |
| --- | --- |
| Returned CI workflows / jobs | 14/14 PASS; 15/15 PASS |
| Inspected steps | 209 PASS; 0 FAIL; 1 optional failure-only diagnostic SKIPPED |
| Required integration commands | 16/16 exit 0; none missing or skipped |
| Identity HTTP / direct contracts / migration cases | 32 requests /22 checks / 9 cases PASS |
| Matching / human DM / Jarred restrictions | PASS;machine excluded,human eligible,CSRF/outsider/CodeLab/preferences denied |
| Composed integration HTTP | 52 requests PASS |
| Authentication HTTP | 207 requests PASS |
| Actual Chromium authentication | 6 checkpoints PASS,including independent-context OTP consumption and reset-token replay denial |
| Actual role Chromium | student/facilitator × desktop/mobile:4 PASS;no page errors |
| Current PHPUnit | 60 tests /280 assertions in three orderings;zero errors/failures/skips |
| Exact message base/candidate | 41/61 HTTP requests;14/27 checks;three60/280 suites each PASS |
| Native OTP | 180 clock observations;8 single-winner rounds PASS |
| Preserved original OTP controls | 360 clock observations;10 double-consumption rounds retain original unsafe results |
| Original PHPUnit control | 46 tests /114 assertions;five inherited failures retained |
| Accepted3X/Y/Z/AB/AC comparisons | PASS;57/245 accepted3X,60/280 later controls |
| Exact-base PHPStan | 15 inherited findings on each side;zero additions |

Chromium151.0.7922.34 exercised actual candidate HTML/JS and FPM APIs. The test environment used owned PHP8.3/PHP-FPM/MariaDB10.11, synthetic data and local mail transport. No production service or real mail was used.

### Downloadable raw artifacts

- Integration run40863189551,artifact12893226135: https://github.com/ecollab-project/ecollab/actions/runs/40863189551/artifacts/12893226135
  - ZIP1324001bytes;SHA256 `937c83adddbfb35aa964d87e606d55122de8d12e84f760dc5d57fe07dc9f3c22`.
- Message comparison run40863189742,artifact12892402766: https://github.com/ecollab-project/ecollab/actions/runs/40863189742/artifacts/12892402766
  - ZIP383173bytes;SHA256 `76aad234c4226dc3f983b77c882343932b41b79c929b8849aff972630e823dd8`.

Both ZIPs were downloaded and recomputed against GitHub's digests. Path traversal was checked before extraction. Original expanded-file hashes were saved before reporters regenerated derived summaries. `phase3ac-report.py`, `phase3aa-report.py` and `phase3ad-report.py` each exited0 on downloaded records; additional source/contract/assertion recomputation passed. The original unsafe controls remain failures as intended, not passing security behavior.

## Limitations and defense nomination

Nominate the exact candidate head above for an isolated defense environment, subject to independent acceptance. Use synthetic canonical seeds, newly registered student/facilitator actors and the CLI-created Jarred identity. Demonstrate signup/login/logout/reset, role dashboards/workspace, classroom announcements, human matching, channel code/polls and privacy checks, human DMs, threads/attachment metadata and notifications. Jarred queue admission and authorization are verified; live inference completion is not part of this nomination.

BLOCKED: the inherited capacity-fixture cleanup expects a `ws_tokens` table absent from canonical migrations. Actual creation proved two human0 accounts; cleanup rolled back. No workaround table or unrelated cleanup change was added. The tool is excluded from defense and capacity acceptance. Passing standalone load-fixture CI uses its declared minimal schema and does not resolve this canonical-schema defect.

External broker/media/Collabora/production CodeLab and live inference integration, complete upload/download on the composed FPM+DB stack, and50–100-student capacity are not certified. Separate targeted CI results do not establish production-equivalent configuration or capacity.

Production OTP writer/trigger/job inventory, legacy-history retention, coordinated cutover, TLS/proxy/clock/storage durability and release authorization remain blocked. Earlier scratch diagnostic archives were unavailable after workspace restoration. One early ledger-setup JSON was overwritten and a duplicate mislabeled snapshot was not relied upon; no claim of recovering that full local record is made. Original security failure controls and complete final required evidence are retained in the hosted artifact.

## Rollback

Restore exact PR101 source and recreate the owned synthetic database. Do not drop/reclassify populated real identities, narrow populated message enums or revive consumed/invalidated OTPs. No merge, production migration/configuration, deployment, Hostinger or live-mail operation was performed or authorized.

## Independent Audit Handoff / conditional next prompt

Audit PR102 at the exact base/head/tree above. Inspect the ten runtime files and manifest amendments, fresh-only migration refusal, all five human writers, CLI provisioning/collisions, machine authentication denial, matching/DM/Jarred contracts, accepted OTP/message preservation and raw artifact recomputation. Verify209passing steps and the single optional skipped diagnostic; do not conceal the canonical cleanup limitation or substitute earlier-head results.

Only after independent full Phase 3AD acceptance, begin Phase 3AE integration/merge preparation: reconcile the accepted stack against the actual `ecollab-collabs` head, define exact merge order/conflict resolutions/schema prerequisites/rollback points and the November 3 defense feature/environment freeze. Prepare a reviewable merge plan and isolated rehearsal. No merge, production transition or deployment is authorized by this prompt. No exploratory optimization phase.
