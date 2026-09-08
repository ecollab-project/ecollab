<?php

declare(strict_types=1);

require_once dirname(__DIR__, 1) . '/vendor/autoload.php';
require_once dirname(__DIR__, 1) . '/database/config/db.php';
require_once __DIR__ . '/handlers/WhiteboardHandler.php';
require_once __DIR__ . '/handlers/DmHandler.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class ChatServer implements MessageComponentInterface
{
    /** @var \SplObjectStorage<ConnectionInterface, array> */
    protected \SplObjectStorage $clients;
    /** @var array<int, ConnectionInterface[]> channel_id => connections */
    protected array $channelSubs = [];
    /** @var array<string, array> resourceId => {user_id, username, channel_id, ...} */
    protected array $connMeta = [];
    /** @var array<int, array[]> voice_channel_id => [{user_id, username, resourceId}] */
    protected array $voiceRooms = [];
    /** @var array<int, ConnectionInterface[]> user_id => [conn, ...] (multiple tabs/devices) */
    protected array $userConns = [];

    /**
     * Coding Buddy is deliberately independent from channel/room subscriptions.
     * session_id => [version, content, connections, dirty_changes, last_persist_at]
     */
    protected array $codeSessions = [];

    private PDO $db;
    private WhiteboardHandler $wbHandler;
    private int $codePersistEveryChanges = 10;
    private int $codePersistEverySeconds = 10;
    private int $codeMaxStateBytes = 1048576;

    public function __construct()
    {
        $this->clients   = new \SplObjectStorage();
        $this->db        = Database::getInstance();
        $this->wbHandler = new WhiteboardHandler();

        // Ensure ws_relay table exists (used by PHP API to push collab-tool events)
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS ws_relay (
                id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                channel_id INT UNSIGNED    NOT NULL,
                payload    TEXT            NOT NULL,
                created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_channel_id (channel_id),
                KEY idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        echo "[ChatServer] Started\n";
    }

    public function drainRelayTable(): void
    {
        try {
            $stmt = $this->db->query("SELECT id, channel_id, payload FROM ws_relay ORDER BY id ASC LIMIT 100");
            $rows = $stmt->fetchAll();
            if (!$rows) return;
            $ids = [];
            foreach ($rows as $row) {
                $ids[] = (int)$row['id'];
                $channelId = (int)$row['channel_id'];
                foreach ($this->channelSubs[$channelId] ?? [] as $conn) {
                    try { $conn->send($row['payload']); } catch (\Exception) {}
                }
            }
            $this->db->exec('DELETE FROM ws_relay WHERE id IN (' . implode(',', $ids) . ')');
        } catch (\Throwable $e) {
            echo "[WS] relay drain error: {$e->getMessage()}\n";
        }
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        $this->clients->attach($conn);
        $rid = $conn->resourceId;
        $this->connMeta[$rid] = [
            'user_id' => null,
            'username' => null,
            'channel_id' => null,
            'authed' => false,
            'code_sessions' => [],
        ];
        echo "[WS] Connection {$rid} opened\n";
    }

    public function onMessage(ConnectionInterface $from, $rawMsg): void
    {
        $rid = $from->resourceId;
        $meta = &$this->connMeta[$rid];
        $data = json_decode($rawMsg, true);
        if (!is_array($data) || empty($data['type'])) return;
        $type = $data['type'];

        if ($type === 'auth') {
            $this->handleAuth($from, $data, $meta);
            return;
        }
        if (!$meta['authed']) {
            $from->send(json_encode(['type' => 'error', 'message' => 'Unauthenticated']));
            return;
        }

        // Coding Buddy is a separate authorization domain. It never inherits
        // channel, collaboration-room, voice, or project membership access.
        if (is_string($type) && str_starts_with($type, 'code_')) {
            $this->handleCodeMessage($from, $data, $meta);
            return;
        }

        $channelScopedTypes = [
            'join_channel','message','message_edited','message_deleted','message_pinned',
            'collab_note_cursor','collab_note_presence','typing','presence','channel_seen',
            'draft_save','thread_reply','mention','join_voice','whiteboard_sync','wb_join',
            'wb_op','wb_cursor','wb_state_save','wb_request_state','screen_share_notify',
            'webrtc_offer','webrtc_answer','webrtc_candidate',
        ];
        if (in_array($type, $channelScopedTypes, true) && !$this->authorizeChannelMessage($from, $data, $meta)) return;

        match ($type) {
            'ping' => $from->send(json_encode(['type' => 'pong'])),
            'join_channel' => $this->handleJoinChannel($from, $data, $meta),
            'leave_channel' => $this->handleLeaveChannel($from, $data, $meta),
            'message' => $this->handleMessage($from, $data, $meta),
            'message_edited' => $this->handleEditedBroadcast($from, $data, $meta),
            'message_deleted' => $this->handleDeletedBroadcast($from, $data, $meta),
            'message_pinned' => $this->handlePinnedBroadcast($from, $data, $meta),
            'collab_note_cursor' => $this->handleNoteRelay($from, $data, $meta),
            'collab_note_presence' => $this->handleNoteRelay($from, $data, $meta),
            'typing' => $this->handleTyping($from, $data, $meta),
            'presence' => $this->handlePresence($from, $data, $meta),
            'channel_seen' => $this->handleChannelSeen($from, $data, $meta),
            'draft_save' => $this->handleDraftSave($from, $data, $meta),
            'thread_reply' => $this->handleThreadReply($from, $data, $meta),
            'mention' => $this->handleMentionRelay($from, $data, $meta),
            'join_voice' => $this->handleJoinVoice($from, $data, $meta),
            'leave_voice' => $this->handleLeaveVoice($from, $data, $meta),
            'whiteboard_sync' => $this->handleWhiteboardSync($from, $data, $meta),
            'wb_join' => $this->handleWbJoin($from, $data, $meta),
            'wb_leave' => $this->handleWbLeave($from, $data, $meta),
            'wb_op' => $this->handleWbOp($from, $data, $meta),
            'wb_cursor' => $this->handleWbCursor($from, $data, $meta),
            'wb_state_save' => $this->handleWbStateSave($from, $data, $meta),
            'wb_request_state' => $this->handleWbRequestState($from, $data, $meta),
            'webrtc_offer' => $this->handleWebRtcSignal($from, $data, $meta, 'webrtc_offer'),
            'webrtc_answer' => $this->handleWebRtcSignal($from, $data, $meta, 'webrtc_answer'),
            'webrtc_candidate' => $this->handleWebRtcSignal($from, $data, $meta, 'webrtc_candidate'),
            'screen_share_notify' => $this->handleScreenShareNotify($from, $data, $meta),
            'connection_request' => $this->handleConnectionRequest($from, $data, $meta),
            'dm_message' => $this->handleDmMessage($from, $data, $meta),
            'dm_typing' => $this->handleDmTyping($from, $data, $meta),
            'notify_conn_req' => $this->handleNotifyConnReq($from, $data, $meta),
            'notify_conn_accepted' => $this->handleNotifyConnAccepted($from, $data, $meta),
            default => null,
        };
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $rid = $conn->resourceId;
        $meta = $this->connMeta[$rid] ?? [];
        if (!empty($meta['channel_id'])) $this->removeFromChannel($conn, (int)$meta['channel_id']);
        $this->cleanupCodeConnection($conn, $meta);

        if (!empty($meta['user_id'])) {
            $uid = (int)$meta['user_id'];
            $username = $meta['username'] ?? '';
            if (isset($this->userConns[$uid])) {
                $this->userConns[$uid] = array_values(array_filter($this->userConns[$uid], fn($c) => $c !== $conn));
                if (empty($this->userConns[$uid])) unset($this->userConns[$uid]);
            }
            $userFullyDisconnected = !isset($this->userConns[$uid]);
            if ($userFullyDisconnected) {
                if (!empty($meta['wb_channel_id'])) {
                    $wbChanId = (int)$meta['wb_channel_id'];
                    $remainingIds = $this->wbHandler->leave($wbChanId, $uid);
                    $leaveNotify = json_encode(['type' => 'wb_peer_left', 'channel_id' => $wbChanId, 'user_id' => $uid, 'username' => $username]);
                    foreach ($remainingIds as $peerId) foreach ($this->userConns[$peerId] ?? [] as $peerConn) try { $peerConn->send($leaveNotify); } catch (\Exception) {}
                }
                foreach ($this->voiceRooms as $vcId => &$participants) {
                    $wasInRoom = array_filter($participants, fn($p) => $p['user_id'] === $uid);
                    if (!empty($wasInRoom)) {
                        $participants = array_values(array_filter($participants, fn($p) => $p['user_id'] !== $uid));
                        $leavePayload = json_encode(['type' => 'voice_leave', 'user_id' => $uid, 'username' => $username, 'channel_id' => $vcId]);
                        foreach ($participants as $p) foreach ($this->userConns[(int)$p['user_id']] ?? [] as $peerConn) try { $peerConn->send($leavePayload); } catch (\Exception) {}
                        $this->broadcastToAll($leavePayload, $conn);
                        if (empty($participants)) unset($this->voiceRooms[$vcId]);
                    }
                }
                unset($participants);
                try { $this->db->prepare("UPDATE users SET voice_channel_id=NULL WHERE id=:id")->execute([':id' => $uid]); } catch (\Exception) {}
                $this->setUserOnline($uid, false);
                $this->broadcastPresence($uid, false, $username);
            }
        }
        $this->clients->detach($conn);
        unset($this->connMeta[$rid]);
        echo "[WS] Connection {$rid} closed\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        echo "[WS] Error on {$conn->resourceId}: {$e->getMessage()}\n";
        $conn->close();
    }

    private function handleAuth(ConnectionInterface $conn, array $data, array &$meta): void
    {
        $wsToken = trim($data['ws_token'] ?? '');
        if ($wsToken === '') { $conn->send(json_encode(['type' => 'error', 'message' => 'Invalid auth: ws_token required'])); return; }
        $hash = hash('sha256', $wsToken);
        $stmt = $this->db->prepare("SELECT u.id, u.username, u.status, u.full_name, u.avatar_color_gradient FROM ws_tokens wt JOIN users u ON u.id = wt.user_id WHERE wt.token_hash = :hash AND wt.expires_at > NOW() AND u.deleted_at IS NULL LIMIT 1");
        $stmt->execute([':hash' => $hash]);
        $user = $stmt->fetch();
        $stmt->closeCursor();
        if (!$user) { $conn->send(json_encode(['type' => 'error', 'message' => 'Invalid or expired auth token'])); return; }
        if ($user['status'] !== 'active') { $conn->send(json_encode(['type' => 'error', 'message' => 'Account is not active'])); return; }
        $userId = (int)$user['id'];
        $username = $user['username'];
        $meta['user_id'] = $userId;
        $meta['username'] = $username;
        $meta['full_name'] = $user['full_name'] ?? $username;
        $meta['gradient'] = $user['avatar_color_gradient'] ?? '';
        $meta['authed'] = true;
        $this->userConns[$userId][] = $conn;
        $this->setUserOnline($userId, true);
        $this->broadcastPresence($userId, true, $username);
        $conn->send(json_encode(['type' => 'auth_ok', 'user_id' => $userId]));
        echo "[WS] User {$username} ({$userId}) authenticated on {$conn->resourceId}\n";
    }

    private function handleCodeMessage(ConnectionInterface $from, array $data, array &$meta): void
    {
        $type = (string)($data['type'] ?? '');
        try {
            match ($type) {
                'code_create' => $this->handleCodeCreate($from, $data, $meta),
                'code_invite_generate' => $this->handleCodeInviteGenerate($from, $data, $meta),
                'code_join' => $this->handleCodeJoin($from, $data, $meta),
                'code_join_request' => $this->handleCodeJoinRequest($from, $data, $meta),
                'code_join_response' => $this->handleCodeJoinResponse($from, $data, $meta),
                'code_role_update' => $this->handleCodeRoleUpdate($from, $data, $meta),
                'code_change' => $this->handleCodeChange($from, $data, $meta),
                'code_cursor' => $this->handleCodeCursor($from, $data, $meta),
                'code_selection' => $this->handleCodeSelection($from, $data, $meta),
                'code_execution' => $this->handleCodeExecution($from, $data, $meta),
                'code_output' => $this->handleCodeOutput($from, $data, $meta),
                'code_leave' => $this->handleCodeLeave($from, $data, $meta),
                'code_session_closed' => $this->handleCodeSessionClosed($from, $data, $meta),
                default => $this->codeError($from, 'Unknown Coding Buddy event.'),
            };
        } catch (\Throwable $e) {
            error_log('[WS] Coding Buddy error: ' . $e->getMessage());
            $this->codeError($from, 'Coding Buddy request failed.');
        }
    }

    private function handleCodeCreate(ConnectionInterface $from, array $data, array &$meta): void
    {
        $uid = (int)$meta['user_id'];
        $initial = (string)($data['content'] ?? '');
        if (strlen($initial) > $this->codeMaxStateBytes) { $this->codeError($from, 'Initial code is too large.'); return; }
        $stmt = $this->db->prepare('INSERT INTO coding_sessions (owner_id, code_state, version) VALUES (:owner, :state, 0)');
        $stmt->execute([':owner' => $uid, ':state' => $initial]);
        $sessionId = (int)$this->db->lastInsertId();
        $stmt = $this->db->prepare("INSERT INTO coding_session_participants (session_id, user_id, status, role, joined_at) VALUES (:sid, :uid, 'approved', 'editor', NOW())");
        $stmt->execute([':sid' => $sessionId, ':uid' => $uid]);
        $this->codeSessions[$sessionId] = ['version' => 0, 'content' => $initial, 'connections' => [], 'dirty_changes' => 0, 'last_persist_at' => microtime(true)];
        $this->codeJoinConnection($from, $meta, $sessionId, 'editor', true);
        $from->send(json_encode(['type' => 'code_created', 'session_id' => $sessionId, 'version' => 0]));
    }

    private function handleCodeInviteGenerate(ConnectionInterface $from, array $data, array $meta): void
    {
        $sessionId = (int)($data['session_id'] ?? 0);
        $uid = (int)$meta['user_id'];
        if (!$sessionId || !$this->isCodeOwner($sessionId, $uid)) { $this->codeError($from, 'Only the coding-session owner can generate invites.'); return; }
        $token = bin2hex(random_bytes(32));
        $expiresIn = (int)($data['expires_in'] ?? 86400);
        if ($expiresIn < 0) $expiresIn = 86400;
        if ($expiresIn > 604800) $expiresIn = 604800;
        $expiresSql = $expiresIn === 0 ? null : date('Y-m-d H:i:s', time() + $expiresIn);
        $stmt = $this->db->prepare('UPDATE coding_sessions SET invite_token = :token, invite_expires_at = :expires WHERE id = :sid AND owner_id = :uid');
        $stmt->execute([':token' => $token, ':expires' => $expiresSql, ':sid' => $sessionId, ':uid' => $uid]);
        $from->send(json_encode([
            'type' => 'code_invite_generated', 'session_id' => $sessionId,
            'invite_token' => $token, 'invite_expires_at' => $expiresSql,
            'link' => '/coding-sessions/' . $sessionId . '/join?token=' . rawurlencode($token),
        ]));
    }

    private function handleCodeJoin(ConnectionInterface $from, array $data, array &$meta): void
    {
        $sessionId = (int)($data['session_id'] ?? 0);
        $uid = (int)$meta['user_id'];
        if (!$sessionId) { $this->codeError($from, 'A coding session is required.'); return; }
        $stmt = $this->db->prepare('SELECT cs.id, cs.owner_id, cs.code_state, cs.version, csp.status, csp.role FROM coding_sessions cs LEFT JOIN coding_session_participants csp ON csp.session_id = cs.id AND csp.user_id = :uid WHERE cs.id = :sid LIMIT 1');
        $stmt->execute([':uid' => $uid, ':sid' => $sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { $this->codeError($from, 'Coding session not found.'); return; }
        $isOwner = (int)$row['owner_id'] === $uid;
        if (!$isOwner && $row['status'] !== 'approved') { $this->codeError($from, 'Coding session access is not approved.'); return; }
        $role = $isOwner ? 'editor' : (string)$row['role'];
        $this->loadCodeSession($sessionId, (string)$row['code_state'], (int)$row['version']);
        $this->codeJoinConnection($from, $meta, $sessionId, $role, false);
    }

    private function handleCodeJoinRequest(ConnectionInterface $from, array $data, array $meta): void
    {
        $sessionId = (int)($data['session_id'] ?? 0);
        $uid = (int)$meta['user_id'];
        if (!$sessionId) { $this->codeError($from, 'A coding session is required.'); return; }
        $stmt = $this->db->prepare('SELECT id, owner_id FROM coding_sessions WHERE id = :sid LIMIT 1');
        $stmt->execute([':sid' => $sessionId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$session) { $this->codeError($from, 'Coding session not found.'); return; }
        $ownerId = (int)$session['owner_id'];
        if ($ownerId === $uid) { $this->codeError($from, 'The owner already has access.'); return; }
        $stmt = $this->db->prepare('SELECT status, joined_at FROM coding_session_participants WHERE session_id = :sid AND user_id = :uid LIMIT 1');
        $stmt->execute([':sid' => $sessionId, ':uid' => $uid]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing && $existing['status'] === 'approved') { $this->codeError($from, 'You are already a coding-session participant.'); return; }
        if ($existing && $existing['status'] === 'pending') { $this->codeError($from, 'A join request is already pending.'); return; }
        if ($existing && !empty($existing['joined_at']) && strtotime((string)$existing['joined_at']) > time() - 60) { $this->codeError($from, 'Please wait before requesting access again.'); return; }
        $stmt = $this->db->prepare("INSERT INTO coding_session_participants (session_id, user_id, status, role, joined_at) VALUES (:sid, :uid, 'pending', 'viewer', NOW()) ON DUPLICATE KEY UPDATE status = 'pending', role = 'viewer', joined_at = NOW()");
        $stmt->execute([':sid' => $sessionId, ':uid' => $uid]);
        $payload = json_encode(['type' => 'code_join_requested', 'session_id' => $sessionId, 'user_id' => $uid, 'username' => $meta['username']]);
        foreach ($this->userConns[$ownerId] ?? [] as $ownerConn) try { $ownerConn->send($payload); } catch (\Exception) {}
        $from->send(json_encode(['type' => 'code_join_request_sent', 'session_id' => $sessionId]));
    }

    private function handleCodeJoinResponse(ConnectionInterface $from, array $data, array $meta): void
    {
        $sessionId = (int)($data['session_id'] ?? 0);
        $targetUserId = (int)($data['user_id'] ?? 0);
        $approve = (bool)($data['approve'] ?? false);
        $uid = (int)$meta['user_id'];
        if (!$sessionId || !$targetUserId || !$this->isCodeOwner($sessionId, $uid)) { $this->codeError($from, 'Only the coding-session owner can approve or reject requests.'); return; }
        $status = $approve ? 'approved' : 'rejected';
        $stmt = $this->db->prepare("UPDATE coding_session_participants SET status = :status, role = IF(:status2 = 'approved', 'viewer', role) WHERE session_id = :sid AND user_id = :uid AND status = 'pending'");
        $stmt->execute([':status' => $status, ':status2' => $status, ':sid' => $sessionId, ':uid' => $targetUserId]);
        if ($stmt->rowCount() < 1) { $this->codeError($from, 'No pending join request was found.'); return; }
        $payload = json_encode(['type' => 'code_join_response', 'session_id' => $sessionId, 'approved' => $approve, 'role' => $approve ? 'viewer' : null]);
        foreach ($this->userConns[$targetUserId] ?? [] as $targetConn) try { $targetConn->send($payload); } catch (\Exception) {}
    }

    private function handleCodeRoleUpdate(ConnectionInterface $from, array $data, array $meta): void
    {
        $sessionId = (int)($data['session_id'] ?? 0);
        $targetUserId = (int)($data['user_id'] ?? 0);
        $role = strtolower(trim((string)($data['role'] ?? '')));
        $uid = (int)$meta['user_id'];
        if (!in_array($role, ['editor', 'viewer'], true)) { $this->codeError($from, 'Invalid coding-session role.'); return; }
        if (!$sessionId || !$targetUserId || !$this->isCodeOwner($sessionId, $uid)) { $this->codeError($from, 'Only the coding-session owner can change roles.'); return; }
        if ($targetUserId === $uid) { $this->codeError($from, 'The owner role cannot be changed.'); return; }
        $stmt = $this->db->prepare("UPDATE coding_session_participants SET role = :role WHERE session_id = :sid AND user_id = :uid AND status = 'approved'");
        $stmt->execute([':role' => $role, ':sid' => $sessionId, ':uid' => $targetUserId]);
        if ($stmt->rowCount() < 1) { $this->codeError($from, 'Approved participant not found.'); return; }
        $this->broadcastCodeSession($sessionId, json_encode(['type' => 'code_role_updated', 'session_id' => $sessionId, 'user_id' => $targetUserId, 'role' => $role]));
    }

    private function handleCodeChange(ConnectionInterface $from, array $data, array $meta): void
    {
        $sessionId = (int)($data['session_id'] ?? 0);
        $clientVersion = filter_var($data['version'] ?? null, FILTER_VALIDATE_INT);
        $content = (string)($data['content'] ?? '');
        $uid = (int)$meta['user_id'];
        if (!$sessionId || $clientVersion === false) { $this->codeError($from, 'session_id and integer version are required.'); return; }
        if (strlen($content) > $this->codeMaxStateBytes) { $this->codeError($from, 'Code snapshot is too large.'); return; }
        if (!$this->requireCodeEditor($from, $sessionId, $uid)) return;
        $this->ensureCodeSessionLoaded($sessionId);
        $serverVersion = (int)$this->codeSessions[$sessionId]['version'];
        if ((int)$clientVersion !== $serverVersion) { $this->sendCodeResync($from, $sessionId); return; }
        $newVersion = $serverVersion + 1;
        $this->codeSessions[$sessionId]['content'] = $content;
        $this->codeSessions[$sessionId]['version'] = $newVersion;
        $this->codeSessions[$sessionId]['dirty_changes']++;
        $this->broadcastCodeSession($sessionId, json_encode(['type' => 'code_change', 'session_id' => $sessionId, 'content' => $content, 'version' => $newVersion, 'user_id' => $uid]), $from);
        $this->maybePersistCodeSession($sessionId);
    }

    private function handleCodeCursor(ConnectionInterface $from, array $data, array $meta): void
    {
        $sessionId = (int)($data['session_id'] ?? 0);
        if (!$this->requireCodeParticipant($from, $sessionId, (int)$meta['user_id'])) return;
        $this->broadcastCodeSession($sessionId, json_encode(['type' => 'code_cursor', 'session_id' => $sessionId, 'position' => $data['position'] ?? null, 'user_id' => (int)$meta['user_id']]), $from);
    }

    private function handleCodeSelection(ConnectionInterface $from, array $data, array $meta): void
    {
        $sessionId = (int)($data['session_id'] ?? 0);
        if (!$this->requireCodeParticipant($from, $sessionId, (int)$meta['user_id'])) return;
        $this->broadcastCodeSession($sessionId, json_encode(['type' => 'code_selection', 'session_id' => $sessionId, 'range' => $data['range'] ?? null, 'user_id' => (int)$meta['user_id']]), $from);
    }

    private function handleCodeExecution(ConnectionInterface $from, array $data, array $meta): void
    {
        $sessionId = (int)($data['session_id'] ?? 0);
        $uid = (int)$meta['user_id'];
        if (!$this->requireCodeEditor($from, $sessionId, $uid)) return;
        // Transport only. No source code is executed in ChatServer.php.
        // A future isolated executor consumes this event and returns code_output.
        $payload = $data;
        $payload['type'] = 'code_execution';
        $payload['session_id'] = $sessionId;
        $payload['user_id'] = $uid;
        $this->broadcastCodeSession($sessionId, json_encode($payload), $from);
    }

    private function handleCodeOutput(ConnectionInterface $from, array $data, array $meta): void
    {
        $sessionId = (int)($data['session_id'] ?? 0);
        $uid = (int)$meta['user_id'];
        if (!$this->requireCodeEditor($from, $sessionId, $uid)) return;
        $payload = [
            'type' => 'code_output', 'session_id' => $sessionId,
            'stdout' => (string)($data['stdout'] ?? ''),
            'stderr' => (string)($data['stderr'] ?? ''),
            'exit_code' => isset($data['exit_code']) ? (int)$data['exit_code'] : null,
        ];
        $this->broadcastCodeSession($sessionId, json_encode($payload), $from);
    }

    private function handleCodeLeave(ConnectionInterface $from, array $data, array &$meta): void
    {
        $sessionId = (int)($data['session_id'] ?? 0);
        if ($sessionId) $this->removeCodeConnection($from, $meta, $sessionId, true);
    }

    private function handleCodeSessionClosed(ConnectionInterface $from, array $data, array $meta): void
    {
        $sessionId = (int)($data['session_id'] ?? 0);
        $uid = (int)$meta['user_id'];
        if (!$sessionId || !$this->isCodeOwner($sessionId, $uid)) { $this->codeError($from, 'Only the coding-session owner can close the session.'); return; }
        $this->ensureCodeSessionLoaded($sessionId);
        $this->persistCodeSession($sessionId);
        $this->broadcastCodeSession($sessionId, json_encode(['type' => 'code_session_closed', 'session_id' => $sessionId]));
        foreach ($this->codeSessions[$sessionId]['connections'] ?? [] as $rid => $entry) {
            if (isset($this->connMeta[$rid])) {
                $this->connMeta[$rid]['code_sessions'] = array_values(array_filter($this->connMeta[$rid]['code_sessions'] ?? [], fn($id) => (int)$id !== $sessionId));
            }
        }
        unset($this->codeSessions[$sessionId]);
    }

    private function codeJoinConnection(ConnectionInterface $conn, array &$meta, int $sessionId, string $role, bool $announceCreate): void
    {
        $this->ensureCodeSessionLoaded($sessionId);
        $rid = $conn->resourceId;
        $uid = (int)$meta['user_id'];
        $alreadyPresent = isset($this->codeSessions[$sessionId]['connections'][$rid]);
        $this->codeSessions[$sessionId]['connections'][$rid] = ['conn' => $conn, 'user_id' => $uid, 'username' => $meta['username'], 'role' => $role];
        if (!in_array($sessionId, $meta['code_sessions'] ?? [], true)) $meta['code_sessions'][] = $sessionId;
        $conn->send(json_encode(['type' => 'code_joined', 'session_id' => $sessionId, 'version' => (int)$this->codeSessions[$sessionId]['version'], 'content' => $this->codeSessions[$sessionId]['content'], 'role' => $role]));
        if (!$alreadyPresent && !$announceCreate) $this->broadcastCodeSession($sessionId, json_encode(['type' => 'code_user_joined', 'session_id' => $sessionId, 'user_id' => $uid, 'username' => $meta['username'], 'role' => $role]), $conn);
    }

    private function cleanupCodeConnection(ConnectionInterface $conn, array $meta): void
    {
        foreach (array_values($meta['code_sessions'] ?? []) as $sessionId) $this->removeCodeConnection($conn, $meta, (int)$sessionId, true);
    }

    private function removeCodeConnection(ConnectionInterface $conn, array &$meta, int $sessionId, bool $broadcast): void
    {
        if (!isset($this->codeSessions[$sessionId])) {
            $meta['code_sessions'] = array_values(array_filter($meta['code_sessions'] ?? [], fn($id) => (int)$id !== $sessionId));
            return;
        }
        $rid = $conn->resourceId;
        $entry = $this->codeSessions[$sessionId]['connections'][$rid] ?? null;
        unset($this->codeSessions[$sessionId]['connections'][$rid]);
        $meta['code_sessions'] = array_values(array_filter($meta['code_sessions'] ?? [], fn($id) => (int)$id !== $sessionId));
        if ($broadcast && $entry !== null) {
            $uid = (int)($entry['user_id'] ?? $meta['user_id'] ?? 0);
            $stillPresent = false;
            foreach ($this->codeSessions[$sessionId]['connections'] as $peer) if ((int)$peer['user_id'] === $uid) { $stillPresent = true; break; }
            if (!$stillPresent) $this->broadcastCodeSession($sessionId, json_encode(['type' => 'code_user_left', 'session_id' => $sessionId, 'user_id' => $uid, 'username' => $entry['username'] ?? ($meta['username'] ?? '')]));
        }
    }

    private function requireCodeParticipant(ConnectionInterface $from, int $sessionId, int $uid): bool
    {
        if (!$sessionId || !$this->getCodeRole($sessionId, $uid)) { $this->codeError($from, 'Coding-session membership is required.'); return false; }
        if (!$this->isCodeConnectionPresent($sessionId, $from)) { $this->codeError($from, 'Join the coding session first.'); return false; }
        return true;
    }

    private function requireCodeEditor(ConnectionInterface $from, int $sessionId, int $uid): bool
    {
        if (!$this->requireCodeParticipant($from, $sessionId, $uid)) return false;
        if ($this->getCodeRole($sessionId, $uid) !== 'editor') { $this->codeError($from, 'Editor access is required.'); return false; }
        return true;
    }

    private function getCodeRole(int $sessionId, int $uid): ?string
    {
        $stmt = $this->db->prepare('SELECT owner_id FROM coding_sessions WHERE id = :sid LIMIT 1');
        $stmt->execute([':sid' => $sessionId]);
        $ownerId = $stmt->fetchColumn();
        if ($ownerId === false) return null;
        if ((int)$ownerId === $uid) return 'editor';
        $stmt = $this->db->prepare("SELECT role FROM coding_session_participants WHERE session_id = :sid AND user_id = :uid AND status = 'approved' LIMIT 1");
        $stmt->execute([':sid' => $sessionId, ':uid' => $uid]);
        $role = $stmt->fetchColumn();
        return $role === false ? null : (string)$role;
    }

    private function isCodeOwner(int $sessionId, int $uid): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM coding_sessions WHERE id = :sid AND owner_id = :uid LIMIT 1');
        $stmt->execute([':sid' => $sessionId, ':uid' => $uid]);
        return (bool)$stmt->fetchColumn();
    }

    private function isCodeConnectionPresent(int $sessionId, ConnectionInterface $conn): bool
    {
        return isset($this->codeSessions[$sessionId]['connections'][$conn->resourceId]);
    }

    private function loadCodeSession(int $sessionId, string $content, int $version): void
    {
        if (isset($this->codeSessions[$sessionId])) return;
        $this->codeSessions[$sessionId] = ['version' => max(0, $version), 'content' => $content, 'connections' => [], 'dirty_changes' => 0, 'last_persist_at' => microtime(true)];
    }

    private function ensureCodeSessionLoaded(int $sessionId): void
    {
        if (isset($this->codeSessions[$sessionId])) return;
        $stmt = $this->db->prepare('SELECT code_state, version FROM coding_sessions WHERE id = :sid LIMIT 1');
        $stmt->execute([':sid' => $sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new \RuntimeException('Coding session not found.');
        $this->loadCodeSession($sessionId, (string)$row['code_state'], (int)$row['version']);
    }

    private function sendCodeResync(ConnectionInterface $from, int $sessionId): void
    {
        $this->ensureCodeSessionLoaded($sessionId);
        $from->send(json_encode(['type' => 'code_resync', 'session_id' => $sessionId, 'content' => $this->codeSessions[$sessionId]['content'], 'version' => (int)$this->codeSessions[$sessionId]['version']]));
    }

    private function maybePersistCodeSession(int $sessionId): void
    {
        if (isset($this->codeSessions[$sessionId]) && $this->codeSessions[$sessionId]['dirty_changes'] >= $this->codePersistEveryChanges) $this->persistCodeSession($sessionId);
    }

    private function persistDirtyCodeSessions(): void
    {
        $now = microtime(true);
        foreach (array_keys($this->codeSessions) as $sessionId) {
            if (($this->codeSessions[$sessionId]['dirty_changes'] ?? 0) < 1) continue;
            if ($now - (float)$this->codeSessions[$sessionId]['last_persist_at'] >= $this->codePersistEverySeconds) $this->persistCodeSession((int)$sessionId);
        }
    }

    private function persistCodeSession(int $sessionId): void
    {
        if (!isset($this->codeSessions[$sessionId])) return;
        $stmt = $this->db->prepare('UPDATE coding_sessions SET code_state = :state, version = :version WHERE id = :sid');
        $stmt->execute([':state' => $this->codeSessions[$sessionId]['content'], ':version' => (int)$this->codeSessions[$sessionId]['version'], ':sid' => $sessionId]);
        $this->codeSessions[$sessionId]['dirty_changes'] = 0;
        $this->codeSessions[$sessionId]['last_persist_at'] = microtime(true);
    }

    private function broadcastCodeSession(int $sessionId, string $payload, ?ConnectionInterface $exclude = null): void
    {
        foreach ($this->codeSessions[$sessionId]['connections'] ?? [] as $entry) {
            $conn = $entry['conn'];
            if ($exclude && $conn === $exclude) continue;
            try { $conn->send($payload); } catch (\Exception) {}
        }
    }

    private function codeError(ConnectionInterface $conn, string $message): void
    {
        $conn->send(json_encode(['type' => 'code_error', 'message' => $message]));
    }

    /** Verify channel access before dispatching any channel-scoped event. */
    private function authorizeChannelMessage(ConnectionInterface $conn, array $data, array $meta): bool
    {
        $channelId = (int)($data['channel_id'] ?? $meta['channel_id'] ?? $meta['voice_channel_id'] ?? $meta['wb_channel_id'] ?? 0);
        $userId = (int)($meta['user_id'] ?? 0);
        if ($channelId <= 0 || $userId <= 0) { $conn->send(json_encode(['type' => 'error', 'message' => 'Channel access denied'])); return false; }
        try {
            $stmt = $this->db->prepare("SELECT c.is_private, c.is_locked, u.role, sm.user_id AS server_member_id, cm.user_id AS channel_member_id FROM channels c JOIN users u ON u.id = :uid_user AND u.deleted_at IS NULL LEFT JOIN server_members sm ON sm.server_id = c.server_id AND sm.user_id = :uid_server LEFT JOIN channel_members cm ON cm.channel_id = c.id AND cm.user_id = :uid_channel WHERE c.id = :cid AND c.type IN ('text', 'announcement', 'voice', 'whiteboard', 'study_room') LIMIT 1");
            $stmt->execute([':uid_user' => $userId, ':uid_server' => $userId, ':uid_channel' => $userId, ':cid' => $channelId]);
            $channel = $stmt->fetch();
        } catch (\Throwable $e) {
            error_log('[WS] channel authorization failed: ' . $e->getMessage());
            $conn->send(json_encode(['type' => 'error', 'message' => 'Channel access denied']));
            return false;
        }
        if (!$channel) { $conn->send(json_encode(['type' => 'error', 'message' => 'Channel access denied'])); return false; }
        $isPrivileged = in_array($channel['role'], ['admin', 'super_admin', 'moderator'], true);
        $hasServerAccess = $channel['server_member_id'] !== null;
        $hasPrivateAccess = !$channel['is_private'] || $channel['channel_member_id'] !== null;
        $isUsable = !$channel['is_locked'] || $isPrivileged;
        if (!$isPrivileged && (!$hasServerAccess || !$hasPrivateAccess || !$isUsable)) { $conn->send(json_encode(['type' => 'error', 'message' => 'Channel access denied'])); return false; }
        return true;
    }

    private function handleJoinChannel(ConnectionInterface $conn, array $data, array &$meta): void
    {
        $channelId = (int)($data['channel_id'] ?? 0);
        if ($channelId <= 0) return;
        if ($meta['channel_id']) $this->removeFromChannel($conn, (int)$meta['channel_id']);
        $meta['channel_id'] = $channelId;
        $this->channelSubs[$channelId][] = $conn;
        $conn->send(json_encode(['type' => 'joined_channel', 'channel_id' => $channelId]));
    }
    private function handleLeaveChannel(ConnectionInterface $conn, array $data, array &$meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['channel_id'] ?? 0);
        if ($channelId) { $this->removeFromChannel($conn, $channelId); $meta['channel_id'] = null; }
    }
    private function handleMessage(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['channel_id'] ?? 0);
        if (!$channelId || empty($data['message'])) return;
        $this->broadcastToChannel($channelId, json_encode(['type' => 'message', 'message' => $data['message']]), $from);
    }
    private function handleTyping(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['channel_id'] ?? 0);
        if (!$channelId) return;
        $this->broadcastToChannel($channelId, json_encode(['type' => 'typing', 'channel_id' => $channelId, 'user_id' => $meta['user_id'], 'username' => $meta['username'], 'typing' => (bool)($data['typing'] ?? false)]), $from);
    }
    private function handlePresence(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($meta['channel_id'] ?? 0);
        $payload = json_encode(['type' => 'presence', 'user_id' => $meta['user_id'], 'online' => true, 'muted' => (bool)($data['muted'] ?? false)]);
        if ($channelId) $this->broadcastToChannel($channelId, $payload, $from);
    }
    private function handleJoinVoice(ConnectionInterface $from, array $data, array &$meta): void
    {
        $channelId = (int)($data['channel_id'] ?? 0); if (!$channelId) return;
        $stmt = $this->db->prepare("SELECT id, username, full_name, avatar_color_gradient, role FROM users WHERE id = :id");
        $stmt->execute([':id' => $meta['user_id']]); $user = $stmt->fetch() ?: [];
        $uid = (int)$meta['user_id']; $meta['voice_channel_id'] = $channelId;
        foreach ($this->voiceRooms as &$participants) $participants = array_filter($participants, fn($p) => $p['user_id'] !== $uid); unset($participants);
        $existingParticipants = array_values($this->voiceRooms[$channelId] ?? []); $this->voiceRooms[$channelId] ??= [];
        $this->voiceRooms[$channelId][] = ['user_id' => $uid, 'username' => $meta['username'], 'full_name' => $user['full_name'] ?? $meta['username'], 'avatar_color_gradient' => $user['avatar_color_gradient'] ?? '#3b82f6,#6366f1', 'role' => $user['role'] ?? 'student', 'resourceId' => $from->resourceId];
        try { $this->db->prepare("UPDATE users SET voice_channel_id=:cid WHERE id=:id")->execute([':cid' => $channelId, ':id' => $uid]); } catch (\Exception) {}
        $payload = json_encode(['type' => 'voice_join', 'user' => $user, 'channel_id' => $channelId]); $already = [];
        foreach ($existingParticipants as $p) foreach ($this->userConns[(int)$p['user_id']] ?? [] as $peerConn) { try { $peerConn->send($payload); $already[$peerConn->resourceId] = true; } catch (\Exception) {} }
        foreach ($this->clients as $client) { if ($client === $from) continue; $rid = $client->resourceId; if (empty($this->connMeta[$rid]['authed']) || isset($already[$rid])) continue; try { $client->send($payload); } catch (\Exception) {} }
        $peers = array_values(array_map(fn($p) => ['user_id' => $p['user_id'], 'username' => $p['username'], 'full_name' => $p['full_name'] ?? $p['username'], 'avatar_color_gradient' => $p['avatar_color_gradient'] ?? '#3b82f6,#6366f1', 'role' => $p['role'] ?? 'student', 'muted' => $p['muted'] ?? false], $existingParticipants));
        $from->send(json_encode(['type' => 'voice_peers', 'peers' => $peers, 'channel_id' => $channelId]));
    }
    private function handleLeaveVoice(ConnectionInterface $from, array $data, array &$meta): void
    {
        $uid = (int)$meta['user_id']; $channelId = (int)($meta['voice_channel_id'] ?? 0); $remaining = [];
        if ($channelId && isset($this->voiceRooms[$channelId])) { $this->voiceRooms[$channelId] = array_values(array_filter($this->voiceRooms[$channelId], fn($p) => $p['user_id'] !== $uid)); $remaining = $this->voiceRooms[$channelId]; if (empty($this->voiceRooms[$channelId])) unset($this->voiceRooms[$channelId]); }
        $meta['voice_channel_id'] = null; try { $this->db->prepare("UPDATE users SET voice_channel_id=NULL WHERE id=:id")->execute([':id' => $uid]); } catch (\Exception) {}
        $payload = json_encode(['type' => 'voice_leave', 'user_id' => $uid, 'username' => $meta['username'], 'channel_id' => $channelId]);
        foreach ($remaining as $p) foreach ($this->userConns[(int)$p['user_id']] ?? [] as $peerConn) try { $peerConn->send($payload); } catch (\Exception) {}
        $this->broadcastToAll($payload, $from);
    }
    private function handleWebRtcSignal(ConnectionInterface $from, array $data, array $meta, string $type): void
    {
        $target = (int)($data['target_user_id'] ?? 0); $voiceChannelId = (int)($meta['voice_channel_id'] ?? 0); $isVoicePeer = false;
        foreach ($this->voiceRooms[$voiceChannelId] ?? [] as $participant) if ((int)$participant['user_id'] === $target) { $isVoicePeer = true; break; }
        if (!$target || !$isVoicePeer || empty($this->userConns[$target])) { $from->send(json_encode(['type' => 'error', 'message' => 'Peer not connected'])); return; }
        $payload = json_encode(['type' => $type, 'from_user_id' => $meta['user_id'], 'from_username' => $meta['username'], 'sdp' => $data['sdp'] ?? null, 'candidate' => $data['candidate'] ?? null, 'is_screen_offer' => $data['is_screen_offer'] ?? false]);
        foreach ($this->userConns[$target] as $peerConn) try { $peerConn->send($payload); } catch (\Exception $e) { error_log('[WS] WebRTC relay error: ' . $e->getMessage()); }
    }
    private function handleScreenShareNotify(ConnectionInterface $from, array $data, array $meta): void
    {
        $uid = (int)$meta['user_id']; $channelId = (int)($meta['voice_channel_id'] ?? $meta['channel_id'] ?? 0); $payload = json_encode(['type' => 'screen_share_notify', 'user_id' => $uid, 'username' => $meta['username'], 'active' => (bool)($data['active'] ?? false), 'channel_id' => $channelId]);
        foreach ($this->voiceRooms[$channelId] ?? [] as $p) { $peerId = (int)$p['user_id']; if ($peerId === $uid) continue; foreach ($this->userConns[$peerId] ?? [] as $conn) try { $conn->send($payload); } catch (\Exception) {} }
    }
    private function handleDeletedBroadcast(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($meta['channel_id'] ?? 0); $messageId = (int)($data['message_id'] ?? 0); if (!$channelId || !$messageId) return;
        $this->broadcastToChannel($channelId, json_encode(['type' => 'message_deleted', 'message_id' => $messageId]), $from);
    }
    private function handleWhiteboardSync(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['channel_id'] ?? 0); if (!$channelId) return;
        $this->broadcastToChannel($channelId, json_encode(['type' => 'whiteboard_sync', 'channel_id' => $channelId, 'user_id' => $meta['user_id'], 'state_json' => $data['state_json'] ?? '']), $from);
    }
    private function handleConnectionRequest(ConnectionInterface $from, array $data, array $meta): void
    {
        $addresseeId = (int)($data['addressee_id'] ?? 0); if (!$addresseeId) return;
        if (!empty($this->userConns[$addresseeId])) foreach ($this->userConns[$addresseeId] as $peerConn) try { $peerConn->send(json_encode(['type' => 'connection_request', 'request_id' => $data['request_id'] ?? null, 'addressee_id' => $addresseeId, 'requester' => $data['requester'] ?? []])); } catch (\Exception) {}
    }
    private function handleDmMessage(ConnectionInterface $from, array $data, array $meta): void { DmHandler::handleDmMessage($from, $data, $meta, $this->userConns, $this->db); }
    private function handleDmTyping(ConnectionInterface $from, array $data, array $meta): void { DmHandler::handleDmTyping($from, $data, $meta, $this->userConns, $this->db); }
    private function handleNotifyConnReq(ConnectionInterface $from, array $data, array $meta): void { DmHandler::handleNotifyConnReq($from, $data, $meta, $this->userConns, $this->db); }
    private function handleNotifyConnAccepted(ConnectionInterface $from, array $data, array $meta): void { DmHandler::handleNotifyConnAccepted($from, $data, $meta, $this->userConns, $this->db); }
    private function handleNoteRelay(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['channel_id'] ?? 0); if (!$channelId) return;
        foreach ($this->channelSubs[$channelId] ?? [] as $conn) { if ($conn === $from) continue; try { $conn->send(json_encode($data)); } catch (\Exception) {} }
    }
    private function handleEditedBroadcast(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['channel_id'] ?? 0); if (!$channelId || empty($data['message'])) return;
        $this->broadcastToChannel($channelId, json_encode(['type' => 'message_edited', 'message' => $data['message']]), $from);
    }
    private function handlePinnedBroadcast(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['channel_id'] ?? 0); if (!$channelId) return;
        $this->broadcastToChannel($channelId, json_encode(['type' => 'message_pinned', 'channel_id' => $channelId, 'message_id' => (int)($data['message_id'] ?? 0), 'pinned' => (bool)($data['pinned'] ?? true), 'pinned_by' => $meta['username']]), $from);
    }
    private function handleChannelSeen(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? 0); if (!$channelId) return; $uid = (int)$meta['user_id']; $payload = json_encode(['type' => 'channel_seen', 'channel_id' => $channelId, 'user_id' => $uid]);
        foreach ($this->userConns[$uid] ?? [] as $conn) { if ($conn === $from) continue; try { $conn->send($payload); } catch (\Exception) {} }
    }
    private function handleDraftSave(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? 0); if (!$channelId) return; $uid = (int)$meta['user_id']; $payload = json_encode(['type' => 'draft_saved', 'channel_id' => $channelId, 'channel_name' => $data['channel_name'] ?? '', 'text' => $data['text'] ?? '']);
        foreach ($this->userConns[$uid] ?? [] as $conn) { if ($conn === $from) continue; try { $conn->send($payload); } catch (\Exception) {} }
    }
    private function handleThreadReply(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['channel_id'] ?? 0); $parentId = (int)($data['parent_id'] ?? 0); if (!$channelId || !$parentId || empty($data['reply'])) return;
        $this->broadcastToChannel($channelId, json_encode(['type' => 'thread_reply', 'channel_id' => $channelId, 'parent_id' => $parentId, 'reply' => $data['reply']]), $from);
    }
    private function handleMentionRelay(ConnectionInterface $from, array $data, array $meta): void
    {
        $target = (int)($data['target_user_id'] ?? 0); if (!$target) return; foreach ($this->userConns[$target] ?? [] as $conn) try { $conn->send(json_encode(['type' => 'mention', 'entry' => $data['entry'] ?? []])); } catch (\Exception) {}
    }
    private function broadcastToChannel(int $channelId, string $payload, ?ConnectionInterface $exclude = null): void
    {
        foreach ($this->channelSubs[$channelId] ?? [] as $conn) { if ($exclude && $conn === $exclude) continue; try { $conn->send($payload); } catch (\Exception $e) { echo "[WS] Send error: {$e->getMessage()}\n"; } }
    }
    private function broadcastToAll(string $payload, ?ConnectionInterface $exclude = null): void
    {
        foreach ($this->clients as $client) { if ($exclude && $client === $exclude) continue; $rid = $client->resourceId; if (empty($this->connMeta[$rid]['authed'])) continue; try { $client->send($payload); } catch (\Exception) {} }
    }
    private function removeFromChannel(ConnectionInterface $conn, int $channelId): void
    {
        if (!isset($this->channelSubs[$channelId])) return; $this->channelSubs[$channelId] = array_values(array_filter($this->channelSubs[$channelId], fn($c) => $c !== $conn)); if (empty($this->channelSubs[$channelId])) unset($this->channelSubs[$channelId]);
    }
    private function setUserOnline(int $userId, bool $online): void
    {
        try { $stmt = $this->db->prepare("UPDATE users SET is_online=:o,last_active_at=NOW() WHERE id=:id"); $stmt->execute([':o' => (int)$online, ':id' => $userId]); } catch (\Exception $e) { echo "[WS] DB error: {$e->getMessage()}\n"; }
    }
    private function broadcastPresence(int $userId, bool $online, string $username): void
    {
        $payload = json_encode(['type' => 'presence', 'user_id' => $userId, 'username' => $username, 'online' => $online]); foreach ($this->clients as $client) try { $client->send($payload); } catch (\Exception) {}
    }
    private function handleWbJoin(ConnectionInterface $from, array $data, array &$meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['channel_id'] ?? 0); if (!$channelId) return; $uid = (int)$meta['user_id']; $username = $meta['username']; $meta['wb_channel_id'] = $channelId;
        $init = $this->wbHandler->join($channelId, $uid, $username); $from->send(json_encode($init)); $notify = json_encode(['type' => 'wb_peer_joined', 'channel_id' => $channelId, 'peer' => $init['you']]);
        foreach ($init['peers'] as $peer) foreach ($this->userConns[(int)$peer['user_id']] ?? [] as $peerConn) try { $peerConn->send($notify); } catch (\Exception) {}
    }
    private function handleWbLeave(ConnectionInterface $from, array $data, array &$meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['wb_channel_id'] ?? 0); if (!$channelId) return; $uid = (int)$meta['user_id'];
        if (!empty($data['state_json']) && $this->wbHandler->canEdit($channelId, $uid)) $this->wbHandler->persistSnapshot($channelId, $uid, $data['state_json']);
        $remaining = $this->wbHandler->leave($channelId, $uid); $meta['wb_channel_id'] = null; $notify = json_encode(['type' => 'wb_peer_left', 'channel_id' => $channelId, 'user_id' => $uid, 'username' => $meta['username']]);
        foreach ($remaining as $peerId) foreach ($this->userConns[$peerId] ?? [] as $peerConn) try { $peerConn->send($notify); } catch (\Exception) {}
    }
    private function handleWbOp(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['wb_channel_id'] ?? 0); if (!$channelId || empty($data['op'])) return;
        if (!$this->wbHandler->canEdit($channelId, (int)$meta['user_id'])) { $from->send(json_encode(['type' => 'wb_locked', 'channel_id' => $channelId, 'message' => 'The host locked this whiteboard.'])); return; }
        $stamped = $this->wbHandler->recordOp($channelId, (int)$meta['user_id'], $data); $payload = json_encode(array_merge(['type' => 'wb_op'], $stamped));
        foreach ($this->wbHandler->getRoomUserIds($channelId, (int)$meta['user_id']) as $peerId) foreach ($this->userConns[$peerId] ?? [] as $peerConn) try { $peerConn->send($payload); } catch (\Exception) {}
    }
    private function handleWbCursor(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['wb_channel_id'] ?? 0); if (!$channelId) return; $uid = (int)$meta['user_id']; $peer = $this->wbHandler->getUserMeta($channelId, $uid);
        $payload = json_encode(['type' => 'wb_cursor', 'channel_id' => $channelId, 'user_id' => $uid, 'username' => $meta['username'], 'color' => $peer['color'] ?? '#a855f7', 'initial' => $peer['initial'] ?? '?', 'x' => $data['x'] ?? 0, 'y' => $data['y'] ?? 0]);
        foreach ($this->wbHandler->getRoomUserIds($channelId, $uid) as $peerId) foreach ($this->userConns[$peerId] ?? [] as $peerConn) try { $peerConn->send($payload); } catch (\Exception) {}
    }
    private function handleWbStateSave(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['wb_channel_id'] ?? 0); if (!$channelId || empty($data['state_json'])) return;
        if (!$this->wbHandler->canEdit($channelId, (int)$meta['user_id'])) { $from->send(json_encode(['type' => 'wb_locked', 'channel_id' => $channelId, 'message' => 'The host locked this whiteboard.'])); return; }
        $this->wbHandler->persistSnapshot($channelId, (int)$meta['user_id'], $data['state_json']); $from->send(json_encode(['type' => 'wb_state_saved', 'channel_id' => $channelId]));
    }
    private function handleWbRequestState(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['wb_channel_id'] ?? 0); if (!$channelId) return; $state = $this->wbHandler->getState($channelId);
        $from->send(json_encode(['type' => 'wb_state', 'channel_id' => $channelId, 'state_json' => $state, 'members' => $this->wbHandler->getMembers($channelId)]));
    }
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    $options = getopt('', ['port::', 'host::']);
    $port = (int)($options['port'] ?? getenv('WS_PORT') ?: 8080);
    $host = $options['host'] ?? getenv('WS_HOST') ?: '0.0.0.0';
    $loop = \React\EventLoop\Loop::get();
    $chat = new ChatServer();
    $server = \Ratchet\Server\IoServer::factory(new \Ratchet\Http\HttpServer(new \Ratchet\WebSocket\WsServer($chat)), $port, $host);
    $loop->addPeriodicTimer(0.2, static function () use ($chat): void { $chat->drainRelayTable(); $chat->persistDirtyCodeSessions(); });
    echo "Ecollab WebSocket server running on {$host}:{$port}\n";
    $server->run();
}
