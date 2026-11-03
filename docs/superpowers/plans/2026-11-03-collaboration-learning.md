# Collaboration and Learning Implementation Plan

> Execute inline with superpowers:executing-plans, regression-first checks and one final fresh review.

**Goal:** Repair ephemeral rooms/stream viewing and implement owner-scoped tasks, workflow-only task assistance, accurate AI capabilities and weekly behavioral peer matching.
**Architecture:** Extend existing PHP services and vanilla controllers. Authorization and task state remain server-side; a weekly snapshot feeds bounded behavioral similarity into the existing scorer.
**Tech Stack:** PHP 8.2+, PDO MySQL/MariaDB, JavaScript, Ratchet, LiveKit, systemd.
**Spec:** docs/superpowers/specs/2026-11-03-collaboration-learning-design.md

## Global constraints
- Base ecollab-collabs; no production mutations or merge; preserve previous fixes in their separate branch.
- Retain CSRF, membership and private-resource boundaries. Never collect private message bodies or source code for matching.
- No active task: normal AI. Active student attempt: workflow only, enforced from database state across routes.
- Weekly job is installed on VPS separately; don't claim it is running.

## Review focus
- Simultaneous leave/join or multiple connections must not remove an occupied room.
- Late media publication/reconnection must produce usable remote Watch/Expand controls.
- Foreign server IDs and forged browser context cannot expand facilitator/AI authority.
- Missing optional schema and opted-out users must not break existing matching or collect data.
- Repeated job/client events must not double-count or leak private activity.

### Task 1: Ownership scope
Files: services/CodeLabService.php, modules/code-lab/index.php, tests/code-lab-regression.php.
Interface: member(sid,uid) returns authoritative can_manage/can_publish; create checks can_publish.
- [x] Add foreign facilitator-membership publishing rejection test; observe failure.
- [x] Enforce owner + facilitator publishing and reflect it in UI.
- [x] Run Code Lab service/UI/execution regressions; commit.

### Task 2: Temporary room lifecycle
Files: services/TemporaryVoiceService.php, websocket/ChatServer.php, API/chat/active-now.php, tests/temporary-voice-lifecycle.php.
Interface: injected PDO service lifecycle methods return deleted channel IDs; broadcast removal only for the proper server.
- [x] Reproduce missing WS leave/switch/disconnect cleanup and occupied-room preservation.
- [x] Serialize lifecycle and wire HTTP/WS cleanup and channel-removal updates.
- [x] Run SQLite lifecycle and existing voice/privacy tests; commit.

### Task 3: Remote stream controls
Files: assets/js/chat/livekit-voice.js, assets/js/chat/voice.js, tests/livekit-voice-regression.cjs.
Interface: every remote participant's stream has working Watch/Unwatch and Expand/Restore.
- [x] Add a remote participant video test and identify attachment/control failure.
- [x] Repair LiveKit attachment/reconnection and controls.
- [x] Run voice, calls and DOM media regressions; commit.

### Task 4: Workflow assistance and capabilities
Files: services/CodeLabIntegrityPolicy.php, services/JarredCapabilityPolicy.php, services/JarredActionService.php, AI API entrypoints, Code Lab UI, tests/code-lab-integrity-policy.php.
Interface: server-side activeAttempt(db,uid) and deterministic task guidance, shared capability/refusal text.
- [x] Test active attempt across pages/forged surfaces, submitted/expired/no-attempt behavior.
- [x] Replace blanket refusal with persisted workflow guidance before provider/actions and expose Code Lab help.
- [x] Add capability help and exact unsupported-operation response; test normal chat and permissions; commit.

### Task 5: Weekly behavioral matching
Files: new PeerBehaviorService, API/profile/learning-activity.php, shared activity JS/include, PeerMatchingService, additive migration, CLI/timer and settings/help copy.
Interface: record authenticated bounded events, refresh snapshots, load aggregate profile, score optional evidence without changing cold starts.
- [x] Test cosine/sparse histories, opt-out, dedup/limits and trusted database inputs.
- [x] Implement authenticated events, source aggregates, atomic weekly refresh, job lock and retention.
- [x] Integrate scorer and disclosure; run matching and real isolated SQL checks; commit.

### Task 6: Release evidence
- [x] Run focused suites plus existing unit/integration/browser checks appropriate to changed flows.
- [x] Fresh review; fix critical issues with regressions; publish a separate reviewable PR and exact pull/deploy/scheduler instructions.
- [x] State live-provider, VPS and representative-load checks not executed.
