<?php

declare(strict_types=1);

use Ratchet\ConnectionInterface;

/**
 * DmHandler — WebSocket handler for real-time Direct Messages and notifications.
 *
 * Add to ChatServer:
 *   use DmHandler;
 *
 * Then in the match block inside onMessage():
 *   'dm_message'        => $this->handleDmMessage($from, $data, $meta),
 *   'dm_typing'         => $this->handleDmTyping($from, $data, $meta),
 *   'notify_conn_req'   => $this->handleNotifyConnReq($from, $data, $meta),
 */
class DmHandler
{
    /**
     * Deliver a DM message to the recipient if they are online.
     *
     * Expected $data:
     *   { type: 'dm_message', conversation_id, message_id, recipient_id, body, created_at }
     */
    public static function handleDmMessage(
        ConnectionInterface $from,
        array $data,
        array $meta,
        array $userConns,  // user_id => ConnectionInterface
        PDO $db
    ): void {
        $recipientId    = (int)($data['recipient_id'] ?? 0);
        $conversationId = (int)($data['conversation_id'] ?? 0);
        $messageId      = (int)($data['message_id'] ?? 0);

        if (!$recipientId || !$conversationId || !$messageId) return;

        $messageStmt = $db->prepare(
            'SELECT dm.body, dm.created_at
                         FROM dm_messages dm
                         JOIN dm_conversations dc ON dc.id = dm.conversation_id
                         WHERE dm.id = :mid AND dm.conversation_id = :cid
                             AND dm.sender_id = :sender AND dm.is_deleted = 0
                             AND ((dc.user_a = :sender_a AND dc.user_b = :recipient_a)
                                 OR (dc.user_b = :sender_b AND dc.user_a = :recipient_b))
                         LIMIT 1'
        );
        $messageStmt->execute([
            ':mid' => $messageId,
            ':cid' => $conversationId,
            ':sender' => (int)$meta['user_id'],
            ':sender_a' => (int)$meta['user_id'],
            ':recipient_a' => $recipientId,
            ':sender_b' => (int)$meta['user_id'],
            ':recipient_b' => $recipientId,
        ]);
        $message = $messageStmt->fetch(PDO::FETCH_ASSOC);
        if (!$message) return;

        $payload = json_encode([
            'type'            => 'dm_message',
            'conversation_id' => $conversationId,
            'message_id'      => $messageId,
            'sender_id'       => $meta['user_id'],
            'sender_name'     => $meta['full_name'] ?? $meta['username'],
            'sender_gradient' => $meta['gradient'] ?? '',
            'body'            => $message['body'],
            'created_at'      => $message['created_at'],
        ]);

        // Send to recipient if online
        if (isset($userConns[$recipientId])) {
            try {
                $userConns[$recipientId]->send($payload);
            } catch (\Throwable) {
            }
        }

        // Echo back to sender for multi-tab sync
        try {
            $from->send($payload);
        } catch (\Throwable) {
        }
    }

    /**
     * Relay DM typing indicator to the other party.
     *
     * Expected $data:
     *   { type: 'dm_typing', conversation_id, recipient_id, is_typing }
     */
    public static function handleDmTyping(
        ConnectionInterface $from,
        array $data,
        array $meta,
        array $userConns,
        PDO $db
    ): void {
        $recipientId    = (int)($data['recipient_id'] ?? 0);
        $conversationId = (int)($data['conversation_id'] ?? 0);

        if (!$recipientId || !$conversationId) return;
        if (!self::conversationPeer($db, $conversationId, (int)$meta['user_id'], $recipientId)) return;
        if (!isset($userConns[$recipientId])) return;

        $payload = json_encode([
            'type'            => 'dm_typing',
            'conversation_id' => $conversationId,
            'sender_id'       => $meta['user_id'],
            'sender_name'     => $meta['full_name'] ?? $meta['username'],
            'is_typing'       => (bool)($data['is_typing'] ?? false),
        ]);

        try {
            $userConns[$recipientId]->send($payload);
        } catch (\Throwable) {
        }
    }

    /**
     * Push a connection-request notification to the addressee if online.
     *
     * Expected $data:
     *   { type: 'notify_conn_req', addressee_id, request_id, requester: {fullName, gradient} }
     */
    public static function handleNotifyConnReq(
        ConnectionInterface $from,
        array $data,
        array $meta,
        array $userConns,
        PDO $db
    ): void {
        $addresseeId = (int)($data['addressee_id'] ?? 0);
        if (!$addresseeId) return;
        if (!isset($userConns[$addresseeId])) return;

        $payload = json_encode([
            'type'       => 'connection_request',
            'request_id' => (int)($data['request_id'] ?? 0),
            'requester'  => [
                'id'       => $meta['user_id'],
                'fullName' => $meta['full_name'] ?? $meta['username'],
                'gradient' => $meta['gradient'] ?? '',
            ],
        ]);

        try {
            $userConns[$addresseeId]->send($payload);
        } catch (\Throwable) {
        }
    }

    /**
     * Push "connection accepted" back to the original requester if online.
     *
     * Expected $data:
     *   { type: 'notify_conn_accepted', requester_id }
     */
    public static function handleNotifyConnAccepted(
        ConnectionInterface $from,
        array $data,
        array $meta,
        array $userConns,
        PDO $db
    ): void {
        $requesterId = (int)($data['requester_id'] ?? 0);
        if (!$requesterId) return;
        if (!isset($userConns[$requesterId])) return;

        $payload = json_encode([
            'type'         => 'connection_accepted',
            'accepted_by'  => [
                'id'       => $meta['user_id'],
                'fullName' => $meta['full_name'] ?? $meta['username'],
                'gradient' => $meta['gradient'] ?? '',
            ],
        ]);

        try {
            $userConns[$requesterId]->send($payload);
        } catch (\Throwable) {
        }
    }

    private static function conversationPeer(PDO $db, int $conversationId, int $userId, int $peerId): bool
    {
        $stmt = $db->prepare(
            'SELECT 1 FROM dm_conversations
             WHERE id = :cid
               AND ((user_a = :uid_a AND user_b = :peer_a)
                 OR (user_b = :uid_b AND user_a = :peer_b))
             LIMIT 1'
        );
        $stmt->execute([
            ':cid' => $conversationId,
            ':uid_a' => $userId,
            ':peer_a' => $peerId,
            ':uid_b' => $userId,
            ':peer_b' => $peerId,
        ]);
        return (bool)$stmt->fetchColumn();
    }
}
