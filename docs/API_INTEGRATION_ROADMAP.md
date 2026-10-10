# eCollab External API Integration Roadmap

## Priority 1 — Core integrations

### GitHub REST API
Use for GitHub profile/repository/activity analysis, language and project signals, and future skill evaluation / peer matching.

### Gemini API
Use for AI Assist, structured skill extraction, resource summarization, document understanding, quizzes, and recommendation reasoning. New integrations should use Google's Interactions API.

### Jina Reader
Use for URL/PDF/HTML ingestion into clean LLM-friendly content. Pair with Gemini for resource summarization and study generation.

### Crossref
Use for academic publication and DOI metadata.

### arXiv
Use for research-paper discovery and academic resources.

## Priority 2 — Specialized

### Open Library
Use for book/resource discovery.

### HackerEarth API V4
Use for Coding Buddy code compilation/execution and evaluation. Requires application credentials; integrate only when Coding Buddy execution is ready.

### Google Books API
Optional secondary book/resource source.

## Priority 3 — Optional

- Wikipedia API — general knowledge/resource lookup.
- REST Countries — profile/localization metadata.
- Open-Meteo — optional weather dashboard feature.

## Development/testing only

- JSONPlaceholder
- ReqRes

These should not become production dependencies.

## Proposed architecture

eCollab UI/API -> integration service layer -> external APIs -> normalized internal DTOs -> MySQL/cache -> AI/ML modules.

External APIs should never be called directly from browser JavaScript when credentials, rate limits, caching, validation, or privacy controls are involved. Keep credentials server-side.

## Initial implementation order

1. GitHub integration service
2. Gemini integration service
3. Jina Reader integration service
4. Crossref research service
5. arXiv research service
6. Open Library resource service
7. HackerEarth Coding Buddy adapter
8. Optional APIs only when a concrete feature requires them

## Credential policy

- Never commit API keys/secrets.
- Store credentials in server environment/configuration outside the repository.
- Public/keyless APIs still need server-side rate limiting, validation, caching, and error handling.
- External API failures must degrade gracefully and must not break chat, collaboration, or authentication.
