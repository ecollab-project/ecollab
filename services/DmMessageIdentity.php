<?php
declare(strict_types=1);
/** Exact per-send identity; repeated message text is never treated as a duplicate. */
final class DmMessageIdentity
{
    public static function record(PDO $db,int $uid,mixed $clientId,string $kind,int $target,array $payload,callable $save): array
    {
        if($clientId===null||$clientId==='')return ['id'=>(int)$save(),'replayed'=>false];
        if(!is_string($clientId)||!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D',$clientId))throw new RuntimeException('Invalid send identity.',400);
        if(!in_array($kind,['dm','group'],true)||$uid<1||$target<1)throw new InvalidArgumentException('Invalid message target');
        $hash=hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR));
        $owns=!$db->inTransaction();if($owns)$db->beginTransaction();
        try{
            $ignore=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'INSERT OR IGNORE':'INSERT IGNORE';
            $q=$db->prepare($ignore.' INTO dm_client_identity (sender_id,client_id,kind,target_id,payload_hash) VALUES (?,?,?,?,?)');$q->execute([$uid,$clientId,$kind,$target,$hash]);
            if($q->rowCount()===0){
                $lock=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
                $q=$db->prepare('SELECT kind,target_id,payload_hash,message_id FROM dm_client_identity WHERE sender_id=? AND client_id=?'.$lock);$q->execute([$uid,$clientId]);$row=$q->fetch(PDO::FETCH_ASSOC);
                if(!$row||$row['kind']!==$kind||(int)$row['target_id']!==$target||!hash_equals($row['payload_hash'],$hash))throw new RuntimeException('This send identity belongs to a different message.',409);
                $id=(int)$row['message_id'];
                $q=$db->prepare('SELECT 1 FROM dm_messages WHERE id=? AND sender_id=? AND is_deleted=0');$q->execute([$id,$uid]);
                if(!$q->fetchColumn())throw new RuntimeException('The original message is no longer available.',409);
                if($owns)$db->commit();return ['id'=>$id,'replayed'=>true];
            }
            $id=(int)$save();if($id<1)throw new RuntimeException('Message was not saved');
            $db->prepare('UPDATE dm_client_identity SET message_id=? WHERE sender_id=? AND client_id=?')->execute([$id,$uid,$clientId]);
            if($owns)$db->commit();return ['id'=>$id,'replayed'=>false];
        }catch(Throwable $e){if($owns&&$db->inTransaction())$db->rollBack();if($e instanceof PDOException&&self::missing($e))throw new RuntimeException('Apply pending message migrations before sending.',503,$e);throw $e;}
    }
    /** Called only after the normal conversation/group authorization and read query. */
    public static function enrich(PDO $db,array $messages,int $uid): array
    {
        $ids=array_values(array_filter(array_map(fn($m)=>(int)($m['id']??0),$messages),fn($id)=>$id>0));if(!$ids)return $messages;
        try{
            $q=$db->prepare('SELECT message_id,client_id FROM dm_client_identity WHERE sender_id=? AND message_id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$q->execute([$uid,...$ids]);$map=[];
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$map[(int)$r['message_id']]=$r['client_id'];
            foreach($messages as &$m)if((int)($m['sender_id']??0)===$uid&&isset($map[(int)$m['id']]))$m['client_message_id']=$map[(int)$m['id']];unset($m);
            return $messages;
        }catch(PDOException $e){if(self::missing($e))return $messages;throw $e;}
    }
    private static function missing(PDOException $e): bool {return $e->getCode()==='42S02'||str_contains($e->getMessage(),'no such table:');}
}
