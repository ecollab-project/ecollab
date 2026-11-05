# Code Lab module creation and run sequence

Source: feat/collaboration-assistance-matching at e2334d0, inspected actual CodeLabService, execution API, page, vanilla JS, Jarred worker and Ollama integration. User requested program/input/run/result order and simpler facilitator task authoring from uploaded modules plus difficulty.

Integration leads; backend service/upload validation, ownership/security and frontend form flow support; QA gates include model failures, private drafts and concurrent publication. Risk is private upload handling, untrusted model output and preserving existing classroom permissions. No production deployment or student data access is included.

Vertical slice: facilitator dialog → authenticated module API → bounded PDF/DOCX/TXT reader → private migration 067 queue → existing two Jarred CLI workers → local structured output → authorized ready draft → editable normal task form → atomic idempotent CodeLabService publication → task list/private student attempts. Program/run layout moves execution controls after stdin, without changing runner contracts or integrity policy.

Design choices: one draft per request, manual review grading by default, no solution code or unreviewed automatic tests, exact module source quote, explicitly bounded excerpt, no public upload copy, no automatic task publication. One module inference at a time across two existing worker processes. Drafts persist across form close/reload and can retry or discard. Manual authoring and advanced fields remain.

Evidence and release commands: docs/code-lab-module-tasks.md. Local PHP/JSDOM tests; hosted MySQL/MariaDB and Chromium acceptance plus existing branch workflows. Real VPS local-model quality/speed remain user acceptance checks. Rollback retains additive job data and published tasks.
