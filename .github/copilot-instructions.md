# E-Collab Copilot Engineering Instructions

## Mission
E-Collab is a full-stack collaborative education platform. Treat this repository as an existing production-like capstone system. Preserve working behavior and the established UI unless a change is explicitly requested.

## Default agent behavior
For non-trivial tasks, prefer the **E-Collab Orchestrator** as the entry point. It must analyze the request and designate one or more specialist agents based on the actual task. It must not invoke every specialist by default.

The specialist agents are:
- `ecollab-fullstack`
- `ecollab-frontend`
- `ecollab-backend`
- `ecollab-ai-ml`
- `ecollab-database`
- `ecollab-debugger`
- `ecollab-security`
- `ecollab-realtime`

The Orchestrator may use one specialist for focused work or combine multiple specialists for cross-stack work. Independent investigations may run in parallel; conflicting edits must be sequenced. A final integration pass is required for multi-agent tasks.

## Repository architecture
- Frontend: PHP-rendered pages plus modular vanilla JavaScript and CSS.
- Backend: PHP 8.1+ APIs and services using PDO.
- Database: MySQL 8 / MariaDB 10.6+.
- Authentication: sessions, CSRF, role middleware, rate limiting, OAuth/OTP flows.
- Realtime: Ratchet + React event loop under `websocket/`.
- AI: PHP AI endpoints under `API/ai/` and AI-assisted chat/collaboration features.
- Tests/static analysis: PHPUnit and PHPStan; CI is defined in `.github/workflows/ci.yml`.
- API contract: `docs/API_REFERENCE.md` and the endpoint implementations are authoritative together.

## Non-negotiable workflow
Before changing code:
1. Inspect the relevant files and their callers/callees.
2. Trace the data flow across browser -> JS -> API -> service -> database and back when applicable.
3. Inspect the database schema/migrations before changing SQL.
4. Check authentication, authorization, CSRF, validation, and ownership boundaries.
5. Identify the root cause and state a short implementation plan.
6. Make the smallest coherent change.
7. Run the narrowest useful validation, then broader tests when practical.
8. Re-check related frontend/backend/API contracts for regressions.

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
- Use the existing vanilla JS/CSS architecture unless the repository already establishes another pattern for the target area.
- Preserve the existing E-Collab visual design, responsive behavior, accessibility, and interaction patterns.
- Do not rewrite unrelated pages or replace working modules merely for style preference.
- Keep API URLs, request payloads, response handling, loading states, empty states, and error states synchronized with the backend.

## Realtime/WebSocket rules
- Trace both the browser WebSocket client and the Ratchet server before changing either side.
- Verify authentication/token issuance, origin/configuration, connection lifecycle, subscriptions, message routing, and reconnect behavior.
- Do not weaken authentication just to make a socket connect.

## AI/ML engineering rules
- First determine whether deterministic logic, search, an existing API, an LLM, embeddings/RAG, or a trained ML model is actually appropriate.
- Keep provider secrets server-side; never expose AI API keys in frontend code.
- Treat model output as untrusted data. Validate and constrain outputs before persistence or privileged actions.
- Prevent cross-user and cross-project context leakage.
- For ML work, explicitly track dataset source, preprocessing, train/validation/test split, leakage risks, baseline, metrics, reproducibility, and inference behavior.
- For LLM/RAG work, track retrieval scope, grounding sources, prompt-injection risks, context limits, latency, cost, and failure behavior.
- Prefer measurable evaluation over claims that an AI feature is "working".

## Security
Treat authentication, authorization, IDOR/ownership checks, SQL injection, XSS, CSRF, file uploads, session handling, WebSocket authorization, secrets, and AI data leakage as first-class concerns. Any task that changes these boundaries should include the Security specialist in the orchestration plan.

## Changes and reporting
Keep changes focused. Do not silently refactor unrelated code. At the end, report:
- root cause or design decision
- agents used, when orchestrated
- files changed
- important behavior changes
- validation/tests performed
- remaining risks or follow-up work
