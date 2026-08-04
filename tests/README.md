# ECOLLAB — Test Suite

Phase 3, Task 3.1. Two tiers: **Unit** (no infrastructure) and **Integration** (needs a real test database).

## Setup

```bash
composer install   # pulls in phpunit/phpunit — not run yet in this environment, do this yourself

# One-time: create a separate test database and apply the existing migrations to it
mysql -u root -e "CREATE DATABASE IF NOT EXISTS ecollab_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
ECOLLAB_TEST_DB_NAME=ecollab_test DB_NAME=ecollab_test php database/migrate.php
```

`tests/bootstrap.php` points `DB_NAME` at `ecollab_test` (or whatever `ECOLLAB_TEST_DB_NAME` is set to) **before** `config.php` loads — no application code was changed to make this work, and it refuses to run if the resolved database name doesn't contain the word "test", as a guard against ever pointing integration tests at real data.

## Running

```bash
vendor/bin/phpunit --testsuite Unit          # fast, no DB needed
vendor/bin/phpunit --testsuite Integration   # needs ecollab_test set up as above
vendor/bin/phpunit                           # both
```

**None of this has been executed in the environment this suite was authored in** — no PHP interpreter or Composer network access was available. Every file was hand-reviewed for correctness against the actual source it tests, but you should run the suite yourself before trusting it green.

## What's covered

| File | Target | Tier |
|---|---|---|
| `Unit/RoleMiddlewareTest.php` | `RoleMiddleware::hasRole()`, `::atLeast()` | Unit |
| `Unit/CsrfTest.php` | `CSRF::token()`, `::regenerate()`, `::field()`, `::verify()` (success path) | Unit |
| `Integration/AuthServiceTest.php` | `AuthService::register()`, `::login()`, including the `pm_user_study_prefs` seeding added in Phase 2 Task 2.1 | Integration |
| `Integration/RateLimiterTest.php` | `RateLimiter::attempt()`, `::clear()` | Integration |

## Known gaps (not covered, and why)

**`RoleMiddleware::requireRole()` / `::requireMinRole()`, and `CSRF::verify()`'s failure path** — all three call `http_response_code()` + `exit()` on failure. Safely asserting an `exit()`-terminated path needs PHPUnit's `@runInSeparateProcess` isolation, which could not be verified against a live interpreter here. Rather than ship a test that might not actually run cleanly, these are left as a follow-up. The success/non-exiting paths of the same classes (`hasRole`, `atLeast`, `verify()` with a valid token) are fully covered.

**`pm_compute_score()` (peer-matching scoring, `API/chat/peer-match.php`)** — not covered at all, deliberately. The function itself is safe to test (it's a plain `function pm_compute_score(PDO $db, int $a, int $b): array`), but the file it lives in is not includable in isolation: `peer-match.php` runs `AuthMiddleware::requireAuth(true)` and an `$action` dispatcher as **top-level code**, executed the instant the file is `require`'d — which would immediately terminate a test process via the same `exit()` pattern as above, auth failure or not.

Making this testable without changing runtime behavior would mean extracting `pm_compute_score()` (and its sibling `pm_*` functions) out of `peer-match.php` into a separate, dispatch-free includable file that both the API endpoint and tests can load — a real, if small, refactor. That's outside this task's "no unnecessary refactoring" scope, so it's being surfaced here as a recommended, explicitly-scoped follow-up rather than done silently:

> **Recommended follow-up task:** split `API/chat/peer-match.php` into `services/PeerMatchScoring.php` (pure functions: `pm_compute_score`, `pm_style_label`, etc. — zero top-level side effects) + a slimmer `API/chat/peer-match.php` that requires it and keeps only the auth-gate + action dispatch. Behavior-preserving, and unblocks testing the platform's core matching algorithm.

## Database safety

Integration tests only ever touch `ecollab_test` (or the database named in `ECOLLAB_TEST_DB_NAME`), never your real `.env`'s `DB_NAME` — enforced by the guard in `tests/bootstrap.php`. Each integration test cleans up the specific rows it created in `tearDown()`, scoped by unique per-test identifiers (`uniqid()`), so tests are safe to run in any order or repeatedly without manual database resets between runs.
