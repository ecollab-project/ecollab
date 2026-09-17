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
        if (!$workspace) throw new RuntimeException('Coworkspace not found.', 404);

        if ($requireAccess) {
            $serverId = (int)($workspace['server_id'] ?? 0);
            if ($serverId < 1) throw new RuntimeException('Coworkspace is not linked to a server.', 500);

            $serverStmt = $db->prepare(
                'SELECT 1
                 FROM server_members sm
                 INNER JOIN servers s ON s.id = sm.server_id
                 WHERE sm.server_id = :sid AND sm.user_id = :uid AND s.status = "active"
                 LIMIT 1'
            );
            $serverStmt->execute([':sid' => $serverId, ':uid' => $userId]);
            if (!$serverStmt->fetchColumn()) throw new RuntimeException('You are not a member of this server.', 403);

            $role = (string)($workspace['member_role'] ?? '');
            $isHost = (int)$workspace['host_id'] === $userId;
            if ($isHost && $role === '') {
                $workspace['member_role'] = 'host';
                return $workspace;
            }
            if ($role === '' && (string)$workspace['visibility'] === 'public') {
                $workspace['member_role'] = 'member';
                return $workspace;
            }
            if ($role === '') throw new RuntimeException('You do not have access to this Coworkspace.', 403);
        }
        return $workspace;
    }

    public static function resolveWhiteboardAccess(PDO $db, int $whiteboardId, int $channelId, int $userId): ?array
    {
        if ($whiteboardId < 1 || $channelId < 1 || $userId < 1) return null;

        $stmt = $db->prepare(
            'SELECT wb.id, wb.workspace_id, wb.visibility, wb.public_permission, wb.created_by,
                    cw.server_id, cw.channel_id, cw.host_id, cw.archived
             FROM collab_whiteboards wb
             INNER JOIN collab_workspaces cw ON cw.id = wb.workspace_id
             WHERE wb.id = :wbid LIMIT 1'
        );
        $stmt->execute([':wbid' => $whiteboardId]);
        $board = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$board || (int)$board['channel_id'] !== $channelId || !empty($board['archived'])) return null;

        $serverId = (int)$board['server_id'];
        if ($serverId < 1) return null;
        $stmt = $db->prepare(
            'SELECT 1
             FROM server_members sm
             INNER JOIN servers s ON s.id = sm.server_id
             WHERE sm.server_id = :sid AND sm.user_id = :uid AND s.status = "active"
             LIMIT 1'
        );
        $stmt->execute([':sid' => $serverId, ':uid' => $userId]);
        if (!$stmt->fetchColumn()) return null;

        $workspaceId = (int)$board['workspace_id'];
        $isHost = (int)$board['host_id'] === $userId;
        $memberRole = null;
        if (!$isHost) {
            $stmt = $db->prepare('SELECT role FROM collab_workspace_members WHERE workspace_id=:wid AND user_id=:uid LIMIT 1');
            $stmt->execute([':wid'=>$workspaceId, ':uid'=>$userId]);
            $memberRole = $stmt->fetchColumn();
            $memberRole = $memberRole === false ? null : $memberRole;
        }

        $stmt = $db->prepare('SELECT visibility FROM collab_workspaces WHERE id=:wid LIMIT 1');
        $stmt->execute([':wid'=>$workspaceId]);
        $workspaceVisibility = (string)$stmt->fetchColumn();
        if (!$isHost && $memberRole === null && $workspaceVisibility !== 'public') return null;

        $stmt = $db->prepare(
            'SELECT permission FROM collab_resource_permissions
             WHERE resource_type="whiteboard" AND resource_id=:rid AND workspace_id=:wid AND user_id=:uid LIMIT 1'
        );
        $stmt->execute([':rid'=>$whiteboardId, ':wid'=>$workspaceId, ':uid'=>$userId]);
        $explicit = $stmt->fetchColumn();
        $explicit = $explicit === false ? null : self::normPerm($explicit);

        $isOwner = $isHost || (int)$board['created_by'] === $userId;
        $publicPermission = self::normPerm($board['public_permission'] ?? 'view');
        $effective = $isOwner ? 'edit' : ($explicit !== null ? $explicit : ($board['visibility'] === 'public' ? $publicPermission : null));
        if ($effective === null) return null;

        return [
            'whiteboard_id' => $whiteboardId,
            'workspace_id' => $workspaceId,
            'channel_id' => $channelId,
            'is_owner' => $isOwner,
            'permission' => $effective,
        ];
    }

    private static function normPerm(mixed $p): string
    {
        $p = strtolower(trim((string)$p));
        return in_array($p, ['view','comment','edit'], true) ? $p : 'view';
    }

    public static function assertServerMember(PDO $db, int $serverId, int $userId): void
    {
        if ($serverId < 1) throw new RuntimeException('A server is required.', 400);
        $stmt = $db->prepare(
            'SELECT 1 FROM server_members sm
             INNER JOIN servers s ON s.id = sm.server_id
             WHERE sm.server_id=:sid AND sm.user_id=:uid AND s.status="active" LIMIT 1'
        );
        $stmt->execute([':sid'=>$serverId, ':uid'=>$userId]);
        if (!$stmt->fetchColumn()) throw new RuntimeException('You are not a member of this server.',403);
    }

    /** @deprecated Channel membership is no longer a Coworkspace authorization boundary. */
    public static function assertChannelMember(PDO $db, int $channelId, int $userId): void
    {
        $stmt=$db->prepare('SELECT server_id FROM channels WHERE id=:cid LIMIT 1');
        $stmt->execute([':cid'=>$channelId]);
        $serverId=(int)($stmt->fetchColumn()?:0);
        if ($serverId<1) throw new RuntimeException('Channel not found.',404);
        self::assertServerMember($db,$serverId,$userId);
    }

    public static function canJoin(PDO $db, array $workspace, int $userId): bool
    {
        $serverId=(int)($workspace['server_id']??0);
        if($serverId<1)return false;
        try{self::assertServerMember($db,$serverId,$userId);}catch(Throwable){return false;}
        $stmt=$db->prepare('SELECT role FROM collab_workspace_members WHERE workspace_id=:wid AND user_id=:uid LIMIT 1');
        $stmt->execute([':wid'=>(int)$workspace['id'],':uid'=>$userId]);
        if($stmt->fetchColumn()!==false)return true;
        if((int)$workspace['host_id']===$userId)return true;
        return (string)$workspace['visibility']==='public';
    }

    public static function role(PDO $db,int $workspaceId,int $userId):?string
    {
        $stmt=$db->prepare('SELECT role FROM collab_workspace_members WHERE workspace_id=:wid AND user_id=:uid LIMIT 1');
        $stmt->execute([':wid'=>$workspaceId,':uid'=>$userId]);
        $role=$stmt->fetchColumn();
        return $role===false?null:(string)$role;
    }

    public static function requireHost(PDO $db,int $workspaceId,int $userId):array
    {
        $workspace=self::get($db,$workspaceId,$userId);
        if((int)$workspace['host_id']!==$userId)throw new RuntimeException('Only the Coworkspace host can perform this action.',403);
        return $workspace;
    }

    public static function memberCount(PDO $db,int $workspaceId):int
    {
        $stmt=$db->prepare('SELECT COUNT(*) FROM collab_workspace_members WHERE workspace_id=:wid');
        $stmt->execute([':wid'=>$workspaceId]);
        return (int)$stmt->fetchColumn();
    }
}
