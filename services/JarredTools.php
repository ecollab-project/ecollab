<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/services/ChannelService.php';

/**
 * JarredTools
 *
 * Read-only eCollab tools for the Jarred system assistant.
 * Every query is scoped to the human requester. Jarred never inherits admin
 * permissions and this class intentionally exposes no destructive operations.
 */
final class JarredTools
{
    private PDO $db;
    private ChannelService $channels;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->channels = new ChannelService();
    }

    public function contextForPrompt(int $requesterId, string $prompt): string
    {
        $prompt = trim($prompt);
        if ($prompt === '') return '';

        $parts = [];

        if (preg_match('/\b(online|who(?:\'s| is) (?:here|online)|active users?|members? online)\b/i', $prompt)) {
            $serverId = $this->resolveServerId($requesterId, $prompt);
            if ($serverId) {
                $members = $this->onlineMembers($requesterId, $serverId);
                $parts[] = "LIVE ECOLLAB PRESENCE (server_id={$serverId}):\n" .
                    ($members ? json_encode($members, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'No members are currently online.');
            } else {
                $parts[] = 'LIVE ECOLLAB PRESENCE: The requester did not specify a server and no server could be resolved. Ask which server they mean.';
            }
        }

        if (preg_match('/\b(find|search|look for|where|thread|message|said|mentioned|discussed|conversation|chat)\b/i', $prompt)) {
            $query = $this->extractSearchQuery($prompt);
            if ($query !== '') {
                $matches = $this->searchMessages($requesterId, $query, 12);
                $parts[] = "ECOLLAB MESSAGE/THREAD SEARCH for " . json_encode($query) . ":\n" .
                    ($matches ? json_encode($matches, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'No accessible matching messages were found.');
            }
        }

        if (preg_match('/\b(server|servers|channel|channels)\b/i', $prompt)) {
            $parts[] = "REQUESTER ACCESSIBLE SERVERS/CHANNELS:\n" .
                json_encode($this->accessibleServersAndChannels($requesterId), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return implode("\n\n", $parts);
    }

    public function onlineMembers(int $requesterId, int $serverId): array
    {
        if (!$this->canAccessServer($requesterId, $serverId)) return [];

        $stmt = $this->db->prepare(
            "SELECT u.id, u.username, u.full_name, u.role, u.is_online,
                    u.last_active_at, sm.server_role, sm.nickname
             FROM users u
             JOIN server_members sm ON sm.user_id = u.id
             WHERE sm.server_id = :sid
               AND u.deleted_at IS NULL
               AND COALESCE(u.is_system, 0) = 0
               AND u.status NOT IN ('banned','suspended','deactivated')
               AND (u.is_online = 1 OR u.last_active_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE))
             ORDER BY u.is_online DESC, u.full_name ASC"
        );
        $stmt->execute([':sid' => $serverId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function searchMessages(int $requesterId, string $query, int $limit = 12): array
    {
        $query = trim($query);
        if ($query === '') return [];
        $limit = max(1, min(25, $limit));

        $sql = "
            SELECT m.id, m.channel_id, m.body, m.created_at,
                   u.username, u.full_name,
                   c.name AS channel_name, c.server_id,
                   s.name AS server_name,
                   m.parent_message_id
            FROM messages m
            JOIN users u ON u.id = m.user_id
            JOIN channels c ON c.id = m.channel_id
            JOIN servers s ON s.id = c.server_id
            JOIN server_members sm ON sm.server_id = c.server_id AND sm.user_id = :uid
            WHERE m.is_deleted = 0
              AND m.body LIKE :q
              AND (
                    c.is_private = 0
                    OR EXISTS (
                        SELECT 1 FROM channel_members cm
                        WHERE cm.channel_id = c.id AND cm.user_id = :uid2
                    )
                    OR sm.server_role IN ('owner','admin','moderator')
                    OR c.created_by = :uid3
              )
            ORDER BY m.created_at DESC
            LIMIT {$limit}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':uid' => $requesterId,
            ':uid2' => $requesterId,
            ':uid3' => $requesterId,
            ':q' => '%' . $query . '%',
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function accessibleServersAndChannels(int $requesterId): array
    {
        $result = [];
        foreach ($this->channels->getServersForUser($requesterId) as $server) {
            $sid = (int)$server['id'];
            $result[] = [
                'server' => [
                    'id' => $sid,
                    'name' => $server['name'],
                    'server_role' => $server['server_role'] ?? 'member',
                ],
                'channels' => array_map(
                    static fn(array $c): array => [
                        'id' => (int)$c['id'],
                        'name' => $c['name'],
                        'type' => $c['type'],
                        'is_private' => (int)($c['is_private'] ?? 0),
                    ],
                    $this->channels->getChannelsForUser($sid, $requesterId)
                ),
            ];
        }
        return $result;
    }

    private function canAccessServer(int $requesterId, int $serverId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM server_members WHERE server_id = :sid AND user_id = :uid LIMIT 1'
        );
        $stmt->execute([':sid' => $serverId, ':uid' => $requesterId]);
        return (bool)$stmt->fetchColumn();
    }

    private function resolveServerId(int $requesterId, string $prompt): ?int
    {
        if (preg_match('/server(?:_id)?\s*[:#]?\s*(\d+)/i', $prompt, $m)) {
            $id = (int)$m[1];
            return $this->canAccessServer($requesterId, $id) ? $id : null;
        }

        $servers = $this->channels->getServersForUser($requesterId);
        if (count($servers) === 1) return (int)$servers[0]['id'];

        foreach ($servers as $server) {
            $name = trim((string)($server['name'] ?? ''));
            if ($name !== '' && stripos($prompt, $name) !== false) return (int)$server['id'];
        }

        return null;
    }

    private function extractSearchQuery(string $prompt): string
    {
        if (preg_match('/["“](.+?)["”]/u', $prompt, $m)) return mb_substr(trim($m[1]), 0, 120);

        $clean = preg_replace(
            '/\b(jarred|please|can you|could you|find|search|look for|where|the|thread|threads|message|messages|chat|conversation|that|about|for|in|our|we|discussed|said|mentioned)\b/i',
            ' ',
            $prompt
        );
        $clean = trim(preg_replace('/\s+/', ' ', (string)$clean));
        return mb_substr($clean, 0, 120);
    }
}
