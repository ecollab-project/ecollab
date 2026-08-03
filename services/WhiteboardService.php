<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/database/config/db.php';

class WhiteboardService {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Get or create whiteboard state for a channel.
     */
    public function getState(int $channelId, int $userId): array {
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
            ];
        }

        return $wb;
    }

    /**
     * Sync / save whiteboard state.
     */
    public function syncState(int $channelId, int $userId, string $stateJson): array {
        $this->verifyAccess($channelId, $userId);

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

    private function verifyAccess(int $channelId, int $userId): void {
        $stmt = $this->db->prepare("
            SELECT 1 FROM channels c
            JOIN server_members sm ON sm.server_id = c.server_id AND sm.user_id = :uid
            WHERE c.id = :cid LIMIT 1
        ");
        $stmt->execute([':uid' => $userId, ':cid' => $channelId]);
        if (!$stmt->fetch()) {
            throw new RuntimeException('Access denied', 403);
        }
    }
}
