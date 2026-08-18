---
name: ecollab-regression
description: Detect regressions caused by E-Collab changes by comparing contracts, neighboring flows, tests, and known failure memory.
---

# Regression Analysis

Identify what the change could break outside the edited lines. Check direct callers, shared services, database consumers, authentication boundaries, UI state, WebSocket consumers, and AI retrieval/context where relevant.

Every confirmed recurring bug should gain a regression test or documented detection procedure. Re-run affected tests after fixes and inspect the final diff for accidental scope expansion.