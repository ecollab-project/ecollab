# Phase 3AF Independent Audit Handoff

**BLOCKED for full defense certification.** Runtime remains identical to accepted PR102. No merge or production operation occurred.

Draft PR: https://github.com/ecollab-project/ecollab/pull/104

Base: `f319fe16ca30b3157a0ca567fa879bec89c75f19`

Head: `28132097544b555f71fc6b6023fd3dc2ef0f6616`

Tree: `60f777763f64b1b895f28590450d19d165d7e2bc`

Branch: `validation/phase3af-defense-candidate-20261030`

Accepted application: `c65d2f994b10dfa7890f6509a2342e58a064571e`

## Final-head results

- Expanded real Chromium: 10/11 PASS; failures remain acceptance blockers.
- Corrected OTP:180 clock observations;8 independent-session single-winner rounds PASS. Original360 clock records/10 unsafe race rounds preserved.
- Current PHPUnit:60 tests/280 assertions in three orderings PASS;original46-test/five-failure control retained.
- Prior required integration command ledger:16 successful exits;installed-candidate command separately records its real exit.
- Identity,migration,auth,message/poll,matching,DM and composed collaboration core: PASS.
- PHPStan:zero additions;15 inherited findings remain.
- Complete cold backup/restore:PASS;4949 source/vendor/upload files;162 schema/data table fingerprints identical;fresh login/history PASS;old sessions not restored.
- Native multipart upload/download: PASS.

| Browser journey | Result |
| --- | --- |
| facilitator classroom creation and navigation | PASS |
| channel text UI persistence | PASS |
| channel native code UI | FAIL |
| public channel poll UI and vote | PASS |
| private channel poll UI and outsider denial | PASS |
| peer matching actual UI | PASS |
| human DM actual UI | PASS |
| threads actual create and read UI | PASS |
| notifications actual UI | PASS |
| owned upload send and download composed UI | PASS |
| CSRF and private history browser controls | PASS |

## Failure interpretation

channel native code UI: Actual Code Snippet action opens Collabs documents/whiteboards;no channel code editor is exposed by this action

The final harness follows the actual Code Snippet navigation into the owned Collabs page. Earlier claims based only on the legacy coming-soon function were preliminary;runtime overrides route the action to Collabs. No channel code-editor success is claimed. The upload test explicitly clicks the visible channel after navigation restoration;earlier hidden-composer failures remain preserved rather than relabeled as application regressions.

## CI and artifact

Exact-head workflows:{'success': 6, 'failure': 1, 'incomplete': 0}. Run:https://github.com/ecollab-project/ecollab/actions/runs/41056595552

Artifact:12977256873 (`phase3af-defense-evidence`),6104257 bytes. SHA-256:`73e2efab5cd8b2922677d4e99f050b3208d226ba2e06c0b1636fb15006a85bbb`.

Downloaded ZIP hash verified;all entries checked for traversal/absolute paths/backslashes/symlinks. Original extracted file hashes preserved before recomputation. See `450-phase3af-raw/hosted-manifest.json` and `recomputed-results.json`.

Recompute commands: `python3 -B tests/phase3ac-report.py RAW`, `phase3ad-report.py RAW`, `phase3af-report.py RAW`. Actual exits are retained;the defense-blocking reporter must exit1 while essential UI fails.

## Source,environment and rollback

Eight added paths only:tests,documentation and CI. `tests/phase3af-source.py` verifies the exact accepted application bytes,OTP blob,head/tree and permitted manifest. Hosted installation has zero overlays. Locked Composer dependencies are installed and hashed;actual PHP8.3 FPM/MariaDB run from the tracked source archive. Synthetic fresh-only069 and CLI-only machine provisioning remain enforced.

Backup contains source,dependencies,explicit synthetic configuration,seed/database and uploads;services are quiesced and restored into independent source/data directories. Only hashes/redacted records are published;private backups are excluded. Environment persistence is runner/workspace disk lifetime,not stable public hosting.

Rollback: Discard only the isolated verification branch/environment;accepted application and ecollab-collabs remain unchanged. Private synthetic backup restores to fresh independent owned directories;no production rollback executed.

## Outstanding gates

- Native channel Code Snippet action navigates to Collabs documents/whiteboards rather than a channel code editor;essential code journey unaccepted
- Persistent disk restore is bounded to workspace/runner lifetime;no stable external defense host certified
- Static uploaded-file URL confidentiality is not certified;recorded anonymous fetch result must not be presented as a private-attachment authorization pass
- Inherited ws_tokens capacity cleanup and excluded external inference/worker/broker/CodeLab/mTLS/media integrations remain excluded
- Existing-database069,production OTP cutover/history retention,TLS/proxy/storage durability remain separate blocked gates
- No merge/deployment/Hostinger/live-mail/production database operation or50–100-student capacity certification

## Next completion work

Use `450-phase3af-next-completion-prompt.md`. Do not advance to Phase3AG or implement a new optimization phase while the essential demonstration checklist and persistent environment remain unaccepted.
