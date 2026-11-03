# Jarred cold starts and pending replies

Plain standalone greetings (hello, hi, hey, good morning/afternoon/evening, kumusta/kamusta) are answered by PHP without loading a model. Greetings containing a real question still use normal assistance. Persisted active Code Lab restrictions continue to apply before greeting fallback.

Synchronous Ollama calls outside Jarred DMs have a 45-second maximum wait. Normal Jarred DM inference now runs in the durable queue described below. Tool rounds share that budget. A transport timeout returns a controlled failure instead of waiting 90 seconds for inference. The CLI-only warm-up has a separate 90-second load budget. Actual end-to-end time still includes middleware, data lookup and upstream configuration; this is not a measured VPS latency guarantee.

AI pending state belongs to its conversation. Moving to another DM/group clears the visible indicator, and returning to the original pending conversation restores it. Timeout/network uncertainty retains an unconfirmed outgoing item and asks the user to check saved history rather than implying the send definitely failed. No automatic POST retry is added. Stored history is not rewritten; control markers/reasoning blocks in old Jarred replies are filtered when displayed. Human messages are unchanged.

## Pull and pre-load on the VPS

After pulling `feat/collaboration-assistance-matching`, restart PHP-FPM and refresh the browser. Apply migration 066 and install the reply workers below before using the updated DM endpoint. No new dependency is required.

```bash
cd /home/ecollabadmin/ecollab-inspect
sudo -u www-data php scripts/warm-jarred.php
```

This loads the configured local model with an empty message list. It sends no user conversation and generates no answer. It skips preloading while interactive requests are active. Run it before a demonstration or use the timer below to keep the model resident between conversations. Existing payloads retain it for ten minutes; the timer refreshes every eight minutes. This keeps model memory allocated while enabled.

The warm-up runs as the observed PHP-FPM account `www-data`, so its inference lock is not created under a different file owner. Review that account and checkout path in the supplied unit files before installing:

```bash
sudo install -m 644 deploy/jarred/ecollab-jarred-warm.service /etc/systemd/system/
sudo install -m 644 deploy/jarred/ecollab-jarred-warm.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now ecollab-jarred-warm.timer
systemctl list-timers ecollab-jarred-warm.timer --no-pager
journalctl -u ecollab-jarred-warm.service -n 20 --no-pager
```

If loading fails, inspect the existing local Ollama service/configuration. Do not change the Nginx timeout to mask a failing model. Disable the warm timer with `sudo systemctl disable --now ecollab-jarred-warm.timer` if rolling back. Migration 066 is additive; retain its tables when reverting code.

## Acceptance

- Cold model: hello replies without an inference call; a substantive question still generates normally.
- Start Jarred request, switch to a human/group chat: no Jarred indicator there. Return while pending: indicator visible only there. Completion clears it.
- A timed-out provider frees its admission slot, returns a controlled error and leaves the UI usable. Verify actual gateway limits/logs separately if a 502/504 persists.
- Saved-email autofill matches the selected login palette, and OAuth links have consistent button typography without inherited underlines.

Local regression tests use a local HTTP fixture and synthetic conversations. No real model cold-start measurement, live VPS mutation, real email/message delivery or real browser autofill session was performed by the implementation.

## Five requests and grounding

The site-wide exclusive inference lock has been replaced by a shared pool of five admission slots across PHP workers using the same OS account (`JARRED_MAX_INFLIGHT`, bounded 1–8). Excess callers wait up to `JARRED_ADMISSION_WAIT_MS` (default 1000, maximum 5000), within the existing inference deadline, then receive controlled backpressure. Conversation turn locks remain per user/conversation. Warm-up yields when real users are active and does not consume a user slot. Locks are private to the PHP OS account; run CLI checks as the FPM account `www-data`.

The observed Hostinger machine has **2 CPUs and 8192 MB RAM** (read-only API inventory, November 3, 2026). Five admitted HTTP requests do **not** mean five fast model generations. The supplied Ollama profile decodes two at a time and queues the rest, keeps one model resident and retains a 4096-token context. This is a starting configuration for the repository's default qwen3:1.7b model (the VPS benchmark subsequently confirmed qwen3:1.7b), not measured production capacity. Long synchronous answers can still hit the 45-second deadline on this shared CPU server. Normal DMs use the separate CLI budget below. Do not raise parallelism to five without measuring memory, latency and the impact on other services.

Official scheduler and systemd settings: https://docs.ollama.com/faq#how-does-ollama-handle-concurrent-requests . Ollama manages model scheduling; normal DMs additionally use the durable application queue below.

After pulling, install the profile only if Ollama uses its normal systemd service:

```bash
systemctl cat ollama.service >/dev/null &&
sudo install -d /etc/systemd/system/ollama.service.d &&
sudo install -m 644 deploy/jarred/ollama-concurrency.conf /etc/systemd/system/ollama.service.d/zz-ecollab-concurrency.conf &&
sudo systemctl daemon-reload &&
sudo systemctl restart ollama.service php8.3-fpm
sudo -u www-data php scripts/warm-jarred.php
sudo -u www-data php scripts/benchmark-jarred-concurrency.php
```

The benchmark creates five concurrent synthetic provider requests, reports completion and exact-marker correctness, and never reads/writes accounts or application messages. It measures short replies only. Follow it with five real users using independent conversations, checking successful delivery, answer correctness and unrelated page responsiveness. Verify FPM worker headroom: five synchronous AI requests can occupy five workers. Inspect only the relevant setting with `sudo grep -H -E '^[[:space:]]*pm.max_children[[:space:]]*=' /etc/php/8.3/fpm/pool.d/*.conf`; retain spare workers for normal pages. This patch does not silently resize pools or change Nginx timeouts. If Ollama is containerized, use the same variables in that existing deployment instead of installing a host service override.

Grounding improvements separate retrieved data from instructions, bound evidence as intact JSON with explicit truncation/omission metadata, reduce old DM history when live evidence is supplied, shorten the DM system prompt and use temperature 0.1. They instruct Jarred to acknowledge unverifiable facts rather than inventing app data or treating prior replies as evidence. Backend role/resource checks and active Code Lab restrictions remain authoritative. These changes reduce risk; no prompt or temperature setting guarantees factual accuracy. Reproduce wrong answers with a concrete prompt/reply pair before attributing them to request concurrency.

Rollback the runtime profile: remove only `/etc/systemd/system/ollama.service.d/zz-ecollab-concurrency.conf`, run `sudo systemctl daemon-reload`, then restart the existing Ollama service. Code rollback is a revert of the concurrency/grounding change. No model download is needed; migration 066 belongs to the durable DM queue below.


## Durable Jarred DM replies (migration 066)

Normal DM questions are saved with a durable job and acknowledged without running model generation inside PHP-FPM. Standalone greetings, capability guidance, Code Lab guidance and authorized deterministic actions retain their immediate PHP path. Other AI surfaces still use their existing synchronous budget. Two CLI workers claim jobs across conversations; the queue admits up to 20 active turns, one per conversation. Defaults: `JARRED_JOB_WORKERS=2`, `JARRED_JOB_TIMEOUT=180` seconds (60–300), `JARRED_QUEUE_LIMIT=20` (5–100). Keep two workers on the observed 2-CPU VPS. Five short synthetic requests passing does not establish full-answer throughput.

The conversation shows queued/preparing status and polls saved history even with an active WebSocket. Navigating or refreshing resumes pending status. Failed or legacy unanswered latest questions offer **Retry Reply**, which reuses the saved message without posting duplicate text. A job can still fail if the model is unavailable or exceeds its CLI budget; retry is explicit. Queued jobs expire after 15 minutes. A crashed worker's six-minute lease can be reclaimed once; stale workers cannot publish. Inference runs outside database transactions; successful publication and job completion commit together. Workers recheck account, conversation, server/resource access, preferences and current Code Lab restrictions before publishing. They never replay write actions.

Run from the existing VPS checkout (unit paths assume `/home/ecollabadmin/ecollab-inspect` and PHP account `www-data`):

```bash
cd ~/ecollab-inspect &&
umask 0022 &&
git pull --ff-only origin feat/collaboration-assistance-matching &&
php database/migrate.php &&
sudo install -m 644 deploy/jarred/ecollab-jarred-worker@.service /etc/systemd/system/ &&
sudo systemctl daemon-reload &&
sudo systemctl enable --now ecollab-jarred-worker@1.service ecollab-jarred-worker@2.service &&
sudo systemctl restart php8.3-fpm ecollab-jarred-worker@1.service ecollab-jarred-worker@2.service
systemctl is-active php8.3-fpm ecollab-jarred-worker@1.service ecollab-jarred-worker@2.service
journalctl -u ecollab-jarred-worker@1.service -u ecollab-jarred-worker@2.service -n 30 --no-pager
```

Keep the previously installed two-generation Ollama profile. No Ollama restart or Composer install is required for this queue change. Hard-refresh the browser, reopen Jarred and use Retry Reply on the saved unanswered question. Verify five independent real-user conversations receive exactly one reply each while other pages remain responsive. Inspect worker logs and saved job states if a reply fails. Private messages are not printed in worker logs.

Validation includes SQLite worker/UI fixtures and MySQL 8/MariaDB concurrent claims. These verify durable admission, two-worker bounds, exact-once publication, lease recovery, retry identity and fresh policy enforcement. They do not certify actual model full-answer speed on the VPS.

Rollback: stop/disable both worker instances, revert the queue code together with its UI/API changes, then restart PHP-FPM. Retain additive queue tables and saved messages; do not drop them or replay completed jobs. Pending questions remain saved and can be retried when the queue is restored.
