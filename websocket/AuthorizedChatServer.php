<?php

declare(strict_types=1);

require_once __DIR__ . '/ChatServer.php';

use Ratchet\ConnectionInterface;

/**
 * WebSocket authorization boundary for channel subscriptions.
 *
 * ChatServer owns the connection lifecycle and channel subscription state.
 * This wrapper intercepts join_channel before ChatServer can mutate
 * channelSubs, ensuring an authenticated user can only subscribe to a
 * channel they are allowed to access.
 */
class AuthorizedChatServer extends ChatServer
{
    /** @var array<int, int> resourceId => authenticated user ID */
    private array $authorizedUsers = [];

    public function onMessage(ConnectionInterface $from, $rawMsg): void
    {
        $data = json_decode($rawMsg, true);
        if (!is_array($data) || empty($data['type'])) {
            parent::onMessage($from, $rawMsg);
            return;
        }

        $resourceId = $from->resourceId;
        $type = $data['type'];

        if ($type === 'auth') {
            $this->rememberAuthenticatedUser($resourceId, $data);
            parent::onMessage($from, $rawMsg);
            return;
        }

        if ($type === 'join_channel') {
            $userId = $this->authorizedUsers[$resourceId] ?? null;
            if ($userId === null) {
                $from->send(json_encode([
                    'type' => 'error',
                    'message' => 'Unauthenticated',
                ]));
                return;
            }

            $channelId = (int)($data['channel_id'] ?? 0);
            if ($channelId <= 0 || !$this->canJoinChannel($channelId, $userId)) {
                $from->send(json_encode([
                    'type' => 'error',
                    'message' => 'Not authorized to join this channel',
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

    private function canJoinChannel(int $channelId, int $userId): bool
    {
        $stmt = $this->authorizationDb()->prepare("
            SELECT
                c.is_private,
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
                    FROM users u
                    WHERE u.id = :uid_role
                      AND u.role IN ('admin', 'super_admin', 'moderator')
                      AND u.status = 'active'
                      AND u.deleted_at IS NULL
                ) AS is_global_moderator
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

        if (!$channel) {
            return false;
        }

        if ((int)$channel['is_global_moderator'] === 1) {
            return true;
        }

        if ((int)$channel['is_server_member'] !== 1) {
            return false;
        }

        return (int)$channel['is_private'] === 0
            || (int)$channel['is_channel_member'] === 1;
    }

    private function authorizationDb(): PDO
    {
        return Database::getInstance();
    }
}
