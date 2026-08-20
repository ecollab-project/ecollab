# ECOLLAB — Comprehensive Repository Audit

**Date:** 2026-08-19
**Scope:** Full repository assessment for Phase 1 — Database Consistency implementation
**Status:** DISCOVERY COMPLETE — Ready for Phase 1 Implementation

---

## Executive Summary

E-Collab is a **full-stack collaborative education platform** built on PHP 8.1+ with MySQL 8/MariaDB, vanilla JavaScript, and Ratchet WebSockets. The codebase is **production-shaped but incomplete** — it has working authentication, messaging, collaboration tools, real-time synchronization, and AI integration scaffolding. However, **critical gaps exist** between documented contracts, database schema, application code, and tests.

**Phase 1 — Database Consistency** must become the foundation for all subsequent work.

---

## 1. Repository Architecture Map

### Top-Level Structure

```
ecollab/
├── API/                      # RESTful endpoints (21+ categories)
├── assets/                   # Frontend CSS, JS (vanilla modular)
├── config.php                # Unified configuration loader
├── database/                 # Schema, migrations, config
├── docs/                     # API reference, guides
├── includes/                 # PHP layout components
├── modules/                  # Server-side rendered pages
├── security/                 # Auth middleware, CSRF, rate limiting
├── services/                 # Business logic layers
├── tests/                    # PHPUnit: Unit + Integration tiers
├── websocket/                # Ratchet WebSocket server + handlers
└── vendor/                   # Composer dependencies
```

### Application Layers

| Layer | Technology | Files | Tier |
|-------|-----------|-------|------|
| **Frontend (Browser)** | Vanilla JS (modular) + CSS | `assets/js/`, `assets/css/` | Client-side |
| **Frontend (Server)** | PHP templates | `modules/`, `includes/` | Server-side render |
| **API (HTTP)** | PHP PDO endpoints | `API/*/*.php` | Stateless |
| **API (WebSocket)** | Ratchet handlers | `websocket/handlers/` | Stateful |
| **Business Logic** | Service classes | `services/` | Shared |
| **Auth & Security** | Middleware + helpers | `security/`, `services/AuthService.php` | Cross-layer |
| **Database** | MySQL 8 / MariaDB 10.6+ | Schema via migrations | Persistent |

### Architecture Type

- **Not a framework** (no Laravel/Symfony framework layer)
- **Direct PHP + PDO** — low abstraction, high code ownership
- **Modular frontend** — each feature area has its own JS module
- **Event-driven WebSocket** — Ratchet with custom handlers, not a pub/sub framework
- **Database-centric** — schema is authoritative, migrations are mostly sequential

---

## 2. Database Table Map

### Core Tables (002_core_schema.sql)

**Identity & Organization:**
- `institutions` — Academic institutions
- `academic_programs` — Degree programs within institutions
- `users` — All user accounts (unified authentication)
- `user_profiles` — Extended profile data
- `user_interests` / `interest_tags` — Tagging system

**Collaboration Workspace:**
- `servers` — Workspaces/organizations
- `server_members` — Membership + roles
- `server_tags` — Workspace tags
- `channels` — Messaging channels within servers
- `study_rooms` — Breakout/study rooms
- `study_room_participants` — Room membership

**Communication:**
- `messages` — Channel messages
- `direct_messages` — DM conversations (table, not messages table)
- `message_reactions` — Emoji reactions
- `message_attachments` — File uploads
- `threads` — Message threads (replying to a message)
- `message_reads` — Read receipts (004 migration)

**Classes & Enrollment:**
- `subject_classes` — Class offerings
- `class_enrollments` — Student enrollment

**Real-Time Collaboration:**
- `study_sessions` — Study session tracking

**Knowledge Base:**
- `notes` — Persistent notes (also replicated in `collab_notes` for OT-synced version)
- `uploaded_files` — Persistent file storage

**AI & Conversations:**
- `ai_sessions` — AI conversation containers
- `ai_messages` — Individual AI messages
- `ai_quick_prompts` — Saved AI prompt templates

**Notifications & Events:**
- `notifications` — User notification queue
- `notification_settings` — Per-user notification preferences (via `user_settings`)

### Extended Tables (Subsequent Migrations)

**Channel Access Control (009):**
- `channel_access_requests` — Join requests for private channels

**Presence & Activity (010):**
- `channel_seen` — Last-read timestamp per channel per user

**Collaboration Tools (011):**
- `collab_notes` — Operational-transform synced notes
- `collab_note_ops` — OT operations log
- `collab_boards` — Kanban boards
- `collab_columns` — Kanban columns
- `collab_tasks` — Tasks/cards
- `collab_timers` — Shared pomodoro timers
- `collab_quizzes` — In-channel quizzes
- `collab_calendar_events` — Shared calendar

**Extra Collaboration Tools (012):**
- `collab_decks` / `collab_flashcards` / `collab_flashcard_reviews` — Spaced-repetition flashcards
- `collab_mindmaps` — Shared mind maps
- `collab_review_requests` / `collab_review_feedback` — Peer code review
- `collab_summaries` — AI-generated channel summaries
- `collab_goals` — Shared study goals
- `collab_resources` — Shared resource links
- `collab_whiteboards` / `collab_whiteboard_ops` — Shared drawing canvas

**Peer Matching (013):**
- `pm_user_study_prefs` — User matching profile
- `pm_subjects` — Subject taxonomy
- `pm_user_subjects` — User subject interests
- `pm_connections` — Friend/connection relationships
- `pm_compatibility_scores` — Cached compatibility ratings

**Security & Audit (014):**
- `security_audit_log` — All security events
- `account_lockouts` — Brute-force protection
- `ws_tokens` — WebSocket authentication tokens

**Subscriptions & Plans (017):**
- `subscription_plans` — Plan offerings

**Onboarding (018):**
- `onboarding_answers` — Student survey responses
- `onboarding_suggested_servers` — Server recommendations based on answers

**Server Invites (019):**
- `server_invites` — Invite codes + usage tracking
- `server_members` — Membership (extended in 019)

**Thread Tracking (020):**
- `threads` — Thread metadata

**User Settings (021):**
- `user_settings` — Per-user preferences (DMs, connection requests, theme, audio, accessibility, notifications)

### Key Foreign Keys
- `user_*` tables → `users.id` (cascading on delete)
- `*_members` tables → `users.id` + parent table
- `messages`, `direct_messages`, `threads`, etc. → `users.id`
- All `collab_*` tables → `channel_id` + `users.id`

---

## 3. Migration Map

### Migration Files (Sequential Order)

| File | Purpose | Tables Affected | Status |
|------|---------|-----------------|--------|
| `000_migration_registry.sql` | Bootstrap: creates `schema_migrations` tracking table | `schema_migrations` | Foundation |
| `002_core_schema.sql` | Core identity, channels, messages, collaboration | 38 tables | Core |
| `004_missing_tables.sql` | Supplements 002: `message_reads`, etc. | 3 tables | Retroactive |
| `005_oauth_columns.sql` | OAuth provider columns on `users` | Alters `users` | Auth |
| `006_chat_addon.sql` | Duplicate/recreate `message_reactions` | Handles idempotency | Redundancy |
| `007_voice_presence.sql` | Voice/presence tracking columns | Alters existing tables | Feature |
| `008_dm_migration.sql` | Direct message conversation structure | `dm_conversations` | Feature |
| `009_channel_access_requests.sql` | Private channel join flow | `channel_access_requests` | Feature |
| `010_channel_seen.sql` | Last-read tracking per user/channel | `channel_seen` | Activity |
| `011_collab_tools.sql` | Kanban, quiz, calendar, notes (OT version) | 9 tables | Tools |
| `012_collab_extra.sql` | Flashcards, mindmaps, reviews, goals, resources | 11 tables | Tools |
| `013_peer_matching.sql` | Matching engine + connections | 5 tables | Feature |
| `014_security.sql` | Audit logging + brute-force protection + WS tokens | 3 tables | Security |
| `015_seeds_v2.sql` | Data seeding (tags, subjects, etc.) | Inserts only | Sample Data |
| `016_seeds_chat.sql` | Chat sample data | Inserts only | Sample Data |
| `017_user_plan_id.sql` | Subscription plans | `subscription_plans` | Billing |
| `018_free_for_all.sql` | Onboarding data structures | 2 tables | Onboarding |
| `019_invites_and_members.sql` | Server invites + enhanced membership | `server_invites` | Feature |
| `020_threads_v2.sql` | Message threading | `threads` + alters | Feature |
| `021_user_settings.sql` | User preferences (theme, notifications, audio, accessibility) | `user_settings` | Feature |

### Migration Issues

**Risk Level: MEDIUM**

1. **Migration 004 (missing_tables.sql) and 006 (chat_addon.sql)**
   - Both recreate or supplement tables that *should* be in 002
   - This pattern suggests `002_core_schema.sql` was incomplete at release
   - Indicates **migrating existing production data forward** is a concern (migration must be idempotent and replay-safe)

2. **No downtime tracking**
   - Migrations do not document which ones *require* zero downtime
   - No "ADD COLUMN ... DEFAULT ..." safety markers for backward compatibility

3. **OAuth migration (005) placed *after* core schema**
   - Should be earlier if OAuth is a supported auth method
   - Indicates OAuth support was added after initial release

4. **Duplicate table creation (006)**
   - Uses `CREATE TABLE IF NOT EXISTS` instead of reusing 002's definition
   - Means developers worked around an incomplete 002 rather than fixing it
   - **Regression risk:** if someone drops 002 and reruns migrations, 006 might create an outdated schema definition

---

## 4. API Inventory

### REST Endpoints by Category

| Category | Endpoint Count | Notable Endpoints |
|----------|---|---|
| **Auth** | 9 | login, signup, logout, OAuth callback, OTP, password reset, WS-token, CSRF, session validation |
| **Chat** | 20+ | send-message, edit, delete, pin, get-messages, channel CRUD, AI-assist, whiteboard, peer-match |
| **DM** | 3 | get-conversations, open-conversation, send-message |
| **Collab (Tools)** | 6 tools | notes, tasks, code, timer, quiz, calendar — each with ~5-10 sub-actions |
| **Collab (Extra)** | 6 tools | flashcards, mindmap, review, summary, goals, resources — each with ~3-5 sub-actions |
| **Friendship** | 3 | send-request, respond-request, get-matches |
| **Servers** | 2 | create-server, join-server |
| **Onboarding** | 2 | get-server-suggestions, join-servers |
| **Notifications** | 2 | get, mark-read |
| **Profile** | 1 | get-profile |
| **Dashboards** | 3 files (admin, facilitator, student) | Each with 5-15 actions per role |
| **System** | 1 | health (basic or full) |
| **Threads** | 1 | get-server-members |

**Total: 70+ distinct API operations**

### API Routing Patterns

1. **Direct endpoint** (`POST /API/chat/send-message.php`)
   - Most common pattern
   - One file = one operation

2. **Router with action parameter** (`POST /API/collab/collab.php?tool=notes&action=load`)
   - Consolidates many operations into one file
   - Requires parsing `$_GET['tool']` + `$_POST['action']` or similar
   - Example: `collab.php` implements ~50 sub-actions across 6 tool categories

3. **Router with action parameter (dashboard)**
   - Three role-specific dashboard files: `admin/`, `facilitator/`, `student/`
   - Each has 5-15 actions via `?action=` query parameter

### API Response Format

**Current Standard** (from README + API_REFERENCE.md):

```json
{
  "success": true,
  "data": { /* operation-specific */ },
  "message": "Optional message"
}
```

or

```json
{
  "success": false,
  "error": "Error message"
}
```

**Inconsistencies Observed:**
- Some endpoints return `{ "success": true, "message_id": 123 }` (minimal success response)
- Some endpoints return `{ "success": false, "error": "..." }` with HTTP 500 status code
- Some endpoints expose PHP stack traces in error output (SECURITY RISK — see Section 9)
- No standardized **request ID** tracking across requests
- No standardized **error codes** (only error strings)

---

## 5. WebSocket Event Inventory

### Event Types Handled by ChatServer.php

**Authentication & Connection:**
- `auth` — WebSocket authentication handshake
- `ping` / `pong` — Heartbeat

**Channel Management:**
- `join_channel` — Subscribe to channel
- `leave_channel` — Unsubscribe from channel
- `channel_seen` — Mark channel as read

**Messaging:**
- `message` — Send message
- `message_edited` — Broadcast edit
- `message_deleted` — Broadcast deletion
- `message_pinned` — Broadcast pin/unpin

**Real-Time Collaboration (Notes):**
- `collab_note_cursor` — Shared cursor position (OT)
- `collab_note_presence` — Collaborator presence

**Typing Indicators:**
- `typing` — User is typing

**Presence:**
- `presence` — User online/offline status

**Drafts:**
- `draft_save` — Save draft message

**Threads:**
- `thread_reply` — Reply in thread

**Mentions:**
- `mention` — @mention relay

**Voice & Video:**
- `join_voice` — Join voice room
- `leave_voice` — Leave voice room
- `webrtc_offer` / `webrtc_answer` / `webrtc_candidate` — WebRTC signaling
- `screen_share_notify` — Screen share notification

**Whiteboard:**
- `whiteboard_sync` — Request full state
- `wb_join` — Join whiteboard
- `wb_leave` — Leave whiteboard
- `wb_op` — Whiteboard operation (draw, shape, etc.)
- `wb_cursor` — Whiteboard cursor
- `wb_state_save` — Periodic state snapshot
- `wb_request_state` — Request state

**Direct Messages:**
- `dm_message` — DM message
- `dm_typing` — DM typing indicator

**Connections & Friendship:**
- `connection_request` — Peer match request
- `notify_conn_req` — Notify recipient
- `notify_conn_accepted` — Notify sender

**Total WebSocket Event Types: 30+**

### WebSocket Event Protocol

**Standard event structure (example):**
```json
{
  "type": "message",
  "channel_id": 12,
  "user_id": 5,
  "content": "Hello",
  "content_type": "text",
  "timestamp": "2026-08-19T10:00:00Z"
}
```

**Issues:**
- No standardized `event_id` for deduplication
- No `client_id` for ACK tracking
- No `revision` for conflict recovery
- Presence/cursor events lack structured metadata

---

## 6. Service Inventory

### Implemented Service Classes

| Service | Purpose | Key Methods | Status |
|---------|---------|-------------|--------|
| `AuthService` | Login, signup, OAuth, OTP, password reset | login(), register(), verifyOTP(), resetPassword() | Complete |
| `UserService` | User CRUD, profile, blocking, reporting | getUser(), updateProfile(), block() | Partial |
| `ChannelService` | Channel CRUD, membership, access control | createChannel(), addMember(), checkAccess() | Partial |
| `MessageService` | Message CRUD, reactions, threading | sendMessage(), editMessage(), deleteMessage() | Partial |
| `MembershipService` | Manages server/channel membership | addServerMember(), removeServerMember() | Partial |
| `PeerMatchingService` | Matching algorithm + scoring | getMatches(), getCompatibility() | Partial |
| `AdminDashboardService` | Admin analytics + user management | getStats(), banUser(), getReports() | Partial |
| `FacilitatorDashboardService` | Facilitator overview + server management | getActivityFeed(), kickMember() | Partial |
| `StudentDashboardService` | Student overview + progress tracking | getOverview(), getActivity() | Partial |
| `AiSessionService` | AI conversation persistence | getSession(), saveMessage() | Partial |
| `OAuthService` | OAuth provider abstraction | getProvider(), validateToken() | Partial |
| `WhiteboardService` | Whiteboard state management | getState(), applyOp(), saveSnapshot() | Partial |
| `Phase46Schema` | (Unclear naming — appears deprecated or phase-specific) | ? | Unknown |

### Service Layer Gaps

**Missing services** that PLAN.md indicates should exist:
- `TaskService` — Task/card management (exists inline in `collab.php`, not as a service)
- `NoteService` — Note persistence (exists inline, mixed with OT logic)
- `NotificationService` — Notification creation/dispatch (none found, inline in various endpoints)
- `EventBus` / `EventDispatcher` — Central event aggregation (does not exist, WebSocket relaying is ad-hoc)
- `ApiResponse` / `ErrorHandler` — Standardized response formatting (does not exist, each endpoint formats independently)
- `RequestContext` — Request ID tracking (does not exist)

---

## 7. Frontend API Inventory

### Frontend API Client Layer

**Current State:**
- **No unified API client** — each feature area makes its own `fetch()` calls
- Located in: `assets/js/chat/`, `assets/js/student/`, `assets/js/admin/`, etc.
- Each module hardcodes error handling, retry logic, etc.
- CSRF token injected manually in each POST
- WebSocket initialized in `socket-core.js` with bootstrap in `socket.js`

### Frontend Files by Function

| File/Folder | Purpose | Technology |
|-------------|---------|------------|
| `assets/js/auth/` | Login, signup, forgot-password | Vanilla JS + fetch |
| `assets/js/chat/socket-core.js` | WebSocket client initialization | Ratchet client adapter |
| `assets/js/chat/socket.js` | Bootstrap wrapper (prevents duplicate socket) | Wrapper + fetch |
| `assets/js/chat/chat.js` | Channel message UI | Vanilla JS + fetch/WS |
| `assets/js/chat/chat-features.js` | Message reactions, pinning, etc. | Vanilla JS + fetch |
| `assets/js/chat/dm-notifications.js` | DM UI + notifications | Vanilla JS + fetch |
| `assets/js/chat/peer-matching.js` | Peer match UI | Vanilla JS + fetch |
| `assets/js/chat/collab-tools.js` | Kanban, quiz, timer, calendar | Vanilla JS + fetch |
| `assets/js/chat/collab-extra.js` | Flashcards, mindmap, goals, resources | Vanilla JS + fetch |
| `assets/js/chat/collab-liveeditor.js` | OT-based shared editor | OT engine + fetch |
| `assets/js/chat/ot-engine.js` | Operational Transform implementation | Algorithm |
| `assets/js/chat/whiteboard.js` | Drawing canvas + operations | Canvas API + fetch/WS |
| `assets/js/chat/voice.js` | WebRTC voice/video + screen share | WebRTC API |
| `assets/js/chat/threads-v2.js` | Message threading UI | Vanilla JS + fetch |
| `assets/js/student/` | Student dashboard | Vanilla JS + fetch |
| `assets/js/facilitator/` | Facilitator dashboard | Vanilla JS + fetch |
| `assets/js/admin/` | Admin dashboard | Vanilla JS + fetch |
| `assets/js/ai-session.js` | AI conversation UI | Vanilla JS + fetch |

### Frontend Error Handling

**Current Pattern:**
```javascript
fetch('/API/chat/send-message.php', {
  method: 'POST',
  body: JSON.stringify({...}),
  headers: {
    'Content-Type': 'application/json',
    'X-CSRF-Token': csrfToken
  }
})
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      // handle success
    } else {
      alert('Error: ' + data.error);  // Raw error to user!
    }
  })
  .catch(e => console.error(e));  // Network error logged, not shown to user
```

**Issues:**
- Raw backend error messages shown to user (not user-friendly)
- Network errors silently logged
- No retry mechanism
- No timeout handling
- No deduplication for retries
- No offline detection

---

## 8. Known 500 / Error Sources

### Identified Error Patterns

**1. WebSocket Authentication Issues**

```text
Symptom: "Invalid or expired auth token"
Location: websocket/ChatServer.php → handleAuth()
Cause: ws_tokens table lookup fails or token expired
Potential Root Causes:
  - Token creation in API/auth/ws-token.php failed
  - Token not inserted into database
  - Token TTL check is wrong
  - Multiple simultaneous socket connections share one token
```

**2. OT (Operational Transform) Conflict**

```text
Location: assets/js/chat/ot-engine.js + API/collab/collab.php (notes action)
Observed Issues:
  - No client_id tracking → can't deduplicate client operations
  - No revision tracking → out-of-order operations cause divergence
  - No ACK mechanism → client doesn't know if operation was applied
```

**3. Direct Message Conversation Lookup**

```text
Location: API/dm/open-conversation.php
Potential Issues:
  - Assumes dm_conversations table has specific structure
  - If user_id pair is reversed, conversation not found
  - No IDOR check observed (user_id parameter not validated)
```

**4. Missing Schema Columns**

```text
Location: Multiple endpoints
Pattern: Code references $row['column_name'] where column doesn't exist
Example: pm_subjects.level (if referenced but not created)
```

**5. File Upload Path Issues**

```text
Location: API/chat/upload-file.php
Potential Issues:
  - Assumes uploads/ directory exists and is writable
  - No disk space check
  - No file type validation
```

**6. PHP Warnings Exposed**

```text
Location: All endpoints
Pattern: Uninitialized variable → PHP warning → JSON parse failure on client
Example: if (!isset($_POST['field']) && $field is used → warning message prepended to JSON
```

### Service Failure Modes

**OAuthService**
- If Google API is unreachable → 502 returned raw
- If token validation fails → "invalid_grant" error (Google's response) leaks to client

**AI Service (Anthropic)**
- If API key is placeholder ("your_anthropic_api_key_here") → 400 from Anthropic
- If rate limit exceeded → 429 not caught/retried

**Database**
- If migration not applied → table doesn't exist → SQLSTATE[42S02]
- If foreign key constraint violated → SQLSTATE[23000] (integrity constraint)

---

## 9. Security Risks

### Critical Issues

**1. Stack Traces Exposed in Error Responses**

**Location:** API endpoints (observed via grep)
**Risk:** Information disclosure
**Example:**
```php
catch (PDOException $e) {
    die(json_encode(['error' => $e->getMessage()])); // WRONG: exposes table/column names
}
```

**Impact:** Attacker learns schema, driver versions, query structure
**Remediation:** Catch exceptions, log internally, return generic error code

---

**2. Direct SQL Errors in JSON Responses**

**Location:** `collab.php`, other endpoints
**Risk:** SQL injection assistance + schema discovery
**Example:**
```
{"success":false,"error":"Unknown column 'user_preferences' in 'on clause'"}
```

**Impact:** Exposes exact column names, table joins
**Remediation:** Generic error code + internal logging

---

**3. IDOR: User Can Access Other Users' Resources**

**Location:** `API/dm/open-conversation.php`, potentially others
**Risk:** Confidentiality breach
**Pattern:** Accepts `?user_id=X` without verifying `X == $_SESSION['user_id']`
**Example Test:**
```
GET /API/dm/open-conversation.php?user_id=999
→ Returns conversations for user_id=999 (not authenticated user)
```

**Remediation:** Always verify `user_id == $_SESSION['user_id']` or load from session only

---

**4. Missing CSRF Validation on Some Endpoints**

**Location:** Some GET endpoints that should be POST
**Risk:** State-changing GET requests
**Pattern:** `GET /API/system/health.php?level=full` — admin data via GET
**Remediation:** Dangerous operations must be POST + CSRF token

---

**5. WebSocket Authorization Bypass**

**Location:** `websocket/ChatServer.php → handleAuth()`
**Risk:** Privilege escalation
**Issue:** Token validation may not re-check channel membership after auth
**Pattern:** User authenticates once, then can `join_channel` without re-checking permissions
**Remediation:** Verify channel membership every `join_channel` event, not just at auth

---

**6. Unencrypted Sensitive Data Storage**

**Location:** `user_settings`, potentially other tables
**Risk:** Privacy breach if database is compromised
**Fields:** Avatar gradients, audio device info (low-sensitivity)
**Remediation:** For truly sensitive data, use `FieldEncryption` class (exists but may not be applied everywhere)

---

**7. Rate Limiting Bypass via Session Fixation**

**Location:** `security/rate-limit/RateLimiter.php`
**Risk:** Brute force attacks
**Pattern:** If rate limiting is keyed by session/user_id, attacking a shared/fixture account bypasses limits
**Remediation:** Rate limit by IP + user_id pair; rotate rate limit table keys on auth events

---

**8. AI Tool Permissions Not Enforced**

**Location:** `API/ai/session.php`, message.php
**Risk:** Privilege escalation
**Pattern:** AI can call `create_task`, etc. with no permission check (if tool system is implemented)
**Remediation:** `AiPermissionGuard` (mentioned in PLAN.md) must exist and be invoked before every AI action

---

**9. Credential Exposure in Repository**

**Location:** `.env` (addressed in SECURITY_CREDENTIAL_AUDIT.md)
**Status:** GOOGLE_CLIENT_SECRET exposed and rotated (per audit)
**Remediation:** Never ship `.env` in archives; use `.env.example` only

---

### Medium Issues

**1. No Request ID Tracking**
- Errors are not traceable across logs
- Clients cannot reference errors in support tickets

**2. Insufficient Input Validation**
- Some endpoints accept raw `$_POST` without type/length checks
- Leads to downstream errors rather than 400 Bad Request

**3. No Rate Limiting on File Upload**
- `API/chat/upload-file.php` may accept unlimited files
- Disk exhaustion attack possible

**4. WebSocket Message Size Limit**
- No check on incoming message payload size
- Large payloads could cause memory exhaustion

**5. Direct Message Conversation Lacks Access Control**
- Two users in a DM conversation may have different permissions on channel context
- Could leak message metadata

---

## 10. Schema Inconsistencies

### Inconsistency #1: User Preferences Field Naming

**Issue:** Historical code references `allow_dm` vs. current schema uses `direct_messages`

**Status:** RESOLVED
- Current schema (migration 021) uses `direct_messages` (TINYINT)
- No remaining code references to `allow_dm` found in recent grep
- **Action:** Verify all reference sites; if found, add regression test

---

### Inconsistency #2: Duplicate `message_reactions` Table Definition

**Location:** `006_chat_addon.sql` recreates table already in `002_core_schema.sql`

**Issue:**
- If migrations are replayed, duplicate definition could cause issues
- If 002 schema changes, 006 won't update → divergence

**Status:** Needs review
- Both definitions should be consolidated into 002
- 006 should be removed or repurposed

---

### Inconsistency #3: Missing Foreign Key on `ws_relay`

**Location:** `websocket/ChatServer.php` creates `ws_relay` table inline

**Issue:**
```sql
-- Created in PHP code, not migration
CREATE TABLE IF NOT EXISTS ws_relay (
    channel_id INT UNSIGNED NOT NULL,
    ...
);
-- No foreign key to channels(id)
```

**Impact:** Orphaned relay entries if channel is deleted
**Remediation:** Move to migration file with proper constraints

---

### Inconsistency #4: Thread Definition Mismatch

**Location:** `messages` table has `thread_id` column (implicit from 020), but `threads` table added in migration 020

**Observation:**
- `messages.thread_id` should exist before threads table is queried
- Migration order seems correct, but no explicit ALTER TABLE in 020

**Status:** Likely correct but should verify 020 adds `thread_id` to messages

---

### Inconsistency #5: Voice/Presence Columns Scattered

**Location:** `007_voice_presence.sql` alters existing tables

**Issue:** Presence features (online status, voice room) are split:
- `users.avatar_gradient`, `users.avatar_url` (core)
- `study_room_participants.joined_at` (rooms-specific)
- `user_settings.output_device`, etc. (settings-specific)

**Pattern:** No single "presence" table with standardized structure
**Impact:** Queries for "users online in this channel" require joins across 3+ tables

---

### Inconsistency #6: Collaborator Presence Tracking

**Location:** No unified presence table; embedded in various collab_* tables

**Issue:** To find "who is editing this note right now," must:
1. Query `collab_notes` for the note
2. Query `collab_note_ops` for recent operations
3. Cross-reference with `users` and WebSocket `connMeta`

**Pattern:** Presence is inferred, not tracked
**Impact:** Race conditions on presence state if WebSocket connection drops

---

### Inconsistency #7: Notification Schema vs. Actual Usage

**Location:** `notifications` table defined in core schema, but also `notification_settings` in `user_settings`

**Issue:**
- `notifications.type` is a string
- `user_settings` has boolean flags for each type
- No standardized notification type enum

**Pattern:** Type checking is string-based, not enum-based
**Impact:** Typos in notification type strings silently create new types

---

### Inconsistency #8: Direct Messages Table Structure

**Location:** `direct_messages` table vs. `messages` table

**Issue:**
- `messages`: `channel_id`, `sender_id`, `content`, `created_at`
- `direct_messages`: Likely similar but separate schema
- No code suggests dual-table query for unified message history

**Pattern:** DMs and channel messages use different tables, not unified
**Impact:** "Global timeline" feature would require UNION, inefficient

---

## 11. Duplicate Implementations

### Duplicate #1: Notes Storage

**Location:** `notes` table + `collab_notes` table

**Difference:**
- `notes`: Simple persistence, no OT
- `collab_notes`: OT-synced, operation log in `collab_note_ops`

**Impact:** Same feature, two implementations; which one is canonical?
**Resolution:** Need clarification — is `notes` deprecated in favor of `collab_notes`?

---

### Duplicate #2: Peer Matching (Two Implementations)

**Location:** `API/chat/peer-match.php` + `services/PeerMatchingService.php`

**Pattern:**
- API endpoint routes to service
- But some frontend code might hit endpoint directly; some might use service

**Risk:** Logic divergence if both aren't synchronized

---

### Duplicate #3: Message Reactions

**Location:** Defined in both `002_core_schema.sql` and recreated in `006_chat_addon.sql`

**Issue:** Same table, two definitions (see Schema Inconsistency #2)

---

### Duplicate #4: User Profile Data

**Location:** `users` table + `user_profiles` table

**Difference:**
- `users`: core identity (email, password, created_at)
- `user_profiles`: extended data (bio, avatar, etc.)

**Pattern:** Two-table user model; some endpoints may not join both
**Risk:** Code that queries only `users` won't have profile data

---

### Duplicate #5: Dashboard Data (Per-Role)

**Location:** `API/admin/dashboard-data.php` vs. `services/AdminDashboardService.php` vs. `modules/admin/dashboard.php`

**Difference:**
- API: stateless endpoint
- Service: business logic
- Module: server-rendered page (may call API)

**Pattern:** Three implementations of "admin dashboard"
**Risk:** Data inconsistency if endpoints diverge

---

## 12. Missing Tests

### Test Coverage Gap

| Component | Current Tests | Required Tests | Gap |
|-----------|---|---|---|
| `AuthService::login()` | Integration test exists | Unit tests for each failure case | Missing edge cases |
| `CSRF::verify()` | Unit test (success path only) | Failure path, expired token, wrong token | CRITICAL |
| `RoleMiddleware` | Unit test (success path) | Failure path (exit) | Known gap |
| `RateLimiter` | Integration test | Performance test (timing accuracy) | Weak |
| **Message sending** | None found | Unit + integration + race condition | CRITICAL |
| **Channel access check** | None found | Authorization tests | CRITICAL |
| **WebSocket auth** | None found | Authentication + rejoin after expiry | CRITICAL |
| **OT engine (ot-engine.js)** | None found | Conflict resolution, duplicate ops, reconnect | CRITICAL |
| **AI tool permissions** | None found | Permission checks per tool | CRITICAL |
| **Two-browser realtime** | None found | Selenium/Playwright browser tests | CRITICAL |
| **Database schema** | None found | Regression tests for schema changes | CRITICAL |
| **API error responses** | None found | Status codes, error format consistency | High |

---

## 13. Recommended Implementation Order

Based on audit findings, here is the **optimal sequence to address Phase 1 — Database Consistency and foundational work**:

### PHASE 1 — Database Consistency (Weeks 1-2)

**Goals:**
- Eliminate schema/code mismatches
- Make migrations deterministic
- Verify all applications code against current schema

**Tasks (in order):**

1. **Schema Consolidation**
   - Merge `002_core_schema.sql` (incomplete) + `004_missing_tables.sql` + `006_chat_addon.sql` into one definitive 002
   - Remove duplicate migration 006
   - Verify all foreign keys, indexes, constraints are present

2. **WebSocket Table Migration**
   - Move inline `ws_relay` table creation from `websocket/ChatServer.php` to migration file
   - Add proper constraints
   - Update `ChatServer.php` to NOT create table (assume migration has run)

3. **Presence Table Standardization**
   - Create unified `presence` table (or expand `channel_seen` with more fields)
   - Consolidate voice room + online status into one schema
   - Migration 007 should be updated

4. **Notification Type Enum**
   - Add `ENUM` constraint to `notifications.type`
   - Define all valid types in `EventTypes.php` (create if missing)
   - Update code to use enum constants, not strings

5. **User Settings Defaults**
   - Ensure all new users get `user_settings` row (via trigger or application logic)
   - Add regression test: verify user created → settings exists

6. **Message Threads Verification**
   - Confirm `messages.thread_id` column exists in 002 or 020
   - If 020 adds it via ALTER, migrate existing 002 to include it
   - Verify all thread queries use correct joins

7. **Foreign Key Audit**
   - Verify all tables have correct FK constraints
   - Check cascade/restrict behavior on delete
   - Add FK for `ws_relay.channel_id` → `channels.id`

8. **Index Audit**
   - Common queries: `user_id`, `channel_id`, `created_at`, `server_id`
   - Verify indexes exist on foreign keys + high-cardinality search columns
   - Add missing indexes from 002

9. **Migration Idempotency**
   - Test: `mysql drop all tables; run migration 000-021` → should succeed
   - Test: `run migrations twice` → should fail only on first run (not second)
   - All migrations must use `CREATE TABLE IF NOT EXISTS` or equivalent

10. **Schema Regression Tests**
    - Create test: verify `users`, `messages`, `channels`, `user_settings` exist
    - Create test: verify critical indexes exist
    - Create test: verify foreign keys are enforced
    - Run in CI: `ecollab_test` database + migration test

**Success Criteria:**
- ✅ All migrations run successfully 0→21 from blank database
- ✅ Migrations are idempotent (safe to run twice)
- ✅ No code references nonexistent columns/tables
- ✅ Foreign keys are enforced (cascade delete tested)
- ✅ Indexes exist for all high-cardinality queries
- ✅ Test database matches production schema

---

### PHASE 2 — Global Error Handling (Weeks 3-4)

**Goals (from PLAN.md):**
- Standardize API response format
- Add request ID tracking
- Hide sensitive errors from clients
- Log all errors for debugging

**Tasks:**

1. Create error handling infrastructure
2. Add request ID middleware
3. Update all 70+ endpoints to use new response format
4. Add error code constants
5. Create error logging service
6. Update tests

---

### PHASE 3+

Only after Phase 1 is 100% complete and all tests pass.

---

## Appendix A: File Counts by Category

```
API/                          ~60 .php files
assets/js/                    ~20 .js files
assets/css/                   ~10 .css files
services/                     ~13 .php files
security/                     ~10 .php files
websocket/                    ~10 .php files
database/migrations/          ~21 .sql files
tests/                        ~5  test files
docs/                         ~3  .md files
```

---

## Appendix B: Dependencies & Versions

```
PHP 8.1+
MySQL 8 / MariaDB 10.6+
Composer (Ratchet, PHPUnit, others)
Vanilla JavaScript (ES6+)
Ratchet WebSocket library
PDO (database abstraction)
```

---

## Appendix C: Critical Questions for Team

1. **Is `notes` table deprecated in favor of `collab_notes`?**
   - If yes: migrate data, drop `notes`, deprecate old endpoint
   - If no: clarify which feature should use which table

2. **Should `ws_relay` be part of the migration system, or remain dynamically created?**
   - Current: Created inline in PHP (risky for schema audits)
   - Proposed: Migrate to `021b` or append to 021

3. **Are there other Google OAuth clients or secrets in use that weren't in the audit file?**
   - Credential audit covered one client; verify completeness

4. **What is `Phase46Schema.php` and is it still in use?**
   - Service class naming suggests it's related to a specific development phase
   - Should be renamed, documented, or removed

5. **Which peer matching implementation is canonical?**
   - `API/chat/peer-match.php` vs. `services/PeerMatchingService.php`

6. **Are there production instances running?**
   - If yes: Phase 1 migrations must support zero-downtime deployment
   - If no: simpler migration strategy is possible

---

## Appendix D: Quick Reference — What to Do Next

**NEXT TASK (If reading this document):**

1. Read **Section 13: Recommended Implementation Order**
2. Review **Phase 1 — Database Consistency** checklist above
3. Start with **Task 1: Schema Consolidation**
4. Run baseline tests: `vendor/bin/phpunit`
5. Follow the Copilot Execution Workflow from PLAN.md (STEP 1 — Understand → STEP 13 — Commit)

---

**Document Generated:** 2026-08-19
**Audit Scope:** Complete repository review (code, schema, tests, API, WebSocket, services, security)
**Status:** READY FOR PHASE 1 IMPLEMENTATION
