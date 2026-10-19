<?php
declare(strict_types=1);

/** Shared SQL for production channel reads and the read-only profiler. */
final class ChannelReadQueries
{
    public static function channels(int $serverId,int $userId,bool $admin):array
    {
        $columns='c.id,c.name,c.slug,c.type,c.description,c.position,c.is_private,c.is_locked,c.member_count,c.created_at';
        if($admin)return ["SELECT $columns,0 AS unread_count,
            CASE WHEN cs.channel_id IS NULL THEN 1 ELSE 0 END AS is_new
            FROM channels c LEFT JOIN channel_seen cs ON cs.channel_id=c.id AND cs.user_id=:seen
            WHERE c.server_id=:sid ORDER BY c.position ASC,c.created_at ASC",[':seen'=>$userId,':sid'=>$serverId]];
        return ["SELECT $columns,0 AS unread_count,
            CASE WHEN cs.channel_id IS NULL THEN 1 ELSE 0 END AS is_new,
            mr.last_read_at AS _unread_since
            FROM channels c
            JOIN server_members sm ON sm.server_id=c.server_id AND sm.user_id=:member
            LEFT JOIN channel_seen cs ON cs.channel_id=c.id AND cs.user_id=:seen
            LEFT JOIN message_reads mr ON mr.channel_id=c.id AND mr.user_id=:reader
            WHERE c.server_id=:sid AND (c.is_private=0
                OR EXISTS(SELECT 1 FROM channel_members cm WHERE cm.channel_id=c.id AND cm.user_id=:private_member)
                OR sm.server_role IN ('owner','admin') OR c.created_by=:creator)
            ORDER BY c.position ASC,c.created_at ASC",[
                ':member'=>$userId,':seen'=>$userId,':reader'=>$userId,':sid'=>$serverId,
                ':private_member'=>$userId,':creator'=>$userId]];
    }

    /** Literal bound cutoffs permit an index range per channel, without a per-message read-marker subquery. */
    public static function unreadBatches(array $channels):array
    {
        $batches=[];
        foreach(array_chunk($channels,50)as$batch){
            $parts=[];$params=[];
            foreach($batch as$c){
                $parts[]='SELECT ? AS channel_id,COUNT(*) AS unread_count FROM messages WHERE channel_id=? AND is_deleted=0 AND created_at>?';
                array_push($params,(int)$c['id'],(int)$c['id'],$c['_unread_since']??'1970-01-01');
            }
            $batches[]=[implode(' UNION ALL ',$parts),$params];
        }
        return $batches;
    }

    /** Caller passes only freshly authorized channels; never cache membership or read markers. */
    public static function withUnread(PDO $db,array $channels):array
    {
        $counts=[];
        foreach(self::unreadBatches($channels)as[$sql,$params]){
            $stmt=$db->prepare($sql);$stmt->execute($params);
            foreach($stmt->fetchAll(PDO::FETCH_ASSOC)as$r)$counts[(int)$r['channel_id']]=(int)$r['unread_count'];
        }
        foreach($channels as&$c){$c['unread_count']=$counts[(int)$c['id']]??0;unset($c['_unread_since']);}
        unset($c);return $channels;
    }

    public static function servers(int $userId,bool $admin):array
    {
        if($admin)return ["SELECT s.id,s.name,s.slug,s.icon_emoji,s.icon_url,s.category,s.type,s.member_count,'admin' AS server_role
            FROM servers s WHERE s.status='active' ORDER BY s.created_at ASC",[]];
        return ["SELECT s.id,s.name,s.slug,s.icon_emoji,s.icon_url,s.category,s.type,s.member_count,sm.server_role
            FROM servers s JOIN server_members sm ON sm.server_id=s.id AND sm.user_id=:uid
            WHERE s.status='active' ORDER BY sm.joined_at ASC",[':uid'=>$userId]];
    }
}
