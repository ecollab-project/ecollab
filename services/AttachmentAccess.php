<?php
declare(strict_types=1);
/** Live stored relationships authorize reads; possession of a filename never does. */
final class AttachmentAccess
{
    public static function validPath(string $p): bool {return (bool)preg_match('#^uploads/(?:threads/)?[A-Za-z0-9][A-Za-z0-9_-]*(?:\.[A-Za-z0-9_-]+)*$#D',$p);}
    private static function rows(PDO $db,string $sql,array $args): array {$q=$db->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);}
    public static function canRead(PDO $db,int $uid,string $kind,int $target,bool $thread=false): bool
    {
        if($uid<1||$target<1)return false;
        if($kind==='dm')return (bool)self::rows($db,'SELECT id FROM dm_conversations WHERE id=? AND (user_a=? OR user_b=?)',[$target,$uid,$uid]);
        if($kind==='group')return (bool)self::rows($db,'SELECT group_id FROM dm_group_members WHERE group_id=? AND user_id=?',[$target,$uid]);
        // Existing human site-administrator read overrides remain explicit.
        $roles=self::rows($db,'SELECT role FROM users WHERE id=?',[$uid]);
        $privileged=$roles&&in_array($roles[0]['role'],$thread?['admin','super_admin']:['admin','super_admin','moderator'],true);
        if($kind==='server'){
            if($privileged)return (bool)self::rows($db,"SELECT id FROM servers WHERE id=? AND status='active'",[$target]);
            return (bool)self::rows($db,"SELECT server_id FROM server_members WHERE server_id=? AND user_id=?",[$target,$uid]);
        }
        if($kind!=='channel')return false;
        if($privileged)return (bool)self::rows($db,'SELECT id FROM channels WHERE id=?',[$target]);
        $r=self::rows($db,"SELECT c.is_private,c.created_by,sm.server_role,EXISTS(SELECT 1 FROM channel_members cm WHERE cm.channel_id=c.id AND cm.user_id=?) AS member FROM channels c JOIN server_members sm ON sm.server_id=c.server_id WHERE c.id=? AND sm.user_id=?",[$uid,$target,$uid]);
        if(!$r)return false;$c=$r[0];
        // Threads only permit owner/admin overrides, matching canAccessChannel().
        return !(int)$c['is_private']||(bool)$c['member']||(int)$c['created_by']===$uid||in_array($c['server_role'],$thread?['owner','admin']:['owner','admin','moderator'],true);
    }
    public static function canDownload(PDO $db,int $uid,string $path,string $base): bool
    {
        if($uid<1||!self::validPath($path))return false;$found=false;
        foreach(self::rows($db,'SELECT m.channel_id,m.is_deleted FROM message_attachments a JOIN messages m ON m.id=a.message_id WHERE a.file_path=?',[$path])as$m){$found=true;if((int)$m['is_deleted']||!self::canRead($db,$uid,'channel',(int)$m['channel_id']))return false;}
        foreach(self::rows($db,'SELECT conversation_id,group_id,is_deleted FROM dm_messages WHERE attachment_path=?',[$path])as$m){$found=true;$kind=$m['conversation_id']?'dm':'group';if((int)$m['is_deleted']||!self::canRead($db,$uid,$kind,(int)($m['conversation_id']?:$m['group_id'])))return false;}
        foreach(self::rows($db,'SELECT t.scope,t.server_id,t.channel_id,t.is_deleted,a.reply_id,r.is_deleted AS reply_deleted,r.thread_id AS reply_thread,t.id FROM thread_attachments a JOIN threads t ON t.id=a.thread_id LEFT JOIN thread_replies r ON r.id=a.reply_id WHERE a.file_url=? OR a.file_url=?',[rtrim($base,'/').'/'.$path,'/'.$path])as$t){
            $found=true;if((int)$t['is_deleted']||($t['reply_id']!==null&&($t['reply_deleted']===null||(int)$t['reply_deleted']||(int)$t['reply_thread']!==(int)$t['id'])))return false;
            if($t['scope']==='public')continue;
            if(!in_array($t['scope'],['channel','server'],true)||!self::canRead($db,$uid,$t['scope'],(int)$t[$t['scope'].'_id'],true))return false;
        }
        // Reused legacy paths fail closed if ANY reference is deleted or unreadable.
        return $found;
    }
}
