<?php

declare(strict_types=1);

require_once __DIR__ . '/../database/config/db.php';

final class CoworkspaceService
{
    public static function get(PDO $db, int $workspaceId, int $userId, bool $requireAccess = true): array
    {
        $stmt = $db->prepare(
            'SELECT w.*, cm.role AS member_role
             FROM collab_workspaces w
             LEFT JOIN collab_workspace_members cm
               ON cm.workspace_id = w.id AND cm.user_id = :uid
             WHERE w.id = :wid AND w.archived = 0
             LIMIT 1'
        );
        $stmt->execute([':uid' => $userId, ':wid' => $workspaceId]);
        $workspace = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$workspace) {
            throw new RuntimeException('Coworkspace not found.', 404);
        }

        if ($requireAccess) {
            $serverId = (int)($workspace['server_id'] ?? 0);
            if ($serverId < 1) {
                throw new RuntimeException('Coworkspace is not linked to a server.', 500);
            }

            $serverStmt = $db->prepare(
                'SELECT 1
                 FROM server_members sm
                 INNER JOIN servers s ON s.id = sm.server_id
                 WHERE sm.server_id = :sid
                   AND sm.user_id = :uid
                   AND s.status = "active"
                 LIMIT 1'
            );
            $serverStmt->execute([':sid' => $serverId, ':uid' => $userId]);
            if (!$serverStmt->fetchColumn()) {
                throw new RuntimeException('You are not a member of this server.', 403);
            }

            $role = (string)($workspace['member_role'] ?? '');
            $isMember = $role !== '';
            $isHost = (int)$workspace['host_id'] === $userId;
            if (!$isMember && !$isHost) {
                throw new RuntimeException('You do not have access to this Coworkspace.', 403);
            }
            if ($isHost && $role === '') {
                $workspace['member_role'] = 'host';
            }
        }
        return $workspace;
    }

    public static function assertServerMember(PDO $db, int $serverId, int $userId): void
    {
        if ($serverId < 1) {
            throw new RuntimeException('A server is required.', 400);
        }

        $stmt = $db->prepare(
            'SELECT 1
             FROM server_members sm
             INNER JOIN servers s ON s.id = sm.server_id
             WHERE sm.server_id = :sid
               AND sm.user_id = :uid
               AND s.status = "active"
             LIMIT 1'
        );
        $stmt->execute([':sid' => $serverId, ':uid' => $userId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('You are not a member of this server.', 403);
        }
    }

    /** @deprecated Channel membership is no longer a Coworkspace authorization boundary. */
    public static function assertChannelMember(PDO $db, int $channelId, int $userId): void
    {
        $stmt = $db->prepare('SELECT server_id FROM channels WHERE id = :cid LIMIT 1');
        $stmt->execute([':cid' => $channelId]);
        $serverId = (int)($stmt->fetchColumn() ?: 0);
        if ($serverId < 1) {
            throw new RuntimeException('Channel not found.', 404);
        }
        self::assertServerMember($db, $serverId, $userId);
    }

    public static function canJoin(PDO $db, array $workspace, int $userId): bool
    {
        $serverId = (int)($workspace['server_id'] ?? 0);
        if ($serverId < 1) return false;

        $serverStmt = $db->prepare(
            'SELECT 1
             FROM server_members sm
             INNER JOIN servers s ON s.id = sm.server_id
             WHERE sm.server_id = :sid
               AND sm.user_id = :uid
               AND s.status = "active"
             LIMIT 1'
        );
        $serverStmt->execute([':sid' => $serverId, ':uid' => $userId]);
        if (!$serverStmt->fetchColumn()) return false;

        $stmt = $db->prepare('SELECT role FROM collab_workspace_members WHERE workspace_id = :wid AND user_id = :uid LIMIT 1');
        $stmt->execute([':wid' => (int)$workspace['id'], ':uid' => $userId]);
        if ($stmt->fetchColumn() !== false) return true;
        if ((int)$workspace['host_id'] === $userId) return true;
        return (string)$workspace['visibility'] === 'public';
    }

    public static function role(PDO $db, int $workspaceId, int $userId): ?string
    {
        $stmt = $db->prepare('SELECT role FROM collab_workspace_members WHERE workspace_id = :wid AND user_id = :uid LIMIT 1');
        $stmt->execute([':wid' => $workspaceId, ':uid' => $userId]);
        $role = $stmt->fetchColumn();
        return $role === false ? null : (string)$role;
    }

    public static function requireHost(PDO $db, int $workspaceId, int $userId): array
    {
        $workspace = self::get($db, $workspaceId, $userId);
        if ((int)$workspace['host_id'] !== $userId) {
            throw new RuntimeException('Only the Coworkspace host can perform this action.', 403);
        }
        return $workspace;
    }

    public static function memberCount(PDO $db, int $workspaceId): int
    {
        $stmt = $db->prepare('SELECT COUNT(*) FROM collab_workspace_members WHERE workspace_id = :wid');
        $stmt->execute([':wid' => $workspaceId]);
        return (int)$stmt->fetchColumn();
    }
}
