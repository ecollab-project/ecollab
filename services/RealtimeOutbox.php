<?php
declare(strict_types=1);

final class RealtimeOutbox
{
    public static function enabled(): bool { return filter_var(env('CENTRIFUGO_ENABLED','false'), FILTER_VALIDATE_BOOLEAN); }

    /** Save message and delivery intent together. No network calls in a chat request. */
    public static function record($db, string $kind, int $target, callable $save): int
    {
        if (!self::enabled()) return (int)$save();
        if (!in_array($kind,['channel','dm','group'],true) || $target < 1) throw new InvalidArgumentException('Invalid delivery target');
        $owns = !$db->inTransaction();
        if ($owns) $db->beginTransaction();
        try {
            $id = (int)$save();
            $stmt = $db->prepare('INSERT INTO realtime_outbox (kind,target_id,message_id) VALUES (:kind,:target,:mid)');
            $stmt->execute([':kind'=>$kind,':target'=>$target,':mid'=>$id]);
            if ($owns) $db->commit();
            return $id;
        } catch (Throwable $e) {
            if ($owns && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /** Audience is recalculated at delivery; no message body or attachment URL enters broker history. */
    public static function recipients($db, array $event): array
    {
        $target=(int)$event['target_id'];$id=(int)$event['message_id'];
        if ($event['kind']==='channel') {
            $sql="SELECT DISTINCT u.id FROM messages m JOIN channels c ON c.id=m.channel_id
                JOIN users u ON u.deleted_at IS NULL
                LEFT JOIN server_members sm ON sm.server_id=c.server_id AND sm.user_id=u.id
                WHERE m.id=:mid AND c.id=:target AND m.is_deleted=0 AND
                (u.role IN ('admin','super_admin') OR (sm.user_id IS NOT NULL AND
                (c.is_private=0 OR sm.server_role IN ('owner','admin') OR c.created_by=u.id OR
                EXISTS(SELECT 1 FROM channel_members cm WHERE cm.channel_id=c.id AND cm.user_id=u.id))))";
        } elseif ($event['kind']==='dm') {
            $sql='SELECT DISTINCT u.id FROM dm_messages m JOIN dm_conversations c ON c.id=m.conversation_id
                JOIN users u ON (u.id=c.user_a OR u.id=c.user_b) AND u.deleted_at IS NULL
                WHERE m.id=:mid AND c.id=:target AND m.is_deleted=0';
        } elseif ($event['kind']==='group') {
            $sql='SELECT DISTINCT u.id FROM dm_messages m JOIN dm_group_members gm ON gm.group_id=m.group_id
                JOIN users u ON u.id=gm.user_id AND u.deleted_at IS NULL
                WHERE m.id=:mid AND gm.group_id=:target AND m.is_deleted=0';
        } else { throw new InvalidArgumentException('Unknown delivery kind'); }
        $stmt=$db->prepare($sql);$stmt->execute([':mid'=>$id,':target'=>$target]);
        return array_values(array_unique(array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN))));
    }

    public static function dispatch($db, CentrifugoService $broker): int
    {
        $rows=$db->query('SELECT * FROM realtime_outbox WHERE published_at IS NULL AND available_at<=NOW() ORDER BY id LIMIT 25')->fetchAll(PDO::FETCH_ASSOC);
        $sent=0;
        foreach($rows as $event){
            try {
                $users=self::recipients($db,$event);
                if($users)$broker->broadcast($users,['type'=>'chat_changed','kind'=>$event['kind'],
                    'target_id'=>(int)$event['target_id'],'message_id'=>(int)$event['message_id'],'event_id'=>(int)$event['id']]);
                $db->prepare('UPDATE realtime_outbox SET published_at=NOW(),last_error=NULL WHERE id=?')->execute([$event['id']]);
                $sent++;
            } catch(Throwable $e) {
                $delay=min(60,2 ** min(6,(int)$event['attempts']));
                $db->prepare("UPDATE realtime_outbox SET attempts=attempts+1,available_at=DATE_ADD(NOW(),INTERVAL $delay SECOND),last_error='Delivery unavailable' WHERE id=?")->execute([$event['id']]);
                error_log('[Realtime delivery] Event '.(int)$event['id'].' deferred; retry in '.$delay.' seconds');
            }
        }
        return $sent;
    }
}
