# E-Collab AI Collaboration Protocol

## Purpose

This protocol makes GitHub the durable hand-off layer between ChatGPT/OpenAI and Claude. The user should not have to copy prompts, audit results, implementation notes, or review feedback between agents.

## Roles

- **Orchestrator:** owns task state, routing, gates, retries, and final integration decisions.
- **ChatGPT/OpenAI:** architecture, requirements, security review, independent audit, acceptance verification.
- **Claude:** repository implementation, targeted debugging, tests, and PR preparation.
- **GitHub Actions/CI:** deterministic validation; never treated as an AI opinion.
- **Human owner:** approves high-risk/destructive changes and final production decisions.

## Shared artifacts

Every task uses a stable ID such as `ECOLLAB-P1-WS-001` and may produce:

- `docs/ai/tasks/<TASK-ID>.md`
- `docs/ai/audits/<TASK-ID>-openai.md`
- `docs/ai/contracts/<TASK-ID>.md`
- `docs/ai/implementation/<TASK-ID>-claude.md`
- `docs/ai/reviews/<TASK-ID>-openai.md`
- `docs/ai/verification/<TASK-ID>.md`
- `docs/ai/state/<TASK-ID>.json`

The artifact is the source of truth for the next stage, not the previous model conversation.

## State machine

`QUEUED -> AUDITING -> CONTRACT_READY -> IMPLEMENTING -> TESTING -> REVIEWING -> VERIFIED`

Failure paths:

- `AUDITING -> BLOCKED`
- `TESTING -> REWORK_REQUIRED -> IMPLEMENTING`
- `REVIEWING -> REWORK_REQUIRED -> IMPLEMENTING`
- `VERIFIED -> HUMAN_APPROVAL` for high-risk changes
- `HUMAN_APPROVAL -> MERGED` or `BLOCKED`

## Rules

1. No agent may claim success without evidence.
2. ChatGPT's audit must be independent of Claude's implementation report.
3. Claude must not weaken security controls to satisfy a failing test.
4. Review feedback must reference concrete files, symbols, behavior, or test evidence.
5. Failed validation loops back to evidence/root-cause analysis.
6. Conflicting edits are never run in parallel.
7. Secrets are never written to artifacts, logs, prompts, or source control.
8. AI output is untrusted input; it must not directly authorize privileged actions.
9. A green CI run is necessary but not sufficient for security or behavioral acceptance.
10. Human approval is mandatory for destructive migrations, production credentials, irreversible data changes, or disabling security controls.

## Minimum task contract

Each task must define:

- objective
- scope
- affected boundaries
- acceptance criteria
- security constraints
- required tests
- allowed files/areas
- prohibited changes
- completion evidence

## Agent hand-off format

Each hand-off must contain:

1. Task ID
2. Current state
3. Evidence inspected
4. Decision/findings
5. Required next action
6. Acceptance criteria status
7. Risks/blockers
8. Artifact paths

## Review policy

OpenAI verification should inspect the actual resulting diff and CI state rather than trusting Claude's report. Claude must address concrete review findings and rerun relevant validation. The loop ends only when all acceptance criteria pass or a blocker is explicitly documented.
