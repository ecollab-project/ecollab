# Phase 3AF evidence provenance correction

Status: PASS for the byte-provenance reconciliation executed on November 1, 2026. This does not certify a defense environment. PR105 remains frozen at `f9e63ff750c0a6c6209810e1e37167105875d371`, tree `a8502d5da68184d3aa2504231e8083ad88df156f`.

The earlier evidence publication did not clearly distinguish the original hosted reports from reports rerun after download. The published file named `final/hosted-raw-manifest.json` actually describes the post-download archive. Its hashes are correct for that archive, but three derived report entries differ from the original hosted ZIP. The historical manifest/archive are preserved byte-for-byte; this document corrects their interpretation.

| Representation | Immutable identity |
|---|---|
| Original GitHub artifact13079396055, run41295404805 | ZIP SHA-256 `e40036c33bbd1706c3f4441523f6353d8112413753788bd9452e5b5ed49f93e2` |
| Later evidence archive in commit a4359f3e0b322e702a3ec71a8957ab63b40c90e6 | TAR.GZ SHA-256 `7784ec90949873335eaf53052330c52dc58a84d9087072edfceb434848bbee87` |

`450-phase3af-correction/provenance/provenance-ledger.json` contains separate manifests for every original ZIP file and every later TAR file. It records exact original/later hashes, sizes, changed fields and each embedded hash reference. The three original reports are also preserved as deterministic gzip files in that directory. No input archive was modified.

| Report | Original hosted SHA-256 | Post-recomputation SHA-256 | Only changed field |
|---|---|---|---|
| integration-summary.json | e1bf92a345178e691682ccc62c5d71edaa79be0a598a059d53e6049b83477604 | f8cfa728e7fac81263ea7d4e087d728b386d8a2d635460305f204d4b4928416c | file_sha256 |
| recomputed-summary.json | 4904be7ec4e3a37ba39c8608e92e2a49711a16c717b3abb8d8d8b397679ae7aa | 41d156576b3607f1b37739d603e053708cce7cd98117fc56cabe105a41f37c88 | raw_sha256 |
| phase3af-summary.json | 0713fc360fed2fac7bd7f740b25e167dae39d305f92b766066d17527be63b251 | cf52858babb33e0ae18d631f721d6b662df1c57a73feaf350c813c86e79cc010 | raw_sha256 |

Root cause: the security reporter ran before the later identity/message/collaboration outputs existed. After download, it was rerun in the now-complete extracted directory, followed by integration and Phase3AF reporters. Their directory-wide hash maps gained later records and references to derived summaries. Some reporters hash other summaries, or a prior copy of themselves, before downstream writes. Repeating that sequence changes metadata without changing the measured records or verdict payloads.

Verified outcomes:

- All 17 references in the original security report,21 in the original integration report and22 in the original Phase3AF report match the original ZIP bytes.
- Every non-hash field in all three original/later report pairs is equal.
- Every other file shared by the two archives is byte-identical.
- All source-record references in the later reports match their archive bytes.
- Four later **derived-summary** references are stale relative to the final later archive: three in recomputed-summary and one in integration-summary. The ledger records them as mismatches; they are not presented as valid current-byte checksums. The original intermediate copies for these references were not separately retained.

The independent manifests are authoritative for comparing these two representations. Embedded mutually dependent summary hashes describe snapshots and must not be used as an immutable manifest for a subsequently rewritten directory. Future verification must preserve the original extraction and write recomputations into a separate directory.

Executed command, exit0:

```bash
python3 tests/phase3af-provenance-reconcile.py \
  ../phase3af-final-full.zip \
  docs/450-phase3af-correction/final/hosted-raw.tar.gz \
  docs/450-phase3af-correction/provenance
```

The verifier pins both archive hashes and the accepted source identity, checks member safety, refuses unexpected changed records or any non-hash payload change, and records every reference rather than suppressing stale ones. It reads JSON and bytes; it does not execute artifact code. Raw command/exit results are in `450-phase3af-correction/provenance/verification.json`.

The unexplained earlier local UTC exit255 remains an open diagnostic observation. This provenance correction does not explain or resolve it. The independent scoped acceptance of software corrections is preserved; the installed host gate remains BLOCKED.
