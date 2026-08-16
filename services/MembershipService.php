<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/database/config/db.php';

/**
 * Handles user server/channel membership data.
 *
 * Extracted from UserService as part of Phase 4.6.
 */
class MembershipService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Returns the user's server/channel membership summary.
     */
    public function getMembershipSummary(int $userId): array
    {
        $myServers = $this->getMyServers($userId);
        $myChannels = $this->getMyChannels($userId);

        $ownedServers = array_values(
            array_filter(
                $myServers,
                fn(array $server): bool => ($server['server_role'] ?? '') === 'owner'
            )
        );

        $ownedChannels = array_values(
            array_filter(
                $myChannels,
                fn(array $channel): bool =>
                (int)($channel['created_by'] ?? 0) === $userId
            )
        );

        return [
            'my_servers' => $myServers,
            'my_channels' => $myChannels,
            'owned_servers' => $ownedServers,
            'owned_channels' => $ownedChannels,

            'servers_joined_count' => count($myServers),
            'channels_joined_count' => count($myChannels),
            'servers_owned_count' => count($ownedServers),
            'channels_owned_count' => count($ownedChannels),

            'servers_managed_count' => count(
                array_filter(
                    $myServers,
                    fn(array $server): bool =>
                    in_array(
                        $server['server_role'] ?? '',
                        ['owner', 'admin', 'moderator'],
                        true
                    )
                )
            ),
        ];
    }

    /**
     * All servers the user belongs to.
     */
    private function getMyServers(int $userId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    s.id,
                    s.name,
                    s.slug,
                    s.icon_emoji,
                    s.category,
                    s.type,
                    s.member_count,
                    s.owner_id,
                    sm.server_role,
                    sm.joined_at,
                    (
                        SELECT COUNT(*)
                        FROM channels c
                        WHERE c.server_id = s.id
                    ) AS channel_count,
                    (s.owner_id = :uid2) AS is_owner
                FROM server_members sm
                JOIN servers s ON s.id = sm.server_id
                WHERE sm.user_id = :uid
                  AND s.status != 'suspended'
                ORDER BY
                    (sm.server_role = 'owner') DESC,
                    s.member_count DESC
                LIMIT 50
            ");

            $stmt->execute([
                ':uid' => $userId,
                ':uid2' => $userId,
            ]);

            return $stmt->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * All accessible channels across the user's servers.
     */
    private function getMyChannels(int $userId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    c.id,
                    c.name,
                    c.slug,
                    c.type,
                    c.member_count,
                    c.server_id,
                    c.created_by,
                    c.is_private,
                    c.is_locked,
                    s.name AS server_name,
                    s.icon_emoji AS server_icon,
                    sm.server_role,
                    (c.created_by = :uid2) AS is_creator
                FROM server_members sm
                JOIN channels c ON c.server_id = sm.server_id
                JOIN servers s ON s.id = c.server_id
                WHERE sm.user_id = :uid
                  AND (
                    c.is_private = 0
                    OR sm.server_role IN ('owner', 'admin', 'moderator')
                    OR c.created_by = :uid3
                  )
                ORDER BY
                    (c.created_by = :uid4) DESC,
                    c.position ASC
                LIMIT 100
            ");

            $stmt->execute([
                ':uid' => $userId,
                ':uid2' => $userId,
                ':uid3' => $userId,
                ':uid4' => $userId,
            ]);

            $rows = $stmt->fetchAll();

            $typeIcons = [
                'text' => '#',
                'voice' => '🔊',
                'announcement' => '📣',
                'whiteboard' => '🖍',
                'study_room' => '📖',
            ];

            foreach ($rows as &$row) {
                $row['icon_emoji'] =
                    $typeIcons[$row['type']] ?? '#';
            }

            unset($row);

            return $rows;
        } catch (Throwable) {
            return [];
        }
    }
}
