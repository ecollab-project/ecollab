# E-Collab Architecture Decision Map

Quick reference for understanding how different parts of E-Collab connect and why certain patterns exist.

## Architecture Patterns

### 1. Why Two User Preference Storage Locations?

**Question:** Why does user preference data live in both `users` table and `user_settings` table?

**Answer:**
- `users` table: Core identity (email, password, created_at, oauth_provider, oauth_id, role, status)
- `user_settings` table: Per-user preferences (theme, notifications, audio devices, accessibility)

**Design Rationale:**
- Separation of concerns: authentication layer vs. UX preferences
- `users` table can be accessed without joining; `user_settings` only when customization needed
- Allows default user settings (via INSERT or trigger) without modifying core `users` schema

**Issue:** No automatic `user_settings` insert on user creation (potential NULL reference bug).

---

### 2. Why Separate `notes` and `collab_notes`?

**Question:** Why are there two notes tables?

**Answer:**
- `notes` table: Simple persistence (possibly legacy or admin-created notes)
- `collab_notes` + `collab_note_ops`: Operational-Transform synced notes for real-time collaboration

**Design Rationale:**
- OT requires operation log tracking; simple notes don't
- Different API endpoints: `/API/chat/` vs. `/API/collab/collab.php?tool=notes`
- Allows gradual rollout of OT feature

**Issue:** Unclear which one is "canonical"; risk of diverging implementations.

**Action Required:** Clarify ownership. If `notes` is legacy, deprecate it and migrate data to `collab_notes`.

---

### 3. Why Three Direct Message Implementations?

**Observation:** Different message types exist:
- `messages` table → channel messages
- `direct_messages` table → one-to-one conversations
- `dm_conversations` table → conversation metadata (migration 008)

**Question:** How do DM queries work?

**Pattern:**
1. Open DM conversation: `API/dm/open-conversation.php` queries/creates `dm_conversations` entry
2. Send DM: `API/dm/send-message.php` inserts into `direct_messages`
3. Get DM history: queries `direct_messages` where `conversation_id = X`

**Issue:** No unified message interface (channel messages and DMs use different tables).

**Design Rationale:**
- DMs pre-date conversations feature; dual-table for backward compatibility
- `dm_conversations` table added later for metadata (e.g., "last_message_at", "participant_list")

**Action Required:** Eventually unify via a polymorphic message table or shared event stream.

---

### 4. WebSocket Relay via `ws_relay` Table

**Question:** How does the REST API push real-time updates to WebSocket clients?

**Answer:**
- Endpoints that create/update data insert a JSON event into `ws_relay` table
- `ChatServer.php` polls `ws_relay` periodically via `drainRelayTable()`
- Events are broadcast to subscribed clients, then deleted from table

**Pattern:**
```
REST Endpoint (e.g., send_message)
   ↓
Database INSERT (message record)
   ↓
INSERT ws_relay (event JSON)
   ↓
ChatServer polls ws_relay
   ↓
WebSocket broadcast to clients
   ↓
DELETE from ws_relay
```

**Design Rationale:**
- Decouples REST from WebSocket
- Ensures real-time updates even if WebSocket connection is temporary
- No direct REST→WebSocket coupling

**Issue:**
- Polling introduces latency (vs. event-driven)
- No cleanup if `drainRelayTable()` crashes (entries accumulate)
- Foreign key missing on `ws_relay.channel_id`

**Action Required:**
- Add FK constraint to `ws_relay.channel_id`
- Consider event-driven architecture (queue) as Phase 6 improvement

---

### 5. Why Multiple Peer Matching Implementations?

**Pattern:**
- `API/chat/peer-match.php` — REST endpoint routing to sub-actions
- `services/PeerMatchingService.php` — Business logic service

**Design Rationale:**
- Service encapsulates algorithm; endpoint is HTTP interface
- Allows service to be called from CLI tools, admin panel, or AI actions

**Issue:** If algorithm changes, both must update; risk of divergence.

**Action Required:** Ensure all peer matching flows call `services/PeerMatchingService.php`, not inline logic.

---

### 6. Operational Transform (OT) Implementation

**Location:** `assets/js/chat/ot-engine.js` + `API/collab/collab.php?tool=notes`

**Pattern:**
```
Client A changes "Hello" → "Hello World"
   ↓
OT transforms to operation: {type: 'insert', pos: 5, text: ' World'}
   ↓
Send via POST or WebSocket
   ↓
Server applies operation to collab_notes content
   ↓
Insert operation record in collab_note_ops (for replay)
   ↓
Broadcast to all other clients
   ↓
Other clients transform local edits against new op
```

**Design Rationale:**
- Prevents conflicts when multiple users edit simultaneously
- Operation log allows late-joining clients to catch up
- Possible to reconstruct full history from operations

**Issue:**
- No client_id tracking (can't deduplicate if client retransmits)
- No ACK mechanism (client doesn't know if op was applied)
- No revision number (can't detect stale ops)

**Action Required:** Add client_id, revision, and ACK to OT protocol for Phase 4.

---

### 7. Authorization Model

**Pattern:**
```
All endpoints check:
   1. Is user authenticated? (session check)
   2. Does user have required role? (RoleMiddleware)
   3. Does user have permission on this resource? (ownership/membership check)
```

**Examples:**
- Send message to channel → verify user is member of channel
- Edit own message → verify sender_id == $_SESSION['user_id']
- Ban user from server → verify user is server admin

**Issue:**
- Ownership checks are scattered across endpoints (not centralized)
- No audit trail of who performed authorization checks
- IDOR bugs possible if checks are missed (e.g., DM conversation access)

**Action Required:** Centralize authorization logic in a service or middleware.

---

### 8. Error Handling Anti-Pattern

**Current Pattern:**
```php
try {
    $stmt = $db->prepare(...);
    $stmt->execute();
} catch (PDOException $e) {
    die(json_encode(['error' => $e->getMessage()])); // BAD: SQL error to client
}
```

**Issues:**
- SQL errors leak schema information
- Stack traces expose internal structure
- No logging of which query failed
- No request ID for tracing

**Action Required:** Phase 2 — Global Error Handling (implement ErrorHandler service).

---

## Summary: Key Architectural Decisions

| Pattern | Why | Current State | Issue | Action |
|---------|-----|---|---|---|
| Two notes tables | OT vs. simple | Both active | Unclear which canonical | Clarify/deprecate |
| Separate DM table | Legacy + metadata | Three tables | Complex queries | Eventually unify |
| WebSocket relay via polling | Decouple HTTP/WS | Works but slow | Latency + cleanup risk | Phase 6: add queue |
| OT without ACK | Simpler initial impl | Functional | Duplicate risk | Phase 4: add ACK |
| Scattered auth checks | Flexibility | All endpoints | Easy to miss | Phase 5: centralize |
| Error handling | Early stage | Leaks internals | Security + UX | Phase 2: standardize |

---

## Decision: Use This Audit for Phase 1

**Question:** Should we change architecture during Phase 1?

**Answer:** **No.** Phase 1 is about making the existing architecture deterministic, not redesigning it.

**Approach:**
1. Phase 1: Stabilize database schema, fix schema/code mismatches
2. Phase 2: Standardize error handling, add request ID
3. Phase 3+: Architectural improvements (unify messages, add event bus, etc.)

---

*Last updated: 2026-08-19*
