# eCollab chat capacity test (k6)

Run the load generator from your PC or another machine, not from the eCollab VPS. The private kit contains `target.json`, `accounts.json`, `chat-capacity.js`, and `plan.js`. Each virtual user uses a different generated student account, obtains a real PHP session/CSRF token, reads the private test channel, and opens Ratchet plus Centrifugo connections.

Install k6 on Windows PowerShell:

```powershell
winget install k6 --source winget
```

Open a new PowerShell window after installation. `k6 version` should show a current release; the kit is tested with k6 2.3.0. The official pinned Windows ZIP is available at https://github.com/grafana/k6/releases/download/v2.3.0/k6-v2.3.0-windows-amd64.zip if needed.

From the extracted kit folder, start small:

```powershell
k6 run -e VUS=2 chat-capacity.js
```

Then test the panel's 50–100 student target, one run at a time:

```powershell
k6 run -e VUS=50 chat-capacity.js
```

```powershell
k6 run -e VUS=100 chat-capacity.js
```

You need at least as many fixture accounts as virtual users. If you prepared only 50 accounts, create/package a 100-account fixture first and clean the earlier fixture by its printed manifest path. Do not reuse one login for all virtual users; that tests one PHP session and produces misleading capacity results.

Message writes are optional and stay in the private test channel:

```powershell
k6 run -e VUS=50 -e WRITE_EVERY=3 chat-capacity.js
```

This sends about one test message per active user every three activity ticks, leaving the last five seconds of each connection iteration for delivery. It measures successful message saves and the writer's own Centrifugo inbox notification. It does not measure every recipient's browser rendering latency.

Defaults: 2 users get a short smoke run; larger runs ramp for 60 seconds, hold for 120 seconds, then ramp down. Message reads occur every five seconds while connections are open. Tune `RAMP_SECONDS`, `HOLD_SECONDS`, `INTERVAL_SECONDS`, `CONNECTION_SECONDS`, and `WRITE_EVERY` only to represent your intended student behavior. Use `RAMP_SECONDS=120` for larger tests. The kit supports up to 1,000 generated users; this is a selectable test size, not a claim that the VPS supports 1,000 users.

Stop with Ctrl+C. Error/latency thresholds also abort unhealthy runs. JSON reports include configured/observed VUs, request counts, p95/p99, authentication success, socket success, optional delivery latency, and failed thresholds. Login latency is checked separately from read latency because password hashing consumes CPU. Starting acceptance targets: read p95 below 1.5 seconds, login p95 below 10 seconds, API/auth/socket success above 99%, optional own-inbox delivery p95 below 3 seconds. These are test targets you may revise, not guarantees.

Passing applies only to this workload and dataset. The script does not run browser JavaScript/rendering, fetch every asset, transfer attachment files, exercise AI inference, send video/audio media, or edit Collabora/whiteboards. Test those separately. Increasing virtual users without realistic think time changes the workload and invalidates comparisons. Run with background AI/document workloads only as a separately labeled test and compare results.

`accounts.json` and the ZIP contain generated passwords. Keep them private; do not commit them or use HTTP debug output. Delete your kit credentials after fixture cleanup. Run through the normal HTTPS URL so Nginx/TLS/session/database limits are included.
