<?php

declare(strict_types=1);

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

    private PDO $db;
    private WhiteboardHandler $wbHandler;

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

    /**
     * Drain the ws_relay table and push pending collab-tool events to channel subs.
     * Called every 200 ms by bin/server.php via $loop->addPeriodicTimer().
     */
    public function drainRelayTable(): void
    {
        try {
            $stmt = $this->db->query(
                "SELECT id, channel_id, payload FROM ws_relay ORDER BY id ASC LIMIT 100"
            );
            $rows = $stmt->fetchAll();
            if (!$rows) return;

            $ids = [];
            foreach ($rows as $row) {
                $ids[]     = (int)$row['id'];
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
            'user_id'    => null,
            'username'   => null,
            'channel_id' => null,
            'authed'     => false,
        ];
        echo "[WS] Connection {$rid} opened\n";
    }

    // ── Message received ──
    public function onMessage(ConnectionInterface $from, $rawMsg): void
    {
        $rid  = $from->resourceId;
        $meta = &$this->connMeta[$rid];

        $data = json_decode($rawMsg, true);
        if (!is_array($data) || empty($data['type'])) return;

        $type = $data['type'];

        // Auth must come first
        if ($type === 'auth') {
            $this->handleAuth($from, $data, $meta);
            return;
        }

        if (!$meta['authed']) {
            $from->send(json_encode(['type' => 'error', 'message' => 'Unauthenticated']));
            return;
        }

        match ($type) {
            // ── Core ──
            'ping'            => $from->send(json_encode(['type' => 'pong'])),
            'join_channel'    => $this->handleJoinChannel($from, $data, $meta),
            'leave_channel'   => $this->handleLeaveChannel($from, $data, $meta),
            // ── Messages ──
            'message'         => $this->handleMessage($from, $data, $meta),
            'message_edited'  => $this->handleEditedBroadcast($from, $data, $meta),
            'message_deleted' => $this->handleDeletedBroadcast($from, $data, $meta),
            'message_pinned'  => $this->handlePinnedBroadcast($from, $data, $meta),
            // ── Live document (OT cursor + presence — low-latency, bypass relay) ──
            'collab_note_cursor'   => $this->handleNoteRelay($from, $data, $meta),
            'collab_note_presence' => $this->handleNoteRelay($from, $data, $meta),
            // ── Typing / Presence ──
            'typing'          => $this->handleTyping($from, $data, $meta),
            'presence'        => $this->handlePresence($from, $data, $meta),
            // ── Channel ──
            'channel_seen'    => $this->handleChannelSeen($from, $data, $meta),
            // ── Drafts (cross-tab sync for same user) ──
            'draft_save'      => $this->handleDraftSave($from, $data, $meta),
            // ── Threads ──
            'thread_reply'    => $this->handleThreadReply($from, $data, $meta),
            // ── Mentions ──
            'mention'         => $this->handleMentionRelay($from, $data, $meta),
            // ── Voice ──
            'join_voice'      => $this->handleJoinVoice($from, $data, $meta),
            'leave_voice'     => $this->handleLeaveVoice($from, $data, $meta),
            // ── Whiteboard (legacy sync) ──
            'whiteboard_sync' => $this->handleWhiteboardSync($from, $data, $meta),
            // ── Collaborative whiteboard ──
            'wb_join'          => $this->handleWbJoin($from, $data, $meta),
            'wb_leave'         => $this->handleWbLeave($from, $data, $meta),
            'wb_op'            => $this->handleWbOp($from, $data, $meta),
            'wb_cursor'        => $this->handleWbCursor($from, $data, $meta),
            'wb_state_save'    => $this->handleWbStateSave($from, $data, $meta),
            'wb_request_state' => $this->handleWbRequestState($from, $data, $meta),
            // ── WebRTC signaling ──
            'webrtc_offer'        => $this->handleWebRtcSignal($from, $data, $meta, 'webrtc_offer'),
            'webrtc_answer'       => $this->handleWebRtcSignal($from, $data, $meta, 'webrtc_answer'),
            'webrtc_candidate'    => $this->handleWebRtcSignal($from, $data, $meta, 'webrtc_candidate'),
            'screen_share_notify' => $this->handleScreenShareNotify($from, $data, $meta),
            // ── Social ──
            'connection_request'  => $this->handleConnectionRequest($from, $data, $meta),
            // ── Direct Messages ──
            'dm_message'           => $this->handleDmMessage($from, $data, $meta),
            'dm_typing'            => $this->handleDmTyping($from, $data, $meta),
            'notify_conn_req'      => $this->handleNotifyConnReq($from, $data, $meta),
            'notify_conn_accepted' => $this->handleNotifyConnAccepted($from, $data, $meta),
            default               => null,
        };
    }

    // ── Connection closed ──
    public function onClose(ConnectionInterface $conn): void
    {
        $rid  = $conn->resourceId;
        $meta = $this->connMeta[$rid] ?? [];

        // Remove from channel subscriptions
        if (!empty($meta['channel_id'])) {
            $this->removeFromChannel($conn, (int)$meta['channel_id']);
        }

        // Mark user offline and clean up voice room
        if (!empty($meta['user_id'])) {
            $uid      = (int)$meta['user_id'];
            $username = $meta['username'] ?? '';

            // Remove this specific connection from userConns; keep others (other tabs)
            if (isset($this->userConns[$uid])) {
                $this->userConns[$uid] = array_values(array_filter(
                    $this->userConns[$uid],
                    fn($c) => $c !== $conn
                ));
                if (empty($this->userConns[$uid])) {
                    unset($this->userConns[$uid]);
                }
            }
            $userFullyDisconnected = !isset($this->userConns[$uid]);

            // Only run presence/voice/whiteboard cleanup when the LAST connection closes
            if ($userFullyDisconnected) {
            // Leave any active whiteboard room
            if (!empty($meta['wb_channel_id'])) {
                $wbChanId     = (int)$meta['wb_channel_id'];
                $remainingIds = $this->wbHandler->leave($wbChanId, $uid);
                $leaveNotify  = json_encode([
                    'type'       => 'wb_peer_left',
                    'channel_id' => $wbChanId,
                    'user_id'    => $uid,
                    'username'   => $username,
                ]);
                foreach ($remainingIds as $peerId) {
                    foreach ($this->userConns[$peerId] ?? [] as $peerConn) {
                        try { $peerConn->send($leaveNotify); } catch (\Exception) {}
                    }
                }
            }

            // Remove from any voice room and notify remaining peers
            foreach ($this->voiceRooms as $vcId => &$participants) {
                $wasInRoom = array_filter($participants, fn($p) => $p['user_id'] === $uid);
                if (!empty($wasInRoom)) {
                    $participants = array_values(array_filter($participants, fn($p) => $p['user_id'] !== $uid));

                    // Notify every remaining peer that this user left
                    $leavePayload = json_encode([
                        'type'       => 'voice_leave',
                        'user_id'    => $uid,
                        'username'   => $username,
                        'channel_id' => $vcId,
                    ]);
                    foreach ($participants as $p) {
                        $peerId = (int)$p['user_id'];
                        foreach ($this->userConns[$peerId] ?? [] as $peerConn) {
                            try { $peerConn->send($leavePayload); } catch (\Exception) {}
                        }
                    }
                    // Also broadcast to text-channel subscribers (sidebar etc.)
                    $this->broadcastToAll($leavePayload, $conn);

                    if (empty($participants)) unset($this->voiceRooms[$vcId]);
                }
            }
            unset($participants);

            // Clear voice channel in DB
            try {
                $this->db->prepare("UPDATE users SET voice_channel_id=NULL WHERE id=:id")->execute([':id' => $uid]);
            } catch (\Exception) {
            }
            $this->setUserOnline($uid, false);
            $this->broadcastPresence($uid, false, $username);
            } // end userFullyDisconnected
        }

        $this->clients->detach($conn);
        unset($this->connMeta[$rid]);
        echo "[WS] Connection {$rid} closed\n";
    }

    // ── Error ──
    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        echo "[WS] Error on {$conn->resourceId}: {$e->getMessage()}\n";
        $conn->close();
    }

    // ═══ Handlers ═══

    private function handleAuth(ConnectionInterface $conn, array $data, array &$meta): void
    {
        $wsToken = trim($data['ws_token'] ?? '');

        if ($wsToken === '') {
            $conn->send(json_encode(['type' => 'error', 'message' => 'Invalid auth: ws_token required']));
            return;
        }

        // Validate the server-issued one-time token stored in the DB
        // Token is written by API/auth/ws-token.php at page load (sha256-hashed)
        $stmt = $this->db->prepare("
            SELECT u.id, u.username, u.status, u.full_name, u.avatar_color_gradient
            FROM ws_tokens wt
            JOIN users u ON u.id = wt.user_id
            WHERE wt.token_hash = :hash
              AND wt.expires_at > NOW()
              AND u.deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([':hash' => hash('sha256', $wsToken)]);
        $user = $stmt->fetch();

        if (!$user) {
            $conn->send(json_encode(['type' => 'error', 'message' => 'Invalid or expired auth token']));
            return;
        }

        if ($user['status'] !== 'active') {
            $conn->send(json_encode(['type' => 'error', 'message' => 'Account is not active']));
            return;
        }

        // Consume the token (one-time use)
        $this->db->prepare("DELETE FROM ws_tokens WHERE token_hash = :hash")
            ->execute([':hash' => hash('sha256', $wsToken)]);

        $userId   = (int)$user['id'];
        $username = $user['username'];

        $meta['user_id']  = $userId;
        $meta['username'] = $username;
        $meta['full_name'] = $user['full_name'] ?? $username;
        $meta['gradient']  = $user['avatar_color_gradient'] ?? '';
        $meta['authed']   = true;

        // Map user_id => [connections] for direct signaling (supports multiple tabs)
        $this->userConns[$userId][] = $conn;

        $this->setUserOnline($userId, true);
        $this->broadcastPresence($userId, true, $username);

        $conn->send(json_encode(['type' => 'auth_ok', 'user_id' => $userId]));
        echo "[WS] User {$username} ({$userId}) authenticated on {$conn->resourceId}\n";
    }

    private function handleJoinChannel(ConnectionInterface $conn, array $data, array &$meta): void
    {
        $channelId = (int)($data['channel_id'] ?? 0);
        if ($channelId <= 0) return;

        // Leave old channel if any
        if ($meta['channel_id']) {
            $this->removeFromChannel($conn, (int)$meta['channel_id']);
        }

        $meta['channel_id'] = $channelId;
        $this->channelSubs[$channelId][] = $conn;

        $conn->send(json_encode(['type' => 'joined_channel', 'channel_id' => $channelId]));
        echo "[WS] User {$meta['username']} joined channel {$channelId}\n";
    }

    private function handleLeaveChannel(ConnectionInterface $conn, array $data, array &$meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['channel_id'] ?? 0);
        if ($channelId) {
            $this->removeFromChannel($conn, $channelId);
            $meta['channel_id'] = null;
        }
    }

    private function handleMessage(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['channel_id'] ?? 0);
        if (!$channelId || empty($data['message'])) return;

        // Broadcast to all subscribers of this channel except sender
        $payload = json_encode([
            'type'    => 'message',
            'message' => $data['message'],
        ]);
        $this->broadcastToChannel($channelId, $payload, $from);
    }

    private function handleTyping(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['channel_id'] ?? 0);
        if (!$channelId) return;

        $payload = json_encode([
            'type'       => 'typing',
            'channel_id' => $channelId,
            'user_id'    => $meta['user_id'],
            'username'   => $meta['username'],
            'typing'     => (bool)($data['typing'] ?? false),
        ]);
        $this->broadcastToChannel($channelId, $payload, $from);
    }

    private function handlePresence(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($meta['channel_id'] ?? 0);
        $payload   = json_encode([
            'type'    => 'presence',
            'user_id' => $meta['user_id'],
            'online'  => true,
            'muted'   => (bool)($data['muted'] ?? false),
        ]);
        if ($channelId) $this->broadcastToChannel($channelId, $payload, $from);
    }

    private function handleJoinVoice(ConnectionInterface $from, array $data, array &$meta): void
    {
        $channelId = (int)($data['channel_id'] ?? 0);
        if (!$channelId) return;

        // Fetch user info
        $stmt = $this->db->prepare("SELECT id, username, full_name, avatar_color_gradient, role FROM users WHERE id = :id");
        $stmt->execute([':id' => $meta['user_id']]);
        $user = $stmt->fetch() ?: [];

        $uid = (int)$meta['user_id'];
        $meta['voice_channel_id'] = $channelId;

        // Remove from any previous voice room
        foreach ($this->voiceRooms as $vcId => &$participants) {
            $participants = array_filter($participants, fn($p) => $p['user_id'] !== $uid);
        }
        unset($participants);

        // Collect existing participants BEFORE adding self
        $existingParticipants = array_values($this->voiceRooms[$channelId] ?? []);

        // Add self to voice room
        if (!isset($this->voiceRooms[$channelId])) {
            $this->voiceRooms[$channelId] = [];
        }
        $this->voiceRooms[$channelId][] = [
            'user_id'              => $uid,
            'username'             => $meta['username'],
            'full_name'            => $user['full_name']            ?? $meta['username'],
            'avatar_color_gradient' => $user['avatar_color_gradient'] ?? '#3b82f6,#6366f1',
            'role'                 => $user['role']                  ?? 'student',
            'resourceId'           => $from->resourceId,
        ];

        // Update DB
        try {
            $this->db->prepare("UPDATE users SET voice_channel_id=:cid WHERE id=:id")
                ->execute([':cid' => $channelId, ':id' => $uid]);
        } catch (\Exception) {
        }

        // ── Notify ALL existing voice room participants directly ──────────
        // We cannot use broadcastToChannel() because voice users are NOT
        // subscribed to the voice channel via join_channel — they only
        // subscribe to text channels. So we relay directly via userConns.
        $voiceJoinPayload = json_encode([
            'type'       => 'voice_join',
            'user'       => $user,
            'channel_id' => $channelId,
        ]);

        // Track connections that already got the direct send, to avoid double-delivery
        $alreadyNotified = [];
        foreach ($existingParticipants as $p) {
            $peerId = (int)$p['user_id'];
            foreach ($this->userConns[$peerId] ?? [] as $peerConn) {
                try {
                    $peerConn->send($voiceJoinPayload);
                    $alreadyNotified[$peerConn->resourceId] = true;
                } catch (\Exception) {}
            }
        }

        // Also broadcast to text-channel subscribers (sidebar active-now etc.)
        // but skip anyone who already received the direct voice_join above
        foreach ($this->clients as $client) {
            if ($client === $from) continue;                          // exclude joiner
            $rid = $client->resourceId;
            if (empty($this->connMeta[$rid]['authed'])) continue;    // skip unauthenticated
            if (isset($alreadyNotified[$rid])) continue;             // skip already notified
            try {
                $client->send($voiceJoinPayload);
            } catch (\Exception) {
            }
        }

        // ── Send joiner the list of existing peers for WebRTC ─────────────
        $peers = array_values(array_map(fn($p) => [
            'user_id'               => $p['user_id'],
            'username'              => $p['username'],
            'full_name'             => $p['full_name']             ?? $p['username'],
            'avatar_color_gradient' => $p['avatar_color_gradient'] ?? '#3b82f6,#6366f1',
            'role'                  => $p['role']                  ?? 'student',
            'muted'                 => $p['muted']                 ?? false,
        ], $existingParticipants));

        $from->send(json_encode([
            'type'       => 'voice_peers',
            'peers'      => $peers,
            'channel_id' => $channelId,
        ]));

        echo "[WS] User {$meta['username']} joined voice channel {$channelId} (peers: " . count($peers) . ")\n";
    }

    private function handleLeaveVoice(ConnectionInterface $from, array $data, array &$meta): void
    {
        $uid       = (int)$meta['user_id'];
        $channelId = (int)($meta['voice_channel_id'] ?? 0);

        // Remove from voice room and collect remaining peers
        $remainingPeers = [];
        if ($channelId && isset($this->voiceRooms[$channelId])) {
            $this->voiceRooms[$channelId] = array_values(
                array_filter($this->voiceRooms[$channelId], fn($p) => $p['user_id'] !== $uid)
            );
            $remainingPeers = $this->voiceRooms[$channelId];
            if (empty($this->voiceRooms[$channelId])) {
                unset($this->voiceRooms[$channelId]);
            }
        }

        $meta['voice_channel_id'] = null;

        // Update DB
        try {
            $this->db->prepare("UPDATE users SET voice_channel_id=NULL WHERE id=:id")->execute([':id' => $uid]);
        } catch (\Exception) {
        }

        $payload = json_encode([
            'type'       => 'voice_leave',
            'user_id'    => $uid,
            'username'   => $meta['username'],
            'channel_id' => $channelId,
        ]);

        // Notify remaining voice room participants directly
        foreach ($remainingPeers as $p) {
            $peerId = (int)$p['user_id'];
            foreach ($this->userConns[$peerId] ?? [] as $peerConn) {
                try { $peerConn->send($payload); } catch (\Exception) {}
            }
        }

        // Also broadcast to all (text channel subscribers)
        $this->broadcastToAll($payload, $from);
    }

    /**
     * WebRTC signaling relay — forwards offer/answer/candidate directly to target peer.
     * data must include: target_user_id, [sdp|candidate]
     */
    private function handleWebRtcSignal(ConnectionInterface $from, array $data, array $meta, string $type): void
    {
        $targetUserId = (int)($data['target_user_id'] ?? 0);
        if (!$targetUserId || empty($this->userConns[$targetUserId])) {
            $from->send(json_encode(['type' => 'error', 'message' => 'Peer not connected']));
            return;
        }

        $payload = json_encode([
            'type'           => $type,
            'from_user_id'   => $meta['user_id'],
            'from_username'  => $meta['username'],
            'sdp'            => $data['sdp'] ?? null,
            'candidate'      => $data['candidate'] ?? null,
            'is_screen_offer' => $data['is_screen_offer'] ?? false,
        ]);

        // Deliver to all connections for this user (multiple tabs)
        foreach ($this->userConns[$targetUserId] as $peerConn) {
            try {
                $peerConn->send($payload);
            } catch (\Exception $e) {
                echo "[WS] WebRTC relay error: {$e->getMessage()}\n";
            }
        }
    }

    /**
     * Screen share notify — broadcasts only to peers in the same voice room.
     */
    private function handleScreenShareNotify(ConnectionInterface $from, array $data, array $meta): void
    {
        $uid       = (int)$meta['user_id'];
        $channelId = (int)($meta['voice_channel_id'] ?? $meta['channel_id'] ?? 0);

        $payload = json_encode([
            'type'      => 'screen_share_notify',
            'user_id'   => $uid,
            'username'  => $meta['username'],
            'active'    => (bool)($data['active'] ?? false),
            'channel_id' => $channelId,
        ]);

        // Only notify peers currently in the same voice room
        $participants = $this->voiceRooms[$channelId] ?? [];
        foreach ($participants as $p) {
            $peerId = (int)$p['user_id'];
            if ($peerId === $uid) continue;
            foreach ($this->userConns[$peerId] ?? [] as $conn) {
                try {
                    $conn->send($payload);
                } catch (\Exception) {
                }
            }
        }
    }

    private function handleDeletedBroadcast(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($meta['channel_id'] ?? 0);
        $messageId = (int)($data['message_id'] ?? 0);
        if (!$channelId || !$messageId) return;

        $payload = json_encode([
            'type'       => 'message_deleted',
            'message_id' => $messageId,
        ]);
        $this->broadcastToChannel($channelId, $payload, $from);
    }

    private function handleWhiteboardSync(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['channel_id'] ?? 0);
        if (!$channelId) return;

        $payload = json_encode([
            'type'       => 'whiteboard_sync',
            'channel_id' => $channelId,
            'user_id'    => $meta['user_id'],
            'state_json' => $data['state_json'] ?? '',
        ]);
        $this->broadcastToChannel($channelId, $payload, $from);
    }

    /**
     * Connection request relay — forwards the notification directly to the addressee only.
     * The sender never receives this; they already got the toast confirmation.
     */
    private function handleConnectionRequest(ConnectionInterface $from, array $data, array $meta): void
    {
        $addresseeId = (int)($data['addressee_id'] ?? 0);
        if (!$addresseeId) return;

        if (!empty($this->userConns[$addresseeId])) {
            $msg = json_encode([
                'type'         => 'connection_request',
                'request_id'   => $data['request_id'] ?? null,
                'addressee_id' => $addresseeId,
                'requester'    => $data['requester'] ?? [],
            ]);
            foreach ($this->userConns[$addresseeId] as $peerConn) {
                try {
                    $peerConn->send($msg);
                } catch (\Exception $e) {
                    error_log('[WS] connection_request relay failed: ' . $e->getMessage());
                }
            }
            echo "[WS] Connection request relayed to user {$addresseeId}\n";
        } else {
            echo "[WS] connection_request: user {$addresseeId} not connected\n";
        }
    }

    // ── Direct Messages (delegates to DmHandler, same pattern as WhiteboardHandler) ──
    private function handleDmMessage(ConnectionInterface $from, array $data, array $meta): void
    {
        DmHandler::handleDmMessage($from, $data, $meta, $this->userConns);
    }

    private function handleDmTyping(ConnectionInterface $from, array $data, array $meta): void
    {
        DmHandler::handleDmTyping($from, $data, $meta, $this->userConns);
    }

    private function handleNotifyConnReq(ConnectionInterface $from, array $data, array $meta): void
    {
        DmHandler::handleNotifyConnReq($from, $data, $meta, $this->userConns);
    }

    private function handleNotifyConnAccepted(ConnectionInterface $from, array $data, array $meta): void
    {
        DmHandler::handleNotifyConnAccepted($from, $data, $meta, $this->userConns);
    }

    // ── Live document cursor / presence relay (bypass ws_relay for low latency) ──
    private function handleNoteRelay(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['channel_id'] ?? 0);
        if (!$channelId) return;

        $payload = json_encode($data);
        foreach ($this->channelSubs[$channelId] ?? [] as $conn) {
            if ($conn === $from) continue; // don't echo back to sender
            try { $conn->send($payload); } catch (\Exception) {}
        }
    }

    // ── Message edit broadcast ────────────────────────────────────────────────
    private function handleEditedBroadcast(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['channel_id'] ?? 0);
        if (!$channelId || empty($data['message'])) return;

        $payload = json_encode([
            'type'    => 'message_edited',
            'message' => $data['message'],
        ]);
        $this->broadcastToChannel($channelId, $payload, $from);
    }

    // ── Message pin/unpin broadcast ───────────────────────────────────────────
    private function handlePinnedBroadcast(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['channel_id'] ?? 0);
        if (!$channelId) return;

        $payload = json_encode([
            'type'       => 'message_pinned',
            'channel_id' => $channelId,
            'message_id' => (int)($data['message_id'] ?? 0),
            'pinned'     => (bool)($data['pinned'] ?? true),
            'pinned_by'  => $meta['username'],
        ]);
        $this->broadcastToChannel($channelId, $payload, $from);
    }

    // ── Channel seen (clear unread badge for all connections of this user) ────
    private function handleChannelSeen(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? 0);
        if (!$channelId) return;

        $uid     = (int)$meta['user_id'];
        $payload = json_encode([
            'type'       => 'channel_seen',
            'channel_id' => $channelId,
            'user_id'    => $uid,
        ]);

        // Send to every other tab/device of THIS user so unread badge clears everywhere
        foreach ($this->userConns[$uid] ?? [] as $conn) {
            if ($conn === $from) continue;
            try { $conn->send($payload); } catch (\Exception) {}
        }
    }

    // ── Draft save (relay to other tabs of this user only) ────────────────────
    private function handleDraftSave(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? 0);
        if (!$channelId) return;

        $uid     = (int)$meta['user_id'];
        $payload = json_encode([
            'type'         => 'draft_saved',
            'channel_id'   => $channelId,
            'channel_name' => $data['channel_name'] ?? '',
            'text'         => $data['text'] ?? '',
        ]);

        // Only relay to other sessions of the same user — not to anyone else
        foreach ($this->userConns[$uid] ?? [] as $conn) {
            if ($conn === $from) continue;
            try { $conn->send($payload); } catch (\Exception) {}
        }
    }

    // ── Thread reply broadcast ────────────────────────────────────────────────
    private function handleThreadReply(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['channel_id'] ?? 0);
        $parentId  = (int)($data['parent_id']  ?? 0);
        if (!$channelId || !$parentId || empty($data['reply'])) return;

        $payload = json_encode([
            'type'       => 'thread_reply',
            'channel_id' => $channelId,
            'parent_id'  => $parentId,
            'reply'      => $data['reply'],
        ]);
        $this->broadcastToChannel($channelId, $payload, $from);
    }

    // ── Mention relay — routes directly to the target user ───────────────────
    private function handleMentionRelay(ConnectionInterface $from, array $data, array $meta): void
    {
        $targetUserId = (int)($data['target_user_id'] ?? 0);
        if (!$targetUserId) return;

        $payload = json_encode([
            'type'  => 'mention',
            'entry' => $data['entry'] ?? [],
        ]);

        foreach ($this->userConns[$targetUserId] ?? [] as $conn) {
            try { $conn->send($payload); } catch (\Exception) {}
        }
    }

    // ═══ Helpers ═══

    private function broadcastToChannel(int $channelId, string $payload, ?ConnectionInterface $exclude = null): void
    {
        if (empty($this->channelSubs[$channelId])) return;
        foreach ($this->channelSubs[$channelId] as $conn) {
            if ($exclude && $conn === $exclude) continue;
            try {
                $conn->send($payload);
            } catch (\Exception $e) {
                echo "[WS] Send error: {$e->getMessage()}\n";
            }
        }
    }

    /** Broadcast to every authenticated connection (e.g. voice events where users aren't in channelSubs). */
    private function broadcastToAll(string $payload, ?ConnectionInterface $exclude = null): void
    {
        foreach ($this->clients as $client) {
            if ($exclude && $client === $exclude) continue;
            $rid = $client->resourceId;
            if (empty($this->connMeta[$rid]['authed'])) continue;
            try {
                $client->send($payload);
            } catch (\Exception) {
            }
        }
    }

    private function removeFromChannel(ConnectionInterface $conn, int $channelId): void
    {
        if (!isset($this->channelSubs[$channelId])) return;
        // array_values() re-indexes the array after filter to prevent sparse key accumulation
        $this->channelSubs[$channelId] = array_values(array_filter(
            $this->channelSubs[$channelId],
            fn($c) => $c !== $conn
        ));
        if (empty($this->channelSubs[$channelId])) {
            unset($this->channelSubs[$channelId]);
        }
    }

    private function setUserOnline(int $userId, bool $online): void
    {
        try {
            $stmt = $this->db->prepare("UPDATE users SET is_online = :o, last_active_at = NOW() WHERE id = :id");
            $stmt->execute([':o' => (int)$online, ':id' => $userId]);
        } catch (\Exception $e) {
            echo "[WS] DB error: {$e->getMessage()}\n";
        }
    }

    private function broadcastPresence(int $userId, bool $online, string $username): void
    {
        $payload = json_encode([
            'type'     => 'presence',
            'user_id'  => $userId,
            'username' => $username,
            'online'   => $online,
        ]);
        foreach ($this->clients as $client) {
            try {
                $client->send($payload);
            } catch (\Exception) {
            }
        }
    }

    // ═══ Collaborative Whiteboard Handlers ═══

    /**
     * A user opens the whiteboard for a channel.
     * Sends them the current state + peer list, notifies existing peers.
     */
    private function handleWbJoin(ConnectionInterface $from, array $data, array &$meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['channel_id'] ?? 0);
        if (!$channelId) return;

        $uid      = (int)$meta['user_id'];
        $username = $meta['username'];

        $meta['wb_channel_id'] = $channelId;

        // Register in room and get initial payload for the joiner
        $initPayload = $this->wbHandler->join($channelId, $uid, $username);
        $from->send(json_encode($initPayload));

        // Notify all other peers already in the room
        $joinNotify = json_encode([
            'type'       => 'wb_peer_joined',
            'channel_id' => $channelId,
            'peer'       => $initPayload['you'],
        ]);
        foreach ($initPayload['peers'] as $peer) {
            $peerId = (int)$peer['user_id'];
            foreach ($this->userConns[$peerId] ?? [] as $peerConn) {
                try { $peerConn->send($joinNotify); } catch (\Exception) {}
            }
        }

        echo "[WB] {$username} joined whiteboard channel {$channelId} (peers: " . count($initPayload['peers']) . ")\n";
    }

    /**
     * A user closes the whiteboard (or disconnects).
     */
    private function handleWbLeave(ConnectionInterface $from, array $data, array &$meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['wb_channel_id'] ?? 0);
        if (!$channelId) return;

        $uid      = (int)$meta['user_id'];
        $username = $meta['username'];

        // If client sent a full state snapshot, persist it
        if (!empty($data['state_json'])) {
            $this->wbHandler->persistSnapshot($channelId, $uid, $data['state_json']);
        }

        $remainingIds = $this->wbHandler->leave($channelId, $uid);
        $meta['wb_channel_id'] = null;

        // Notify remaining peers
        $leaveNotify = json_encode([
            'type'       => 'wb_peer_left',
            'channel_id' => $channelId,
            'user_id'    => $uid,
            'username'   => $username,
        ]);
        foreach ($remainingIds as $peerId) {
            foreach ($this->userConns[$peerId] ?? [] as $peerConn) {
                try { $peerConn->send($leaveNotify); } catch (\Exception) {}
            }
        }

        echo "[WB] {$username} left whiteboard channel {$channelId}\n";
    }

    /**
     * Relay a drawing op to all other peers in the whiteboard room.
     * Op is also logged for late-join replay.
     *
     * Expected data:
     *   { channel_id, op: 'stroke_start'|'stroke_point'|'stroke_end'|'erase'|
     *                     'sticky_add'|'sticky_move'|'sticky_text'|
     *                     'text_add'|'text_move'|'text_edit'|'undo'|'clear',
     *     ... op-specific fields }
     */
    private function handleWbOp(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['wb_channel_id'] ?? 0);
        if (!$channelId || empty($data['op'])) return;

        $uid     = (int)$meta['user_id'];
        $stamped = $this->wbHandler->recordOp($channelId, $uid, $data);

        $payload = json_encode(array_merge(['type' => 'wb_op'], $stamped));

        // Relay to all other peers in the whiteboard room
        $peerIds = $this->wbHandler->getRoomUserIds($channelId, $uid);
        foreach ($peerIds as $peerId) {
            foreach ($this->userConns[$peerId] ?? [] as $peerConn) {
                try { $peerConn->send($payload); } catch (\Exception) {}
            }
        }
    }

    /**
     * High-frequency cursor position relay — NOT persisted.
     * data: { channel_id, x, y }
     */
    private function handleWbCursor(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['wb_channel_id'] ?? 0);
        if (!$channelId) return;

        $uid  = (int)$meta['user_id'];
        $peer = $this->wbHandler->getUserMeta($channelId, $uid);

        $payload = json_encode([
            'type'       => 'wb_cursor',
            'channel_id' => $channelId,
            'user_id'    => $uid,
            'username'   => $meta['username'],
            'color'      => $peer['color'] ?? '#a855f7',
            'initial'    => $peer['initial'] ?? '?',
            'x'          => $data['x'] ?? 0,
            'y'          => $data['y'] ?? 0,
        ]);

        $peerIds = $this->wbHandler->getRoomUserIds($channelId, $uid);
        foreach ($peerIds as $peerId) {
            foreach ($this->userConns[$peerId] ?? [] as $peerConn) {
                try { $peerConn->send($payload); } catch (\Exception) {}
            }
        }
    }

    /**
     * Client explicitly saves the full canvas state (e.g. before close).
     * data: { channel_id, state_json }
     */
    private function handleWbStateSave(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['wb_channel_id'] ?? 0);
        if (!$channelId || empty($data['state_json'])) return;

        $this->wbHandler->persistSnapshot($channelId, (int)$meta['user_id'], $data['state_json']);
        $from->send(json_encode(['type' => 'wb_state_saved', 'channel_id' => $channelId]));
    }

    /**
     * A peer requests the current state (e.g. after reconnect).
     * Responds directly to the requester.
     */
    private function handleWbRequestState(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['wb_channel_id'] ?? 0);
        if (!$channelId) return;

        $state = $this->wbHandler->getState($channelId);
        $from->send(json_encode([
            'type'       => 'wb_state',
            'channel_id' => $channelId,
            'state_json' => $state,
            'members'    => $this->wbHandler->getMembers($channelId),
        ]));
    }
}
