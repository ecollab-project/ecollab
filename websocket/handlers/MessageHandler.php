<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database/config/db.php';

/**
 * MessageHandler — Processes and persists messages received via WebSocket.
 * Used by ChatServer to delegate message-specific logic.
 */
class MessageHandler {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Validate and persist an incoming message, return the full message row.
     */
    public function process(int $channelId, int $senderId, array $data): ?array {
        $content     = trim($data['content'] ?? '');
        $contentType = in_array($data['content_type'] ?? 'text', ['text','image','file','code'], true)
            ? $data['content_type'] : 'text';
        $parentId    = isset($data['parent_id']) ? (int)$data['parent_id'] : null;

        if ($content === '') return null;

        // Verify access
        $access = $this->db->prepare("
            SELECT 1 FROM channels c
            JOIN server_members sm ON sm.server_id = c.server_id AND sm.user_id = :uid
            WHERE c.id = :cid AND c.is_locked = 0 LIMIT 1
        ");
        $access->execute([':uid' => $senderId, ':cid' => $channelId]);
        if (!$access->fetch()) return null;

        $stmt = $this->db->prepare("
            INSERT INTO messages (channel_id, sender_id, content, content_type, parent_id, created_at, updated_at)
            VALUES (:cid, :uid, :content, :type, :parent, NOW(), NOW())
        ");
        $stmt->execute([
            ':cid'     => $channelId,
            ':uid'     => $senderId,
            ':content' => $content,
            ':type'    => $contentType,
            ':parent'  => $parentId,
        ]);
        $id = (int)$this->db->lastInsertId();

        return $this->fetchFullMessage($id);
    }

    private function fetchFullMessage(int $id): ?array {
        $stmt = $this->db->prepare("
            SELECT m.*, u.username, u.full_name, u.avatar_color_gradient, u.role, u.is_verified
            FROM messages m
            JOIN users u ON u.id = m.sender_id
            WHERE m.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $msg = $stmt->fetch();
        if (!$msg) return null;
        $msg['reactions']   = [];
        $msg['attachments'] = [];
        return $msg;
    }
}
