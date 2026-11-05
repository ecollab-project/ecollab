# Jarred durable DM replies

Goal: a normal DM question must not fail solely because local inference exceeds the 45-second web request budget. Keep other application pages responsive and preserve saved-message identity, authorization and active Code Lab restrictions.

Source: feat/collaboration-assistance-matching at 915a0e0. The VPS accepted five short synthetic qwen3:1.7b prompts in 9.4 seconds; this did not validate longer authenticated DM turns. User subsequently reproduced the application timeout.

Flow: DM composer → authenticated/CSRF-protected send endpoint → atomic user-message/job reservation (migration 066) → immediate queued acknowledgement → two CLI workers → fresh authorized context → local model → fresh authorization/Code Lab check → atomic assistant message/outbox/completed job → existing conversation poll/realtime sync. Immediate PHP greetings and action authority remain. Workers never execute write actions.

Boundaries: queue at most 20 active turns and one per conversation; two running jobs by default; 180-second CLI inference budget; queued expiry 15 minutes; six-minute worker lease, one crash recovery. Normal synchronous AI services retain 45 seconds. Failed or legacy unanswered questions retry through a separate authenticated endpoint without inserting another user message. A stale worker cannot publish after another lease is issued.

Review lenses: backend service integration leads; transaction/concurrency, access control and worker runtime capacity support; QA covers exact-once publication, cross-conversation isolation, rollback, authorization changes, fresh Code Lab policy, crash recovery and keyboard-preserving retry state.

Verification: SQLite service/worker fixtures, JSDOM composer/status/retry fixtures, concurrent five-process MySQL 8/MariaDB claims in hosted CI, existing PHPStan/PHPUnit and collaboration regressions. These are synthetic checks; production full-answer speed remains a VPS acceptance check. No production messages were inspected or deployment performed.

Deployment and rollback: deploy/jarred/README.md. Apply additive migration 066, install and start two www-data worker services, restart PHP-FPM, hard refresh and retry the saved question. Keep the installed Ollama two-generation profile. On rollback stop the new workers and revert queue API/UI together; retain saved messages and additive tables.
