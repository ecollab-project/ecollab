# Phase 3AE — Independent Audit Handoff

**Engineering result: PASS for cumulative planning and isolated rehearsal. Independent Phase 3AE acceptance pending. Actual merge NOT AUTHORIZED; production release BLOCKED; final defense environment certification PENDING.**

## Exact sources

| Reference | SHA |
| --- | --- |
| Integration `ecollab-collabs` | `c838359711a14e9b6eb3f345c4386d184a178b25` |
| Independently accepted PR102 base | `c65d2f994b10dfa7890f6509a2342e58a064571e` |
| Phase 3AE Draft PR103 head | `f319fe16ca30b3157a0ca567fa879bec89c75f19` |
| Phase 3AE tree | `a714e8d2ce0d7f548dcb5ad8e3c83a80c243429f` |
| Hosted synthetic merge checkout | `e5460803ab5181fe65fecdbea991006991bb1169` |

PR: https://github.com/ecollab-project/ecollab/pull/103

Branch: `integration/phase3ae-defense-rehearsal-20261030`, stacked on PR102. Open,draft,unmerged. Six added test/docs/workflow files;**zero runtime,application,migration or configuration changes** from accepted PR102. The hosted checkout has the identical Phase 3AE tree and empty overlays. Original accepted OTP blob `7013114a3a7bc0d183c01fc92e0687f06202d3a7` and all accepted message/identity files remain unchanged.

## Cumulative review and merge plan

Accepted PR102 is70commits ahead of integration,with725 cumulative paths,69runtime/deployment paths and6migration additions. Phase 3AE adds one evidence/test commit,so its head is71commits ahead. The hosted source inventory records37 live PR snapshots66–102,each path's integration/accepted blob and SHA256,and acceptance boundaries. Parallel PR66–73 and85 remain excluded. Final Phase3U/V application deltas are empty;do not cherry-pick rejected trial commits.

Read-only `git merge-tree --write-tree` simulations exited0:

- Integration→accepted PR102 yields tree `7f6686f3d382f8764e84851411f2f92af840077c`.
- Integration→Phase 3AE head yields tree `a714e8d2ce0d7f548dcb5ad8e3c83a80c243429f`.

No conflicts or target-ref mutation occurred. The proposal is one future reviewed cumulative integration change,not37 independent PR merges:inherited74–84→scoped86–89→final reverted/investigation90–97→accepted98OTP→99–102validation/message/identity→test-onlyPhase 3AE. Recheck exact endpoints,source hashes,branch policy and external automation before any separately owner-authorized merge. Git ancestry does not authorize production or imply blanket acceptance of staging services.

Detailed plan: `docs/450-phase3ae-merge-plan.md`. Machine inventory and lossless cumulative runtime diff are in the hosted artifact. The diff reproduced after normalizing only Git-generated7vs8-character index labels;full source blob IDs and SHA256 values were independently checked.

## Final-head evidence

**7/7 workflows PASS;7/7 jobs PASS;116 steps PASS;0 FAIL;0 SKIPPED.**

- All16 retained required integration commands exited0;additional Phase 3AE source/decision gates passed.
- Full fresh migration chain and identity:32HTTP requests,22contract checks,nine migration cases(seven expected refusals,two success/repeat) PASS.
- Human/system matching separation,DM and Jarred authorization,private/outsider/CSRF gates PASS.
- Composed integration:52HTTP requests PASS. Authentication:207HTTP requests PASS.
- Actual Chromium:6auth checkpoints and4student/facilitator desktop/mobile role cases PASS.
- Current PHPUnit:60tests/280assertions,three orderings,zero failures/errors/skips.
- Candidate message/poll:61HTTP/27checks;three60/280orderings PASS.
- Native OTP:180clock observations and8independent-session single-winner rounds PASS.
- Original controls preserved:360clock observations,10unsafe double-consumption rounds,46-test/114-assertion baseline with5inherited failures.
- Accepted regression controls PASS;exact accepted-base PHPStan15/15 inherited findings,zero additions.

Hosted artifact: https://github.com/ecollab-project/ecollab/actions/runs/41045400002/artifacts/12971359746

- Name:`phase3ae-rehearsal-evidence`;1407824bytes.
- ZIP SHA256:`ac6aa25aeaa18ac5dd76426ea72272a82b6a3b666fe2ed9b9f9c1cfe59d08a4e`.
- Downloaded ZIP matched GitHub's digest;extraction paths checked;original file hashes saved before derived-summary regeneration.
- `phase3ac-report.py`,`phase3aa-report.py`,`phase3ad-report.py`,`phase3ae-report.py` each recomputed exit0 from downloaded raw records. Additional source/merge/identity/required-command assertions PASS.
- Full commands,exit codes,CI steps,hashes,37PR dispositions and69runtime hashes are in `450-phase3ae-final-evidence.json`.

## Defense candidate and backup boundary

Nominate exact Phase 3AE head `f319fe16ca30b3157a0ca567fa879bec89c75f19` for the next isolated test candidate. Its runtime remains accepted PR102. The environment is disposable loopback PHP8.3/PHP-FPM/MariaDB10.11 with local nonproduction mail and synthetic canonical seeds/registered student-facilitator accounts/explicit CLI machine provisioning. Seed,fixture,configuration and dependency hashes are in the final evidence JSON and raw reports. No persistent public demonstration server was deployed.

A tracked-source-only archive was generated twice reproducibly using:

```sh
git archive --format=tar f319fe16ca30b3157a0ca567fa879bec89c75f19
```

267601920bytes;SHA256 `07f7bfc1c1d8fc4877fab77dd5901f7ca465e3eb10b23edc8a0f0af6dc580d2e`. It excludes runtime `.env` and untracked vendor overlays;it is source,not an installed or deployed build. Dependencies and the synthetic database must be recreated through the recorded rehearsal procedure.

Demonstration proposal:synthetic signup/verification/login/logout/reset;student/facilitator dashboard/workspace;classroom membership/announcement;channel messages/code/polls;human matching/DMs;threads/attachment metadata;notifications. Actual browser coverage is auth and role dashboard navigation;other listed operations have actualHTTP/DB evidence. Fixture classroom creation is direct synthetic setup,not certified complete classroom-creation UI. Full composed upload/download and live external service completion remain separate gates.

Backup:exact source archive plus immutable raw-evidence walkthrough and fresh-fixture recreation instructions. A live recording and persistent installed-environment backup/restore drill are **NOT RUN**,reserved for the next bounded candidate verification. Do not present mock or prerecorded behavior as live integration.

## Remaining blockers and rollback

- **BLOCKED:**canonical capacity-fixture cleanup expects missing `ws_tokens`;its transaction rolled back. Exclude it from defense/capacity acceptance;no workaround schema was introduced.
- **BLOCKED:**migration069 supports only the explicitly authorized fresh canonical fixture. Existing/production account classification and coordinated OTP legacy/history cutover remain separately unapproved.
- **NOT CERTIFIED:**external broker/media/Collabora/production CodeLab/live inference,complete composed upload/download and50–100-student capacity.
- **PENDING:**persistent defense environment,complete UI journeys,backup/restore and final November 1 defense build certification.
- **NOT VERIFIED:**external webhooks,branch protection/merge policy and production service configuration. No tracked workflow deployment indicators were found in a bounded source-pattern inventory;that is not proof external automation is absent.

Rollback this phase by discarding only its test/docs/workflow branch and recreating owned fixtures;integration reference remains unchanged. Future source checkpoints do not authorize database downgrades. Never narrow populated enums,drop populated identity state,reclassify machines as humans or revive OTPs. No actual merge,production migration/data/config operation,deployment,Hostinger or real mail was performed.

## Next bounded engineering prompt

Use the accompanying `450-phase3ae-next-phase3af-prompt.md` **only after independent Phase 3AE acceptance**. It requires a frozen isolated Phase 3AF candidate,actual composed browser/upload journey closure,backup/restore and artifact verification. No exploratory optimization,actual merge or production execution is authorized. October 31testing,November 1defense nomination,November 2freeze andNovember 3defense remain the schedule.
