# Jarred cold starts and pending replies

Plain standalone greetings (hello, hi, hey, good morning/afternoon/evening, kumusta/kamusta) are answered by PHP without loading a model. Greetings containing a real question still use normal assistance. Persisted active Code Lab restrictions continue to apply before greeting fallback.

Interactive Ollama calls have a 45-second maximum wait. Tool rounds share that budget. A transport timeout returns a controlled failure instead of waiting 90 seconds for inference. The CLI-only warm-up has a separate 90-second load budget. Actual end-to-end time still includes middleware, data lookup and upstream configuration; this is not a measured VPS latency guarantee.

AI pending state belongs to its conversation. Moving to another DM/group clears the visible indicator, and returning to the original pending conversation restores it. Timeout/network uncertainty retains an unconfirmed outgoing item and asks the user to check saved history rather than implying the send definitely failed. No automatic POST retry is added. Stored history is not rewritten; control markers/reasoning blocks in old Jarred replies are filtered when displayed. Human messages are unchanged.

## Pull and pre-load on the VPS

After pulling `feat/collaboration-assistance-matching`, restart PHP-FPM and refresh the browser. No migration or dependency change is required for this patch.

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

If loading fails, inspect the existing local Ollama service/configuration. Do not change the Nginx timeout to mask a failing model. Disable the warm timer with `sudo systemctl disable --now ecollab-jarred-warm.timer` if rolling back. The code update has no database rollback.

## Acceptance

- Cold model: hello replies without an inference call; a substantive question still generates normally.
- Start Jarred request, switch to a human/group chat: no Jarred indicator there. Return while pending: indicator visible only there. Completion clears it.
- A timed-out provider frees its admission slot, returns a controlled error and leaves the UI usable. Verify actual gateway limits/logs separately if a 502/504 persists.
- Saved-email autofill matches the selected login palette, and OAuth links have consistent button typography without inherited underlines.

Local regression tests use a local HTTP fixture and synthetic conversations. No real model cold-start measurement, live VPS mutation, real email/message delivery or real browser autofill session was performed by the implementation.

## Five requests and grounding

The site-wide exclusive inference lock has been replaced by a shared pool of five admission slots across PHP workers using the same OS account (`JARRED_MAX_INFLIGHT`, bounded 1–8). Excess callers wait up to `JARRED_ADMISSION_WAIT_MS` (default 1000, maximum 5000), within the existing inference deadline, then receive controlled backpressure. Conversation turn locks remain per user/conversation. Warm-up yields when real users are active and does not consume a user slot. Locks are private to the PHP OS account; run CLI checks as the FPM account `www-data`.

The observed Hostinger machine has **2 CPUs and 8192 MB RAM** (read-only API inventory, November 3, 2026). Five admitted HTTP requests do **not** mean five fast model generations. The supplied Ollama profile decodes two at a time and queues the rest, keeps one model resident and retains a 4096-token context. This is a starting configuration for the repository's default qwen3:1.7b model (the live model/version and current free RAM remain unverified), not measured production capacity. Long answers can still hit the 45-second deadline on this shared CPU server. Do not raise parallelism to five without measuring memory, latency and the impact on other services.

Official scheduler and systemd settings: https://docs.ollama.com/faq#how-does-ollama-handle-concurrent-requests . Model queueing is managed by Ollama; no durable/background app job queue is introduced here.

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

Rollback the runtime profile: remove only `/etc/systemd/system/ollama.service.d/zz-ecollab-concurrency.conf`, run `sudo systemctl daemon-reload`, then restart the existing Ollama service. Code rollback is a revert of the concurrency/grounding change. No database migration or model download is needed.
