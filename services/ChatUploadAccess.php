<?php
declare(strict_types=1);
require_once __DIR__.'/ChannelAccessService.php';

/** Uploads use current account and channel write policy, including active moderation. */
final class ChatUploadAccess
{
    public static function check(PDO $db,int $uid,string $kind,int $id): void
    {
        if($id<1||!in_array($kind,['channel','dm','group'],true))throw new RuntimeException('Select a chat before uploading.',400);
        (new AccessPolicy($db))->assertAccount($uid);
        if($kind==='dm'||$kind==='group'){
            $sql=$kind==='dm'?'SELECT id FROM dm_conversations WHERE id=:id AND (user_a=:a OR user_b=:b)':'SELECT group_id FROM dm_group_members WHERE group_id=:id AND user_id=:a';
            $args=[':id'=>$id,':a'=>$uid];if($kind==='dm')$args[':b']=$uid;
            $q=$db->prepare($sql);$q->execute($args);
            if(!$q->fetchColumn())throw new RuntimeException('Chat access denied.',403);
            return;
        }
        $channel=(new ChannelAccessService($db))->write($id,$uid);
        if(!in_array($channel['type'],['text','announcement'],true))throw new RuntimeException('Chat access denied.',403);
        if($channel['type']==='announcement'){
            $q=$db->prepare('SELECT u.role,sm.server_role FROM users u LEFT JOIN server_members sm ON sm.user_id=u.id AND sm.server_id=? WHERE u.id=?');
            $q->execute([(int)$channel['server_id'],$uid]);$roles=$q->fetch(PDO::FETCH_ASSOC);
            $manager=in_array($roles['role'],['admin','super_admin'],true)||in_array($roles['server_role'],['owner','admin','moderator'],true)||(int)$channel['created_by']===$uid;
            if(!$manager)throw new RuntimeException('You cannot upload in this channel.',403);
        }
    }
}
