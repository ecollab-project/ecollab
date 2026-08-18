---
name: ecollab-security
description: Audit E-Collab changes for authentication, authorization, ownership, injection, XSS, CSRF, secrets, uploads, WebSocket, and AI data security risks.
---

# Security Gate

Check authentication and server-side authorization at every changed boundary. Verify ownership/IDOR protections, CSRF, input/output encoding, prepared SQL, file-upload constraints, session handling, rate limits, WebSocket authorization, CORS, and secret handling.

For AI features, check prompt injection, cross-user/project context leakage, untrusted model output, tool permissions, provider key exposure, and sensitive logging.

Treat security regressions as blockers unless explicitly accepted by the project owner.