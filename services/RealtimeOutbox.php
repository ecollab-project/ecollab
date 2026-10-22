<?php
declare(strict_types=1);
require_once __DIR__.'/RealtimeDispatchMetrics.php';
require_once __DIR__.'/AccessPolicy.php';

final class RealtimeOutbox
{
    public static function enabled(): bool { return filter_var(function_exists('env') ? env('CENTRIFUGO_ENABLED','false') : (getenv('CENTRIFUGO_ENABLED') ?: 'false'), FILTER_VALIDATE_BOOLEAN); }

    /** Save message and delivery intent together. No network calls in a chat request. */
    public static function record($db, string $kind, int $target, callable $save): int
    {
        if (!self::enabled()) return (int)$save();
        if (!in_array($kind,['channel','dm','group','code_lab'],true) || $target < 1) throw new InvalidArgumentException('Invalid delivery target');
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

    /** Replace an obsolete job-state hint with a new event ID; readers fetch authoritative state. */
    public static function codeLabState(PDO $db,int $serverId,int $jobId): void
    {
        if (!self::enabled()) return;
        if (!$db->inTransaction()) throw new LogicException('Job state delivery requires its transaction');
        $db->prepare("DELETE FROM realtime_outbox WHERE kind='code_lab' AND message_id=?")->execute([$jobId]);
        self::record($db,'code_lab',$serverId,fn()=>$jobId);
    }

    /** Audience is recalculated at delivery; no message body or attachment URL enters broker history. */
    public static function recipients($db, array $event): array
    {
        $target=(int)$event['target_id'];$id=(int)$event['message_id'];
        if ($event['kind']==='channel') {
            $sql="SELECT u.id FROM messages m JOIN channels c ON c.id=m.channel_id
                JOIN servers s ON s.id=c.server_id AND s.status='active'
                JOIN server_members sm ON sm.server_id=c.server_id
                JOIN users u ON u.id=sm.user_id AND u.deleted_at IS NULL
                WHERE m.id=:mid AND c.id=:target AND m.is_deleted=0 AND
                (c.is_private=0 OR sm.server_role IN ('owner','admin') OR c.created_by=u.id OR
                EXISTS(SELECT 1 FROM channel_members cm WHERE cm.channel_id=c.id AND cm.user_id=u.id))
                UNION
                SELECT u.id FROM users u
                WHERE u.role IN ('admin','super_admin') AND u.deleted_at IS NULL
                AND EXISTS(SELECT 1 FROM messages m JOIN channels c ON c.id=m.channel_id
                    WHERE m.id=:admin_mid AND c.id=:admin_target AND m.is_deleted=0)";
        } elseif ($event['kind']==='dm') {
            $sql='SELECT DISTINCT u.id FROM dm_messages m JOIN dm_conversations c ON c.id=m.conversation_id
                JOIN users u ON (u.id=c.user_a OR u.id=c.user_b) AND u.deleted_at IS NULL
                WHERE m.id=:mid AND c.id=:target AND m.is_deleted=0';
        } elseif ($event['kind']==='group') {
            $sql='SELECT DISTINCT u.id FROM dm_messages m JOIN dm_group_members gm ON gm.group_id=m.group_id
                JOIN users u ON u.id=gm.user_id AND u.deleted_at IS NULL
                WHERE m.id=:mid AND gm.group_id=:target AND m.is_deleted=0';
        } elseif ($event['kind']==='code_lab') {
            $sql="SELECT u.id FROM code_lab_jobs j JOIN users u ON u.id=j.user_id AND u.deleted_at IS NULL
                JOIN server_members sm ON sm.user_id=u.id AND sm.server_id=j.server_id
                JOIN servers s ON s.id=j.server_id AND s.status='active'
                WHERE u.status NOT IN ('banned','suspended','deactivated') AND j.id=:mid AND j.server_id=:target AND j.state IN ('done','failed')";
        } else { throw new InvalidArgumentException('Unknown delivery kind'); }
        $account=AccessPolicy::accountSql('recipient');
        // Wrap UNION too: blocked platform administrators must not bypass account policy.
        $sql="SELECT eligible.id FROM ($sql) eligible JOIN users recipient ON recipient.id=eligible.id WHERE $account";
        if (in_array($event['kind'],['channel','code_lab'],true)) {
            $server=$event['kind']==='channel' ? '(SELECT server_id FROM channels WHERE id=:policy_target)' : ':policy_target';
            $sql.=" AND EXISTS(SELECT 1 FROM servers policy_server WHERE policy_server.id=$server AND policy_server.status='active' AND ".AccessPolicy::moderationSql('recipient','policy_server').')';
        }
        $params=[':mid'=>$id,':target'=>$target];
        if(in_array($event['kind'],['channel','code_lab'],true))$params[':policy_target']=$target;
        if($event['kind']==='channel')$params+=[':admin_mid'=>$id,':admin_target'=>$target];
        $stmt=$db->prepare($sql);$stmt->execute($params);
        return array_values(array_unique(array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN))));
    }

    public static function dispatch($db, CentrifugoService $broker,?RealtimeDispatchMetrics $metrics=null): int
    {
        $timed=static fn(string $phase,callable $work)=>$metrics?$metrics->measure($phase,$work):$work();
        $rows=$timed('select',fn()=>$db->query('SELECT * FROM realtime_outbox WHERE published_at IS NULL AND available_at<=NOW() ORDER BY id LIMIT 25')->fetchAll(PDO::FETCH_ASSOC));
        $sent=0;
        foreach($rows as $event){
            $success=false;$recipientCount=0;
            try {
                $users=$timed('recipients',fn()=>self::recipients($db,$event));$recipientCount=count($users);
                if($users)$timed('broadcast',fn()=>$broker->broadcast($users,['type'=>$event['kind']==='code_lab'?'code_lab_changed':'chat_changed','kind'=>$event['kind'],
                    'target_id'=>(int)$event['target_id'],'message_id'=>(int)$event['message_id'],'event_id'=>(int)$event['id']]));
                $timed('acknowledge',fn()=>$db->prepare('UPDATE realtime_outbox SET published_at=NOW(),last_error=NULL WHERE id=?')->execute([$event['id']]));
                $sent++;$success=true;
            } catch(Throwable $e) {
                $delay=min(60,2 ** min(6,(int)$event['attempts']));
                $timed('retry',fn()=>$db->prepare("UPDATE realtime_outbox SET attempts=attempts+1,available_at=DATE_ADD(NOW(),INTERVAL $delay SECOND),last_error='Delivery unavailable' WHERE id=?")->execute([$event['id']]));
                error_log('[Realtime delivery] Event '.(int)$event['id'].' deferred; retry in '.$delay.' seconds');
            }finally{$metrics?->outcome($success,$recipientCount);}
        }
        return $sent;
    }
}

