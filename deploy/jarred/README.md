# Jarred cold starts and pending replies

Plain standalone greetings (hello, hi, hey, good morning/afternoon/evening, kumusta/kamusta) are answered by PHP without loading a model. Greetings containing a real question still use normal assistance. Persisted active Code Lab restrictions continue to apply before greeting fallback.

Interactive Ollama calls have a 45-second maximum wait. Tool rounds share that budget. A transport timeout returns a controlled failure instead of waiting 90 seconds for inference. The CLI-only warm-up has a separate 90-second load budget. Actual end-to-end time still includes middleware, data lookup and upstream configuration; this is not a measured VPS latency guarantee.

AI pending state belongs to its conversation. Moving to another DM/group clears the visible indicator, and returning to the original pending conversation restores it. Timeout/network uncertainty retains an unconfirmed outgoing item and asks the user to check saved history rather than implying the send definitely failed. No automatic POST retry is added. Old messages already stored with control-token text are not rewritten.

## Pull and pre-load on the VPS

After pulling `feat/collaboration-assistance-matching`, restart PHP-FPM and refresh the browser. No migration or dependency change is required for this patch.

```bash
cd /home/ecollabadmin/ecollab-inspect
php scripts/warm-jarred.php
```

This loads the configured local model with an empty message list. It sends no user conversation and generates no answer. It can report busy if another request owns the inference lock. Run it before a demonstration or use the timer below to keep the model resident between conversations. Existing payloads retain it for ten minutes; the timer refreshes every eight minutes. This keeps model memory allocated while enabled.

Review the default user/path in the supplied unit files before installing:

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
- A timed-out provider frees the inference lock, returns a controlled error and leaves the UI usable. Verify actual gateway limits/logs separately if a 502/504 persists.
- Saved-email autofill matches the selected login palette, and OAuth links have consistent button typography without inherited underlines.

Local regression tests use a local HTTP fixture and synthetic conversations. No real model cold-start measurement, live VPS mutation, real email/message delivery or real browser autofill session was performed by the implementation.
