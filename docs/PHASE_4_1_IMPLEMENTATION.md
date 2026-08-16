# Phase 4.1 — AI Session Persistence

## Scope

This implementation turns Ecollab's existing AI schema into a persistent conversation feature.

Existing tables reused:
- `ai_sessions`
- `ai_messages`
- `ai_quick_prompts`

No new database migration is required.

## API

- `GET /API/ai/sessions.php` — list authenticated user's sessions
- `POST /API/ai/sessions.php` — create a session
- `GET /API/ai/session.php?id=...` — load one session and its messages
- `PATCH /API/ai/session.php` — rename a session
- `DELETE /API/ai/session.php` — delete a session
- `POST /API/ai/message.php` — send a prompt and persist the AI response
- `GET /API/ai/quick-prompts.php` — load active quick prompts

Mutating endpoints require the existing Ecollab CSRF token.

## Security

- All endpoints require authentication.
- Every session query is scoped to the authenticated user's `user_id`.
- AI requests are rate-limited to 20 messages per user per hour.
- Prompt size is limited to 4000 characters.
- The Anthropic key is read from `.env` and is never exposed to JavaScript.
- AI responses are inserted into the DOM with `textContent`, not `innerHTML`.
- Markdown/code rendering is intentionally not included in Phase 4.1; that belongs to Phase 4.4.

## Local setup

1. Make a backup of the project.
2. Merge this patch into the Ecollab root.
3. Confirm `ANTHROPIC_API_KEY` is set in your local `.env`.
4. Run `composer dump-autoload`.
5. Run PHP syntax checks.
6. Run PHPUnit and PHPStan.
7. Test the AI modal in both student and facilitator dashboards.

## Important

Do not commit `.env` or any real Anthropic API key.

## Definition of done

- Create conversation
- List conversations
- Load conversation history
- Persist user and assistant messages
- Rename conversation
- Delete conversation
- Load quick prompts from the database
- Ownership checks
- CSRF protection
- Rate limiting
- Student dashboard integration
- Facilitator dashboard integration
- PHPUnit/PHPStan/CI remain green
