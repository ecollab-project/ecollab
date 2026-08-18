---
name: quality-playbook
description: Perform deep E-Collab quality audits that trace behavior to requirements, discover regressions, and verify fixes.
---

# Quality Playbook

For substantial or risky changes use: Explore -> Requirements -> Functional tests -> Code review -> Spec/contract audit -> Reconciliation -> Verification.

Trace every confirmed defect to an observable requirement or behavior and add a regression test when practical. Check both structural correctness and runtime behavior. Verify API contracts across browser, PHP, database, WebSocket, and AI boundaries as applicable.

Do not claim success from compilation or static analysis alone. Report tests run, failures, residual risk, and what was not verified.

Use this skill proportionally; small changes may use a focused validation subset.