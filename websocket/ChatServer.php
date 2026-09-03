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
                    try {
                        $conn->send($row['payload']);
                    } catch (\Exception) {
                    }
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

    public function onMessage(ConnectionInterface $from, $rawMsg): void
    {
        $rid  = $from->resourceId;
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

        $channelScopedTypes = [
            'join_channel',
            'message',
            'message_edited',
            'message_deleted',
            'message_pinned',
            'collab_note_cursor',
            'collab_note_presence',
            'typing',
            'presence',
            'channel_seen',
            'draft_save',
            'thread_reply',
            'mention',
            'join_voice',
            'whiteboard_sync',
            'wb_join',
            'wb_op',
            'wb_cursor',
            'wb_state_save',
            'wb_request_state',
            'screen_share_notify',
            'webrtc_offer',
            'webrtc_answer',
            'webrtc_candidate',
        ];
        if (
            in_array($type, $channelScopedTypes, true)
            && !$this->authorizeChannelMessage($from, $data, $meta)
        ) {
            return;
        }

        match ($type) {
            'ping'            => $from->send(json_encode(['type' => 'pong'])),
            'join_channel'    => $this->handleJoinChannel($from, $data, $meta),
            'leave_channel'   => $this->handleLeaveChannel($from, $data, $meta),
            'message'         => $this->handleMessage($from, $data, $meta),
            'message_edited'  => $this->handleEditedBroadcast($from, $data, $meta),
            'message_deleted' => $this->handleDeletedBroadcast($from, $data, $meta),
            'message_pinned'  => $this->handlePinnedBroadcast($from, $data, $meta),
            'collab_note_cursor'   => $this->handleNoteRelay($from, $data, $meta),
            'collab_note_presence' => $this->handleNoteRelay($from, $data, $meta),
            'typing'          => $this->handleTyping($from, $data, $meta),
            'presence'        => $this->handlePresence($from, $data, $meta),
            'channel_seen'    => $this->handleChannelSeen($from, $data, $meta),
            'draft_save'      => $this->handleDraftSave($from, $data, $meta),
            'thread_reply'    => $this->handleThreadReply($from, $data, $meta),
            'mention'         => $this->handleMentionRelay($from, $data, $meta),
            'join_voice'      => $this->handleJoinVoice($from, $data, $meta),
            'leave_voice'     => $this->handleLeaveVoice($from, $data, $meta),
            'whiteboard_sync' => $this->handleWhiteboardSync($from, $data, $meta),
            'wb_join'          => $this->handleWbJoin($from, $data, $meta),
            'wb_leave'         => $this->handleWbLeave($from, $data, $meta),
            'wb_op'            => $this->handleWbOp($from, $data, $meta),
            'wb_cursor'        => $this->handleWbCursor($from, $data, $meta),
            'wb_state_save'    => $this->handleWbStateSave($from, $data, $meta),
            'wb_request_state' => $this->handleWbRequestState($from, $data, $meta),
            'webrtc_offer'        => $this->handleWebRtcSignal($from, $data, $meta, 'webrtc_offer'),
            'webrtc_answer'       => $this->handleWebRtcSignal($from, $data, $meta, 'webrtc_answer'),
            'webrtc_candidate'    => $this->handleWebRtcSignal($from, $data, $meta, 'webrtc_candidate'),
            'screen_share_notify' => $this->handleScreenShareNotify($from, $data, $meta),
            'connection_request'  => $this->handleConnectionRequest($from, $data, $meta),
            'dm_message'           => $this->handleDmMessage($from, $data, $meta),
            'dm_typing'            => $this->handleDmTyping($from, $data, $meta),
            'notify_conn_req'      => $this->handleNotifyConnReq($from, $data, $meta),
            'notify_conn_accepted' => $this->handleNotifyConnAccepted($from, $data, $meta),
            default               => null,
        };
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $rid  = $conn->resourceId;
        $meta = $this->connMeta[$rid] ?? [];
        if (!empty($meta['channel_id'])) $this->removeFromChannel($conn, (int)$meta['channel_id']);

        if (!empty($meta['user_id'])) {
            $uid      = (int)$meta['user_id'];
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
                    foreach ($remainingIds as $peerId) foreach ($this->userConns[$peerId] ?? [] as $peerConn) try {
                        $peerConn->send($leaveNotify);
                    } catch (\Exception) {
                    }
                }

                foreach ($this->voiceRooms as $vcId => &$participants) {
                    $wasInRoom = array_filter($participants, fn($p) => $p['user_id'] === $uid);
                    if (!empty($wasInRoom)) {
                        $participants = array_values(array_filter($participants, fn($p) => $p['user_id'] !== $uid));
                        $leavePayload = json_encode(['type' => 'voice_leave', 'user_id' => $uid, 'username' => $username, 'channel_id' => $vcId]);
                        foreach ($participants as $p) foreach ($this->userConns[(int)$p['user_id']] ?? [] as $peerConn) try {
                            $peerConn->send($leavePayload);
                        } catch (\Exception) {
                        }
                        $this->broadcastToAll($leavePayload, $conn);
                        if (empty($participants)) unset($this->voiceRooms[$vcId]);
                    }
                }
                unset($participants);
                try {
                    $this->db->prepare("UPDATE users SET voice_channel_id=NULL WHERE id=:id")->execute([':id' => $uid]);
                } catch (\Exception) {
                }
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
        if ($wsToken === '') {
            $conn->send(json_encode(['type' => 'error', 'message' => 'Invalid auth: ws_token required']));
            return;
        }

        $hash = hash('sha256', $wsToken);
        $stmt = $this->db->prepare("
            SELECT u.id, u.username, u.status, u.full_name, u.avatar_color_gradient
            FROM ws_tokens wt
            JOIN users u ON u.id = wt.user_id
            WHERE wt.token_hash = :hash
              AND wt.expires_at > NOW()
              AND u.deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([':hash' => $hash]);
        $user = $stmt->fetch();
        $stmt->closeCursor();

        if (!$user) {
            $conn->send(json_encode(['type' => 'error', 'message' => 'Invalid or expired auth token']));
            return;
        }
        if ($user['status'] !== 'active') {
            $conn->send(json_encode(['type' => 'error', 'message' => 'Account is not active']));
            return;
        }

        // Keep the short-lived token valid for its expiry window so duplicate
        // browser bootstraps/tabs cannot invalidate the valid connection.
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

    /**
     * Verify channel access before dispatching any channel-scoped event.
     * The client-provided channel ID is never trusted for authorization.
     */
    private function authorizeChannelMessage(ConnectionInterface $conn, array $data, array $meta): bool
    {
        $channelId = (int)($data['channel_id']
            ?? $meta['channel_id']
            ?? $meta['voice_channel_id']
            ?? $meta['wb_channel_id']
            ?? 0);
        $userId = (int)($meta['user_id'] ?? 0);

        if ($channelId <= 0 || $userId <= 0) {
            $conn->send(json_encode(['type' => 'error', 'message' => 'Channel access denied']));
            return false;
        }

        try {
            $stmt = $this->db->prepare("
                SELECT c.is_private, c.is_locked, u.role,
                       sm.user_id AS server_member_id,
                       cm.user_id AS channel_member_id
                FROM channels c
                JOIN users u ON u.id = :uid_user AND u.deleted_at IS NULL
                LEFT JOIN server_members sm
                    ON sm.server_id = c.server_id AND sm.user_id = :uid_server
                LEFT JOIN channel_members cm
                    ON cm.channel_id = c.id AND cm.user_id = :uid_channel
                WHERE c.id = :cid
                  AND c.type IN ('text', 'announcement', 'voice', 'whiteboard', 'study_room')
                LIMIT 1
            ");
            $stmt->execute([
                ':uid_user' => $userId,
                ':uid_server' => $userId,
                ':uid_channel' => $userId,
                ':cid' => $channelId,
            ]);
            $channel = $stmt->fetch();
        } catch (\Throwable $e) {
            error_log('[WS] channel authorization failed: ' . $e->getMessage());
            $conn->send(json_encode(['type' => 'error', 'message' => 'Channel access denied']));
            return false;
        }

        if (!$channel) {
            $conn->send(json_encode(['type' => 'error', 'message' => 'Channel access denied']));
            return false;
        }

        $isPrivileged = in_array($channel['role'], ['admin', 'super_admin', 'moderator'], true);
        $hasServerAccess = $channel['server_member_id'] !== null;
        $hasPrivateAccess = !$channel['is_private'] || $channel['channel_member_id'] !== null;
        $isUsable = !$channel['is_locked'] || $isPrivileged;

        if (!$isPrivileged && (!$hasServerAccess || !$hasPrivateAccess || !$isUsable)) {
            $conn->send(json_encode(['type' => 'error', 'message' => 'Channel access denied']));
            return false;
        }

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
        if ($channelId) {
            $this->removeFromChannel($conn, $channelId);
            $meta['channel_id'] = null;
        }
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
        $channelId = (int)($data['channel_id'] ?? 0);
        if (!$channelId) return;
        $stmt = $this->db->prepare("SELECT id, username, full_name, avatar_color_gradient, role FROM users WHERE id = :id");
        $stmt->execute([':id' => $meta['user_id']]);
        $user = $stmt->fetch() ?: [];
        $uid = (int)$meta['user_id'];
        $meta['voice_channel_id'] = $channelId;
        foreach ($this->voiceRooms as &$participants) $participants = array_filter($participants, fn($p) => $p['user_id'] !== $uid);
        unset($participants);
        $existingParticipants = array_values($this->voiceRooms[$channelId] ?? []);
        $this->voiceRooms[$channelId] ??= [];
        $this->voiceRooms[$channelId][] = ['user_id' => $uid, 'username' => $meta['username'], 'full_name' => $user['full_name'] ?? $meta['username'], 'avatar_color_gradient' => $user['avatar_color_gradient'] ?? '#3b82f6,#6366f1', 'role' => $user['role'] ?? 'student', 'resourceId' => $from->resourceId];
        try {
            $this->db->prepare("UPDATE users SET voice_channel_id=:cid WHERE id=:id")->execute([':cid' => $channelId, ':id' => $uid]);
        } catch (\Exception) {
        }
        $payload = json_encode(['type' => 'voice_join', 'user' => $user, 'channel_id' => $channelId]);
        $already = [];
        foreach ($existingParticipants as $p) foreach ($this->userConns[(int)$p['user_id']] ?? [] as $peerConn) {
            try {
                $peerConn->send($payload);
                $already[$peerConn->resourceId] = true;
            } catch (\Exception) {
            }
        }
        foreach ($this->clients as $client) {
            if ($client === $from) continue;
            $rid = $client->resourceId;
            if (empty($this->connMeta[$rid]['authed']) || isset($already[$rid])) continue;
            try {
                $client->send($payload);
            } catch (\Exception) {
            }
        }
        $peers = array_values(array_map(fn($p) => ['user_id' => $p['user_id'], 'username' => $p['username'], 'full_name' => $p['full_name'] ?? $p['username'], 'avatar_color_gradient' => $p['avatar_color_gradient'] ?? '#3b82f6,#6366f1', 'role' => $p['role'] ?? 'student', 'muted' => $p['muted'] ?? false], $existingParticipants));
        $from->send(json_encode(['type' => 'voice_peers', 'peers' => $peers, 'channel_id' => $channelId]));
    }

    private function handleLeaveVoice(ConnectionInterface $from, array $data, array &$meta): void
    {
        $uid = (int)$meta['user_id'];
        $channelId = (int)($meta['voice_channel_id'] ?? 0);
        $remaining = [];
        if ($channelId && isset($this->voiceRooms[$channelId])) {
            $this->voiceRooms[$channelId] = array_values(array_filter($this->voiceRooms[$channelId], fn($p) => $p['user_id'] !== $uid));
            $remaining = $this->voiceRooms[$channelId];
            if (empty($this->voiceRooms[$channelId])) unset($this->voiceRooms[$channelId]);
        }
        $meta['voice_channel_id'] = null;
        try {
            $this->db->prepare("UPDATE users SET voice_channel_id=NULL WHERE id=:id")->execute([':id' => $uid]);
        } catch (\Exception) {
        }
        $payload = json_encode(['type' => 'voice_leave', 'user_id' => $uid, 'username' => $meta['username'], 'channel_id' => $channelId]);
        foreach ($remaining as $p) foreach ($this->userConns[(int)$p['user_id']] ?? [] as $peerConn) try {
            $peerConn->send($payload);
        } catch (\Exception) {
        }
        $this->broadcastToAll($payload, $from);
    }

    private function handleWebRtcSignal(ConnectionInterface $from, array $data, array $meta, string $type): void
    {
        $target = (int)($data['target_user_id'] ?? 0);
        $voiceChannelId = (int)($meta['voice_channel_id'] ?? 0);
        $isVoicePeer = false;
        foreach ($this->voiceRooms[$voiceChannelId] ?? [] as $participant) {
            if ((int)$participant['user_id'] === $target) {
                $isVoicePeer = true;
                break;
            }
        }
        if (!$target || !$isVoicePeer || empty($this->userConns[$target])) {
            $from->send(json_encode(['type' => 'error', 'message' => 'Peer not connected']));
            return;
        }
        $payload = json_encode([
            'type' => $type,
            'from_user_id' => $meta['user_id'],
            'from_username' => $meta['username'],
            'sdp' => $data['sdp'] ?? null,
            'candidate' => $data['candidate'] ?? null,
            'is_screen_offer' => $data['is_screen_offer'] ?? false,
        ]);
        foreach ($this->userConns[$target] as $peerConn) try {
            $peerConn->send($payload);
        } catch (\Exception $e) {
            error_log('[WS] WebRTC relay error: ' . $e->getMessage());
        }
    }

    private function handleScreenShareNotify(ConnectionInterface $from, array $data, array $meta): void
    {
        $uid = (int)$meta['user_id'];
        $channelId = (int)($meta['voice_channel_id'] ?? $meta['channel_id'] ?? 0);
        $payload = json_encode(['type' => 'screen_share_notify', 'user_id' => $uid, 'username' => $meta['username'], 'active' => (bool)($data['active'] ?? false), 'channel_id' => $channelId]);
        foreach ($this->voiceRooms[$channelId] ?? [] as $p) {
            $peerId = (int)$p['user_id'];
            if ($peerId === $uid) continue;
            foreach ($this->userConns[$peerId] ?? [] as $conn) try {
                $conn->send($payload);
            } catch (\Exception) {
            }
        }
    }

    private function handleDeletedBroadcast(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($meta['channel_id'] ?? 0);
        $messageId = (int)($data['message_id'] ?? 0);
        if (!$channelId || !$messageId) return;
        $this->broadcastToChannel($channelId, json_encode(['type' => 'message_deleted', 'message_id' => $messageId]), $from);
    }
    private function handleWhiteboardSync(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['channel_id'] ?? 0);
        if (!$channelId) return;
        $this->broadcastToChannel($channelId, json_encode(['type' => 'whiteboard_sync', 'channel_id' => $channelId, 'user_id' => $meta['user_id'], 'state_json' => $data['state_json'] ?? '']), $from);
    }
    private function handleConnectionRequest(ConnectionInterface $from, array $data, array $meta): void
    {
        $addresseeId = (int)($data['addressee_id'] ?? 0);
        if (!$addresseeId) return;
        if (!empty($this->userConns[$addresseeId])) {
            foreach ($this->userConns[$addresseeId] as $peerConn) try {
                $peerConn->send(json_encode(['type' => 'connection_request', 'request_id' => $data['request_id'] ?? null, 'addressee_id' => $addresseeId, 'requester' => $data['requester'] ?? []]));
            } catch (\Exception) {
            }
        }
    }
    private function handleDmMessage(ConnectionInterface $from, array $data, array $meta): void
    {
        DmHandler::handleDmMessage($from, $data, $meta, $this->userConns, $this->db);
    }
    private function handleDmTyping(ConnectionInterface $from, array $data, array $meta): void
    {
        DmHandler::handleDmTyping($from, $data, $meta, $this->userConns, $this->db);
    }
    private function handleNotifyConnReq(ConnectionInterface $from, array $data, array $meta): void
    {
        DmHandler::handleNotifyConnReq($from, $data, $meta, $this->userConns, $this->db);
    }
    private function handleNotifyConnAccepted(ConnectionInterface $from, array $data, array $meta): void
    {
        DmHandler::handleNotifyConnAccepted($from, $data, $meta, $this->userConns, $this->db);
    }
    private function handleNoteRelay(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['channel_id'] ?? 0);
        if (!$channelId) return;
        foreach ($this->channelSubs[$channelId] ?? [] as $conn) {
            if ($conn === $from) continue;
            try {
                $conn->send(json_encode($data));
            } catch (\Exception) {
            }
        }
    }
    private function handleEditedBroadcast(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['channel_id'] ?? 0);
        if (!$channelId || empty($data['message'])) return;
        $this->broadcastToChannel($channelId, json_encode(['type' => 'message_edited', 'message' => $data['message']]), $from);
    }
    private function handlePinnedBroadcast(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['channel_id'] ?? 0);
        if (!$channelId) return;
        $this->broadcastToChannel($channelId, json_encode(['type' => 'message_pinned', 'channel_id' => $channelId, 'message_id' => (int)($data['message_id'] ?? 0), 'pinned' => (bool)($data['pinned'] ?? true), 'pinned_by' => $meta['username']]), $from);
    }
    private function handleChannelSeen(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? 0);
        if (!$channelId) return;
        $uid = (int)$meta['user_id'];
        $payload = json_encode(['type' => 'channel_seen', 'channel_id' => $channelId, 'user_id' => $uid]);
        foreach ($this->userConns[$uid] ?? [] as $conn) {
            if ($conn === $from) continue;
            try {
                $conn->send($payload);
            } catch (\Exception) {
            }
        }
    }
    private function handleDraftSave(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? 0);
        if (!$channelId) return;
        $uid = (int)$meta['user_id'];
        $payload = json_encode(['type' => 'draft_saved', 'channel_id' => $channelId, 'channel_name' => $data['channel_name'] ?? '', 'text' => $data['text'] ?? '']);
        foreach ($this->userConns[$uid] ?? [] as $conn) {
            if ($conn === $from) continue;
            try {
                $conn->send($payload);
            } catch (\Exception) {
            }
        }
    }
    private function handleThreadReply(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['channel_id'] ?? 0);
        $parentId = (int)($data['parent_id'] ?? 0);
        if (!$channelId || !$parentId || empty($data['reply'])) return;
        $this->broadcastToChannel($channelId, json_encode(['type' => 'thread_reply', 'channel_id' => $channelId, 'parent_id' => $parentId, 'reply' => $data['reply']]), $from);
    }
    private function handleMentionRelay(ConnectionInterface $from, array $data, array $meta): void
    {
        $target = (int)($data['target_user_id'] ?? 0);
        if (!$target) return;
        foreach ($this->userConns[$target] ?? [] as $conn) try {
            $conn->send(json_encode(['type' => 'mention', 'entry' => $data['entry'] ?? []]));
        } catch (\Exception) {
        }
    }
    private function broadcastToChannel(int $channelId, string $payload, ?ConnectionInterface $exclude = null): void
    {
        foreach ($this->channelSubs[$channelId] ?? [] as $conn) {
            if ($exclude && $conn === $exclude) continue;
            try {
                $conn->send($payload);
            } catch (\Exception $e) {
                echo "[WS] Send error: {$e->getMessage()}\n";
            }
        }
    }
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
        $this->channelSubs[$channelId] = array_values(array_filter($this->channelSubs[$channelId], fn($c) => $c !== $conn));
        if (empty($this->channelSubs[$channelId])) unset($this->channelSubs[$channelId]);
    }
    private function setUserOnline(int $userId, bool $online): void
    {
        try {
            $stmt = $this->db->prepare("UPDATE users SET is_online=:o,last_active_at=NOW() WHERE id=:id");
            $stmt->execute([':o' => (int)$online, ':id' => $userId]);
        } catch (\Exception $e) {
            echo "[WS] DB error: {$e->getMessage()}\n";
        }
    }
    private function broadcastPresence(int $userId, bool $online, string $username): void
    {
        $payload = json_encode(['type' => 'presence', 'user_id' => $userId, 'username' => $username, 'online' => $online]);
        foreach ($this->clients as $client) try {
            $client->send($payload);
        } catch (\Exception) {
        }
    }
    private function handleWbJoin(ConnectionInterface $from, array $data, array &$meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['channel_id'] ?? 0);
        if (!$channelId) return;
        $uid = (int)$meta['user_id'];
        $username = $meta['username'];
        $meta['wb_channel_id'] = $channelId;
        $init = $this->wbHandler->join($channelId, $uid, $username);
        $from->send(json_encode($init));
        $notify = json_encode(['type' => 'wb_peer_joined', 'channel_id' => $channelId, 'peer' => $init['you']]);
        foreach ($init['peers'] as $peer) {
            foreach ($this->userConns[(int)$peer['user_id']] ?? [] as $peerConn) try {
                $peerConn->send($notify);
            } catch (\Exception) {
            }
        }
    }
    private function handleWbLeave(ConnectionInterface $from, array $data, array &$meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['wb_channel_id'] ?? 0);
        if (!$channelId) return;
        $uid = (int)$meta['user_id'];
        if (!empty($data['state_json']) && $this->wbHandler->canEdit($channelId, $uid)) {
            $this->wbHandler->persistSnapshot($channelId, $uid, $data['state_json']);
        }
        $remaining = $this->wbHandler->leave($channelId, $uid);
        $meta['wb_channel_id'] = null;
        $notify = json_encode(['type' => 'wb_peer_left', 'channel_id' => $channelId, 'user_id' => $uid, 'username' => $meta['username']]);
        foreach ($remaining as $peerId) foreach ($this->userConns[$peerId] ?? [] as $peerConn) try {
            $peerConn->send($notify);
        } catch (\Exception) {
        }
    }
    private function handleWbOp(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['wb_channel_id'] ?? 0);
        if (!$channelId || empty($data['op'])) return;
        if (!$this->wbHandler->canEdit($channelId, (int)$meta['user_id'])) {
            $from->send(json_encode(['type' => 'wb_locked', 'channel_id' => $channelId, 'message' => 'The host locked this whiteboard.']));
            return;
        }
        $stamped = $this->wbHandler->recordOp($channelId, (int)$meta['user_id'], $data);
        $payload = json_encode(array_merge(['type' => 'wb_op'], $stamped));
        foreach ($this->wbHandler->getRoomUserIds($channelId, (int)$meta['user_id']) as $peerId) foreach ($this->userConns[$peerId] ?? [] as $peerConn) try {
            $peerConn->send($payload);
        } catch (\Exception) {
        }
    }
    private function handleWbCursor(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['wb_channel_id'] ?? 0);
        if (!$channelId) return;
        $uid = (int)$meta['user_id'];
        $peer = $this->wbHandler->getUserMeta($channelId, $uid);
        $payload = json_encode(['type' => 'wb_cursor', 'channel_id' => $channelId, 'user_id' => $uid, 'username' => $meta['username'], 'color' => $peer['color'] ?? '#a855f7', 'initial' => $peer['initial'] ?? '?', 'x' => $data['x'] ?? 0, 'y' => $data['y'] ?? 0]);
        foreach ($this->wbHandler->getRoomUserIds($channelId, $uid) as $peerId) foreach ($this->userConns[$peerId] ?? [] as $peerConn) try {
            $peerConn->send($payload);
        } catch (\Exception) {
        }
    }
    private function handleWbStateSave(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['wb_channel_id'] ?? 0);
        if (!$channelId || empty($data['state_json'])) return;
        if (!$this->wbHandler->canEdit($channelId, (int)$meta['user_id'])) {
            $from->send(json_encode(['type' => 'wb_locked', 'channel_id' => $channelId, 'message' => 'The host locked this whiteboard.']));
            return;
        }
        $this->wbHandler->persistSnapshot($channelId, (int)$meta['user_id'], $data['state_json']);
        $from->send(json_encode(['type' => 'wb_state_saved', 'channel_id' => $channelId]));
    }
    private function handleWbRequestState(ConnectionInterface $from, array $data, array $meta): void
    {
        $channelId = (int)($data['channel_id'] ?? $meta['wb_channel_id'] ?? 0);
        if (!$channelId) return;
        $state = $this->wbHandler->getState($channelId);
        $from->send(json_encode(['type' => 'wb_state', 'channel_id' => $channelId, 'state_json' => $state, 'members' => $this->wbHandler->getMembers($channelId)]));
    }
}

if (
    PHP_SAPI === 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)
) {
    $options = getopt('', ['port::', 'host::']);
    $port = (int)($options['port'] ?? getenv('WS_PORT') ?: 8080);
    $host = $options['host'] ?? getenv('WS_HOST') ?: '0.0.0.0';

    $loop = \React\EventLoop\Loop::get();
    $chat = new ChatServer();
    $server = \Ratchet\Server\IoServer::factory(
        new \Ratchet\Http\HttpServer(new \Ratchet\WebSocket\WsServer($chat)),
        $port,
        $host
    );
    $loop->addPeriodicTimer(0.2, static fn() => $chat->drainRelayTable());
    echo "Ecollab WebSocket server running on {$host}:{$port}\n";
    $server->run();
}
