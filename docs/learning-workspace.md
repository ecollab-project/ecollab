# Student and facilitator learning workspaces

The default student and facilitator dashboards share a responsive learning-focused interface. The admin dashboard is separate. These views do not request system health, capacity, infrastructure, broker diagnostics, or AI training metrics.

Students see joined spaces, discover publicly joinable spaces, accessible study sessions and files, personal notes, a personal planner, message activity, and notifications. Jarred uses the existing conversation interface; study buddies use the existing matching and connection workflow. Joining checks current visibility, active status, and active bans on the server, with an idempotent membership insert.

Facilitators select a space they own. They see members and message participation over 30 days (owner excluded), resources, upcoming sessions, announcements, reports, and member collaboration permissions. Sessions are saved in `study_room_sessions` in a channel belonging to the selected owned space. Announcements save message, delivery intent, and notifications together; private announcement-channel notifications follow channel access. Reports are reviewed inside the workspace through the scoped learning tools API. The account shortcut opens the new Preferences section. Profile/account editing opens the current chat Settings page. Student dashboard links stay on the new workspace or open current chat/collaboration modules. Legacy `modules/student/tools.php` and `modules/facilitator/tools.php` routes now authenticate and redirect to current workspace pages, preserving recognized page aliases and selected-space context.

Personal notes are always scoped to the authenticated actor. Updates require an opaque revision token and reject stale edits, including two edits in the same second. The planner saves personal events; it does not notify other people or create study-session invitations. Session attendance is managed through the existing channel and voice workflows; a schedule entry is not an RSVP or attendance record.

Resources list metadata for uploaded files attached to accessible channels, then open the authenticated channel for download. Files without a channel and message-only attachments are available through their existing tools. Private-channel access follows ChannelService membership, explicit channel membership, creator, and owner/admin rules. Participant counts and message counts describe participation, not grades or learning outcomes.

Dates and times in the new interface are shown in Philippines time (UTC+8). Date inputs explicitly use that timezone. The service converts stored database wall-clock values through the database session offset to UTC for transport.

List requests are bounded: 200 spaces/channels/resources/notes, 500 current members, and 100 sessions/planner events/notifications/discoverable spaces. Pagination and search operate on the loaded set. Counts derived from these sets are labeled as listed records, not unrestricted totals. Unavailable data sources show an unavailable state; they do not become fabricated successes or zero values. No polling is enabled; Refresh reloads data. Theme and density preferences are stored on the device.

## Deploy on the VPS

```bash
cd ~/ecollab-inspect
(
  umask 0022
  git pull --ff-only origin ecollab-collabs
)
bash scripts/deploy-learning-workspace.sh
```

No new migration, service configuration, or cron change is needed. The existing database migrations, AI/matching services, and message delivery worker remain prerequisites. The deployment helper adjusts only the explicit public files and the new public JavaScript directory; it does not recursively change permissions or touch environment files.

Hard-refresh both dashboard URLs. Check student notes, notification ownership, public joining, and private-channel file filtering. Check a facilitator with two owned spaces: switch spaces, schedule a session, publish an announcement, update member permissions, and review reports. Check a facilitator without an owned space and verify another facilitator cannot access the selected space by changing the URL. Check mobile navigation and dialogs on a real browser.

## Validation

```bash
php tests/learning-workspace-regression.php
node --check assets/js/learning/workspace.js
# jsdom must be available to Node; PHP_BIN can select a PHP executable.
node tests/learning-workspace-ui-regression.cjs
```

The PHP test uses PDO SQLite and verifies personal ownership, revision conflicts, private-channel resource filtering, facilitator ownership, session timestamps/channel validation, public-join restrictions, idempotency, announcement transaction rollback, private-channel notifications, and absence of system features in the default dashboards. The DOM test renders both authenticated PHP pages and exercises navigation, forms, CSRF, pagination/search, announcement and session submission, permission scope, theme preferences, text escaping, and unavailable-source handling. It is not a rendered-browser visual test or a production MySQL integration test.


## Connected learning tools

Both roles now have a Tools & workflows page, a learning profile editor, public documents/whiteboards catalog, and Help & policy links. Dedicated chat, library, and collaboration editors remain their own modules; dashboard tools no longer open the retained legacy dashboard. Profile edits update only the authenticated user's display name, bio, year, study style and goal. They preserve academic program, weekly goals, links, avatar, and account role. Students can submit reason/proof for facilitator access through the existing approval workflow (JPG/PNG/PDF, up to 5 MB).

Facilitators manage reports, active bans, settings, and participation inside the new shell. Report evidence/revision checks reject stale reviews; staff accounts and the server owner are protected from moderation actions. Actions include dismiss, resolve without removing content, mute/suspend for 24 hours through the platform's existing server restrictions, and ban from the selected space. Restrictions, report decisions, member-count updates and audit entries commit together. Unban restores eligibility; it does not automatically rejoin the former member. Suspension enforcement remains that of the existing platform endpoints; this change does not add restrictions to every independent collaboration editor.

Space settings use a revision check and apply to the selected owner-controlled space. Channel locking affects existing non-announcement channels; announcement channels stay locked. Permission changes can apply to the selected space or, with confirmation, every owned space. Creating a space uses the existing authenticated creation endpoint, includes owner membership, initializes the member counter and locks its announcement channel.

The facilitator monitor supports owned spaces and existing explicit assignments/management relationships. Assigned-only monitors are read-only, show a clearly named space, and hide ownership controls; assignment does not grant settings or report-review authority. The old shared monitor entry route redirects to the current role-specific workspace. Charts show messages and contributors over 30 Philippines-time days, with the owner excluded from contributors. They describe participation, not grades. No host, capacity, service status or infrastructure views are included.

The documents/whiteboards catalog shows up to 200 public items in public workspaces, and applies private-channel authorization. Other private collaboration resources remain reachable through the dedicated collaboration hub. Reports and active bans show up to 200 records with local search/pagination. Unavailable sources show an error and Retry.

Additional validation:

```bash
php tests/learning-tools-regression.php
node tests/learning-workspace-ui-regression.cjs
```

Tests cover personal profile ownership/preservation, private collaboration filtering, assigned-monitor authority, owner/staff protection, stale evidence/settings/bans, mute expiry, unban semantics, transaction rollback, legacy route redirects, and connected profile/settings/review/unban forms. The UI test also checks assigned-monitor navigation. Run the updated `scripts/deploy-learning-workspace.sh`; no new migration is needed. Verify both role dashboards on the VPS in an actual browser, including file-upload requests and database behavior.
