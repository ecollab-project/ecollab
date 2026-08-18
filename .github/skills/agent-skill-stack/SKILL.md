---
name: agent-skill-stack
description: Select the smallest compatible set of E-Collab skills for a task. Use for multi-step work, skill audits, overlap checks, and orchestration.
---

# Agent Skill Stack

Derive skills from the requested outcome, not keywords. Inspect available E-Collab skills first, then select only the minimum set that covers the workflow.

1. Identify input, operation, output, constraints, and observable success.
2. Map each step to capabilities using input -> operation -> output.
3. Add cross-cutting skills only when justified: security, quality, evaluation, regression, documentation.
4. Reject duplicates and mutually conflicting skills.
5. Prefer project-local E-Collab skills over generic alternatives.
6. For risky changes, require validation and security gates.
7. After selection, record why each skill is needed and what it must produce.

Never load every skill by default. The orchestrator owns final routing.