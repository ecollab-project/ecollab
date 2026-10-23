<?php
declare(strict_types=1);

require_once __DIR__.'/AccessPolicy.php';

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

    public function readers(int $channelId, array $userIds): array
    {
        $out=[];
        foreach(array_chunk(array_values(array_unique(array_map('intval',$userIds))),100) as $batch){
            if(!$batch)continue;
            $in=implode(',',array_fill(0,count($batch),'?'));
            $q=$this->db->prepare("SELECT u.id FROM channels c JOIN servers s ON s.id=c.server_id
                JOIN users u ON u.id IN ($in) LEFT JOIN server_members sm ON sm.server_id=s.id AND sm.user_id=u.id
                WHERE c.id=? AND s.status='active' AND ".AccessPolicy::accountSql()." AND
                (u.role IN ('admin','super_admin') OR (sm.user_id IS NOT NULL AND (c.is_private=0 OR
                sm.server_role IN ('owner','admin') OR c.created_by=u.id OR EXISTS(SELECT 1 FROM channel_members cm WHERE cm.channel_id=c.id AND cm.user_id=u.id))))
                AND ".AccessPolicy::moderationSql('u','s'));
            $q->execute([...$batch,$channelId]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id)$out[(int)$id]=true;
        }
        return $out;
    }

    public function write(int $channelId, int $userId): array
    {
        $channel=$this->read($channelId,$userId);
        if (!(new AccessPolicy($this->db))->server($userId,(int)$channel['server_id'],'write',true))
            throw new RuntimeException('Channel write access denied',403);
        if (!empty($channel['is_locked'])) {
            $q=$this->db->prepare('SELECT role FROM users WHERE id=?');$q->execute([$userId]);
            if (!in_array($q->fetchColumn(),['admin','super_admin'],true)) throw new RuntimeException('Channel locked',403);
        }
        return $channel;
    }

    public function voiceChannels(array $ids, int $userId): array
    {
        return $this->channels($ids, $userId, true);
    }
}
