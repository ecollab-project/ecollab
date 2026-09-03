<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/database/config/db.php';

class WhiteboardService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Get or create whiteboard state for a channel.
     */
    public function getState(int $channelId, int $userId): array
    {
        $this->verifyAccess($channelId, $userId);

        $stmt = $this->db->prepare("
            SELECT wb.* FROM whiteboards wb
            WHERE wb.channel_id = :cid
            ORDER BY wb.updated_at DESC LIMIT 1
        ");
        $stmt->execute([':cid' => $channelId]);
        $wb = $stmt->fetch();

        if (!$wb) {
            // Auto-create blank whiteboard
            $ins = $this->db->prepare("
                INSERT INTO whiteboards (channel_id, state_json, created_by, created_at, updated_at)
                VALUES (:cid, :state, :uid, NOW(), NOW())
            ");
            $ins->execute([
                ':cid'   => $channelId,
                ':state' => json_encode(['objects' => [], 'background' => '#0b0f1a']),
                ':uid'   => $userId,
            ]);
            $wb = [
                'id'         => (int)$this->db->lastInsertId(),
                'channel_id' => $channelId,
                'state_json' => json_encode(['objects' => [], 'background' => '#0b0f1a']),
                'created_by' => $userId,
                'locked'     => false,
            ];
        }

        $wb['locked'] = (bool)($wb['locked'] ?? false);
        $wb['is_host'] = (int)$wb['created_by'] === $userId;
        return $wb;
    }

    /**
     * Sync / save whiteboard state.
     */
    public function syncState(int $channelId, int $userId, string $stateJson): array
    {
        $this->verifyAccess($channelId, $userId);

        $current = $this->getState($channelId, $userId);
        if (!empty($current['locked']) && (int)$current['created_by'] !== $userId) {
            throw new RuntimeException('Whiteboard is locked by the host', 423);
        }

        // Validate JSON
        $decoded = json_decode($stateJson, true);
        if ($decoded === null) {
            throw new InvalidArgumentException('Invalid whiteboard state JSON', 400);
        }

        $stmt = $this->db->prepare("
            SELECT id FROM whiteboards WHERE channel_id = :cid ORDER BY updated_at DESC LIMIT 1
        ");
        $stmt->execute([':cid' => $channelId]);
        $existing = $stmt->fetch();

        if ($existing) {
            $upd = $this->db->prepare("
                UPDATE whiteboards SET state_json=:state, updated_by=:uid, updated_at=NOW()
                WHERE id=:id
            ");
            $upd->execute([':state' => $stateJson, ':uid' => $userId, ':id' => $existing['id']]);
            $wbId = $existing['id'];
        } else {
            $ins = $this->db->prepare("
                INSERT INTO whiteboards (channel_id, state_json, created_by, created_at, updated_at)
                VALUES (:cid, :state, :uid, NOW(), NOW())
            ");
            $ins->execute([':cid' => $channelId, ':state' => $stateJson, ':uid' => $userId]);
            $wbId = (int)$this->db->lastInsertId();
        }

        return ['id' => $wbId, 'channel_id' => $channelId, 'state_json' => $stateJson];
    }

    public function listVersions(int $channelId, int $userId): array
    {
        $this->verifyAccess($channelId, $userId);
        $stmt = $this->db->prepare('SELECT id, version_no, title, created_by, created_at FROM whiteboard_versions WHERE channel_id=:cid ORDER BY version_no DESC');
        $stmt->execute([':cid' => $channelId]);
        return $stmt->fetchAll();
    }

    public function saveVersion(int $channelId, int $userId, string $title, string $stateJson): array
    {
        $this->verifyAccess($channelId, $userId);
        if (json_decode($stateJson, true) === null) throw new InvalidArgumentException('Invalid whiteboard state JSON', 400);
        $board = $this->getState($channelId, $userId);
        if (!empty($board['locked']) && (int)$board['created_by'] !== $userId) {
            throw new RuntimeException('Whiteboard is locked by the host', 423);
        }
        $next = (int)$this->db->query('SELECT COALESCE(MAX(version_no), 0) + 1 FROM whiteboard_versions WHERE whiteboard_id=' . (int)$board['id'])->fetchColumn();
        $stmt = $this->db->prepare('INSERT INTO whiteboard_versions (whiteboard_id,channel_id,version_no,title,state_json,created_by) VALUES(:wid,:cid,:ver,:title,:state,:uid)');
        $cleanTitle = mb_substr(trim($title) ?: 'Whiteboard Session', 0, 200);
        $stmt->execute([':wid' => $board['id'], ':cid' => $channelId, ':ver' => $next, ':title' => $cleanTitle, ':state' => $stateJson, ':uid' => $userId]);
        $current = $this->db->prepare('UPDATE whiteboards SET state_json=:state, updated_by=:uid, updated_at=NOW() WHERE id=:id');
        $current->execute([':state' => $stateJson, ':uid' => $userId, ':id' => $board['id']]);
        $this->broadcast($channelId, ['type' => 'wb_version_saved', 'channel_id' => $channelId, 'version_no' => $next, 'title' => $cleanTitle, 'user_id' => $userId]);
        return ['id' => (int)$this->db->lastInsertId(), 'version_no' => $next];
    }

    public function getVersion(int $channelId, int $userId, int $versionId): array
    {
        $this->verifyAccess($channelId, $userId);
        $stmt = $this->db->prepare('SELECT id, version_no, title, state_json, created_by, created_at FROM whiteboard_versions WHERE id=:id AND channel_id=:cid LIMIT 1');
        $stmt->execute([':id' => $versionId, ':cid' => $channelId]);
        $version = $stmt->fetch();
        if (!$version) throw new RuntimeException('Whiteboard version not found', 404);
        return $version;
    }

    public function restoreVersion(int $channelId, int $userId, int $versionId): array
    {
        $version = $this->getVersion($channelId, $userId, $versionId);
        $board = $this->getState($channelId, $userId);
        if (!empty($board['locked']) && (int)$board['created_by'] !== $userId) {
            throw new RuntimeException('Whiteboard is locked by the host', 423);
        }
        $stmt = $this->db->prepare('UPDATE whiteboards SET state_json=:state, updated_by=:uid, updated_at=NOW() WHERE id=:id');
        $stmt->execute([':state' => $version['state_json'], ':uid' => $userId, ':id' => $board['id']]);
        $this->broadcast($channelId, ['type' => 'wb_state_reverted', 'channel_id' => $channelId, 'version_id' => $versionId, 'version_no' => $version['version_no'], 'state_json' => $version['state_json'], 'user_id' => $userId]);
        return ['id' => (int)$board['id'], 'channel_id' => $channelId, 'state_json' => $version['state_json'], 'version_no' => (int)$version['version_no']];
    }

    public function setLocked(int $channelId, int $userId, bool $locked): array
    {
        $this->verifyAccess($channelId, $userId);
        $board = $this->getState($channelId, $userId);
        if ((int)$board['created_by'] !== $userId) throw new RuntimeException('Only the host can lock the whiteboard', 403);
        $stmt = $this->db->prepare('UPDATE whiteboards SET locked=:locked, locked_by=IF(:locked2=1,:uid,NULL), locked_at=IF(:locked3=1,NOW(),NULL) WHERE id=:id');
        $value = $locked ? 1 : 0;
        $stmt->execute([':locked' => $value, ':locked2' => $value, ':locked3' => $value, ':uid' => $userId, ':id' => $board['id']]);
        $this->broadcast($channelId, ['type' => 'wb_lock_changed', 'channel_id' => $channelId, 'locked' => $locked, 'user_id' => $userId]);
        return ['locked' => $locked, 'locked_by' => $locked ? $userId : null];
    }

    private function broadcast(int $channelId, array $payload): void
    {
        try {
            $stmt = $this->db->prepare('INSERT INTO ws_relay (channel_id,payload,created_at) VALUES(:cid,:payload,NOW())');
            $stmt->execute([':cid' => $channelId, ':payload' => json_encode($payload)]);
        } catch (Throwable) {
            // Relay delivery is optional; the database write remains authoritative.
        }
    }

    private function verifyAccess(int $channelId, int $userId): void
    {
        $stmt = $this->db->prepare("
            SELECT 1 FROM channels c
            JOIN server_members sm ON sm.server_id = c.server_id AND sm.user_id = :uid
            LEFT JOIN channel_members cm ON cm.channel_id = c.id AND cm.user_id = :member_uid
            WHERE c.id = :cid AND (COALESCE(c.is_private, 0) = 0 OR cm.user_id IS NOT NULL)
            LIMIT 1
        ");
        $stmt->execute([':uid' => $userId, ':member_uid' => $userId, ':cid' => $channelId]);
        if (!$stmt->fetch()) {
            throw new RuntimeException('Access denied', 403);
        }
    }
}
