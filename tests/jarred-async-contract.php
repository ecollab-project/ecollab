<?php
declare(strict_types=1);
require_once __DIR__.'/../services/JarredJobService.php';
function ok(bool $v,string $label):void{if(!$v)throw new RuntimeException($label);echo "PASS $label\n";}
$dsn=getenv('JARRED_ASYNC_TEST_DSN');
if(!$dsn||!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=jarred_async_test$/D',$dsn))throw new RuntimeException('Owned loopback fixture required');
$db=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
(new ReflectionProperty(Database::class,'instance'))->setValue(null,$db);
$jobs=new JarredJobService($db);
function admit(array $surface=[],?int $sid=null):int{global $db,$jobs;$db->beginTransaction();try{$jobs->reserveConversation(1,1);$db->exec("INSERT INTO dm_messages(conversation_id,sender_id,body)VALUES(1,1,'Synthetic lesson')");$id=$jobs->enqueue(1,2,1,(int)$db->lastInsertId(),$sid,$surface);$db->commit();return $id;}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}}
function assistantCount():int{global $db;return(int)$db->query('SELECT COUNT(*) FROM dm_messages WHERE sender_id=2')->fetchColumn();}
require_once __DIR__.'/../services/JarredErrorResponse.php';
foreach([400,403,409,503,0]as$code){[$status,$message]=JarredErrorResponse::map(new RuntimeException('PRIVATE_EXCEPTION_SENTINEL',$code));ok(!str_contains($message,'PRIVATE'),'safe exception allowlist '.$code);}
// Cancellation, policy changes and deleted source must fence response persistence.
foreach(['cancel','source_deleted','conversation_removed','suspended','account_deleted','disabled','server_removed','server_ban','channel_removed','document_removed','stamp_changed']as$case){
 $surface=$case==='channel_removed'?['channel_id'=>1]:($case==='document_removed'?['document_id'=>1]:[]);
 $id=admit($surface,in_array($case,['server_removed','server_ban','channel_removed','document_removed'],true)?1:null);$before=assistantCount();
 $jobs->workOne(fn()=>null,function()use($case,$id,$db,$jobs){
  switch($case){
   case 'cancel':$jobs->cancel(1,1,$id);break;
   case 'source_deleted':$db->exec("UPDATE dm_messages SET is_deleted=1 WHERE id=(SELECT source_message_id FROM jarred_jobs WHERE id=$id)");break;
   case 'conversation_removed':$db->exec('UPDATE dm_conversations SET user_a=99 WHERE id=1');break;
   case 'suspended':$db->exec("UPDATE users SET status='suspended' WHERE id=1");break;
   case 'account_deleted':$db->exec('UPDATE users SET deleted_at=CURRENT_TIMESTAMP WHERE id=1');break;
   case 'disabled':$db->exec('UPDATE user_settings SET jarred_enabled=0 WHERE user_id=1');break;
   case 'server_removed':$db->exec('DELETE FROM server_members WHERE user_id=1');break;
   case 'server_ban':$db->exec("INSERT INTO moderation_actions VALUES(1,1,'ban',1,NULL)");break;
   case 'channel_removed':$db->exec('DELETE FROM channel_members WHERE user_id=1');break;
   case 'document_removed':$db->exec('DELETE FROM collab_resource_permissions WHERE user_id=1');break;
   case 'stamp_changed':require_once __DIR__.'/../services/JarredTools.php';$stamp=(new JarredTools())->authorizationStamp(1);$db->exec("UPDATE users SET role='facilitator' WHERE id=1");return ['text'=>'PRIVATE_SENTINEL','authorization_stamp'=>$stamp];
  }
  return 'PRIVATE_SENTINEL';
 });
 ok(assistantCount()===$before,'no reply after '.$case);
 $state=$db->query("SELECT state FROM jarred_jobs WHERE id=$id")->fetchColumn();ok(in_array($state,['failed','cancelled'],true),'terminal policy state '.$case);
 $db->exec("UPDATE users SET status='active',role='student',deleted_at=NULL WHERE id=1;UPDATE dm_conversations SET user_a=1 WHERE id=1;UPDATE user_settings SET jarred_enabled=1 WHERE user_id=1;DELETE FROM moderation_actions");
 if($case==='server_removed')$db->exec("INSERT INTO server_members VALUES(1,1,'member')");
 if($case==='channel_removed')$db->exec('INSERT INTO channel_members VALUES(1,1)');
 if($case==='document_removed')$db->exec("INSERT INTO collab_resource_permissions VALUES(1,'document',1,1,'view')");
}
// Durable action checkpoint: transactional effect and response are committed together.
$id=admit();$calls=0;$jobs->workOne(function()use($db,&$calls){$calls++;$db->exec("INSERT INTO effects VALUES(1,'safe')");throw new RuntimeException('PRIVATE_SENTINEL');},fn()=>throw new LogicException('not reached'));
ok((int)$db->query('SELECT COUNT(*) FROM effects')->fetchColumn()===0,'failed action rolls back effect');
$jobs->workOne(function()use($db,&$calls){$calls++;$db->exec("INSERT INTO effects VALUES(1,'safe')");return ['reply'=>'Created','action'=>['type'=>'open_channel','channel_id'=>1]];},fn()=>throw new LogicException('not reached'));
ok((int)$db->query('SELECT COUNT(*) FROM effects')->fetchColumn()===1&&$calls===2,'one committed action effect');
ok($jobs->status(1,1,$id)['job']['state']==='done','action reply committed');
$before=assistantCount();ok(!$jobs->workOne(fn()=>throw new LogicException('duplicate'),fn()=>throw new LogicException('duplicate')),'done job is not reexecuted');
for($i=0;$i<3;$i++)ok($jobs->status(1,1,$id)['job']['state']==='done','duplicate authorized status read');
ok(assistantCount()===$before,'polling never inserts a reply');
try{$jobs->status(3,1,$id);throw new LogicException('unauthorized');}catch(RuntimeException $e){ok($e->getCode()===403,'other account status denied');}
// Aggregate snapshots and current recipient policy remain separate from delivery ordering.
putenv('CENTRIFUGO_ENABLED=true');$event=['kind'=>'dm','target_id'=>1,'message_id'=>(int)$db->query("SELECT id FROM dm_messages WHERE sender_id=2 ORDER BY id DESC LIMIT 1")->fetchColumn()];
ok(in_array(1,RealtimeOutbox::recipients($db,$event),true),'active DM recipient');
$db->exec("UPDATE users SET status='suspended' WHERE id=1");ok(!in_array(1,RealtimeOutbox::recipients($db,$event),true),'suspended DM recipient excluded');
$db->exec("UPDATE users SET status='active' WHERE id=1");
echo "PASS MariaDB policy, action transaction and persistence contracts; no exactly-once inference claim\n";
