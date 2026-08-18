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
            'join_voice'     => $this->handleJoinVoice($from, $data, $meta),
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
            'dm_typing'           => $this->handleDmTyping($from, $data, $meta),
            'notify_conn_req'     => $this->handleNotifyConnReq($from, $data, $meta),
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
                    $leaveNotify = json_encode(['type'=>'wb_peer_left','channel_id'=>$wbChanId,'user_id'=>$uid,'username'=>$username]);
                    foreach ($remainingIds as $peerId) foreach ($this->userConns[$peerId] ?? [] as $peerConn) try { $peerConn->send($leaveNotify); } catch (\Exception) {}
                }

                foreach ($this->voiceRooms as $vcId => &$participants) {
                    $wasInRoom = array_filter($participants, fn($p) => $p['user_id'] === $uid);
                    if (!empty($wasInRoom)) {
                        $participants = array_values(array_filter($participants, fn($p) => $p['user_id'] !== $uid));
                        $leavePayload = json_encode(['type'=>'voice_leave','user_id'=>$uid,'username'=>$username,'channel_id'=>$vcId]);
                        foreach ($participants as $p) foreach ($this->userConns[(int)$p['user_id']] ?? [] as $peerConn) try { $peerConn->send($leavePayload); } catch (\Exception) {}
                        $this->broadcastToAll($leavePayload, $conn);
                        if (empty($participants)) unset($this->voiceRooms[$vcId]);
                    }
                }
                unset($participants);
                try { $this->db->prepare("UPDATE users SET voice_channel_id=NULL WHERE id=:id")->execute([':id'=>$uid]); } catch (\Exception) {}
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
            $conn->send(json_encode(['type'=>'error','message'=>'Invalid auth: ws_token required']));
            return;
        }

        $hash = hash('sha256', $wsToken);
        $stmt = $this->db->prepare("
            SELECT u.id, u.username, u.status, u.full_name, u.avatar_color_gradient
            FROM ws_tokens wt
            JOIN users u ON u.id = wt.user_id
            WHERE wt.token_hash = :hash
              AND wt.expires_at > UTC_TIMESTAMP()
              AND u.deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([':hash'=>$hash]);
        $user = $stmt->fetch();
        $stmt->closeCursor();

        if (!$user) {
            // Never log the raw token. The hash prefix is enough to correlate a
            // browser auth attempt with the database row during local debugging.
            echo "[WS] Auth rejected: token hash prefix " . substr($hash, 0, 12) . "\n";
            $conn->send(json_encode(['type'=>'error','message'=>'Invalid or expired auth token']));
            return;
        }
        if ($user['status'] !== 'active') {
            $conn->send(json_encode(['type'=>'error','message'=>'Account is not active']));
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
        $conn->send(json_encode(['type'=>'auth_ok','user_id'=>$userId]));
        echo "[WS] User {$username} ({$userId}) authenticated on {$conn->resourceId}\n";
    }
