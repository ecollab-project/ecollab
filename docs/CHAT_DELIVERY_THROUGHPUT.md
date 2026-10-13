# Delivery throughput changes

The 300-user test on a 2-vCPU VPS saturated CPU and exceeded delivery latency and success thresholds. These changes reduce worker overhead; they do not establish a new capacity claim.

- Channel audiences start from indexed server membership and union authorized global administrators. Audience permissions are recalculated for every event, including deleted messages, revoked membership and deleted users. No audience cache is introduced.
- A retained cURL handle reuses local HTTP connections. Install/enable PHP cURL to use this path; the stream fallback still works but does not provide connection reuse.
- Broadcast requests contain at most 500 unique user inboxes, reducing a 1,000-member audience from ten calls to two. Notifications still reach authorized offline members' inbox history.
- The broker object remains alive across worker iterations. Full successful batches use a 1 ms yield rather than the 250 ms idle delay. Partial/failed batches retain the idle delay and existing retry backoff. The single-worker database lock is retained.
- Every 30 seconds of activity the worker logs delivered events, cumulative dispatch duration, pending count and oldest pending age. Logs contain no message bodies, recipient identities or credentials. The broker check also reports pending age and retry count.

Publication failures, malformed replies and per-recipient errors remain failures. An event is marked published only after every batch succeeds. A retry can repeat prior successful batches; clients must continue deduplicating message/event IDs.

## Deploy

Save the prior commit and copies of the four production files outside the application. Pull the fast-forward update, grant www-data read access to the changed files, run PHP syntax checks and `php tests/centrifugo-regression.php`, then reload PHP-FPM and restart `ecollab-realtime`. Verify broker health and worker summaries. No database migration or secret change is required. The separately added message index remains in place.

Repeat the existing 100-user smoke and 300-user workload, retaining separate result files. Compare HTTP/delivery p95, successful deliveries, CPU, pending age and retry count. Do not relax thresholds to claim improvement. Browser rendering, media, uploads, AI and document editing are outside this workload.

## Rollback

Restore only `services/RealtimeOutbox.php`, `services/CentrifugoService.php`, `scripts/realtime-worker.php` and `scripts/check-centrifugo.php` from the saved pre-update commit/copies, restore www-data read access, reload PHP-FPM and restart `ecollab-realtime`. Keep the database, saved messages and current environment. Do not rewind unrelated upgrades or delete outbox rows.
