# Unified Code Lab console

User correction: remove the standalone Program input (stdin) section, put typed input and returned results in one console, and remove unusable inline Jarred guidance from the coding workspace. Preserve facilitator module-based task creation.

Source: feat/collaboration-assistance-matching at 50503ca. The actual CodeLabRunner/CodeLabExecutionService uses fixed stdin per queued run and returns completed output. This change does not pretend to implement live process I/O or replay a program to simulate interactivity.

Frontend experience leads, with vanilla JS state/keyboard, theme/accessibility, integration-contract and QA reviews. Page → single console input/output DOM → existing run API stdin → existing isolated runner → literal output into same console. Remove workspace Jarred control markup/handler and promotional guidance copy; retain authoritative Code Lab policies and facilitator generation.

State checks: preserve typed input across runs; normal Enter adds a line; Ctrl/Cmd+Enter runs only when allowed; protect a pending run from duplicate sends; keep focus while console input is read-only; reject expired/submitted execution; ignore late run acknowledgements after workspace changes; recover controls on errors. Output uses textContent. Theme colors and narrow viewport bounds remain.

Verification: JSDOM actual page tests plus existing private-attempt, module creation, clipboard and policy regressions. Hosted Chromium tests run input/output inside the console at 240/390/1440px alongside module generation/review. Existing backend stdin and grading contracts remain. No migration, dependency or worker configuration change is required for this UI fix.

Deployment: pull branch, restart PHP-FPM, hard-refresh Code Lab. Revert page/JS/CSS together to roll back. No production data or settings are changed by the agent.
