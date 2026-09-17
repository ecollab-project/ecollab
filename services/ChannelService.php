<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/database/config/db.php';

class ChannelService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Get all channels for a server the user is a member of.
     */
    public function getChannelsForUser(int $serverId, int $userId): array
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS channel_seen (
                    channel_id  INT UNSIGNED    NOT NULL,
                    user_id     BIGINT UNSIGNED NOT NULL,
                    first_seen_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (channel_id, user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Throwable $e) {
            // Non-fatal — will just show all channels without new-badge
        }

        $roleStmt = $this->db->prepare("SELECT role FROM users WHERE id = :uid LIMIT 1");
        $roleStmt->execute([':uid' => $userId]);
        $role = $roleStmt->fetchColumn() ?: 'student';

        if (in_array($role, ['admin', 'super_admin', 'moderator'], true)) {
            $stmt = $this->db->prepare("
                SELECT c.id, c.name, c.slug, c.type, c.description, c.position,
                       c.is_private, c.is_locked, c.member_count, c.created_at,
                       0 AS unread_count,
                       CASE WHEN cs.channel_id IS NULL THEN 1 ELSE 0 END AS is_new
                FROM channels c
                LEFT JOIN channel_seen cs ON cs.channel_id = c.id AND cs.user_id = :uid_seen
                WHERE c.server_id = :server_id
                ORDER BY c.position ASC, c.created_at ASC
            ");
            $stmt->execute([':server_id' => $serverId, ':uid_seen' => $userId]);
        } else {
            $stmt = $this->db->prepare("
                SELECT c.id, c.name, c.slug, c.type, c.description, c.position,
                       c.is_private, c.is_locked, c.member_count, c.created_at,
                       (
                           SELECT COUNT(*) FROM messages m
                           WHERE m.channel_id = c.id
                             AND m.is_deleted = 0
                             AND m.created_at > COALESCE((
                                 SELECT mr.last_read_at FROM message_reads mr
                                 WHERE mr.user_id = :uid AND mr.channel_id = c.id
                             ), '1970-01-01')
                       ) AS unread_count,
                       CASE WHEN cs.channel_id IS NULL THEN 1 ELSE 0 END AS is_new
                FROM channels c
                JOIN server_members sm ON sm.server_id = c.server_id AND sm.user_id = :uid2
                LEFT JOIN channel_seen cs ON cs.channel_id = c.id AND cs.user_id = :uid4
                WHERE c.server_id = :server_id
                  AND (
                      c.is_private = 0
                      OR EXISTS (
                          SELECT 1 FROM channel_members cm
                          WHERE cm.channel_id = c.id AND cm.user_id = :uid3
                      )
                      OR sm.server_role IN ('owner','admin','moderator')
                      OR c.created_by = :uid5
                  )
                ORDER BY c.position ASC, c.created_at ASC
            ");
            $stmt->execute([
                ':uid'       => $userId,
                ':uid2'      => $userId,
                ':uid3'      => $userId,
                ':uid4'      => $userId,
                ':uid5'      => $userId,
                ':server_id' => $serverId,
            ]);
        }
        return $stmt->fetchAll();
    }

    /**
     * Get a single channel by ID, verifying server membership.
     */
    public function getChannel(int $channelId, int $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT c.*, s.name AS server_name
            FROM channels c
            JOIN servers s ON s.id = c.server_id
            JOIN server_members sm ON sm.server_id = c.server_id AND sm.user_id = :uid
            WHERE c.id = :channel_id
        ");
        $stmt->execute([':uid' => $userId, ':channel_id' => $channelId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Create a new channel in a server (owner/admin/moderator only).
     */
    public function createChannel(int $serverId, int $userId, array $data): array
    {
        $stmt = $this->db->prepare("
            SELECT server_role FROM server_members
            WHERE server_id = :sid AND user_id = :uid
        ");
        $stmt->execute([':sid' => $serverId, ':uid' => $userId]);
        $member = $stmt->fetch();
        if (!$member || !in_array($member['server_role'], ['owner', 'admin', 'moderator'], true)) {
            throw new RuntimeException('Insufficient permissions to create channels', 403);
        }

        $name = trim($data['name'] ?? '');
        if ($name === '') {
            throw new InvalidArgumentException('Channel name is required', 400);
        }
        $baseSlug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
        if ($baseSlug === '') $baseSlug = 'channel';
        $slug = substr($baseSlug, 0, 60);
        $suffix = 2;
        while (true) {
            $chkStmt = $this->db->prepare("SELECT 1 FROM channels WHERE server_id = :sid AND slug = :slug LIMIT 1");
            $chkStmt->execute([':sid' => $serverId, ':slug' => $slug]);
            if (!$chkStmt->fetchColumn()) break;
            $tail = '-' . $suffix++;
            $slug = substr($baseSlug, 0, max(1, 60 - strlen($tail))) . $tail;
        }
        $type = in_array($data['type'] ?? 'text', ['text', 'voice', 'announcement', 'whiteboard', 'study_room'], true)
            ? $data['type'] : 'text';
        $isPrivate = !empty($data['is_private']) ? 1 : 0;

        $posStmt = $this->db->prepare("SELECT COALESCE(MAX(position),0)+1 AS pos FROM channels WHERE server_id=:sid");
        $posStmt->execute([':sid' => $serverId]);
        $pos = (int)$posStmt->fetchColumn();

        $ins = $this->db->prepare("
            INSERT INTO channels (server_id, name, slug, type, description, position, is_private, created_by)
            VALUES (:sid, :name, :slug, :type, :desc, :pos, :priv, :uid)
        ");
        $ins->execute([
            ':sid'  => $serverId,
            ':name' => $name,
            ':slug' => $slug,
            ':type' => $type,
            ':desc' => trim($data['description'] ?? ''),
            ':pos'  => $pos,
            ':priv' => $isPrivate,
            ':uid'  => $userId,
        ]);
        $id = (int)$this->db->lastInsertId();

        if ($isPrivate) {
            // The creator is always a member of their own private channel.
            $this->db->prepare('INSERT IGNORE INTO channel_members(channel_id, user_id) VALUES(?, ?)')
                ->execute([$id, $userId]);
        }

        return $this->getChannel($id, $userId) ?? [];
    }

    /**
     * Get all servers the user is a member of.
     */
    public function getServersForUser(int $userId): array
    {
        $roleStmt = $this->db->prepare("SELECT role FROM users WHERE id = :uid LIMIT 1");
        $roleStmt->execute([':uid' => $userId]);
        $role = $roleStmt->fetchColumn() ?: 'student';

        if (in_array($role, ['admin', 'super_admin', 'moderator'], true)) {
            $stmt = $this->db->prepare("
                SELECT s.id, s.name, s.slug, s.icon_emoji, s.icon_url,
                       s.category, s.type, s.member_count, 'admin' AS server_role
                FROM servers s
                WHERE s.status = 'active'
                ORDER BY s.created_at ASC
            ");
            $stmt->execute();
        } else {
            $stmt = $this->db->prepare("
                SELECT s.id, s.name, s.slug, s.icon_emoji, s.icon_url,
                       s.category, s.type, s.member_count, sm.server_role
                FROM servers s
                JOIN server_members sm ON sm.server_id = s.id AND sm.user_id = :uid
                WHERE s.status = 'active'
                ORDER BY sm.joined_at ASC
            ");
            $stmt->execute([':uid' => $userId]);
        }
        return $stmt->fetchAll();
    }

    /**
     * Get online members for a server.
     */
    public function getOnlineMembers(int $serverId): array
    {
        $stmt = $this->db->prepare("
            SELECT u.id, u.username, u.full_name, u.avatar_url,
                   u.avatar_color_gradient, u.status, u.is_online, u.role,
                   sm.server_role, sm.nickname
            FROM users u
            JOIN server_members sm ON sm.user_id = u.id AND sm.server_id = :sid
            WHERE u.is_online = 1 AND u.status NOT IN ('banned','suspended','deactivated')
            ORDER BY sm.server_role ASC, u.full_name ASC
        ");
        $stmt->execute([':sid' => $serverId]);
        return $stmt->fetchAll();
    }

    /**
     * Mark messages in a channel as read for a user.
     */
    public function markRead(int $channelId, int $userId): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO message_reads (user_id, channel_id, last_read_at)
            VALUES (:uid, :cid, NOW())
            ON DUPLICATE KEY UPDATE last_read_at = NOW()
        ");
        $stmt->execute([':uid' => $userId, ':cid' => $channelId]);
    }
}
