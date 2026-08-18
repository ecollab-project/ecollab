# E-Collab Copilot Engineering Instructions

## Mission
E-Collab is a full-stack collaborative education platform. Treat this repository as an existing production-like capstone system. Preserve working behavior and the established UI unless a change is explicitly requested.

## Default agent behavior
For non-trivial tasks, prefer the **E-Collab Orchestrator** as the entry point. It must analyze the request and designate one or more specialist agents AND the smallest compatible set of skills based on evidence. It must not invoke every specialist or skill by default.

The specialist agents are:
- `ecollab-fullstack`
- `ecollab-frontend`
- `ecollab-backend`
- `ecollab-ai-ml`
- `ecollab-database`
- `ecollab-debugger`
- `ecollab-security`
- `ecollab-realtime`

## Skill system
Skills live under `.github/skills/` and are progressively activated when relevant. Use `.github/skills/agent-skill-stack/SKILL.md` to choose the minimum compatible stack.

Core E-Collab skills include project loop, codebase knowledge, PHP/API debugging, database integrity, realtime, security, AI/ML engineering, AI evaluation, API contracts, frontend quality, webapp testing, regression analysis, failure memory, harness engineering, quality playbook, AI team orchestration, and skill creation.

Never load all skills just because they exist. Avoid overlapping instructions and keep project-local adaptations authoritative.

## Repository architecture
- Frontend: PHP-rendered pages plus modular vanilla JavaScript and CSS.
- Backend: PHP 8.1+ APIs and services using PDO.
- Database: MySQL 8 / MariaDB 10.6+.
- Authentication: sessions, CSRF, role middleware, rate limiting, OAuth/OTP flows.
- Realtime: Ratchet + React event loop under `websocket/`.
- AI: PHP AI endpoints under `API/ai/` and AI-assisted chat/collaboration features.
- Tests/static analysis: PHPUnit and PHPStan; CI is defined in `.github/workflows/ci.yml`.
- API contract: `docs/API_REFERENCE.md` and endpoint implementations are authoritative together.

## Non-negotiable workflow
Before changing code:
1. Inspect relevant files and callers/callees.
2. Trace browser -> JS -> API -> service -> database and back when applicable.
3. Inspect schema/migrations before changing SQL.
4. Check authentication, authorization, CSRF, validation, and ownership boundaries.
5. Identify root cause and state a short implementation plan.
6. Select the minimum useful agents and skills.
7. Make the smallest coherent change.
8. Run narrow validation, then broader tests when practical.
9. Re-check related contracts and regressions.
10. If validation fails, loop back to evidence and fix the root cause.

Never guess that a file, function, session field, API parameter, database column, or service exists. Verify it.

## PHP/backend rules
- Use PDO prepared statements; never interpolate untrusted values into SQL.
- Keep API responses consistent with existing JSON contracts.
- Do not suppress fatal errors or turn failures into false success responses.
- Preserve existing middleware and authorization checks.
- Validate input server-side even when the frontend validates it.
- Do not expose stack traces, credentials, API keys, tokens, or sensitive database details in production responses.
- Reuse existing services/helpers when appropriate instead of duplicating business logic.

## Frontend rules
- Use the existing vanilla JS/CSS architecture unless the repository establishes another pattern for the target area.
- Preserve visual design, responsive behavior, accessibility, and interaction patterns.
- Do not rewrite unrelated pages or replace working modules merely for style preference.
- Keep API URLs, payloads, response handling, loading states, empty states, and errors synchronized with backend behavior.

## Realtime/WebSocket rules
- Trace both browser client and Ratchet server before changing either side.
- Verify authentication/token issuance, origin/configuration, connection lifecycle, subscriptions, routing, and reconnect behavior.
- Do not weaken authentication just to make a socket connect.

## AI/ML engineering rules
- Determine whether deterministic logic, search, an existing provider API, an LLM, embeddings/RAG, or trained ML is appropriate.
- Keep provider secrets server-side; never expose AI API keys in frontend code.
- Treat model output as untrusted data. Validate and constrain outputs before persistence or privileged actions.
- Prevent cross-user and cross-project context leakage.
- For ML, track data source, preprocessing, split, leakage, baseline, metrics, reproducibility, and inference behavior.
- For LLM/RAG, track retrieval scope, grounding, prompt-injection risks, context limits, latency, cost, and failure behavior.
- Prefer measurable evaluation over claims that an AI feature is working.

## Security
Treat authentication, authorization, IDOR/ownership checks, SQL injection, XSS, CSRF, file uploads, session handling, WebSocket authorization, secrets, and AI data leakage as first-class concerns. Changes affecting these boundaries require a Security review.

## Harness and failure memory
When an important or recurring agent mistake is discovered, use `harness-engineering` and `ecollab-failure-memory` to turn it into durable guidance, regression detection, or tests. Do not record guesses as failures.

## Changes and reporting
Keep changes focused. Do not silently refactor unrelated code. At the end, report root cause/design decision, agents and skills used, files changed, validation performed, integration status, and remaining risks.

See `.github/skills/skill-sources.md` for audited upstream sources and the policy for adapting external skills.