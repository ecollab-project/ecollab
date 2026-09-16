<?php
use Ratchet\ConnectionInterface;

class DmHandler
{
 public static function handleDmMessage(ConnectionInterface $from,array $data,array $meta,array $userConns, PDO $db):void{
  $rid=(int)($data['recipient_id']??0);$cid=(int)($data['conversation_id']??0);$mid=(int)($data['message_id']??0);if(!$rid||!$cid||!$mid)return;
  $s=$db->prepare('SELECT dm.body,dm.created_at FROM dm_messages dm JOIN dm_conversations dc ON dc.id=dm.conversation_id WHERE dm.id=:mid AND dm.conversation_id=:cid AND dm.sender_id=:sender AND dm.is_deleted=0 AND ((dc.user_a=:sender_a AND dc.user_b=:recipient_a) OR (dc.user_b=:sender_b AND dc.user_a=:recipient_b)) LIMIT 1');
  $s->execute([':mid'=>$mid,':cid'=>$cid,':sender'=>(int)$meta['user_id'],':sender_a'=>(int)$meta['user_id'],':recipient_a'=>$rid,':sender_b'=>(int)$meta['user_id'],':recipient_b'=>$rid]);$m=$s->fetch(PDO::FETCH_ASSOC);if(!$m)return;
  $p=json_encode(['type'=>'dm_message','conversation_id'=>$cid,'message_id'=>$mid,'sender_id'=>$meta['user_id'],'sender_name'=>$meta['full_name']??$meta['username'],'sender_gradient'=>$meta['gradient']??'','body'=>$m['body'],'created_at'=>$m['created_at']]);
  if(isset($userConns[$rid]))try{$userConns[$rid]->send($p);}catch(\Throwable){}try{$from->send($p);}catch(\Throwable){}
 }
 public static function handleDmGroupMessage(ConnectionInterface $from,array $data,array $meta,array $userConns, PDO $db):void{
  $gid=(int)($data['group_id']??0);$mid=(int)($data['message_id']??0);$uid=(int)$meta['user_id'];if(!$gid||!$mid)return;
  $s=$db->prepare('SELECT dm.body,dm.created_at FROM dm_messages dm JOIN dm_group_members gm ON gm.group_id=dm.group_id AND gm.user_id=:caller WHERE dm.id=:mid AND dm.group_id=:gid AND dm.sender_id=:sender AND dm.is_deleted=0 LIMIT 1');$s->execute([':mid'=>$mid,':gid'=>$gid,':sender'=>$uid,':caller'=>$uid]);$m=$s->fetch(PDO::FETCH_ASSOC);if(!$m)return;
  $s=$db->prepare('SELECT user_id FROM dm_group_members WHERE group_id=:gid AND user_id!=:caller');$s->execute([':gid'=>$gid,':caller'=>$uid]);$p=json_encode(['type'=>'dm_group_message','group_id'=>$gid,'message_id'=>$mid,'sender_id'=>$uid,'sender_name'=>$meta['full_name']??$meta['username'],'sender_gradient'=>$meta['gradient']??'','body'=>$m['body'],'created_at'=>$m['created_at']]);foreach($s->fetchAll(PDO::FETCH_COLUMN) as $id)if(isset($userConns[(int)$id])try{$userConns[(int)$id]->send($p);}catch(\Throwable){}try{$from->send($p);}catch(\Throwable){}
 }
 public static function handleDmGroupTyping(ConnectionInterface $from,array $data,array $meta,array $userConns, PDO $db):void{
  $gid=(int)($data['group_id']??0);$uid=(int)$meta['user_id'];if(!$gid)return;$s=$db->prepare('SELECT 1 FROM dm_group_members WHERE group_id=:gid AND user_id=:uid');$s->execute([':gid'=>$gid,':uid'=>$uid]);if(!$s->fetchColumn())return;$s=$db->prepare('SELECT user_id FROM dm_group_members WHERE group_id=:gid AND user_id!=:caller');$s->execute([':gid'=>$gid,':caller'=>$uid]);$p=json_encode(['type'=>'dm_group_typing','group_id'=>$gid,'sender_id'=>$uid,'sender_name'=>$meta['full_name']??$meta['username'],'is_typing'=>(bool)($data['is_typing']??false)]);foreach($s->fetchAll(PDO::FETCH_COLUMN) as $id)if(isset($userConns[(int)$id])try{$userConns[(int)$id]->send($p);}catch(\Throwable){}
 }
 public static function handleDmTyping(ConnectionInterface $from,array $data,array $meta,array $userConns, PDO $db):void{
  $rid=(int)($data['recipient_id']??0);$cid=(int)($data['conversation_id']??0);if(!$rid||!$cid||!self::conversationPeer($db,$cid,(int)$meta['user_id'],$rid)||!isset($userConns[$rid]))return;$p=json_encode(['type'=>'dm_typing','conversation_id'=>$cid,'sender_id'=>$meta['user_id'],'sender_name'=>$meta['full_name']??$meta['username'],'is_typing'=>(bool)($data['is_typing']??false)]);try{$userConns[$rid]->send($p);}catch(\Throwable){}
 }
 public static function handleNotifyConnReq(ConnectionInterface $from,array $data,array $meta,array $userConns, PDO $db):void{
  $aid=(int)($data['addressee_id']??0);$rid=(int)($data['request_id']??0);$uid=(int)$meta['user_id'];if(!$aid||!$rid||$aid===$uid)return;$s=$db->prepare('SELECT 1 FROM connection_requests WHERE id=:rid AND requester_id=:uid AND addressee_id=:aid LIMIT 1');$s->execute([':rid'=>$rid,':uid'=>$uid,':aid'=>$aid]);if(!$s->fetchColumn()||!isset($userConns[$aid]))return;$p=json_encode(['type'=>'connection_request','request_id'=>$rid,'requester'=>['id'=>$uid,'fullName'=>$meta['full_name']??$meta['username'],'gradient'=>$meta['gradient']??'']]);try{$userConns[$aid]->send($p);}catch(\Throwable){}
 }
 public static function handleNotifyConnAccepted(ConnectionInterface $from,array $data,array $meta,array $userConns, PDO $db):void{
  $rid=(int)($data['requester_id']??0);$qid=(int)($data['request_id']??0);$uid=(int)$meta['user_id'];if(!$rid||$rid===$uid||!isset($userConns[$rid]))return;$where='requester_id=:requester AND addressee_id=:caller';$params=[':requester'=>$rid,':caller'=>$uid];if($qid){$where='id=:rid AND requester_id=:requester AND addressee_id=:caller';$params[':rid']=$qid;}$s=$db->prepare("SELECT 1 FROM connection_requests WHERE {$where} AND status='accepted' LIMIT 1");$s->execute($params);if(!$s->fetchColumn())return;$p=json_encode(['type'=>'connection_accepted','accepted_by'=>['id'=>$uid,'fullName'=>$meta['full_name']??$meta['username'],'gradient'=>$meta['gradient']??'']]);try{$userConns[$rid]->send($p);}catch(\Throwable){}
 }
 public static function handleVoiceInvite(ConnectionInterface $from,array $data,array $meta,array $userConns, PDO $db):void{
  $target=(int)($data['target_user_id']??0);$cid=(int)($data['channel_id']??0);$uid=(int)$meta['user_id'];if(!$target||!$cid||$target===$uid||!isset($userConns[$target]))return;$s=$db->prepare("SELECT c.name,c.server_id,s.name AS server_name FROM channels c JOIN servers s ON s.id=c.server_id JOIN server_members a ON a.server_id=c.server_id AND a.user_id=:caller JOIN server_members b ON b.server_id=c.server_id AND b.user_id=:target WHERE c.id=:cid AND c.type='voice' LIMIT 1");$s->execute([':caller'=>$uid,':target'=>$target,':cid'=>$cid]);$c=$s->fetch(PDO::FETCH_ASSOC);if(!$c)return;$p=json_encode(['type'=>'voice_invite','channel_id'=>$cid,'channel_name'=>$c['name'],'server_id'=>(int)$c['server_id'],'server_name'=>$c['server_name'],'from'=>['id'=>$uid,'fullName'=>$meta['full_name']??$meta['username'],'gradient'=>$meta['gradient']??'']]);try{$userConns[$target]->send($p);}catch(\Throwable){}
 }
 public static function handleDmCallSignal(ConnectionInterface $from,array $data,array $meta,array $userConns, PDO $db,string $type):void{
  $uid=(int)$meta['user_id'];$target=(int)($data['target_user_id']??0);$gid=(int)($data['group_id']??0);$log=(int)($data['log_id']??0);if(!$target||$target===$uid||!isset($userConns[$target]))return;
  if($gid){$s=$db->prepare('SELECT 1 FROM dm_group_members WHERE group_id=:gid AND user_id=:caller AND EXISTS(SELECT 1 FROM dm_group_members WHERE group_id=:gid2 AND user_id=:target)');$s->execute([':gid'=>$gid,':caller'=>$uid,':gid2'=>$gid,':target'=>$target]);}
  else{$s=$db->prepare('SELECT 1 FROM dm_conversations WHERE (user_a=:a AND user_b=:b)');$s->execute([':a'=>min($uid,$target),':b'=>max($uid,$target)]);}
  if(!$s->fetchColumn())return;
  if($type==='dm_call_offer'){$s=$db->prepare('INSERT INTO dm_call_history(caller_id,callee_id,group_id,is_video,status) VALUES(:caller,:callee,:gid,:video,"ringing")');$s->execute([':caller'=>$uid,':callee'=>$gid?null:$target,':gid'=>$gid?:null,':video'=>(int)(bool)($data['is_video']??false)]);$log=(int)$db->lastInsertId();}
  elseif($log){if($type==='dm_call_answer')$db->prepare('UPDATE dm_call_history SET status="answered",answered_at=NOW() WHERE id=:id')->execute([':id'=>$log]);elseif($type==='dm_call_decline')$db->prepare('UPDATE dm_call_history SET status="declined",ended_at=NOW() WHERE id=:id AND status="ringing"')->execute([':id'=>$log]);elseif($type==='dm_call_end')$db->prepare("UPDATE dm_call_history SET status=IF(status='answered','ended','missed'),ended_at=NOW(),duration_seconds=IF(status='answered',TIMESTAMPDIFF(SECOND,answered_at,NOW()),NULL) WHERE id=:id AND status IN ('ringing','answered')")->execute([':id'=>$log]);}
  $p=json_encode(['type'=>$type,'from_user_id'=>$uid,'from_username'=>$meta['full_name']??$meta['username'],'from_gradient'=>$meta['gradient']??'','group_id'=>$gid?:null,'log_id'=>$log?:null,'is_video'=>(bool)($data['is_video']??false),'sdp'=>$data['sdp']??null,'candidate'=>$data['candidate']??null]);
  try{$userConns[$target]->send($p);}catch(\Throwable){}
  if($type==='dm_call_offer'){try{$from->send(json_encode(['type'=>'dm_call_offer_sent','log_id'=>$log]));}catch(\Throwable){}}
 }
 private static function conversationPeer(PDO $db,int $cid,int $uid,int $peer):bool{$s=$db->prepare('SELECT 1 FROM dm_conversations WHERE id=:cid AND ((user_a=:a AND user_b=:b) OR (user_a=:b2 AND user_b=:a2)) LIMIT 1');$s->execute([':cid'=>$cid,':a'=>$uid,':b'=>$peer,':b2'=>$uid,':a2'=>$peer]);return(bool)$s->fetchColumn();}
}
