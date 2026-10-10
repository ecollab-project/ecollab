# Opt-in Centrifugo saved-message delivery

Centrifugo v6.9.6 and the MIT-licensed Centrifuge JavaScript client 5.7.4 supplement eCollab's existing transport. Keep Ratchet running: calls, typing, Yjs presence, whiteboards, edits, deletes and reactions continue to use it. LiveKit and resumable uploads are unchanged. This integration covers saved channel, direct and group messages, including attachment messages and saved Jarred replies.

## Data and authorization

When enabled, each saved message and its notification intent commit together. A single PHP worker recalculates current authorized recipients and publishes IDs to authenticated per-user inboxes. Broker history contains IDs, never message bodies or file URLs. Clients read content from existing authorized APIs. Access checks still run when replaying a notification; a revoked member cannot fetch private content. Browser publishing is disabled. Connection/subscription JWTs expire after five minutes and refresh through a CSRF-protected authenticated endpoint.

The database remains authoritative. The outbox retries failed deliveries with bounded backoff; an unavailable broker does not make message writes perform network calls. Ratchet delivery remains available. Clients deduplicate IDs and perform database catch-up after subscribing/reconnecting, including after broker restart/history expiry. Inbox history holds at most 128 notifications for ten minutes in memory; it is not a durable message store or a multi-node deployment. Pagination recovers more than one page of missed messages. Background notifications retain their existing mechanisms; this is not a replacement for OS push when the browser is closed.

## VPS deployment

For the current VPS layout: `/home/ecollabadmin/ecollab-inspect`, PHP 8.3, www-data, Docker Compose, Nginx. Copy only command blocks into the VPS terminal. No npm build is needed on the VPS; the pinned browser bundle is committed.

1. Pull and grant access to changed tracked files. The previous commit is recorded for returning to code, independently of database backups.

```bash
cd ~/ecollab-inspect
mkdir -p "$HOME/ecollab-backups"
git rev-parse HEAD > "$HOME/ecollab-backups/before-centrifugo-commit.txt"
git switch ecollab-collabs && git pull --ff-only origin ecollab-collabs

git diff --name-only --diff-filter=AM -z "$(cat "$HOME/ecollab-backups/before-centrifugo-commit.txt")" HEAD |
  sudo xargs -0 -r chgrp www-data
git diff --name-only --diff-filter=AM -z "$(cat "$HOME/ecollab-backups/before-centrifugo-commit.txt")" HEAD |
  sudo xargs -0 -r chmod g+r
sudo chgrp www-data scripts services API/auth API/dm assets/js/chat
sudo chmod g+rx scripts services API/auth API/dm assets/js/chat
sudo chmod 755 deploy deploy/centrifugo
sudo chmod 644 deploy/centrifugo/config.json
```

2. Apply the additive migration and prepare private keys. Preparation keeps the feature off. It backs up `.env` outside the web root and creates a mode-600 broker environment file containing only broker secrets. Never copy the whole application `.env` into the broker container.

```bash
cd ~/ecollab-inspect
php database/migrate.php &&
php tests/centrifugo-regression.php &&
php scripts/configure-centrifugo.php prepare &&
docker compose -f deploy/centrifugo/compose.yaml up -d
```

3. Configure the WebSocket proxy. The installer identifies exactly one ecollab.tech HTTPS block, backs it up, adds an include and runs `nginx -t`. It restores that block if validation fails. If it cannot identify one block, it stops without guessing: manually include `/etc/nginx/snippets/ecollab-centrifugo.conf` inside the correct HTTPS block using the supplied location snippet. Other deployments must also change the allowed origin in config.json and systemd paths.

```bash
cd ~/ecollab-inspect
sudo python3 scripts/install-centrifugo-nginx.py &&
sudo systemctl reload nginx
php scripts/check-centrifugo.php
```

The internal HTTP API binds to loopback through Docker; only the exact WebSocket path is proxied publicly. Do not proxy `/api` or an admin interface. Wait for the container to start if the broker check initially fails; inspect `docker logs --tail 30 ecollab-centrifugo` and repeat the check.

4. Enable after broker/database checks pass, start the worker and reload PHP. Existing chat tabs need a reload to load the new adapter. Ratchet does not need a restart for this upgrade.

```bash
cd ~/ecollab-inspect
php scripts/configure-centrifugo.php enable &&
sudo install -m 644 deploy/centrifugo/ecollab-realtime.service /etc/systemd/system/ecollab-realtime.service &&
sudo systemctl daemon-reload &&
sudo systemctl enable --now ecollab-realtime &&
sudo systemctl reload php8.3-fpm
sudo systemctl is-active ecollab-realtime
sudo -u www-data php /home/ecollabadmin/ecollab-inspect/scripts/check-centrifugo.php
```

## Acceptance checks

With two accounts, test channel, direct and group text/image messages without refreshing. Disconnect one browser from the network, send messages from the other, then reconnect; verify catch-up and no duplicates. Send over 50 messages during a disconnect to exercise pagination. Remove private-channel/group membership and verify revoked content stays inaccessible. Test calls and whiteboard presence separately to confirm their existing transport still works.

DevTools should show a successful `/realtime/connection/websocket` handshake (101), not merely a ws-token HTTP 200. `window.EcollabDelivery.state` should report `connected`. `check-centrifugo.php` should show pending notification count returning to zero after sends. Inspect `journalctl -u ecollab-realtime -n 30 --no-pager` and `docker stats --no-stream ecollab-centrifugo` while also using AI/calls. Container cap: 256 MB/0.5 CPU; worker cap: 128 MB/0.25 CPU. These caps are limits, not measured expected utilization or guaranteed latency.

## Rollback

Disable the optional transport first, preserving all saved messages and the additive table:

```bash
cd ~/ecollab-inspect
php scripts/configure-centrifugo.php disable &&
sudo systemctl stop ecollab-realtime &&
sudo systemctl reload php8.3-fpm
```

Reload chat tabs. Existing Ratchet delivery remains in use. You can then stop the broker with `docker compose -f deploy/centrifugo/compose.yaml stop`. Do not restore an old full database backup merely to disable this transport. For code rollback use the saved commit and your existing backup procedure; permissions still need to permit www-data traversal/read access. Published intents are retained for three days; pending intents are retained until delivered.

## Development checks

`npm ci --ignore-scripts --prefix tools/chat-delivery`; `npm run build --prefix tools/chat-delivery`; `node tests/centrifugo-client-regression.cjs`; `php tests/centrifugo-regression.php`. The chat-delivery workflow tests a real pinned broker, inbox authentication, denied client publish, history recovery, and a disposable MySQL fixture for recipient authorization, transaction rollback and retry. Never run the disposable database fixture against the production database.

Primary references: https://centrifugal.dev/docs/server/configuration, https://centrifugal.dev/docs/server/server_api, https://centrifugal.dev/docs/server/channel_token_auth, https://github.com/centrifugal/centrifuge-js.
