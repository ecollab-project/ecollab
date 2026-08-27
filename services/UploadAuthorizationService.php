<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/database/config/db.php';

/**
 * Centralizes the existing chat authorization model for file uploads.
 *
 * Regular users must belong to the target server. Private channels additionally
 * require channel membership unless the user manages the channel. Privileged
 * global roles retain the same global override already used by messaging.
 */
class UploadAuthorizationService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Authorize an upload destination and return its trusted server/channel data.
     *
     * @throws RuntimeException with HTTP-style status codes on denial.
     */
    public function authorize(int $userId, int $serverId, int $channelId): array
    {
        if ($userId <= 0 || $serverId <= 0 || $channelId <= 0) {
            throw new RuntimeException('Valid server_id and channel_id are required', 400);
        }

        $roleStmt = $this->db->prepare('SELECT role FROM users WHERE id = :uid LIMIT 1');
        $roleStmt->execute([':uid' => $userId]);
        $userRole = $roleStmt->fetchColumn() ?: 'student';
        $isPrivileged = in_array($userRole, ['admin', 'super_admin', 'moderator'], true);

        $stmt = $this->db->prepare("
            SELECT
                c.id,
                c.server_id,
                c.type,
                c.is_private,
                c.is_locked,
                c.created_by,
                EXISTS(
                    SELECT 1
                    FROM channel_members cm
                    WHERE cm.channel_id = c.id AND cm.user_id = :uid_channel
                ) AS has_channel_access,
                sm.server_role
            FROM channels c
            LEFT JOIN server_members sm
              ON sm.server_id = c.server_id AND sm.user_id = :uid_server
            WHERE c.id = :cid AND c.server_id = :sid
            LIMIT 1
        ");
        $stmt->execute([
            ':uid_channel' => $userId,
            ':uid_server'  => $userId,
            ':cid'         => $channelId,
            ':sid'         => $serverId,
        ]);
        $channel = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$channel) {
            throw new RuntimeException('Channel/server mismatch or channel not found', 403);
        }

        if ((int)$channel['is_locked'] === 1) {
            throw new RuntimeException('Channel is locked', 403);
        }

        // Match MessageService::sendMessage(): only normal text/study-room
        // channels accept regular message attachments.
        if (!in_array($channel['type'], ['text', 'study_room'], true)) {
            throw new RuntimeException('Uploads are not allowed in this channel type', 403);
        }

        if ($isPrivileged) {
            return $this->trustedContext($channel, $userId, $userRole);
        }

        if (empty($channel['server_role'])) {
            throw new RuntimeException('Not a member of this server', 403);
        }

        $canManage = in_array($channel['server_role'], ['owner', 'admin', 'moderator'], true)
            || (int)$channel['created_by'] === $userId;

        if ((int)$channel['is_private'] === 1 && !(bool)$channel['has_channel_access'] && !$canManage) {
            throw new RuntimeException('You do not have access to this private channel', 403);
        }

        return $this->trustedContext($channel, $userId, $userRole);
    }

    private function trustedContext(array $channel, int $userId, string $userRole): array
    {
        return [
            'user_id'          => $userId,
            'user_role'        => $userRole,
            'server_id'        => (int)$channel['server_id'],
            'channel_id'       => (int)$channel['id'],
            'channel_type'     => (string)$channel['type'],
            'is_private'       => (bool)$channel['is_private'],
            'is_locked'        => (bool)$channel['is_locked'],
            'server_role'      => $channel['server_role'] ?? null,
            'has_channel_access' => (bool)$channel['has_channel_access'],
        ];
    }
}
