<?php
declare(strict_types=1);

/** Current database policy, never session-role or client-asserted authorization. */
final class ChannelAccessService
{
    public function __construct(private PDO $db) {}

    private function channels(array $ids, int $userId, bool $voice): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
        if (!$ids || $userId <= 0) return [];
        if (count($ids) > 100) throw new InvalidArgumentException('Too many channels.');
        $in = implode(',', array_fill(0, count($ids), '?'));
        // A server suspension is view-only; a chat mute does not remove voice access.
        $blocked = $voice ? "'ban','suspend'" : "'ban'";
        $sql = "SELECT c.* FROM channels c JOIN servers s ON s.id=c.server_id
            JOIN users u ON u.id=? AND u.deleted_at IS NULL AND u.status IN ('active','offline','idle')
            LEFT JOIN server_members sm ON sm.server_id=c.server_id AND sm.user_id=u.id
            WHERE c.id IN ($in) AND s.status='active'
            AND (u.role IN ('admin','super_admin') OR
                (sm.user_id IS NOT NULL AND (c.is_private=0 OR sm.server_role IN ('owner','admin')
                 OR c.created_by=u.id OR EXISTS(SELECT 1 FROM channel_members cm WHERE cm.channel_id=c.id AND cm.user_id=u.id))))
            AND NOT EXISTS(SELECT 1 FROM moderation_actions ma WHERE ma.target_user_id=u.id
                AND (ma.server_id=c.server_id OR ma.server_id IS NULL) AND ma.is_active=1
                AND ma.action_type IN ($blocked) AND (ma.expires_at IS NULL OR ma.expires_at>CURRENT_TIMESTAMP))";
        if ($voice) $sql .= " AND c.type='voice' AND (c.is_locked=0 OR u.role IN ('admin','super_admin'))";
        $q = $this->db->prepare($sql); $q->execute([$userId, ...$ids]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $out[(int)$row['id']] = $row;
        return $out;
    }

    public function read(int $channelId, int $userId): array
    {
        return $this->channels([$channelId], $userId, false)[$channelId]
            ?? throw new RuntimeException('Channel access denied', 403);
    }

    public function voice(int $channelId, int $userId): array
    {
        return $this->voiceChannels([$channelId], $userId)[$channelId]
            ?? throw new RuntimeException('Voice channel access denied', 403);
    }

    public function voiceChannels(array $ids, int $userId): array
    {
        return $this->channels($ids, $userId, true);
    }
}
