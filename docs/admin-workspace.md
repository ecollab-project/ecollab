# Admin Workspace v2

The admin dashboard at `/modules/admin/dashboard.php` now starts with four focused
cards, an actual review queue, a seven-day activity chart, a capacity comparison,
service availability and recent users. All other screens sit in Workspace,
Review, Insights and Tools navigation groups. `Ctrl/Cmd + K` finds a page.

## Connected screens

- **People:** paginated search by name/username/email, role/status filters, account
  details, account creation, protected role changes, ban/unban with reason, and
  export of the currently displayed page. Synthetic k6 users are excluded unless
  explicitly included. Self-modification and changes to super admins are rejected;
  admin accounts cannot be banned using this screen. Stale changes return 409.
- **Spaces:** paginated server/channel search, actual owner/member/channel counts,
  visibility/status, and links to existing server monitoring. Creating/deleting
  spaces remains in the messages workspace rather than a pretend admin control.
- **Courses & interests:** actual academic programs and interest tags, plus audited
  interest creation. Academic program configuration remains read-only here.
- **Reports:** existing student-owned report scope and backend review semantics.
  Dismissal or resolution/removal requires a confirmation and review note.
  Facilitator-owned reports stay with their facilitator.
- **Facilitator requests:** existing approval API, uploaded proof links and review
  notes. Approving access is a deliberate confirmation step.
- **Feedback:** paginated status filters and audited status transitions.
- **Activity insights:** selectable 7/30/90-day message/contributor series, role
  counts and export. Contributors sent a non-deleted message; they are not all
  users who logged in or were online. Days use Asia/Manila and take the database
  timestamp offset into account. Missing database sources remain unavailable,
  not fabricated zeros. Synthetic message senders are excluded.
- **AI & peer matching:** local-service availability and recorded peer-match outcomes
  and scores, with the existing local-LLM group recommender. Compatibility scores
  are not accuracy. No training progress or model accuracy is invented.
- **System capacity:** preserves migration 053, test records, forecasts, audits,
  exclusions and the existing minute collector. Its refresh follows preferences.
- **System status:** 1-minute load (not CPU utilization), memory, disk, uptime,
  PHP version, bounded DB/Centrifugo/semantic/Ollama availability checks, outbox
  backlog and recorded application errors. Cloud AI shows configuration only.
- **Audit trail:** paginated action/actor search, severity filters and current-page
  export. It shows application activity, not an exhaustive OS or security log.
- **Announcements:** explicit selected-server confirmation; publication and existing
  realtime delivery intents are saved atomically. Only existing announcement
  channels are used. The selector lists the latest 100 servers.
- **Preferences:** dark/light/system theme, compact rows, 30/60s/manual refresh,
  stored per account in that browser; working password change and sign-out.

No fake settings toggles, dummy success notifications or editable secrets are
shown. CSV cells that could start spreadsheet formulas are escaped. Rendering of
user-controlled text uses DOM text nodes, including messages and evidence.

## Refresh and security

Only the current visible page refreshes. Normal refresh defaults to 30 seconds;
service checks are reused for 60 seconds on Overview. Manual refresh can request
new checks. Host/service requests release the PHP session before probing. The
local group recommendation endpoint also releases its session before inference.
No profiles or conversations are sent by health probes. HTTP probes use fixed
loopback-only configured URLs, deadlines and response-size bounds.

All new APIs require admin/super_admin sessions. Writes require the shared CSRF
validator, explicit POST, validation, database transactions and activity records.
User actions verify expected role/status. Ban/unban clears remember tokens and
online flags; existing application session enforcement remains unchanged.

## Deployment

A backup of `ecollab-collabs` before this upgrade is preserved as
`backup/ecollab-collabs-before-admin-v2-20261013`, including the tracked Centrifugo
config/Compose/service/Nginx files. Runtime secrets remain on the VPS.

No new database migration or replacement cron job is needed. Migration 053 must
already be installed for capacity data. On the current VPS checkout:

```bash
cd /home/ecollabadmin/ecollab-inspect
# Use a readable umask for this pull only, without changing secret permissions.
( umask 0022
  git pull --ff-only origin ecollab-collabs
)
bash scripts/deploy-admin-workspace.sh
```

The helper stops on tracked local changes and checks PHP syntax. It adjusts mode
644 only for its explicit public dashboard code/assets list; it does not recursively
change permissions or touch `.env`, uploads or Centrifugo runtime configuration.
Hard-refresh the browser and verify the production read endpoints and one reversible
admin action. Existing calls and server monitor routes remain available.

## Validation

```bash
php tests/admin-workspace-regression.php # pdo_sqlite for isolated tests
php tests/system-capacity-regression.php
node --check assets/js/admin/workspace.js
node --check assets/js/admin/system-capacity.js
bash -n scripts/deploy-admin-workspace.sh
```

The isolated query tests cover actual pagination, canonical-schema visibility,
search binding, synthetic exclusions, role protections, optimistic conflicts,
feedback transitions, user/profile atomicity, audits and announcement/outbox rollback.
JSDOM checks cover the complete rendered PHP layout, navigation, safe text output,
user dialogs, CSRF/expected-state payloads, error handling, preferences, the finder,
and all connected screens. These tests are not a signed-in production MariaDB or
browser visual smoke test. Verify those after deployment.


## Connected admin pages and tools

The admin workspace now includes **Tools & workflows** and **Space monitor**. Tool cards navigate within the shared shell to reports, facilitator approvals, feedback, people/spaces, announcements, AI/matching, audit, activity, capacity, and status. Messages, the collaboration hub, and personal Settings remain dedicated modules with explicit links.

The legacy admin monitor URL (`modules/admin/server-monitor.php?server_id=…`) redirects to `dashboard.php?page=monitor&server_id=…&tab=…`. The `reports.php` and `tools.php` entry routes authenticate and redirect to their canonical workspace views. Facilitator-scoped monitoring retains its existing authorization and route behavior.

Space monitoring shows academic collaboration data, not VPS resource metrics. It supports 7/30/90-day message and contributor activity using Philippines day boundaries, owner identification, channels, current members, recent messages, and reports. The owner is excluded from member/contributor summary counts and contributor chart values. Message counts include owner messages; current member rows identify the owner. Contributors can include former members who sent messages in the selected period. Deleted messages are excluded. Source failures remain null/unavailable rather than fabricated zero counts. Daily values remain readable when the external chart library fails to load.

Monitor channel/member lists are bounded to 200/500 records, with local filtering and 20-row pagination. Recent activity and reports show the latest 30, independently of the chart date range. The space picker searches 25 matches at a time; refine the query for further spaces. CSV exports contain the selected monitor's daily activity values. Browser history preserves monitor space, tab, and date range.

Reports now use `API/admin/reports.php` and `AdminReportService`: database pagination, search, status and space filters, an evidence preview, explicit confirmed decisions, revision checks, and transactional audit logging. Resolution rejects reports that were handled or whose evidence changed since the review was loaded. Resolving a report with message evidence marks that message deleted; resolving a report without message evidence records the decision only. Dismissal does not remove content. The existing student-owned-space moderation scope is retained; facilitator-owned-space monitor reports are visible for inspection and indicate that their facilitator handles review actions.

Deploy with the existing `scripts/deploy-admin-workspace.sh` after pulling with `umask 0022`. It now covers the new routes, API, and services. No new migration is required. Backup: `backup/ecollab-collabs-before-admin-tools-v2-20261013`, including tracked Centrifugo configuration.

Additional checks:

```bash
php tests/admin-connected-regression.php
# jsdom must be available to Node. PHP_BIN optionally selects PHP.
node tests/admin-connected-ui-regression.cjs
```

The PHP tests use PDO SQLite for report scope, search/pagination, changed evidence, stale decisions, atomic audit rollback, monitor date windows/counts, and unavailable sources. The DOM tests render the actual authenticated PHP page and exercise tools, monitor links/tabs/search/pagination/date ranges/deep links/history, report scope/pagination/confirmation/CSRF/revisions, safe text rendering, and unavailable requests. Production MySQL integration and visual checks in an actual browser remain deployment checks.
