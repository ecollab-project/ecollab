# E-Collab 2.0 — Master Implementation Plan

> **Purpose:** Transform E-Collab into a reliable, real-time, AI-assisted academic collaboration platform with strong error handling, live dashboards, collaboration tools, automations, and GitHub Copilot-friendly implementation steps.
>
> **Development environment:** VS Code + GitHub Copilot
>
> **Repository:** `ecollab-project/ecollab`

---

## 1. Core Objective

E-Collab should become an integrated platform where:

- Students and facilitators communicate in real time.
- Users collaborate through shared tools.
- Dashboards update without page refreshes.
- Tasks, notes, calendar, quizzes, goals, resources, whiteboards, code tools, and chat work together.
- AI understands the user's E-Collab context.
- AI can perform authorized actions through controlled tools.
- Chat can trigger dashboard and collaboration actions.
- System events can trigger automated workflows.
- Browser-side errors are predictable and recoverable.
- PHP/API errors are centrally logged and traceable.
- Database schema and application code remain synchronized.
- WebSocket functionality is reliable and secure.
- Critical workflows have automated tests.
- Optional services do not become single points of failure.

---

# 2. Development Rules

GitHub Copilot MUST follow these rules:

1. **Inspect before editing.**
2. Do not rewrite the entire project.
3. Do not remove working functionality without verification.
4. Prefer incremental refactoring.
5. Preserve backward compatibility where practical.
6. Every change must be tested.
7. Fix root causes instead of suppressing errors.
8. Never solve a PHP 500 by hiding the exception.
9. Never expose sensitive server/database/API information.
10. Search the repository before creating new services, endpoints, events, tables, utilities, or clients.
11. Reuse existing architecture whenever possible.
12. Add regression tests for every bug fixed.
13. Keep changes small and reversible.

---

# 3. Phase 0 — Full Repository Audit

## Objective

Before implementation, perform a complete audit of the repository.

Inspect:

```text
API/
assets/
config.php
database/
security/
services/
tests/
websocket/
composer.json
phpunit.xml
.github/
```

Create:

```text
docs/
├── ECOLLAB_ARCHITECTURE.md
├── API_INVENTORY.md
├── DATABASE_INVENTORY.md
├── WEBSOCKET_INVENTORY.md
├── ERROR_INVENTORY.md
└── FEATURE_DEPENDENCY_MAP.md
```

Document:

- Existing APIs
- Existing services
- Existing database tables
- Existing migrations
- Existing WebSocket events
- Existing collaboration tools
- Existing AI functionality
- Existing dashboard functionality
- Existing tests
- Existing CI checks
- Duplicate implementations
- Known errors
- Security risks
- Schema inconsistencies

---

# 4. Phase 1 — Database Consistency

**Priority: CRITICAL**

The migration system must become the single source of truth.

Recommended structure:

```text
database/
├── migrations/
├── seeds/
├── fixtures/
└── archive/
    └── legacy-schemas/
```

Do not allow application code to depend on obsolete schema dumps.

---

## 4.1 Database Schema Audit

Compare:

```text
PHP SQL queries
        VS
database migrations
        VS
expected application schema
```

Detect:

- Missing tables
- Missing columns
- Wrong column names
- Wrong data types
- Missing indexes
- Missing foreign keys
- Invalid enum values
- Duplicate tables
- Obsolete columns

---

## 4.2 Known Schema Issue

Investigate the known mismatch:

Existing code references:

```sql
allow_dm
```

while the current user settings schema uses:

```sql
direct_messages
```

Do not blindly rename one side.

Determine:

1. Which behavior the application expects.
2. Which schema should be canonical.
3. Which files reference either field.
4. Whether migration history depends on either name.
5. Whether a compatibility migration is required.

Then standardize the project and add a regression test.

---

## 4.3 Database Health Checks

Create or improve:

```text
API/health/
├── database.php
└── system.php
```

Verify:

- Database connection
- Migration state
- Required tables
- Required columns
- Required indexes
- Required foreign keys

Successful response:

```json
{
  "success": true,
  "database": {
    "connected": true,
    "schema_valid": true,
    "migration_version": 21
  }
}
```

Invalid response:

```json
{
  "success": false,
  "error": {
    "code": "DATABASE_SCHEMA_INVALID",
    "message": "Database schema validation failed.",
    "request_id": "..."
  }
}
```

Never expose raw SQL errors in production.

---

# 5. Phase 2 — Global Error Handling

Create or consolidate:

```text
core/
├── ApiResponse.php
├── ApiException.php
├── ErrorHandler.php
├── RequestContext.php
├── ErrorCodes.php
└── Logger.php
```

If equivalent architecture already exists, improve it instead of duplicating it.

---

## 5.1 Request IDs

Every HTTP request receives a request ID.

Accept:

```http
X-Request-ID
```

if valid.

Otherwise generate one.

Example:

```text
ec-20260819-a81f29
```

Return:

```http
X-Request-ID: ec-20260819-a81f29
```

The same ID must appear in server logs.

---

## 5.2 Standard API Response

All JSON APIs should eventually use:

### Success

```json
{
  "success": true,
  "data": {},
  "meta": {
    "request_id": "ec-..."
  }
}
```

### Error

```json
{
  "success": false,
  "error": {
    "code": "NOT_AUTHORIZED",
    "message": "You do not have permission to perform this action.",
    "retryable": false,
    "request_id": "ec-..."
  }
}
```

---

## 5.3 Standard Error Codes

Centralize:

```text
VALIDATION_ERROR
AUTH_REQUIRED
AUTH_INVALID
CSRF_INVALID
FORBIDDEN
NOT_FOUND
NOT_MEMBER
CONFLICT
DUPLICATE_REQUEST
RATE_LIMITED
DATABASE_ERROR
DATABASE_SCHEMA_INVALID
EXTERNAL_SERVICE_ERROR
AI_SERVICE_ERROR
WEBSOCKET_UNAVAILABLE
INTERNAL_ERROR
SERVICE_UNAVAILABLE
```

Suggested HTTP mappings:

```text
400 → VALIDATION_ERROR
401 → AUTH_REQUIRED / AUTH_INVALID
403 → FORBIDDEN / NOT_MEMBER
404 → NOT_FOUND
409 → CONFLICT / DUPLICATE_REQUEST
422 → VALIDATION_ERROR
429 → RATE_LIMITED
500 → INTERNAL_ERROR / DATABASE_ERROR
502 → EXTERNAL_SERVICE_ERROR
503 → SERVICE_UNAVAILABLE
```

---

## 5.4 Production Error Security

Never expose:

- SQL statements
- Database credentials
- File paths
- API keys
- Stack traces
- Internal class names
- Provider secrets
- Environment variables

Production responses should be safe:

```json
{
  "success": false,
  "error": {
    "code": "INTERNAL_ERROR",
    "message": "Something went wrong.",
    "request_id": "ec-..."
  }
}
```

Development can provide diagnostics.

---

# 6. Phase 3 — Frontend API Reliability

Create or consolidate:

```text
assets/js/core/api.js
```

All frontend API calls should eventually use:

```js
EcollabAPI.get(...)
EcollabAPI.post(...)
EcollabAPI.put(...)
EcollabAPI.delete(...)
```

The client should handle:

- Credentials
- CSRF
- JSON parsing
- Request IDs
- Timeouts
- Network failures
- 401
- 403
- 404
- 409
- 422
- 429
- 500
- 502
- 503
- Retryable failures
- Request cancellation
- Duplicate request prevention

---

## 6.1 Frontend Error UX

Never expose:

```text
500 Internal Server Error
```

directly to users.

Instead:

```text
We couldn't complete that action.

Request ID:
ec-20260819-a81f29

[Try Again]
```

Authentication errors:

```text
Your session expired.

[Sign In Again]
```

Retryable errors:

```text
Connection temporarily unavailable.

Retrying...
```

---

## 6.2 Network Resilience

Implement:

- Timeout
- Retry
- Exponential backoff
- Request cancellation
- Duplicate request prevention
- Offline detection
- Reconnection

Do not automatically retry:

```text
400
401
403
404
422
```

Retry cautiously:

```text
408
429
500
502
503
504
```

Only automatically retry mutations when they are idempotent or protected by an idempotency key.

---

# 7. Phase 4 — Idempotency

Critical mutation APIs should support:

```http
Idempotency-Key: <unique-client-request-id>
```

Examples:

- Message sending
- Task creation
- Note creation
- Calendar event creation
- Goal creation
- AI actions
- Notification creation

Prevent:

```text
request
↓
timeout
↓
retry
↓
duplicate record
```

Use database uniqueness constraints where appropriate.

---

# 8. Phase 5 — WebSocket Hardening

Audit:

```text
assets/js/chat/socket.js
assets/js/chat/socket-core.js
websocket/
```

Implement or improve:

- Authentication
- Authorization
- Heartbeat
- Ping/pong
- Automatic reconnect
- Exponential backoff
- Connection state
- Message acknowledgement
- Duplicate protection
- Channel authorization
- Event validation
- Rate limiting

---

## 8.1 WebSocket Authorization

Before:

```text
join_channel
```

verify:

```text
authenticated user
+
channel exists
+
user is permitted to access channel
```

REST and WebSocket permission boundaries must match.

---

## 8.2 WebSocket Connection States

Frontend states:

```text
CONNECTING
CONNECTED
RECONNECTING
DISCONNECTED
AUTH_FAILED
SERVER_UNAVAILABLE
```

UI should communicate status:

```text
🟢 Connected
🟡 Reconnecting...
🔴 Offline
```

---

## 8.3 Reconnect Strategy

Use exponential backoff:

```text
1s
2s
4s
8s
16s
30s maximum
```

Reset after successful connection.

Never create multiple simultaneous sockets.

---

## 8.4 WebSocket Event Protocol

Standardize events:

```json
{
  "event_id": "evt_...",
  "type": "task.created",
  "channel_id": 123,
  "actor_id": 5,
  "entity": {
    "type": "task",
    "id": 42
  },
  "payload": {},
  "timestamp": "..."
}
```

---

# 9. Phase 6 — Event Bus

Create or consolidate:

```text
events/
├── Event.php
├── EventBus.php
├── EventDispatcher.php
├── EventTypes.php
└── listeners/
```

Core events:

```text
message.created
message.updated
message.deleted

dm.created

task.created
task.updated
task.completed
task.deleted

note.created
note.updated

calendar.event.created
calendar.event.updated
calendar.event.deleted

quiz.created
quiz.completed

flashcards.created

goal.created
goal.updated
goal.completed

resource.created

whiteboard.updated

member.joined
member.left

notification.created

study.session.started
study.session.completed

peer.match.created

announcement.created
```

---

## 9.1 Event Flow

Example:

```text
TaskService
    ↓
Database Transaction
    ↓
task.created
    ↓
EventBus
    ├── WebSocket
    ├── Notification
    ├── Dashboard
    ├── Activity Feed
    ├── Analytics
    └── AI Context
```

Business logic should not manually update every dependent system.

Use events for secondary effects.

---

# 10. Phase 7 — Real-Time Dashboards

Dashboard behavior:

```text
Initial REST snapshot
        ↓
WebSocket live events
        ↓
Local state update
        ↓
UI updates
```

No full page refresh should be required for real-time changes.

---

## 10.1 Student Dashboard Events

Listen for:

```text
task.created
task.completed
goal.updated
notification.created
study.session.completed
quiz.completed
peer.match.created
announcement.created
```

---

## 10.2 Facilitator Dashboard Events

Additionally listen for:

```text
student.activity
assignment.submitted
quiz.completed
student_at_risk
project.updated
group.updated
```

---

# 11. Phase 8 — Integrated Collaboration Platform

Existing tools:

```text
Chat
DM
Tasks
Notes
Code
Timer
Quiz
Calendar
Flashcards
Mindmap
Reviews
Goals
Resources
Whiteboard
Voice
Screen Sharing
```

must become interconnected.

Do not add unnecessary tools before integrating existing functionality.

---

## 11.1 Shared Project Context

Where appropriate:

```text
Project
├── Members
├── Chat
├── Tasks
├── Notes
├── Calendar
├── Resources
├── Goals
├── Quiz
├── Flashcards
├── Code
├── Whiteboard
└── Activity
```

Each component should understand:

```text
project_id
channel_id
actor_id
permissions
```

---

# 12. Phase 9 — Real-Time Notes and Whiteboard

## Shared Notes

Preserve existing OT implementation if present.

Harden with:

```text
operation_id
client_id
client_revision
server_revision
acknowledgement
conflict recovery
reconnect recovery
```

Handle:

- Duplicate operations
- Out-of-order operations
- Connection loss
- Browser refresh
- Stale revisions

---

## Whiteboard

Preserve operation-based synchronization.

Operations may include:

```text
add_shape
move_shape
resize_shape
delete_shape
draw_stroke
text_update
```

Use:

```text
operations
snapshots
revisions
participants
cursors
```

Periodically create snapshots.

Do not send the entire whiteboard state for every small change.

---

# 13. Phase 10 — AI Tool System

Evolve AI chat into an authorized tool-using assistant.

Create or consolidate:

```text
ai/
├── AiAgent.php
├── AiContext.php
├── AiTool.php
├── AiToolRegistry.php
├── AiPermissionGuard.php
├── AiActionExecutor.php
└── tools/
```

---

## 13.1 AI Tools

Implement incrementally:

```text
create_task
update_task
assign_task
delete_task

create_note
update_note

create_calendar_event
update_calendar_event

create_goal
update_goal

create_quiz
create_flashcards

summarize_channel
summarize_project
summarize_notes

analyze_progress
analyze_activity

create_announcement
send_notification

find_peer
recommend_resources

generate_report
```

---

## 13.2 AI Security

AI must NEVER execute arbitrary SQL.

Correct flow:

```text
Natural Language
      ↓
Intent
      ↓
Structured Tool Call
      ↓
Permission Validation
      ↓
Business Service
      ↓
Database
```

Never:

```text
AI → SQL
```

---

## 13.3 AI Permission Model

Before every AI action verify:

- Authenticated user
- Role
- Resource ownership
- Channel membership
- Project membership
- Action permission
- Target-user permission

Example:

A student asking AI to delete a facilitator account must be rejected by the permission layer.

---

## 13.4 AI Confirmation System

Destructive actions require confirmation:

- Delete task
- Delete note
- Remove member
- Send announcement
- Bulk modify tasks
- Other destructive operations

Example:

```text
This will remove 8 tasks.

Confirm?

[Cancel] [Confirm]
```

---

# 14. Phase 11 — Chat → Actions and Dashboards

Users should be able to say:

```text
Create a task called "Finish ERD" due Friday.
```

AI:

```text
create_task
```

Then:

```text
Database
   ↓
EventBus
   ↓
WebSocket
   ↓
Dashboard
   ↓
Notification
```

Other examples:

```text
"Show my unfinished tasks."
```

→ Open/filter task dashboard.

```text
"What am I behind on?"
```

→ Analyze current progress.

```text
"Show my study progress."
```

→ Open progress dashboard.

```text
"Create a study goal for PHP."
```

→ Create goal.

---

# 15. Phase 12 — AI Project Automation

Support requests such as:

```text
Turn this conversation into a project.
```

System may create:

```text
Project
Channel
Members
Tasks
Notes
Goals
Resources
```

Require confirmation before large-scale changes.

---

# 16. Phase 13 — Automation Engine

Create:

```text
automation/
├── AutomationEngine.php
├── Workflow.php
├── Trigger.php
├── Condition.php
├── Action.php
├── Executor.php
└── AutomationRegistry.php
```

---

## 16.1 Automation Triggers

Examples:

```text
task.completed
task.overdue
quiz.completed
quiz.low_score
student.inactive
goal.completed
project.created
project.completed
member.joined
assignment.submitted
study.session.completed
```

---

## 16.2 Automation Actions

Examples:

```text
send_notification
create_task
create_goal
create_flashcards
create_quiz
generate_summary
update_dashboard
send_announcement
schedule_event
generate_report
```

---

## 16.3 Automation Example

Trigger:

```text
quiz.completed
```

Condition:

```text
score < 70%
```

Actions:

```text
generate_flashcards
create_recommended_study_goal
notify_student
```

---

## 16.4 Overdue Task Automation

Trigger:

```text
task.overdue
```

Condition:

```text
overdue > 24 hours
```

Actions:

```text
notify_assignee
update_dashboard
notify_project_leader
```

Do not spam users.

Implement notification throttling.

---

## 16.5 Project Completion Automation

Trigger:

```text
all project tasks completed
```

Actions:

```text
generate project summary
mark project milestone complete
notify facilitator
update dashboard
```

---

# 17. Phase 14 — Activity System

Create a unified activity feed.

Examples:

```text
User completed "Database API"
User uploaded a resource
User joined the project
User completed the quiz
Group updated the whiteboard
AI created 3 tasks
```

Activity records should reference:

```text
actor
action
entity
project
channel
timestamp
```

---

# 18. Phase 15 — Notifications

Canonical notification schema:

```text
notifications
├── id
├── user_id
├── type
├── title
├── body
├── entity_type
├── entity_id
├── is_read
├── read_at
└── created_at
```

Use consistent terminology.

Do not mix `body`, `message`, and `content` for the same concept.

---

## Notification Regression Tests

Verify:

- Mark one notification read only changes one record.
- Mark all read changes all appropriate records.
- Unauthorized users cannot alter other users' notifications.
- Duplicate notifications are prevented where appropriate.

---

# 19. Phase 16 — Testing Strategy

Maintain:

```text
Unit tests
Integration tests
API tests
Browser tests
Realtime tests
Security tests
```

---

## 19.1 API Test Matrix

Critical endpoints should test applicable responses:

```text
200
201
400
401
403
404
409
422
429
500
502
503
```

Test:

- Missing authentication
- Invalid CSRF
- Invalid input
- Invalid IDs
- Nonexistent resource
- Non-member access
- Duplicate requests
- Database failure
- External-service failure

---

# 20. Phase 17 — Browser Testing

Use Playwright or the repository's existing browser-testing framework.

Recommended:

```text
tests/browser/
├── login.spec
├── chat.spec
├── dm.spec
├── notifications.spec
├── dashboard.spec
├── tasks.spec
├── notes.spec
├── calendar.spec
├── quiz.spec
├── whiteboard.spec
└── realtime.spec
```

---

## 20.1 Two-Browser Realtime Test

Critical workflow:

```text
Browser A
    ↓
Login
    ↓
Open channel
    ↓
Send message

Browser B
    ↓
Login
    ↓
Open same channel
    ↓
Receive message without refresh
```

Also test:

```text
Browser A creates task
        ↓
Browser B sees task
        ↓
Dashboard updates
        ↓
Notification appears
```

---

# 21. Phase 18 — WebSocket Tests

Test:

```text
connection
authentication
authorization
join
leave
message
typing
presence
reconnect
expired token
duplicate socket
invalid event
unauthorized channel
server restart
```

---

# 22. Phase 19 — Database Regression Tests

Every fixed schema mismatch receives a regression test.

Example:

```text
user_settings.direct_messages
```

must match all application references.

CI should fail if critical code references nonexistent database fields.

---

# 23. Phase 20 — CI/CD

Extend GitHub Actions.

Recommended pipeline:

```text
checkout
    ↓
composer install
    ↓
PHP syntax check
    ↓
PHPStan
    ↓
PHPUnit
    ↓
MySQL service
    ↓
Database migrations
    ↓
Integration tests
    ↓
Playwright
    ↓
WebSocket tests
    ↓
Security checks
```

Pull requests should fail if critical checks fail.

---

# 24. Phase 21 — Static Analysis

Increase PHPStan coverage gradually.

Target:

```text
Level 6+
```

Eventually:

```text
Level 8
```

Do not force a high level immediately if the existing codebase requires staged cleanup.

---

# 25. Phase 22 — JavaScript Quality

If compatible with the existing frontend, add:

```text
ESLint
Prettier
```

Catch:

- Undefined variables
- Unused variables
- Invalid promises
- Unhandled async errors
- Duplicate handlers
- Unsafe DOM access

---

# 26. Phase 23 — Observability

Create structured logs:

```text
logs/
├── application
├── api
├── websocket
├── ai
├── automation
└── security
```

Important operations should record:

```text
timestamp
request_id
user_id
event_id
action
result
duration
```

Never log:

- Passwords
- API keys
- Session tokens
- CSRF tokens
- Unnecessary private user data

---

# 27. Phase 24 — Performance

Measure:

```text
API response time
database query time
WebSocket latency
AI latency
dashboard load time
```

Add slow-query logging.

Example threshold:

```text
SLOW QUERY > 500ms
```

Make the threshold configurable.

---

## Database Index Audit

Review indexes for frequently queried fields such as:

```text
user_id
channel_id
server_id
project_id
created_at
updated_at
is_read
status
```

Do not add indexes blindly. Use query analysis.

---

# 28. Phase 25 — Cache Strategy

Do not introduce Redis unless the architecture actually requires it.

For a local/XAMPP/capstone deployment, prioritize:

```text
MySQL
+
PHP
+
WebSocket
+
ws_relay
```

Redis can be introduced later if scaling requires it.

---

# 29. Phase 26 — Degraded Mode

E-Collab should remain useful when optional services fail.

If AI fails:

```text
Chat continues.
Tasks continue.
Notes continue.
Calendar continues.
Dashboard continues.
```

If WebSocket fails:

```text
REST fallback continues where appropriate.
```

If notifications fail:

```text
Core task creation still succeeds.
```

Optional services must not become single points of failure.

---

# 30. Phase 27 — Feature Flags

Create a lightweight feature-flag mechanism for risky features.

Examples:

```text
AI_ACTIONS
AUTOMATIONS
REALTIME_DASHBOARD
VOICE
WHITEBOARD
```

Unstable features can then be disabled without deleting their implementation.

---

# 31. Phase 28 — Security Audit

Audit:

```text
SQL injection
XSS
CSRF
IDOR
Authorization
File uploads
WebSocket authorization
Rate limiting
Session security
OAuth
Password handling
AI tool permissions
```

Pay special attention to:

```text
user_id
channel_id
server_id
task_id
note_id
project_id
```

Every resource must verify ownership, membership, or permission.

---

## IDOR Testing

A user must not access another user's:

```text
DM
notes
tasks
settings
goals
dashboard data
private resources
```

simply by changing:

```text
?id=123
```

or:

```json
{
  "user_id": 123
}
```

---

# 32. Phase 29 — Frontend Global Error Monitor

Implement a lightweight frontend error monitor.

Capture:

```text
window.onerror
unhandledrejection
API failures
WebSocket failures
```

Include safe diagnostic information:

```text
request_id
current page
error type
timestamp
```

Do not collect sensitive data.

---

# 33. Phase 30 — Developer Error Panel

Only when:

```text
APP_DEBUG=true
```

provide developer diagnostics.

Example:

```text
E-COLLAB DEBUG

Request ID:
ec-...

Endpoint:
/API/dm/open-conversation.php

HTTP:
500

Error:
DATABASE_ERROR

Message:
Unknown column ...

Trace:
...

[Copy Diagnostics]
```

Never display this in production.

---

# 34. Documentation

Create or update:

```text
docs/
├── ARCHITECTURE.md
├── API.md
├── DATABASE.md
├── WEBSOCKET.md
├── EVENTS.md
├── AI.md
├── AUTOMATIONS.md
├── SECURITY.md
├── TESTING.md
├── DEPLOYMENT.md
└── TROUBLESHOOTING.md
```

---

# 35. Troubleshooting Guide

Document common errors:

```text
500
401
403
404
429
WebSocket disconnected
Invalid WS token
CSRF failure
Database migration failure
AI unavailable
OAuth failure
```

For each error document:

```text
Symptoms
Cause
Diagnostic command
Solution
Verification
```

---

# 36. Implementation Order

## Sprint 1 — Stability Foundation

- [ ] Repository audit
- [ ] Database audit
- [ ] Schema mismatch fixes
- [ ] Migration cleanup
- [ ] Global error handling
- [ ] Request IDs
- [ ] Standard API response

## Sprint 2 — API and Browser Reliability

- [ ] Frontend API client
- [ ] Frontend error handling
- [ ] Notification normalization
- [ ] Notification bug fixes
- [ ] API contract tests

## Sprint 3 — Realtime Foundation

- [ ] WebSocket authorization
- [ ] WebSocket reconnect
- [ ] Heartbeat
- [ ] Event protocol
- [ ] ws_relay hardening

## Sprint 4 — Event-Driven Platform

- [ ] EventBus
- [ ] Event types
- [ ] Live dashboard updates
- [ ] Live notification updates
- [ ] Activity feed

## Sprint 5 — AI Actions

- [ ] AI Tool Registry
- [ ] AI permissions
- [ ] AI action confirmation
- [ ] `create_task`
- [ ] `create_note`
- [ ] `create_calendar_event`
- [ ] `create_goal`

## Sprint 6 — AI Intelligence

- [ ] AI summaries
- [ ] AI progress analysis
- [ ] AI quiz generation
- [ ] AI flashcard generation
- [ ] Chat → dashboard
- [ ] Chat → project

## Sprint 7 — Automation

- [ ] Automation Engine
- [ ] Triggers
- [ ] Conditions
- [ ] Actions
- [ ] Automation history
- [ ] Automation failure handling

## Sprint 8 — Full Testing

- [ ] Playwright
- [ ] Two-browser realtime tests
- [ ] Collaboration tests
- [ ] CI integration

## Sprint 9 — Production Hardening

- [ ] Security audit
- [ ] Performance audit
- [ ] Observability
- [ ] Documentation
- [ ] Deployment hardening

---

# 37. Definition of Done

A feature is NOT complete merely because it works once.

A feature is complete when applicable:

```text
[ ] Backend implemented
[ ] Frontend implemented
[ ] Authorization implemented
[ ] Validation implemented
[ ] Error handling implemented
[ ] Logging implemented
[ ] Request ID supported
[ ] Database migration exists
[ ] Tests exist
[ ] Realtime event exists
[ ] Notification exists
[ ] Dashboard integration exists
[ ] AI integration exists
[ ] Documentation exists
[ ] No existing feature regressed
```

---

# 38. Golden Architecture

Do not make E-Collab bigger merely by adding isolated features.

Make the existing systems integrated.

Target architecture:

```text
                    E-COLLAB
                        │
                   EVENT SYSTEM
                        │
          ┌─────────────┼─────────────┐
          ↓             ↓             ↓
        CHAT          TASKS        CALENDAR
          │             │             │
          └─────────────┼─────────────┘
                        ↓
                    AI ENGINE
                        │
                   AUTOMATIONS
                        │
                   DASHBOARDS
                        │
                    WEBSOCKET
                        │
                    LIVE USERS
```

---

# 39. Example End-to-End Workflow

User says:

> "We need to finish our capstone database by Friday. Make a plan for everyone."

E-Collab should:

```text
Analyze project
      ↓
Find project
      ↓
Find members
      ↓
Find current tasks
      ↓
Check calendar
      ↓
Check progress
      ↓
Generate recommended plan
      ↓
Ask for confirmation
```

After confirmation:

```text
AI
 ↓
Tool Registry
 ↓
Permission Guard
 ↓
TaskService
 ↓
Database Transaction
 ↓
EventBus
 ↓
WebSocket
 ↓
Dashboard
 ↓
Notifications
 ↓
Activity Feed
```

Every authorized member sees the changes live.

The dashboard updates.

Tasks appear.

Notifications arrive.

The AI can later answer:

> "How are we doing?"

by analyzing actual E-Collab project data rather than inventing an answer.

---

# 40. First Copilot Task

**DO NOT implement the entire plan immediately.**

First perform:

```text
ECOLLAB AUDIT
```

Produce:

1. Repository architecture map
2. Database table map
3. Migration map
4. API inventory
5. WebSocket event inventory
6. Service inventory
7. Frontend API inventory
8. Known 500/error sources
9. Security risks
10. Schema inconsistencies
11. Duplicate implementations
12. Missing tests
13. Recommended implementation order

Then implement only:

```text
PHASE 1 — DATABASE CONSISTENCY
```

After Phase 1 passes all tests, continue to:

```text
PHASE 2 — GLOBAL ERROR HANDLING
```

Do not implement Phase 3+ until the previous phase is stable.

---

# 41. Copilot Execution Workflow

For every task:

```text
STEP 1 — Understand
STEP 2 — Search repository
STEP 3 — Map dependencies
STEP 4 — Plan minimal change
STEP 5 — Implement
STEP 6 — Run static checks
STEP 7 — Run unit tests
STEP 8 — Run integration tests
STEP 9 — Run browser/realtime tests
STEP 10 — Review git diff
STEP 11 — Fix regressions
STEP 12 — Document
STEP 13 — Commit
```

Never go directly from:

```text
request
```

to:

```text
large rewrite
```

---

# 42. Success Criteria

E-Collab 2.0 is successful when:

- [ ] Database schema is deterministic.
- [ ] Migrations are the source of truth.
- [ ] Known schema/code mismatches are eliminated.
- [ ] API errors are standardized.
- [ ] Every request has a traceable ID.
- [ ] Production errors do not leak internals.
- [ ] Frontend handles API failures gracefully.
- [ ] WebSocket reconnects reliably.
- [ ] WebSocket authorization matches REST.
- [ ] Dashboard updates without refresh.
- [ ] Collaboration tools synchronize reliably.
- [ ] AI can perform authorized actions.
- [ ] Chat can trigger actions.
- [ ] Automations can react to events.
- [ ] Users receive live notifications.
- [ ] Critical flows have browser tests.
- [ ] Two-browser realtime tests pass.
- [ ] CI catches regressions.
- [ ] Existing functionality remains intact.

---

# 43. Final Target

The ultimate E-Collab architecture should be:

```text
                         ┌─────────────────────┐
                         │       USERS         │
                         └──────────┬──────────┘
                                    │
                    ┌───────────────┴───────────────┐
                    │                               │
              REST / HTTP                      WebSocket
                    │                               │
                    └───────────────┬───────────────┘
                                    │
                         ┌──────────▼──────────┐
                         │   APPLICATION CORE  │
                         └──────────┬──────────┘
                                    │
              ┌─────────────────────┼─────────────────────┐
              │                     │                     │
        ┌─────▼─────┐        ┌──────▼──────┐       ┌─────▼─────┐
        │  SERVICES │        │  EVENT BUS  │       │ AI ENGINE │
        └─────┬─────┘        └──────┬──────┘       └─────┬─────┘
              │                     │                     │
              │              ┌──────┼──────┐              │
              │              │      │      │              │
              │             WS   Notify  Activity         │
              │                     │                     │
              └─────────────────────┼─────────────────────┘
                                    │
                         ┌──────────▼──────────┐
                         │       MYSQL        │
                         └─────────────────────┘
```

The key principle is:

> **E-Collab should behave as one connected system, not a collection of unrelated features.**

---

# END OF ECOLLAB 2.0 MASTER IMPLEMENTATION PLAN
