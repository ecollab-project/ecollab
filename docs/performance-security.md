# Request performance and security hardening

## Deployment

Back up the database using your existing VPS backup process, then deploy from `ecollab-collabs` and **apply migration 061 before serving normal traffic**:

```sh
cd ~/ecollab-inspect
git pull --ff-only origin ecollab-collabs
php database/migrate.php --dry-run
php database/migrate.php
```

Hard-refresh chat tabs to load the updated voice-status client. No Docker, kernel, firewall, PHP-FPM pool or model-size changes are made. Existing Centrifugo configuration is retained.

## Changes

- Chat history/channel reads, active presence, DM open/list and notification reads release the session lock before database work. A slow request no longer unnecessarily serializes other requests from that same session. These routes do not write session state; database authorization/read tracking is retained.
- Channel reads no longer run table creation. Notification polls no longer query table/column metadata. Existing migrations 009 and 031 own those schemas.
- Migration 061 owns the rate-limit table and adds `(lookup_key, created_at)` for window queries. Existing counters are preserved. Runtime DDL is removed. The migration is repeatable.
- Rate limit count+insert is serialized per database/action/identity using a bounded MariaDB advisory lock. Contention fails closed. Locks are released on database errors. Retry time uses the database clock, not PHP timezone. Long action names fit the existing key column instead of overflowing it. Cleanup per accepted attempt is capped at 100 old rows for that identity.
- Rate limits, account lockout and audit logging use one client-IP resolver. Untrusted forwarding headers are ignored. `TRUSTED_PROXIES` is an optional comma-separated list of exact IPv4/IPv6 proxy addresses or CIDRs. It defaults to empty: direct Nginx/FPM installations use `REMOTE_ADDR`. If Nginx real-IP handling already sets the actual client, leave it empty. Never trust arbitrary client networks or `0.0.0.0/0`. When using a genuine proxy chain, configure only its verified network ranges; the resolver walks X-Forwarded-For from the actual peer toward the first untrusted hop. A proxy must overwrite its client-IP headers. This change may aggregate limits by edge IP until a proxied installation is configured correctly.
- Both CSRF implementations reject absent, empty and non-string tokens. Voice presence joins/leaves require POST and a CSRF token. The voice client now includes its existing session token.
- The shared session starter enables strict IDs, cookies only and disables URL-based session IDs. Existing cookie security configuration is preserved: production HTTPS must keep `SESSION_SECURE=true` in its private configuration.
- Internal database errors from chat history are not returned to users.
- Local Ollama transport accepts only loopback HTTP, rejects credentials/query/path tricks, ignores proxy environment variables, does not follow redirects and caps output at 1024 tokens. The existing inference lock, 4096 context and CPU thread cap are retained. Remote Ollama URLs are intentionally unsupported by this local-VPS service.

## Jarred cold starts

The observed 21-second first answer was mostly model loading. Before a demonstration or peak-use period, preload it without reading user data or generating an answer:

```sh
sudo -u www-data php scripts/warm-jarred.php
```

The command respects the same non-blocking inference lock as normal requests. It keeps the model resident for the existing 10-minute idle period, with matching context/thread options. It creates no daemon or periodic load; models can still unload under memory pressure. Do not run as root, which can leave the inference lock owned by the wrong user. See [Ollama's preload documentation](https://docs.ollama.com/faq#how-can-i-preload-a-model-into-ollama-to-get-faster-response-times).

## Validation and limits

Tests exercise spoofed/direct/trusted IPv4/IPv6 IP chains, real PHP CSRF/session handling, 12 concurrent MariaDB requests against a quota of three, long-action limits, DB-clock retry timing, error-path lock release, repeated migration, real cURL redirect/proxy behavior and model warm-up payloads. Existing settings, browser, calls and notification checks remain enabled.

These are focused fixes, not a penetration-test certificate or a new capacity measurement. Retest the existing k6 workload to measure latency/throughput after deployment; monitor CPU/RAM alongside it. VPS OS patching, backup retention, firewall exposure, production TLS/session settings and secret rotation remain operational controls and were not changed remotely.

References: [PHP session security](https://www.php.net/manual/en/session.security.ini.php), [OWASP CSRF](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html), [MariaDB GET_LOCK](https://mariadb.com/docs/server/reference/sql-functions/secondary-functions/miscellaneous-functions/get_lock).

### Bounded chat member loading (2026-10-18)

`GET /API/chat/get-channel.php?id=...` now returns the first 20 visible
members, plus `member_total` and `members_complete`. The sidebar already
renders 20 rows. `members=all` explicitly requests the full directory for
member search and mentions; `members=none` skips both roster and count work
for the existing read-marker heartbeat. All modes retain the channel and
private-channel access checks and `no-store` responses. Directory-only
requests do not advance the read marker.

The member dialog now searches the full directory rather than the 20 DOM
rows. Concurrent directory requests share one in-flight HTTP request;
channel switches discard stale responses. Refreshes replace the preview
and its count, so presence and membership are not permanently cached.
No schema, compiler, AI, broker or load-test configuration changes are needed.

Deploy with `git pull --ff-only origin ecollab-collabs` and reload PHP-FPM
if OPcache timestamp validation is disabled. Repeat the existing 300-user
120-second ramp / 300-second hold benchmark with the same parameters.
Compare HTTP times, received bytes, API failures, socket failures and own
message delivery; the earlier run received 1.79 GB. A smaller response is
expected, but throughput and latency improvement require a new VPS run.
The load-test script and workload have not changed.
