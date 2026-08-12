# ECOLLAB — Full API Reference

Companion to `README.md`'s API Reference section (which covers the 9 `API/auth/*` endpoints in detail). This document covers every other endpoint in the project — everything under `API/chat`, `API/collab`, `API/dm`, `API/friendship`, `API/server`, `API/onboarding`, `API/notifications`, `API/profile`, `API/admin`, `API/facilitator`, `API/student`, `API/system`, and `API/threads`.

**Conventions used throughout the project** (same as `README.md`'s existing section):
- All endpoints accept and return JSON, except where noted (a few read `$_GET` query params instead of a JSON body).
- Mutating (`POST`) endpoints require the `X-CSRF-Token` header, verified via `CSRF::verify()`.
- "Auth" column: **Session** = any logged-in user (`AuthMiddleware::requireAuth(true)`, returns JSON 401 if not logged in) · **Role: X** = requires at least that role (`RoleMiddleware`) · **None** = publicly accessible.
- Several endpoints are **routers**: one file dispatches many actions via a `?action=`, `?tool=`, or JSON-body `action`/`tool` field. Those are documented as one row per action/tool in the summary table, grouped under their file.

---

## Summary Table

### Auth — see `README.md`, not repeated here (9 endpoints, all documented there in full)

### Chat — messages, channels, reactions

| Method | Endpoint | Description | Auth |
|---|---|---|---|
| GET | `/API/chat/get-channels.php` | List channels in the current server | Session |
| GET | `/API/chat/get-channel.php` | Get one channel's details | Session |
| POST | `/API/chat/create-channel.php` | Create a channel in a server | Session |
| GET | `/API/chat/get-messages.php` | Paginated message history for a channel | Session |
| POST | `/API/chat/send-message.php` | Send a message (text/file/poll) | Session |
| POST | `/API/chat/edit-message.php` | Edit a message you sent | Session |
| POST | `/API/chat/delete-message.php` | Delete a message (own, or moderator+) | Session |
| POST | `/API/chat/pin-message.php` | Pin/unpin a message | Session |
| POST | `/API/chat/report-message.php` | Report a message to moderators | Session |
| POST | `/API/chat/vote-poll.php` | Cast a vote on a poll message | Session |
| GET | `/API/chat/get-mentions.php` | Unread @mentions for the current user | Session |
| POST | `/API/chat/mark-channel-seen.php` | Mark a channel as read | Session |
| GET | `/API/chat/channel-members.php` | List members of a channel | Session |
| GET | `/API/chat/channel-access-request.php` | List/request access to a private channel | Session |
| GET | `/API/chat/active-now.php` | Users currently online in the server | Session |
| GET | `/API/chat/get-matches.php` | (Legacy) peer-match summary — see `peer-match.php` below for the current engine | Session |
| POST | `/API/chat/upload-file.php` | Upload a file attachment | Session |
| GET | `/API/chat/whiteboard-sync.php` | Fetch current whiteboard state for a channel | Session |
| POST | `/API/chat/ai-assist.php` | Get an AI-suggested reply (Anthropic-backed) | Session |
| GET | `/API/chat/nav-view-data.php` | Sidebar/nav bootstrap data (servers, channels, DMs) | Session |
| GET | `/API/chat/send-test.php` | Manual WebSocket send test (dev tool) | Session |
| GET | `/API/chat/debug-check.php` | Debug/diagnostic dump (dev tool) | None |

### Chat — Peer Matching (`API/chat/peer-match.php`, one router, `?action=`)

| Action | Method | Description |
|---|---|---|
| `get_tags` | GET | List all subject/hobby/interest tags |
| `get_profile` | GET | Get the current user's matching profile |
| `save_profile` | POST | Save/update the current user's matching profile |
| `get_matches` | GET | Ranked list of compatible peers |
| `search_users` | GET | Search users by name/tag |
| `send_request` | POST | Send a connection request |
| `respond_request` | POST | Accept/decline a connection request |
| `list_requests` | GET | List pending connection requests |
| `get_compatibility` | GET | Compatibility score breakdown with a specific user |
| `submit_feedback` | POST | Rate a match after connecting |
| `get_leaderboard` | GET | Top-matched users leaderboard |

All actions: **Session** auth required.

### Chat — Collaboration Tools (`API/collab/collab.php`, one router, `?tool=` then `?action=`)

| Tool | Sub-actions (representative) | Description |
|---|---|---|
| `notes` | `load`, `save`, `get`, `note_op`, `note_title` | Shared notes with operational-transform live sync |
| `tasks` | list/create/update/delete-style actions | Kanban board (columns + tasks) |
| `code` | list/create/run-style actions | Shared code snippets + execution |
| `timer` | start/stop/log-style actions | Pomodoro timer, shared per channel |
| `quiz` | list/create/attempt-style actions | In-channel quizzes |
| `calendar` | list/create-style actions | Channel events |

All tools/actions: **Session** auth required. Full sub-action lists are extensive (each tool is its own PHP function with several actions) — read the relevant `collab_<tool>()` function in `API/collab/collab.php` directly for exhaustive detail if implementing against a specific one.

### Chat — Collaboration Extra Tools (`API/collab/collab-extra.php`, one router, `?tool=` then `?action=`)

| Tool | Sub-actions (representative) | Description |
|---|---|---|
| `flashcards` | `list_decks`, `get_deck`, `create_deck`, `add_card`, `rate_card`, `delete_deck` | Flashcard decks + spaced-repetition review |
| `mindmap` | `get`, `save` | Shared mind map per channel |
| `review` | `list`, `get`, `create`, `add_feedback`, `close` | Peer code/work review requests |
| `summary` | `list`, `generate` | AI-generated channel summaries |
| `goals` | (list/create/react-style actions) | Shared study goals with reactions |
| `resources` | (list/create/vote-style actions) | Shared resource links with voting |

All tools/actions: **Session** auth required. Same note as above re: exhaustive sub-action detail.

### Direct Messages

| Method | Endpoint | Description | Auth |
|---|---|---|---|
| GET | `/API/dm/get-conversations.php` | List the current user's DM conversations | Session |
| GET | `/API/dm/open-conversation.php` | Open/create a DM conversation with a specific user | Session |
| POST | `/API/dm/send-message.php` | Send a DM in an existing conversation | Session |

### Friendship / Connections

| Method | Endpoint | Description | Auth |
|---|---|---|---|
| POST | `/API/friendship/send-request.php` | Send a friend/connection request | Session |
| POST | `/API/friendship/respond-request.php` | Accept or decline a request | Session |
| GET | `/API/friendship/API_get-matches.php` | Suggested connections (legacy naming — file literally starts with `API_`) | Session |

### Servers

| Method | Endpoint | Description | Auth |
|---|---|---|---|
| POST | `/API/server/create-server.php` | Create a new server (workspace) | Session |
| POST | `/API/server/join-server.php` | Join a server via invite code | Session |

### Onboarding

| Method | Endpoint | Description | Auth |
|---|---|---|---|
| GET | `/API/onboarding/get-server-suggestions.php` | Suggested servers based on onboarding answers | Session (weaker: `requireAuth()` not `requireAuth(true)`) |
| POST | `/API/onboarding/join-servers.php` | Bulk-join the servers picked during onboarding | Session (weaker, same as above) |

### Notifications

| Method | Endpoint | Description | Auth |
|---|---|---|---|
| GET | `/API/notifications/get.php` | List notifications for the current user | Session |
| POST | `/API/notifications/mark-read.php` | Mark one, several, or all notifications read | Session |

### Profile

| Method | Endpoint | Description | Auth |
|---|---|---|---|
| GET | `/API/profile/get-profile.php` | Get a user's public profile, by `user_id` or `name` | Session |

### Dashboards (`?action=`-routed, one file per role)

**`API/admin/dashboard-data.php`**

| Action | Method | Description |
|---|---|---|
| `stats` (default) | GET | Platform-wide stats |
| `users` | GET | User list/management view |
| `create_user` | POST | Admin-create a user account |
| `change_role` | POST | Change a user's role |
| `ban_user` | POST | Ban a user |
| `kick_user` | POST | Kick a user from a server |
| `get_reports` | GET | List content reports |
| `resolve_report` | POST | Resolve a content report |
| `send_announcement` | POST | Platform-wide announcement |
| `export` | GET | Export data |

**`API/facilitator/dashboard-data.php`**

| Action | Method | Description |
|---|---|---|
| `all` (default) | GET | Facilitator's dashboard overview |
| `kick_member` | POST | Remove a member from a server |
| `create_announcement` | POST | Server-level announcement |
| `resolve_report` | POST | Resolve a content report |
| `update_channel_settings` | POST | Update a channel's settings |
| `activity` | GET | Activity feed |

**`API/student/dashboard-data.php`**

| Action | Method | Description |
|---|---|---|
| `all` (default) | GET | Student's dashboard overview |
| `notifications` | GET | Notifications panel data |
| `mark_notif_read` | POST | Mark one notification read |
| `mark_all_read` | POST | Mark all notifications read |
| `join_server` | POST | Join a server from the dashboard |
| `save_note` | POST | Quick-save a note |
| `update_profile` | POST | Update own profile |
| `activity` | GET | Personal activity feed |

All dashboard actions: **Session** auth required, plus the relevant role check (admin dashboard requires admin+, facilitator dashboard requires facilitator+).

### System & Threads

| Method | Endpoint | Description | Auth |
|---|---|---|---|
| GET | `/API/system/health.php?level=basic` | Uptime-monitor-friendly `{"status":"ok"}` | **None** |
| GET | `/API/system/health.php?level=full` | Full diagnostics: DB connectivity, migration status, feature availability, PHP extensions | **Role: admin** |
| GET | `/API/threads/get-server-members.php` | All members of the current server (for the DM panel), excluding self | Session |

---

## Request / Response Examples

A representative set — not every single action, but enough to show the shape conventions used throughout (which are consistent across the whole API surface).

**GET /API/chat/get-messages.php?channel_id=12&before=500**
```json
// Response
{ "success": true, "messages": [ { "id": 501, "channel_id": 12, "sender_id": 7, "content": "...", "content_type": "text", "created_at": "2026-08-01 10:00:00" } ] }
```

**POST /API/chat/send-message.php**
```json
// Request
{ "channel_id": 12, "content": "Hey, anyone free to study tonight?", "content_type": "text" }

// Success
{ "success": true, "message_id": 892 }

// Failure
{ "error": "Message content required" }
```

**POST /API/chat/ai-assist.php**
```json
// Request
{ "prompt": "Can you help me phrase a polite disagreement?", "recent_messages": "..." }

// Success
{ "success": true, "reply": "You could try: \"I see it a bit differently — here's why...\"" }

// Failure (feature not configured)
{ "success": false, "error": "AI assist is not currently available." }
```

**GET /API/chat/peer-match.php?action=get_matches**
```json
// Response
{ "ok": true, "users": [ { "id": 14, "username": "sara_kim", "score_total": 78, "shared_interests": ["ai", "data-science"], "already_connected": false } ] }
```

**POST /API/chat/peer-match.php?action=save_profile**
```json
// Request
{ "study_style": "group", "primary_goal": "build_projects", "subjects": [1, 4], "interests": [3, 9], "hobbies": [2] }

// Success
{ "ok": true }
```

**GET /API/dm/get-conversations.php**
```json
// Response
{ "success": true, "conversations": [ { "id": 5, "partner_id": 9, "partner_name": "john_doe", "last_message": "...", "unread_count": 2 } ] }
```

**POST /API/dm/send-message.php**
```json
// Request
{ "conversation_id": 5, "body": "Hey, did you finish the assignment?" }

// Success
{ "success": true, "message_id": 231 }

// Failure
{ "error": "conversation_id and non-empty body (max 4000 chars) required" }
```

**POST /API/friendship/send-request.php**
```json
// Request
{ "addressee_id": 9, "addressee_name": "john_doe" }

// Success (new request)
{ "success": true, "status": "pending", "message": "Request sent" }

// Success (already connected)
{ "success": true, "status": "accepted", "message": "Already connected" }
```

**POST /API/friendship/respond-request.php**
```json
// Request
{ "request_id": 44, "action": "accept" }

// Success
{ "success": true, "status": "accepted", "message": "Connection accepted" }
```

**POST /API/server/create-server.php**
```json
// Request
{ "name": "AI & ML Study Group", "template": "study-group" }

// Success
{ "success": true, "server_id": 7, "name": "AI & ML Study Group" }
```

**POST /API/server/join-server.php**
```json
// Request
{ "invite_code": "ABC123XY" }

// Success (new member)
{ "success": true, "server_id": 7, "name": "AI & ML Study Group" }

// Success (already a member)
{ "success": true, "already_member": true, "name": "AI & ML Study Group" }

// Failure
{ "error": "Invalid invite link" }
```

**GET /API/notifications/get.php**
```json
// Response
{ "success": true, "notifications": [ { "id": 12, "title": "New reply", "body": "...", "type": "mention", "is_read": false, "created_at": "..." } ] }
```

**POST /API/notifications/mark-read.php**
```json
// Request — mark specific notifications
{ "ids": [12, 13] }

// Request — mark all (omit "ids" or send null)
{ "ids": null }

// Success
{ "success": true }
```

**GET /API/profile/get-profile.php?user_id=9**
```json
// Response
{ "id": 9, "username": "john_doe", "full_name": "John Doe", "role": "student", "study_style": "group", "total_study_hours": 210.0 }

// Failure
{ "error": "User not found" }
```

**GET /API/system/health.php?level=basic**
```json
// Response — public, no auth
{ "status": "ok" }
```

**GET /API/system/health.php?level=full** *(requires admin role)*
```json
// Response
{
  "status": "ok",
  "database": { "connected": true },
  "migrations_applied": ["002_core_schema.sql", "004_missing_tables.sql", "..."],
  "features": { "collaboration_tools": true, "peer_matching": true, "field_encryption": true },
  "php_extensions": { "sodium": true, "pdo_mysql": true, "curl": true }
}
```

---

## Notes on inconsistencies found while writing this reference

Documented here rather than silently working around them, so they're visible for future cleanup:

- **`API/friendship/API_get-matches.php`** — the file itself is named with a redundant `API_` prefix (every other file in this directory doesn't have this). Left as-is; renaming would break whatever currently links to it.
- **`API/onboarding/get-server-suggestions.php`** and **`join-servers.php`** use `AuthMiddleware::requireAuth()` (no `true` argument) rather than `requireAuth(true)` like every other authenticated endpoint in the project. The practical difference (per `AuthMiddleware`'s own implementation) is whether a failed auth check redirects to the login page (`requireAuth()`) versus returns a JSON 401 response (`requireAuth(true)`) — for a JSON API endpoint, `requireAuth(true)` is the pattern used everywhere else and is almost certainly what was intended here too. Not changed as part of this documentation task — flagged for a future fix.
- **Collaboration tool routers** (`collab.php`, `collab-extra.php`) are deep two-level dispatchers (`?tool=` then an internal `?action=` per tool) with many sub-actions each. This document covers them at the tool level rather than exhaustively listing every sub-action's request/response shape — that level of detail would roughly triple this document's length for two files out of fifty-six. If a future task needs full per-action documentation for these two files specifically, that's a reasonable, scoped follow-up.
