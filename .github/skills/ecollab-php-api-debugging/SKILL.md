---
name: ecollab-php-api-debugging
description: Diagnose E-Collab PHP API failures such as 500, 401, 403, 404, malformed JSON, and database exceptions by tracing the complete request path.
---

# PHP API Debugging

Reproduce the request. Inspect endpoint, includes, session/auth/CSRF, input validation, service calls, SQL, exception/error logs, and response serialization.

For 500 errors, find the actual fatal/exception cause before editing. Verify database schema before changing SQL. Preserve authorization and ownership checks. Return consistent API responses; never hide failures to make the frontend appear successful.

After fixing, test the endpoint directly and through its consuming frontend flow.