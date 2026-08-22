<?php

declare(strict_types=1);

require_once __DIR__ . '/ChatServer.php';

use Ratchet\ConnectionInterface;

/**
 * WebSocket authorization boundary for channel access.
 *
 * ChatServer owns the connection lifecycle and channel subscription state.
 * This wrapper enforces the channel permission model before ChatServer can
 * subscribe a connection or route a channel-scoped event.
 */
class AuthorizedChatServer extends ChatServer
{
    /** @var array<int, int> resourceId => authenticated user ID */
    private array $authorizedUsers = [];

    /**
     * Channel-scoped event types. These events must use the connection's
     * server-verified current channel rather than a client-selected channel.
     *
     * @var array<string, true>
     */
    private const CHANNEL_SCOPED_TYPES = [
        'leave_channel' => true,
        'message' => true,
        'message_edited' => true,
        'message_deleted' => true,
        'message_pinned' => true,
        'collab_note_cursor' => true,
        'collab_note_presence' => true,
        'typing' => true,
        'channel_seen' => true,
        'draft_save' => true,
        'thread_reply' => true,
        'mention' => true,
        'whiteboard_sync' => true,
        'wb_join' => true,
        'wb_leave' => true,
        'wb_op' => true,
        'wb_cursor' => true,
        'wb_state_save' => true,
        'wb_request_state' => true,
    ];

    public function onMessage(ConnectionInterface $from, $rawMsg): void
    {
        $data = json_decode($rawMsg, true);
        if (!is_array($data) || empty($data['type'])) {
            parent::onMessage($from, $rawMsg);
            return;
        }

        $resourceId = $from->resourceId;
        $type = (string)$data['type'];

        if ($type === 'auth') {
            $this->rememberAuthenticatedUser($resourceId, $data);
            parent::onMessage($from, $rawMsg);
            return;
        }

        $userId = $this->authorizedUsers[$resourceId] ?? null;
        if ($userId === null) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Unauthenticated',
            ]));
            return;
        }

        if ($type === 'join_channel') {
            $channelId = (int)($data['channel_id'] ?? 0);
            if ($channelId <= 0 || !$this->canJoinChannel($channelId, $userId)) {
                $from->send(json_encode([
                    'type' => 'error',
                    'message' => 'Not authorized to join this channel',
                ]));
                return;
            }

            parent::onMessage($from, $rawMsg);
            return;
        }

        if ($type === 'join_voice') {
            $voiceChannelId = (int)($data['channel_id'] ?? 0);
            if ($voiceChannelId <= 0 || !$this->canJoinChannel($voiceChannelId, $userId)) {
                $from->send(json_encode([
                    'type' => 'error',
                    'message' => 'Not authorized to join this voice channel',
                ]));
                return;
            }

            parent::onMessage($from, $rawMsg);
            return;
        }

        if ($type === 'screen_share_notify') {
            $voiceChannelId = (int)($this->connMeta[$resourceId]['voice_channel_id'] ?? 0);
            if ($voiceChannelId <= 0 || !$this->isVoiceParticipant($voiceChannelId, $userId)) {
                $from->send(json_encode([
                    'type' => 'error',
                    'message' => 'Join a voice channel before sharing your screen',
                ]));
                return;
            }

            $data['channel_id'] = $voiceChannelId;
            $rawMsg = json_encode($data);
            if ($rawMsg === false) {
                $from->send(json_encode([
                    'type' => 'error',
                    'message' => 'Invalid voice event',
                ]));
                return;
            }
        }

        if (in_array($type, ['webrtc_offer', 'webrtc_answer', 'webrtc_candidate'], true)) {
            $voiceChannelId = (int)($this->connMeta[$resourceId]['voice_channel_id'] ?? 0);
            $targetUserId = (int)($data['target_user_id'] ?? 0);
            if ($voiceChannelId <= 0 || $targetUserId <= 0 || !$this->isVoiceParticipant($voiceChannelId, $userId) || !$this->isVoiceParticipant($voiceChannelId, $targetUserId)) {
                $from->send(json_encode([
                    'type' => 'error',
                    'message' => 'Peer is not in your authorized voice channel',
                ]));
                return;
            }
        }

        if (isset(self::CHANNEL_SCOPED_TYPES[$type])) {
            $currentChannelId = (int)($this->connMeta[$resourceId]['channel_id'] ?? 0);
            if ($currentChannelId <= 0) {
                $from->send(json_encode([
                    'type' => 'error',
                    'message' => 'Join a channel before sending channel events',
                ]));
                return;
            }

            // Never allow a client to select a different channel in the
            // payload. ChatServer's handlers historically prefer
            // data['channel_id'] over connMeta['channel_id'], so normalize it
            // to the server-tracked, previously authorized subscription.
            $data['channel_id'] = $currentChannelId;
            $rawMsg = json_encode($data);
            if ($rawMsg === false) {
                $from->send(json_encode([
                    'type' => 'error',
                    'message' => 'Invalid channel event',
                ]));
                return;
            }
        }

        parent::onMessage($from, $rawMsg);
    }

    public function onClose(ConnectionInterface $conn): void
    {
        unset($this->authorizedUsers[$conn->resourceId]);
        parent::onClose($conn);
    }

    /**
     * Resolve the authenticated user from the same short-lived WebSocket
     * token used by ChatServer. The parent still performs the authoritative
     * authentication and lifecycle update immediately afterward.
     */
    private function rememberAuthenticatedUser(int $resourceId, array $data): void
    {
        $wsToken = trim((string)($data['ws_token'] ?? ''));
        if ($wsToken === '') {
            unset($this->authorizedUsers[$resourceId]);
            return;
        }

        $hash = hash('sha256', $wsToken);
        $stmt = $this->authorizationDb()->prepare("
            SELECT u.id
            FROM ws_tokens wt
            JOIN users u ON u.id = wt.user_id
            WHERE wt.token_hash = :hash
              AND wt.expires_at > NOW()
              AND u.deleted_at IS NULL
              AND u.status = 'active'
            LIMIT 1
        ");
        $stmt->execute([':hash' => $hash]);
        $userId = $stmt->fetchColumn();
        $stmt->closeCursor();

        if ($userId === false) {
            unset($this->authorizedUsers[$resourceId]);
            return;
        }

        $this->authorizedUsers[$resourceId] = (int)$userId;
    }

    /**
     * Check that a user is an active participant in a server-authorized voice
     * room. The room state is the server-side source of truth for WebRTC peer
     * signaling and screen-share notifications.
     */
    private function isVoiceParticipant(int $voiceChannelId, int $userId): bool
    {
        foreach ($this->voiceRooms[$voiceChannelId] ?? [] as $participant) {
            if ((int)($participant['user_id'] ?? 0) === $userId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mirror the REST channel-access model in API/chat/get-channel.php:
     * - the user must belong to the channel's server;
     * - public channels are accessible to server members;
     * - private channels require channel_members membership unless the user
     *   is a server owner/admin/moderator or the channel creator.
     *
     * Global users.role is deliberately not used as a server-scoped bypass.
     */
    private function canJoinChannel(int $channelId, int $userId): bool
    {
        $stmt = $this->authorizationDb()->prepare("
            SELECT
                c.is_private,
                c.created_by,
                EXISTS(
                    SELECT 1
                    FROM server_members sm
                    WHERE sm.server_id = c.server_id
                      AND sm.user_id = :uid_server
                ) AS is_server_member,
                EXISTS(
                    SELECT 1
                    FROM channel_members cm
                    WHERE cm.channel_id = c.id
                      AND cm.user_id = :uid_channel
                ) AS is_channel_member,
                EXISTS(
                    SELECT 1
                    FROM server_members sm_role
                    WHERE sm_role.server_id = c.server_id
                      AND sm_role.user_id = :uid_role
                      AND sm_role.server_role IN ('owner', 'admin', 'moderator')
                ) AS can_manage_server
            FROM channels c
            WHERE c.id = :channel_id
            LIMIT 1
        ");
        $stmt->execute([
            ':uid_server' => $userId,
            ':uid_channel' => $userId,
            ':uid_role' => $userId,
            ':channel_id' => $channelId,
        ]);
        $channel = $stmt->fetch();
        $stmt->closeCursor();

        if (!$channel || (int)$channel['is_server_member'] !== 1) {
            return false;
        }

        if ((int)$channel['is_private'] === 0) {
            return true;
        }

        return (int)$channel['is_channel_member'] === 1
            || (int)$channel['can_manage_server'] === 1
            || (int)$channel['created_by'] === $userId;
    }

    private function authorizationDb(): PDO
    {
        return Database::getInstance();
    }
}
