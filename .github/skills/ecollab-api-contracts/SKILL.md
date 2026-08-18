---
name: ecollab-api-contracts
description: Keep E-Collab frontend, API, backend services, database behavior, and documentation synchronized when endpoints change.
---

# API Contracts

Treat `docs/API_REFERENCE.md` and actual endpoint behavior together as the contract. Verify HTTP method, authentication, CSRF expectations, parameters, validation, status codes, JSON shape, error shape, pagination, and ownership.

When changing an endpoint, inspect all callers and update documentation/tests when behavior changes. Test both valid and invalid requests and at least one real consuming frontend flow.