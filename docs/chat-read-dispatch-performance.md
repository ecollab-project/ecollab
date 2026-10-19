# Exact unread counts and delivery-phase diagnostics

This change targets the channel-list unread query measured at roughly 23ms in
the October 19 profile. It does not change FPM limits, broker CPU quotas, rate
limits, polling frequency, load-test thresholds or message delivery semantics.

Channel listing first fetches only authorized channels and their read timestamps.
Exact unread counts then use bound channel/timestamp values in batches of up to
50 `UNION ALL` count queries. This lets the existing
`messages(channel_id,is_deleted,created_at)` index use timestamp ranges instead
of evaluating a nested read-marker lookup in the message filter. No new index
or migration is required on the deployment already containing
`idx_channel_deleted_created`.

The old timestamp semantics remain: strictly newer, nondeleted messages count;
own messages still count; no read marker means the original 1970 cutoff. Counts
are neither capped nor estimated. Admins retain zero unread counts. Membership,
private-channel visibility, ordering, `is_new`, and server-list fields are
unchanged. Neither permission results nor read timestamps are cached. Internal
cutoff fields are removed from API responses. The two read stages can observe
concurrent message/read-marker changes; displayed counts remain advisory as
with live chat, without adding a transaction to HTTP requests.

## Deploy

Backup branch: `backup/before-chat-read-dispatch-20261019` (includes tracked
Centrifugo configuration; private environment files are not copied to Git).

```bash
cd ~/ecollab-inspect
git pull --ff-only origin ecollab-collabs
sudo systemctl reload php8.3-fpm
sudo systemctl restart ecollab-realtime
sudo systemctl is-active php8.3-fpm ecollab-realtime
php scripts/profile-chat-reads.php 80
```

Restart only the outbox dispatcher, not the WebSocket or Centrifugo service.
Delivery intents remain durable; a publication interrupted before acknowledgement
can be retried with its existing event ID and client deduplication behavior.

## Read-only before/after comparison

`channel_list_unread_legacy` measures the original query.
`channel_list_unread` includes both the authorized-channel read and ALL count
batches, including their database round trips. `count_plan` explains the first
count batch; verify range access on the timestamp index. `exact_counts_match`
must be true for the fixture student. Both implementations run inside the same
read-only transaction snapshot. No markers or user data are changed.

`server_list` now profiles the same query/columns used by the real endpoint.
The CLI still returns only timings, row counts and plans, never names, messages,
file paths, SQL bind values or read timestamps. These SQL timings exclude FPM
queueing, HTTP, session locks, encoding and read-marker writes.

The MySQL 8 and MariaDB 10.11 regressions compare exact counts over 50,000 old
messages and more than 50 visible channels. They exercise cutoff equality,
deleted messages, no-marker fallback, own messages, private members/creators/
owners/admins, revoked permissions and foreign-server exclusion.

## Delivery phases

Every approximately 30 seconds the existing `delivery_summary` also reports
`window_seconds`, `attempted`, `failed`, `recipient_targets`, and `phases`.
Each phase has `calls`, `total_ms`, `avg_ms`, and `max_ms`:

| Phase | Measured wall time |
|---|---|
| select | Read the next outbox batch |
| recipients | Fresh permission-aware recipient query and list construction |
| broadcast | All broker requests needed to publish one event |
| acknowledge | Mark that event published in the database |
| retry | Store backoff after a failed operation |

Failed operations are timed too. `recipient_targets` sums recipient counts over
attempts, not unique online users or confirmed deliveries. A broker broadcast
may require multiple bounded HTTP requests. `dispatch_ms` remains accumulated
wall time over dispatch calls in the window, not single-message latency or CPU
usage. Phase totals are also wall time and include scheduling/I/O waits.
They cannot identify CPU versus database/broker contention without host metrics.
Only bounded aggregates are stored; no extra per-message logging is added.

```bash
sudo journalctl -u ecollab-realtime --since '10 minutes ago' --no-pager -o cat | grep delivery_summary
```

After confirming count equivalence and reviewing timings, repeat the SAME 300-user
chat profile from the Windows kit, with other load tests stopped:

```powershell
Set-Location "$HOME\ecollab-capacity-test\kit"
Test-Chat -Users 300
Get-Content (Get-ChildItem .\results-chat-300-*.json | Sort-Object LastWriteTime -Descending | Select-Object -First 1).FullName -Raw
```

No runner refresh is needed if `Test-Chat` is already defined in that PowerShell
session. Keep the existing 120s ramp, 300s hold, 5s read interval, 30s connections
and `WRITE_EVERY=3`. Require full schedule, actual exit 0 and unchanged thresholds;
repeat-validated 300-user capacity still needs two independent passes after this
deployment. These changes do not establish mixed compiler/media/AI capacity.
