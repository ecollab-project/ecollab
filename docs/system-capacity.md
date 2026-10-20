# Admin system capacity

Open **Admin dashboard → Analytics & Insights → System Capacity**, or
`/modules/admin/dashboard.php?page=capacity`.

The capacity reference is a completed, passed test for a named workload. The
migration seeds the administrator-provided 2026-10-13 chat results: **300 users
passed**, **350 users failed**. The 300-user result includes a five-minute hold;
HTTP p95 438.714ms, own-message delivery p95 2265.900ms, and API success 99.99780%.
These are supplied historical records, not a benchmark performed by this feature.
No operational target is assumed. The maximum capacity remains undetermined.

Admins can add immutable evidence records, select a completed passed test as the
reference, and set or clear an operational target at or below that reference.
Settings changes retain actor IDs and prior values. Concurrent edits are rejected;
refresh before retrying. This feature never enforces a login or connection limit.

## Deployment

After merging the capacity change into `ecollab-collabs`, from the deployed checkout:

```bash
git pull --ff-only origin ecollab-collabs
php database/migrate.php --status
php database/migrate.php --dry-run
php database/migrate.php
php scripts/collect-system-capacity.php
```

Review all pending migrations shown by the runner before applying them. The new
migration is `053_system_capacity.sql`.

Install a minute collector under a user that can read the application's `.env` and
connect to its database. For the current VPS checkout, add to `crontab -e`:

```cron
* * * * * /usr/bin/php /home/ecollabadmin/ecollab-inspect/scripts/collect-system-capacity.php >> /home/ecollabadmin/capacity-collector.log 2>&1
```

Set normal log rotation for that log. Collection continues when no dashboard is
open. The collector retains 90 days and inserts at most one sample per UTC minute.
GET requests remain read-only; viewing the admin dashboard does not manufacture
historical samples. Check `last_sample_at` in the response and the UI stale warning
if cron is stopped. No history is backfilled before installation.

## Meaning of the counts

- **Active users:** unique non-deleted, non-banned/non-deactivated/non-suspended
  users marked online whose latest activity or login timestamp is within 2 minutes.
- **Recent presence:** the same rule with a 10-minute window. Each user is counted
  once, regardless of tabs or transports. Existing application presence is an
  estimate; the page does not claim to know exact open WebSocket users.
- Synthetic accounts matching `k6_%@example.invalid` are excluded from these live
  production counts. Load-test capacity remains recorded separately.
- Today's peak and hourly bars are maxima of minute samples in **Asia/Manila**.
  Missing hours stay null; gaps are not interpreted as zero. Minute sampling can
  miss shorter spikes. Only today's collected history appears in the chart.
- Usage percentage and the expected-peak calculator compare counts to recorded
  test evidence. They do not predict latency, CPU, or mixed-workload performance.

The API permits only authenticated admin/super_admin users. POST requires CSRF,
validates dates and metric ranges, and saves records/settings/audit atomically.

## Verification

```bash
php tests/system-capacity-regression.php # needs pdo_sqlite (test only)
node --check assets/js/admin/system-capacity.js
```

On the deployment, verify the migration, run the collector, inspect the page,
save a target and a failed test (reference must stay unchanged), and verify that a
student/facilitator receives 403 and a POST without CSRF is denied. A production
MySQL/MariaDB database and signed-in session are required for those smoke checks.
