---
name: harness-engineering
description: Turn recurring E-Collab agent mistakes into durable instructions, tests, failure memory, drift checks, and governance.
---

# Harness Engineering

Treat E-Collab as the source of truth. Inspect existing instructions, CI, tests, docs, and failure history before changing the agent harness.

Convert high-value rules into enforceable checks where practical. Record recurring user-visible or high-risk failures under `docs/failures/` with root cause and prevention. Add small drift checks when instructions, scripts, tests, or architecture docs can silently become stale.

Do not copy generic agent templates blindly. Prefer updating an existing rule over creating duplicates. Every harness change must name its validation command or manual verification point.

Concept: Instructions + Constraints + Feedback + Memory + Evaluation + Governance.