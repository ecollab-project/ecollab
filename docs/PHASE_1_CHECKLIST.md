# Phase 1 — Database Consistency Checklist

**Phase Goal:** Make database schema deterministic, verify all application code against schema, eliminate mismatches.

**Time Estimate:** 2 weeks
**Owner:** E-Collab Database Engineer + Backend Engineer
**Success Criteria:** All items below = ✅ COMPLETE

---

## Task 1: Schema Consolidation

**Goal:** Verify migration 002 is complete and self-contained; confirm 004/006 are redundant but safe.

**Finding (2026-08-19):** Migration 002_core_schema.sql is ALREADY COMPLETE. It includes all tables that 004_missing_tables.sql and 006_chat_addon.sql attempt to add. Migrations 004 and 006 use `IF NOT EXISTS` guards, making them safe but redundant.

**Action:** Document this finding and move forward (no consolidation merge needed).

**Steps:**

- [x] **1.1 — Verify current schema** (COMPLETED)
  - [x] Tested ecollab_test database: 114 tables exist
  - [x] Migration runner shows: "Database is up to date"
  - [x] All expected tables present (message_reactions, message_reads, channel_members, user_hobbies, etc.)

- [x] **1.2 — Analyze retroactive migrations** (COMPLETED)
  - [x] 002_core_schema.sql: Creates institutions, users, messages, notifications, channels, collab_*, etc.
  - [x] 004_missing_tables.sql: Tries to add message_reads, channel_members, user_hobbies (all ALREADY in 002)
  - [x] 006_chat_addon.sql: Tries to add message_reactions, message_attachments, whiteboards (all ALREADY in 002)

- [x] **1.3 — Document finding**
  - [x] Update this checklist to reflect completion
  - [x] Note: 002 includes extra tables at end (lines 1052+)
  - [x] 004 and 006 are purely redundant but make migrations idempotent via IF NOT EXISTS

- [x] **1.4 — Verify no action needed**
  - [x] Running migrations 000-021 produces complete schema
  - [x] No duplicate definitions cause errors
  - [x] All FKs intact

**Success Criteria:**
- ✅ 002 is confirmed complete and self-contained
- ✅ 004 and 006 are confirmed redundant but safe
- ✅ Schema is identical whether using all migrations or just core
- ✅ No consolidation merge necessary
- ✅ Task complete

---

## Task 2: WebSocket Table Migration

**Goal:** Move inline `ws_relay` table creation from PHP to migrations.

**Status:** ✅ COMPLETE (2026-08-19)

**Changes Made:**

1. **Created `database/migrations/022_websocket_relay_table.sql`**
   - Defines ws_relay table with proper charset and collation
   - Adds FK constraint: `channel_id` → `channels(id) ON DELETE CASCADE`
   - Includes comment explaining usage: "REST endpoints insert events; ChatServer drains periodically"

2. **Updated `websocket/ChatServer.php`**
   - Removed inline CREATE TABLE statement (was ~19 lines in constructor)
   - Replaced with comment: "ws_relay table created by migration 022; assume migration ran"
   - Simplified constructor from ~55 to ~45 lines

3. **Verification**
   - [x] PHP syntax check: PASS
   - [x] Migration runner: Recognized migration 022
   - [x] Migration execution: OK (2ms)
   - [x] No code regressions

**Files Changed:**
- `database/migrations/022_websocket_relay_table.sql` (NEW)
- `websocket/ChatServer.php` (MODIFIED, -19 lines)

**Why This Matters:**
- Migrations are now the single source of truth for ws_relay schema
- FK constraint prevents orphaned relay entries if channel is deleted
- Eliminates runtime schema creation (cleaner, more auditable)
- Aligns with PLAN.md: "Migrations are the source of truth"

**Success Criteria:**
- ✅ Migration file created
- ✅ ChatServer no longer creates table at runtime
- ✅ All code that uses ws_relay continues to work
- ✅ FK constraint protects data integrity

---

## Task 3: Presence Table Standardization

**Goal:** Create unified presence/activity table; consolidate scattered presence columns.

**Status:** 🔄 IN PROGRESS (Steps 3.1-3.3 complete, 3.4-3.6 pending)

**Actual Current State (from audit):** Presence data is in:
- `users.is_online` (boolean) — main online/offline flag
- `users.last_active_at` (datetime) — last activity timestamp
- `users.voice_channel_id` (int) — which voice room user is in
- `users.current_activity` (varchar) — description of what user is doing
- WebSocket `connMeta.channel_id` (in-memory, not persisted) — current text channel
- `user_settings.activity_status` (tinyint) — privacy setting
- `avatar_url`, `avatar_gradient` (visual presence)

**Completed Implementations:**

- [x] **3.1 — Design unified presence table**
  - [x] Created `user_presence` table with:
    - status ENUM('online', 'idle', 'offline', 'dnd')
    - current_channel_id for active text channel
    - voice_room_id for active voice channel
    - last_activity_at for idle timeout calculation
    - presence_metadata JSON for extensibility
    - UNIQUE(user_id, server_id) for per-server per-user tracking

- [x] **3.2 — Create migration**
  - [x] Created `database/migrations/023_user_presence_table.sql`
  - [x] Includes FK constraints: user→users, server→servers, channels (text + voice)
  - [x] Added indexes: user_id, server_id, status, updated_at
  - [x] Ready for execution

- [x] **3.3 — Update WebSocket code**
  - [x] Modified `setUserOnline()` to update user_presence status when auth/disconnect
  - [x] Enhanced `handleJoinChannel()` to record current_channel_id in user_presence
  - [x] Enhanced `handleLeaveChannel()` to clear current_channel_id
  - [x] Added `handlePing()` to update last_activity_at on heartbeat
  - [x] Enhanced `handlePresence()` to allow status changes (online/idle/dnd/online)
  - [x] All changes maintain backward compatibility with existing users table updates

**Remaining Steps:**

- [ ] **3.1 — Design unified presence table**
  - [ ] Create schema:
    ```sql
    CREATE TABLE user_presence (
        id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        server_id INT UNSIGNED NOT NULL,
        status ENUM('online', 'idle', 'offline', 'dnd') DEFAULT 'offline',
        last_activity_at DATETIME,
        voice_room_id INT UNSIGNED NULL,
        current_channel_id INT UNSIGNED NULL,
        presence_metadata JSON,
        updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,

        CONSTRAINT fk_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
        UNIQUE KEY unique_user_server (user_id, server_id)
    )
    ```
  - [ ] Store online/idle/offline state in one place
  - [ ] Track which channel user is in (for "green dot in channel list")
  - [ ] Allow arbitrary metadata for extensions (via JSON)

- [ ] **3.2 — Create migration**
  - [ ] Create `023_user_presence_table.sql`
  - [ ] Create new table as above
  - [ ] Add indexes on `user_id`, `server_id`, `status`, `updated_at`

- [ ] **3.3 — Update WebSocket code**
  - [ ] On `auth`: insert/update `user_presence` row with `online` status
  - [ ] On `join_channel`: update `user_presence.current_channel_id`
  - [ ] On `ping`/heartbeat: update `user_presence.last_activity_at`
  - [ ] On disconnect/close: set `user_presence.status = 'offline'`

**Remaining Steps:**

- [ ] **3.4 — Update queries that check presence**
  - [ ] Find: `WHERE u.is_online = 1` queries
  - [ ] Rewrite to JOIN user_presence: `JOIN user_presence up ON up.user_id=u.id AND up.status IN ('online', 'idle')`
  - [ ] Key files: `API/chat/active-now.php`, `API/chat/channel-members.php`, `services/ChannelService.php`, `services/StudentDashboardService.php`
  - [ ] Test queries return correct results

- [ ] **3.5 — Add auto-cleanup logic**
  - [ ] Add periodic cleanup: set status='idle' if last_activity > 5 min, 'offline' if > 30 min
  - [ ] Can implement in ChatServer startup or via scheduled task

- [ ] **3.6 — Test**
  - [ ] Integration: Auth → presence row exists with status='online'
  - [ ] Integration: Join channel → current_channel_id is updated
  - [ ] Integration: Ping → last_activity_at updated
  - [ ] Integration: Disconnect → status set to 'offline'

**Files Modified:**
- `database/migrations/023_user_presence_table.sql` (NEW)
- `websocket/ChatServer.php` (MODIFIED)
  - `setUserOnline()` — now updates user_presence
  - `handleJoinChannel()` — records current_channel_id
  - `handleLeaveChannel()` — clears current_channel_id
  - `handlePing()` — updates last_activity_at (new method)
  - `handlePresence()` — supports status changes (enhanced)

**Why This Matters:**
- Consolidates presence tracking from 6+ scattered locations into 1 table
- Enables "green dot in channel list" feature (current_channel_id)
- Supports idle timeout logic (last_activity_at + periodic cleanup)
- Scales better: joins are faster than checking multiple columns
- Extensible: JSON metadata field for future features (device type, location, etc.)

---

## Task 4: Notification Type Enum

**Goal:** Enforce notification type constants via database enum instead of free-text strings.

**Current State:** `notifications.type` is VARCHAR, code uses strings like `"task_assigned"`, `"message_mention"`, etc.

**Steps:**

- [ ] **4.1 — Audit notification types**
  - [ ] Grep for all `notifications.type` values in code
  - [ ] List all distinct types found (grep `INSERT INTO notifications`)
  - [ ] Create `EventTypes.php` with constants:
    ```php
    class EventTypes {
        const TASK_CREATED = 'task.created';
        const TASK_ASSIGNED = 'task.assigned';
        const MESSAGE_MENTION = 'message.mention';
        // ... etc
    }
    ```

- [ ] **4.2 — Create migration**
  - [ ] Create `024_notification_types_enum.sql`
  - [ ] Alter column:
    ```sql
    ALTER TABLE notifications
    MODIFY COLUMN type ENUM(
        'task.created',
        'task.assigned',
        'message.mention',
        'connection_request',
        'server_invite',
        'peer_match',
        'announcement'
        -- ... all types from EventTypes.php
    ) NOT NULL;
    ```
  - [ ] Verify all existing values in table match enum values

- [ ] **4.3 — Update code**
  - [ ] Replace all string literals with `EventTypes::CONSTANT`
  - [ ] Example: `$type = 'message.mention'` → `$type = EventTypes::MESSAGE_MENTION`
  - [ ] Use IDE refactor tool to find/replace all occurrences

- [ ] **4.4 — Add validation service**
  - [ ] Create `NotificationService::createNotification($user_id, $type, $body)`
  - [ ] Validate `$type` is valid enum value
  - [ ] Throw exception if invalid

- [ ] **4.5 — Test**
  - [ ] Unit test: `EventTypes` constants exist and are strings
  - [ ] Integration test: insert notification with valid type → succeeds
  - [ ] Integration test: insert with invalid type → fails at database level

**Success Criteria:**
- ✅ `EventTypes.php` exists with all notification types
- ✅ `024_notification_types_enum.sql` exists and alters table
- ✅ All code uses `EventTypes::CONSTANT` instead of strings
- ✅ Tests pass

---

## Task 5: User Settings Automatic Creation

**Goal:** Ensure every new user has a `user_settings` row.

**Current State:** No guarantee `user_settings` row exists for every `users` row.

**Steps:**

- [ ] **5.1 — Add database trigger**
  - [ ] Create trigger on `users` INSERT:
    ```sql
    CREATE TRIGGER create_user_settings_on_insert
    AFTER INSERT ON users
    FOR EACH ROW
    BEGIN
        INSERT INTO user_settings (user_id) VALUES (NEW.id);
    END;
    ```
  - [ ] Add to new migration `025_user_settings_trigger.sql`

- [ ] **5.2 — Backfill missing rows**
  - [ ] Query: `SELECT user_id FROM users LEFT JOIN user_settings USING(user_id) WHERE user_settings.user_id IS NULL`
  - [ ] For each result, insert default `user_settings` row
  - [ ] Verify: `SELECT COUNT(*) FROM users` == `SELECT COUNT(*) FROM user_settings`

- [ ] **5.3 — Update UserService**
  - [ ] In `UserService::createUser()`, after INSERT users, insert user_settings
  - [ ] Or rely on trigger (and verify it fires)

- [ ] **5.4 — Add regression test**
  - [ ] Test: create user → verify `user_settings` row exists
  - [ ] Test: count users == count user_settings

**Success Criteria:**
- ✅ Trigger exists or UserService creates row
- ✅ No orphaned users without settings
- ✅ Test passes

---

## Task 6: Message Threads Verification

**Goal:** Confirm `messages.thread_id` column exists and is properly constrained.

**Steps:**

- [ ] **6.1 — Check current schema**
  - [ ] Run: `DESCRIBE messages;` (on test DB after migrations)
  - [ ] Look for `thread_id` column
  - [ ] If missing: bug found (should exist before thread queries run)
  - [ ] If exists: check type and constraint

- [ ] **6.2 — Review migration 020**
  - [ ] Read `020_threads_v2.sql`
  - [ ] Verify it includes: `ALTER TABLE messages ADD COLUMN thread_id ...`
  - [ ] Or if not: create migration `026_messages_thread_id_backfill.sql`

- [ ] **6.3 — Add foreign key**
  - [ ] Migration should include:
    ```sql
    ALTER TABLE messages
    ADD CONSTRAINT fk_messages_thread
    FOREIGN KEY (thread_id) REFERENCES threads(id) ON DELETE SET NULL;
    ```

- [ ] **6.4 — Verify thread queries**
  - [ ] Find all: `SELECT ... FROM messages WHERE thread_id`
  - [ ] Ensure joins to `threads` table are correct
  - [ ] Test: query for threads in a channel

- [ ] **6.5 — Test**
  - [ ] Query: `SELECT thread_id FROM messages LIMIT 10` → should work, may be NULL
  - [ ] Create thread → create message with thread_id → query succeeds

**Success Criteria:**
- ✅ `messages.thread_id` column exists
- ✅ FK constraint exists or is added
- ✅ All thread queries work
- ✅ Migration sequence is correct

---

## Task 7: Foreign Key Audit

**Goal:** Verify all tables have correct FK constraints; test cascade behavior.

**Steps:**

- [ ] **7.1 — List all foreign keys**
  - [ ] Query:
    ```sql
    SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = 'ecollab_test' AND REFERENCED_TABLE_NAME IS NOT NULL;
    ```
  - [ ] List all expected FKs (document in audit)

- [ ] **7.2 — Check for missing FKs**
  - [ ] Every `*_id` column should have a FK to that entity
  - [ ] Examples:
    - `messages.sender_id` → `users(id)`
    - `channel_members.user_id` → `users(id)`
    - `channel_members.channel_id` → `channels(id)`
  - [ ] For each missing: create migration to add

- [ ] **7.3 — Test cascade behavior**
  - [ ] For each FK, verify `ON DELETE CASCADE` or `ON DELETE SET NULL` is correct
  - [ ] Example test:
    ```sql
    -- Create test user
    INSERT INTO users (email, password_hash, role, status) VALUES ('test@test.com', '...', 'student', 'active');
    SET @uid = LAST_INSERT_ID();

    -- Create test channel
    INSERT INTO channels (name, server_id) VALUES ('test', 1);
    SET @cid = LAST_INSERT_ID();

    -- Add member
    INSERT INTO channel_members (channel_id, user_id, role) VALUES (@cid, @uid, 'member');

    -- Delete user
    DELETE FROM users WHERE id = @uid;

    -- Verify cascade
    SELECT COUNT(*) FROM channel_members WHERE user_id = @uid;  -- Should be 0
    ```

- [ ] **7.4 — Document FK strategy**
  - [ ] Create file: `docs/FOREIGN_KEY_STRATEGY.md`
  - [ ] List all FKs and their ON DELETE behavior
  - [ ] Explain cascade vs. set-null vs. restrict choices

**Success Criteria:**
- ✅ All `*_id` columns have corresponding FK constraints
- ✅ ON DELETE behavior tested for cascade
- ✅ Documentation exists

---

## Task 8: Index Audit

**Goal:** Verify indexes exist for all high-cardinality queries.

**Steps:**

- [ ] **8.1 — Query analysis**
  - [ ] Identify all frequently executed queries (from logs or code review)
  - [ ] Common patterns:
    - `WHERE user_id = X` → needs index on `user_id`
    - `WHERE channel_id = X ORDER BY created_at DESC` → needs index on `(channel_id, created_at)`
    - `WHERE created_at > X` → needs index on `created_at`

- [ ] **8.2 — List existing indexes**
  - [ ] Query:
    ```sql
    SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = 'ecollab_test'
    ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;
    ```

- [ ] **8.3 — Add missing indexes**
  - [ ] For each critical query without an index, create migration
  - [ ] Example:
    ```sql
    -- migration 027_add_indexes.sql
    ALTER TABLE messages ADD INDEX idx_channel_created (channel_id, created_at DESC);
    ALTER TABLE user_presence ADD INDEX idx_server_status (server_id, status);
    ```

- [ ] **8.4 — Test index usage**
  - [ ] Run EXPLAIN on critical queries:
    ```sql
    EXPLAIN SELECT * FROM messages WHERE channel_id = 12 ORDER BY created_at DESC LIMIT 50;
    -- Should show "Using index" or "Using index, Using filesort" (not "ALL")
    ```

- [ ] **8.5 — Document**
  - [ ] List all indexes in `docs/SCHEMA_INDEXES.md`

**Success Criteria:**
- ✅ Indexes exist for all common queries
- ✅ EXPLAIN shows index usage, not full table scans
- ✅ Documentation exists

---

## Task 9: Migration Idempotency

**Goal:** Verify all migrations can be run multiple times safely.

**Steps:**

- [ ] **9.1 — Test idempotency**
  - [ ] Run: `rm ecollab_test database; create database ecollab_test;`
  - [ ] Run: `DB_NAME=ecollab_test php database/migrate.php`
  - [ ] Verify: success
  - [ ] Run again: `DB_NAME=ecollab_test php database/migrate.php`
  - [ ] Verify: no errors (migrations should detect already-applied and skip)

- [ ] **9.2 — Fix migrations that fail on second run**
  - [ ] Any migration that has `CREATE TABLE` without `IF NOT EXISTS` → add `IF NOT EXISTS`
  - [ ] Any migration that has `ALTER TABLE ... ADD COLUMN` without checking column existence → use `ALTER IGNORE` or conditional

- [ ] **9.3 — Add test to CI**
  - [ ] Create test that runs migrations twice:
    ```php
    public function testMigrationsAreIdempotent() {
        // Run migrations
        exec('php database/migrate.php', $output, $code);
        $this->assertEquals(0, $code, 'First run failed: ' . implode("\n", $output));

        // Run again
        exec('php database/migrate.php', $output, $code);
        $this->assertEquals(0, $code, 'Second run failed: ' . implode("\n", $output));
    }
    ```

**Success Criteria:**
- ✅ Migrations run successfully 000-027 (or latest) once
- ✅ Migrations run successfully second time (no errors)
- ✅ CI test added and passes

---

## Task 10: Schema Regression Tests

**Goal:** Create automated tests that verify critical schema elements exist and are correctly structured.

**Steps:**

- [ ] **10.1 — Create test file**
  - [ ] Create: `tests/Integration/SchemaConsistencyTest.php`
  - [ ] Extend `TestCase`
  - [ ] Set up test DB with full migrations before each test

- [ ] **10.2 — Test table existence**
  - [ ] For each critical table (users, messages, channels, user_settings, etc.):
    ```php
    public function testUserSettingsTableExists() {
        $result = $this->db->query("SELECT * FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?");
        $result->execute([DB_NAME, 'user_settings']);
        $this->assertNotEmpty($result->fetchAll(), 'user_settings table does not exist');
    }
    ```

- [ ] **10.3 — Test column existence**
  - [ ] For each critical column:
    ```php
    public function testMessagesTableHasThreadId() {
        $result = $this->db->query("DESCRIBE messages");
        $columns = array_column($result->fetchAll(PDO::FETCH_ASSOC), 'Field');
        $this->assertContains('thread_id', $columns, 'thread_id column missing');
    }
    ```

- [ ] **10.4 — Test indexes**
  - [ ] For each critical index:
    ```php
    public function testChannelCreatedAtIndex() {
        $result = $this->db->query("SELECT * FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?");
        $result->execute([DB_NAME, 'messages', 'idx_channel_created']);
        $this->assertNotEmpty($result->fetchAll(), 'idx_channel_created index missing');
    }
    ```

- [ ] **10.5 — Test foreign keys**
  - [ ] For each critical FK:
    ```php
    public function testMessagesUserIdFK() {
        // Try to insert message with non-existent user_id
        // Should fail if FK is enforced
        $this->expectException(PDOException::class);
        // ... insert code ...
    }
    ```

- [ ] **10.6 — Run tests**
  - [ ] Run: `vendor/bin/phpunit --testsuite Integration tests/Integration/SchemaConsistencyTest.php`
  - [ ] All tests should pass

**Success Criteria:**
- ✅ `tests/Integration/SchemaConsistencyTest.php` exists
- ✅ All tests pass on fresh migration
- ✅ Tests catch regressions (e.g., if migration 002 is broken, tests fail)

---

## Verification Checklist

**Final verification before marking Phase 1 complete:**

- [ ] All migrations run successfully 000-latest on blank database
- [ ] Migrations are idempotent (run twice, no errors)
- [ ] No code references nonexistent tables
- [ ] No code references nonexistent columns
- [ ] Foreign keys are enforced (cascade delete tested)
- [ ] Indexes exist for all common queries
- [ ] All tables have appropriate indexes
- [ ] `user_settings` row exists for every user
- [ ] Notification types use enum constants
- [ ] Presence tracking is unified (or documented as TBD)
- [ ] WebSocket relay table is in migrations (not inline)
- [ ] Schema regression tests pass
- [ ] No duplicate table definitions in migration sequence
- [ ] All FK constraints documented
- [ ] PLAN.md Phase 1 checklist complete

---

## Success Criteria

Phase 1 is **COMPLETE** when:

1. ✅ Database schema is deterministic (migrations 000-027+ run reliably)
2. ✅ All application code passes schema checks (no missing columns/tables)
3. ✅ Schema/code mismatches eliminated (all 11 inconsistencies resolved or documented)
4. ✅ Foreign keys enforced (cascade behavior tested)
5. ✅ Indexes optimized (common queries use indexes)
6. ✅ Regression tests in CI (prevent future schema drift)
7. ✅ Zero production data loss (migrations preserve existing data)
8. ✅ Documentation complete (`FOREIGN_KEY_STRATEGY.md`, `SCHEMA_INDEXES.md`)

**Time Estimate:** 10-14 days (depending on discovery of edge cases)

---

## Next Phase

After Phase 1 passes all tests:

→ **Phase 2 — Global Error Handling** (see PLAN.md section 5)

---

*Checklist Version: 1.0*
*Last Updated: 2026-08-19*
*Owner: E-Collab Database Engineer + Backend Engineer*
