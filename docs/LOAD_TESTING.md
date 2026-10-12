# Chat/API capacity testing

This upgrade adds an optional k6 test kit and CLI fixture management. It makes no changes to the live UI, application environment flags, Nginx, PHP-FPM, Ratchet, Centrifugo, LiveKit or AI configuration. There is no migration and no new service to install on the VPS. k6 runs elsewhere.

## Prepare on the VPS

The fixture helper creates normal student accounts with random passwords, a private server/channel, explicit memberships and 50 sample messages inside one transaction. It sends no signup email. Credentials stay mode-600 outside the web root. These are real logins through the existing API, not bypass sessions or disabled rate limits. Test accounts are visible to administrators as `Load Test Student`; ordinary students cannot access the private fixture server. Cleanup later deactivates the accounts and removes the private server/messages.

First test 2 users; a 50-account fixture is enough for smoke and the first 50-user run:

```bash
cd ~/ecollab-inspect
git switch ecollab-collabs && git pull --ff-only origin ecollab-collabs &&
php scripts/load-test-fixture.php create 50 https://ecollab.tech wss://ws.ecollab.tech &&
python3 scripts/package-load-tests.py
```

The files are CLI-only; they need no www-data access or service reload. Keep the printed manifest path. `package-load-tests.py` builds `/home/ecollabadmin/ecollab-load-tests/ecollab-k6-kit.zip` from the latest fixture. The ZIP includes only synthetic credentials, target IDs and runner source; it never includes `.env` or LiveKit/Centrifugo secrets.

## Copy to your Windows PC

Open Windows PowerShell on your PC, separate from your VPS terminal:

```powershell
New-Item -ItemType Directory -Force "$HOME\ecollab-capacity-test" | Out-Null
Set-Location "$HOME\ecollab-capacity-test"
$EcollabSshHost = Read-Host 'VPS SSH host or IP'
scp "ecollabadmin@${EcollabSshHost}:/home/ecollabadmin/ecollab-load-tests/ecollab-k6-kit.zip" .
Expand-Archive -Force .\ecollab-k6-kit.zip .\kit
winget install k6 --source winget
```

Open a new PowerShell window after installation, then:

```powershell
Set-Location "$HOME\ecollab-capacity-test\kit"
k6 run -e VUS=2 chat-capacity.js
```

If the smoke test passes, try 50 users:

```powershell
k6 run -e VUS=50 chat-capacity.js
```

Optional writes/delivery test:

```powershell
k6 run -e VUS=50 -e WRITE_EVERY=3 chat-capacity.js
```

Use the runner README for additional profiles. Stop increasing load when thresholds fail. Do not skip directly to 1,000 users. Prepare a larger distinct-account fixture before attempting a higher VU count, and retain every manifest path for cleanup.

## Observe the VPS in another SSH terminal

```bash
vmstat 1 30
```

```bash
docker stats --no-stream
systemctl is-active ecollab-websocket ecollab-realtime php8.3-fpm
```

```bash
sudo journalctl -u ecollab-realtime -u ecollab-websocket -n 30 --no-pager
sudo tail -n 30 /var/log/nginx/error.log
```

Record the test's user count/profile alongside its JSON results and CPU/memory observations. Failed authentication or socket checks indicate a functional/configuration limit, not just slow rendering. The generator's CPU/network can also limit a large run; measure it separately. Passing 50 users for this read/connection profile is not proof that 50 simultaneous video callers or AI prompts work.

## Cleanup after stopping the runner

On the VPS, cleanup the latest fixture:

```bash
cd ~/ecollab-inspect
php scripts/load-test-fixture.php cleanup
```

For older fixtures supply their printed full manifest path. Cleanup validates the exact synthetic identities, server ownership, channel and membership. It refuses to remove a fixture with a real member/message or changed channels. It removes fixture message outbox rows, messages and server, revokes test passwords/remember tokens, marks test accounts deactivated/deleted, and removes unused WebSocket tokens. Authentication audit history remains. The manifest is retained without passwords; the latest ZIP is removed. Delete the ZIP and accounts.json copies from your PC afterward.

If creation fails, the DB transaction rolls back. There is no application rollback needed because runtime files were not changed.

## Validation

The load-test-kit workflow runs the real pinned k6 2.3.0 binary against a local mock application, verifying distinct logins/cookies, CSRF, reads, Ratchet joins, broker ping responses, HTTP 201 saves and publication timing. A failure case verifies refused writes fail the test. A disposable MySQL fixture validates account creation, private file modes, cleanup guards and rollback. CI does not send traffic to ecollab.tech and does not establish production capacity.

Primary docs: https://grafana.com/docs/k6/latest/using-k6/k6-options/reference/ and https://grafana.com/docs/k6/latest/javascript-api/k6-websockets/.
