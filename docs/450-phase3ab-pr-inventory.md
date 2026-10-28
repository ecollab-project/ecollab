# Exact pending PR inventory

Source comparison only. Independent acceptance and merge readiness are not inferred. Full SHAs/path hashes are in the evidence JSON.

| PR | Topic | Head ancestor | Changed runtime/deployment paths | Exact / changed / absent at stack |
|---|---|---|---:|---|
| [#66](https://github.com/ecollab-project/ecollab/pull/66) | Phase 1: optimize backend queries and unify channel access | no | 14 | 1 / 12 / 1 |
| [#67](https://github.com/ecollab-project/ecollab/pull/67) | Phase 2: shared LiveKit counts, async Jarred and bounded realtime work | no | 28 | 1 / 21 / 6 |
| [#68](https://github.com/ecollab-project/ecollab/pull/68) | Integration review: fix Jarred queue expiry and private resource access | no | 3 | 0 / 3 / 0 |
| [#69](https://github.com/ecollab-project/ecollab/pull/69) | Phase 3C: enforce authorization and reconcile revoked realtime access | no | 33 | 7 / 23 / 3 |
| [#70](https://github.com/ecollab-project/ecollab/pull/70) | Phase 3C: gate LiveKit reconnects with current room authorization | no | 3 | 0 / 0 / 3 |
| [#71](https://github.com/ecollab-project/ecollab/pull/71) | Phase 3C: retire stale broker JWTs and reauthorize current sessions | no | 12 | 0 / 8 / 4 |
| [#72](https://github.com/ecollab-project/ecollab/pull/72) | Phase 3D: fix LiveKit revocation RPC and validate real media outages | no | 1 | 0 / 1 / 0 |
| [#73](https://github.com/ecollab-project/ecollab/pull/73) | Phase 3E: test broker fail-closed proposal and retain R7 release gates | no | 0 | 0 / 0 / 0 |
| [#74](https://github.com/ecollab-project/ecollab/pull/74) | Phase 3F: repair MariaDB coworkspace uniqueness without rewriting migration history | yes | 3 | 3 / 0 / 0 |
| [#75](https://github.com/ecollab-project/ecollab/pull/75) | Phase 3G: add mTLS Code Lab transport and separate-host staging gates | yes | 7 | 7 / 0 / 0 |
| [#76](https://github.com/ecollab-project/ecollab/pull/76) | Phase 3I: audit Jarred governance and fix opt-out and private error handling | yes | 7 | 6 / 1 / 0 |
| [#77](https://github.com/ecollab-project/ecollab/pull/77) | Phase 3J: share bounded Ollama execution and validate complete admin groups | yes | 5 | 4 / 1 / 0 |
| [#78](https://github.com/ecollab-project/ecollab/pull/78) | [STAGING ONLY] Phase 3K compatibility rehearsal for PRs 74–77 | yes | 22 | 20 / 2 / 0 |
| [#79](https://github.com/ecollab-project/ecollab/pull/79) | [STAGING ONLY] Reconcile async Jarred DM transactions and private errors (K1) | yes | 20 | 19 / 1 / 0 |
| [#80](https://github.com/ecollab-project/ecollab/pull/80) | [STAGING ONLY] Reduce dashboard and peer-matching application overhead (Phase 3M) | yes | 8 | 5 / 3 / 0 |
| [#81](https://github.com/ecollab-project/ecollab/pull/81) | [STAGING ONLY] Profile dashboard HTTP/browser work and optimize exact unread counts (Phase 3N) | yes | 3 | 2 / 1 / 0 |
| [#82](https://github.com/ecollab-project/ecollab/pull/82) | [STAGING ONLY] Profile chat reads/workers and reduce exact recent-chat and sidebar work (Phase 3O) | yes | 2 | 2 / 0 / 0 |
| [#83](https://github.com/ecollab-project/ecollab/pull/83) | [STAGING REVIEW] Validate bounded FPM workloads and aggregate facilitator history (Phase 3P) | yes | 1 | 1 / 0 / 0 |
| [#84](https://github.com/ecollab-project/ecollab/pull/84) | [STAGING REVIEW] Batch peer cache writes and thread attachment reads (Phase 3Q) | yes | 4 | 3 / 1 / 0 |
| [#85](https://github.com/ecollab-project/ecollab/pull/85) | security(code-lab): stage Piston API on Node.js 24.22.1 with versioned runtime | no | 5 | 0 / 1 / 4 |
| [#86](https://github.com/ecollab-project/ecollab/pull/86) | [DRAFT] Phase 3R: remove repeated peer matching CPU work | yes | 1 | 1 / 0 / 0 |
| [#87](https://github.com/ecollab-project/ecollab/pull/87) | [DRAFT] Validate Phase 3R peer CPU optimization against exact base | yes | 0 | 0 / 0 / 0 |
| [#88](https://github.com/ecollab-project/ecollab/pull/88) | [DRAFT] Phase 3S: profile native FPM reads and aggregate facilitator activity | yes | 1 | 0 / 1 / 0 |
| [#89](https://github.com/ecollab-project/ecollab/pull/89) | [DRAFT] Phase 3T: count only the selected facilitator channel | yes | 1 | 1 / 0 / 0 |
| [#90](https://github.com/ecollab-project/ecollab/pull/90) | [DRAFT] Phase 3U: reject plan-dependent workspace join hint | yes | 0 | 0 / 0 / 0 |
| [#91](https://github.com/ecollab-project/ecollab/pull/91) | Phase 3V: statistics-aware dashboard profiling and rejected unread-count rewrite | yes | 0 | 0 / 0 / 0 |
| [#92](https://github.com/ecollab-project/ecollab/pull/92) | Phase 3W: typed SQL telemetry, migrated schema and write-aware controls | yes | 0 | 0 / 0 / 0 |
| [#93](https://github.com/ecollab-project/ecollab/pull/93) | Phase 3X: verified signup and full-schema authentication test contracts | yes | 0 | 0 / 0 / 0 |
| [#94](https://github.com/ecollab-project/ecollab/pull/94) | Phase 3Y: real HTTP authentication and isolated OTP timezone evidence | yes | 0 | 0 / 0 / 0 |
| [#95](https://github.com/ecollab-project/ecollab/pull/95) | Phase 3Z: OTP timezone evidence and bounded correction proposal | yes | 0 | 0 / 0 / 0 |
| [#96](https://github.com/ecollab-project/ecollab/pull/96) | Phase 3AA: frozen OTP UTC trial, consumption race and transition decision | yes | 0 | 0 / 0 / 0 |
