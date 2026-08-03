<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database/config/db.php';

/**
 * PresenceHandler — Tracks online status and broadcasts presence events.
 */
class PresenceHandler {
    private PDO $db;

    /** @var array<int, int[]> serverId => [userId, ...] */
    private array $onlinePerServer = [];

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function userConnected(int $userId): void {
        $this->db->prepare("UPDATE users SET is_online=1, last_active_at=NOW() WHERE id=:id")
            ->execute([':id' => $userId]);
    }

    public function userDisconnected(int $userId): void {
        $this->db->prepare("UPDATE users SET is_online=0, last_active_at=NOW() WHERE id=:id")
            ->execute([':id' => $userId]);
    }

    public function heartbeat(int $userId): void {
        $this->db->prepare("UPDATE users SET last_active_at=NOW() WHERE id=:id")
            ->execute([':id' => $userId]);
    }

    public function getOnlineUsers(int $serverId): array {
        $stmt = $this->db->prepare("
            SELECT u.id, u.username, u.full_name, u.avatar_color_gradient, u.role
            FROM users u
            JOIN server_members sm ON sm.user_id = u.id AND sm.server_id = :sid
            WHERE u.is_online = 1
        ");
        $stmt->execute([':sid' => $serverId]);
        return $stmt->fetchAll();
    }
}
