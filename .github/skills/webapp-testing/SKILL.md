---
name: webapp-testing
description: Exercise E-Collab through the browser and verify real user flows, console errors, network/API behavior, and UI regressions.
---

# Web App Testing

Use for frontend changes, cross-stack features, browser bugs, and final integration checks.

1. Start the application using the repository's documented environment.
2. Exercise the affected user flow, not just isolated functions.
3. Inspect browser console errors and failed network requests.
4. Verify HTTP status, JSON payloads, authentication state, and visible UI state.
5. For realtime features, verify connection, reconnect, message delivery, and error behavior.
6. Capture a reproducible failure before changing code.
7. Re-run the exact scenario after the fix and check adjacent flows.

Never treat a page rendering successfully as proof that its API/backend behavior is correct.