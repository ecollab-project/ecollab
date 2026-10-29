# Phase 3AD continuation: message content-type default

Integration readiness remains **BLOCKED**. This bounded follow-up corrects one reproduced message defect; it does not resolve the system-account schema gate or certify a defense build.

Exact base: `77d791d72ad5e07089dead0e886d1b5ef6811149` (draft PR99). Branch: `integration/phase3ad-message-default-20261029`. The only application change is in `services/MessageService.php`: normalize the optional content type once, then reuse that normalized value. Missing/null values now persist `text`; supported explicit values and existing invalid-value fallback are preserved. Authentication, authorization, CSRF, response fields, schema and OTP source remain unchanged. This does not change generic error handling or claim all SQL-detail disclosure is fixed.

The owner selected a **fresh synthetic database** for the November 3 defense. Existing-data backfills and production transitions remain excluded. That selection alone does not establish an authoritative system-account provisioning contract.

## Actual isolated evidence

Native PHP8.3.6/FPM and owned MariaDB10.11, real full migration runner, four real API-created synthetic accounts, actual authentication/CSRF, owned private channels, no session seeding or application/DDL substitution, loopback mail only.

| Check | Exact base | Candidate |
|---|---|---|
| Omitted/null content type | HTTP500, no business mutation | HTTP201, stored/read as text |
| Explicit text/image/file | PASS | PASS |
| Invalid string/array type fallback | stored text | stored text |
| Explicit code/poll | FAIL500 | FAIL500 |
| CSRF/outsider/private/announcement denial | 403, no business mutation | same |
| Empty content | 400, no business mutation | same |
| Full PHPUnit default/reverse/random31028 | 60/280 each, exit0 | 60/280 each, exit0 |
| PHPStan | 15 inherited, exit1 | same15, exit1; 0 added |

The complete fixture executed 39 base and 41 candidate requests; 14 checks each. Harness PASS means these exact expectations held, including preserved failures. It does **not** mean code/poll or complete integration passed. The initial exploratory test stopped at unexpected code500; that failed raw run is retained separately.

Commands (source `phase3u-env.sh`, set `PHPRC` to owned PHP INI locally):

- Base checkout at pinned77d, copied new test harness only: `PHASE3AD_MESSAGE_CONTROL=1 PHASE3AD_MESSAGE_EVIDENCE=<base.json> python3 -B tests/phase3ad-message-default.py` exit0.
- Candidate: `PHASE3AD_MESSAGE_EVIDENCE=<candidate.json> python3 -B tests/phase3ad-message-default.py` exit0.
- Both: `php8.3 vendor/bin/phpstan analyse --error-format=json --no-progress` exit1; normalized path/message/identifier comparison exit0.
- `php8.3 -l services/MessageService.php`, workflow YAML parse and `git diff --check`: exit0.

Raw JSON includes each request, data/checksum controls, source hashes/working diff, full suite commands/exits/JUnit, migration commands/DDL/ledger, private-data-redacted logs. Lossless gzips and SHA-256 manifest are in `450-phase3ad-message-evidence.json`. Local candidate samples explicitly record the uncommitted application delta over77d; they are not mislabeled as a published candidate commit. Hosted CI will record the tested publication tree separately.

## Remaining gates

Canonical users DDL still lacks `users.is_system`. Readers include matching, DM and Jarred. Roles' similarly named flag is unrelated. No authoritative user-system writer exists in the inspected supported migration/application paths. Do not remove eligibility filters or guess historical identity from a username. Before a schema correction, define fresh human/system provisioning, reserved identities, writer/default semantics and fail-closed handling of existing/unclassified rows; actual production inventory/backfill remains separate.

The canonical messages enum accepts text/image/file/system/ai_response but the service also accepts code/poll. Both code and poll reproduce HTTP500 in base and candidate. The patch deliberately preserves those failures pending an explicit schema/API compatibility correction. Generic error leakage is likewise unresolved.

New browser/concurrency runs are NOT RUN locally for this bounded follow-up. Accepted OTP source is unchanged (Git blob `7013114a3a7bc0d183c01fc92e0687f06202d3a7`); that is source preservation, not a new security-test claim. Prior PR99 browser/OTP records remain historical evidence at their own exact head.

Hosted CI is NOT RUN at this document checkpoint. The dedicated workflow tests exact base and candidate with the same harness plus three full suite orderings and uploads all records. Its scoped green result cannot override the known integration blockers. Original PR99's immutable integration evidence remains unchanged.

Rollback: revert this MessageService delta and follow-up tests/docs/CI to77d. No database change occurred. Preserve the accepted OTP correction; never resurrect consumed/invalidated codes. No merge, deployment, Hostinger, production schema/data/configuration, live mail or capacity certification.

## Independent Audit Handoff and next engineering prompt

Review the draft follow-up's exact head/tree and base77d, sole application diff, base/candidate hashes, raw39/41 HTTP requests, six full PHPUnit runs, PHPStan15/15 comparison and final-head hosted artifact. Keep Phase3AD BLOCKED.

Continue Phase3AD on the agreed fresh synthetic defense environment. Resolve the system-account schema/provisioning contract and code/poll schema/API mismatch with bounded isolated corrections and preserved failing controls. Re-run matching/DM, all critical role workflows, browser and OTP concurrency against the actual composed candidate before nominating the November3 build. Do not start another phase or optimization track; no production operations are authorized.
