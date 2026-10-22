<?php
declare(strict_types=1);
require_once __DIR__.'/../services/JarredJobService.php';
function check($ok,string $what):void{if(!$ok)throw new RuntimeException($what);}
$dsn=getenv('PHASE2_TEST_DSN')?:'sqlite::memory:';
if($dsn!=='sqlite::memory:'&&!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=ecollab_phase2_test$/D',$dsn))throw new RuntimeException('Use a disposable local phase2 test database');
$db=new PDO($dsn,getenv('PHASE2_TEST_USER')?:'root',getenv('PHASE2_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$schema="CREATE TABLE moderation_actions(target_user_id INTEGER,server_id INTEGER,action_type VARCHAR(20),is_active INTEGER,expires_at DATETIME);
CREATE TABLE users(id INTEGER PRIMARY KEY,deleted_at TEXT,status TEXT,is_system INTEGER,username TEXT,full_name TEXT,avatar_url TEXT,avatar_color_gradient TEXT);
CREATE TABLE user_settings(user_id INTEGER PRIMARY KEY,jarred_enabled INTEGER);
CREATE TABLE dm_conversations(id INTEGER PRIMARY KEY,user_a INTEGER,user_b INTEGER,last_message TEXT,last_msg_at TEXT);
CREATE TABLE dm_messages(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,sender_id INTEGER,body TEXT,is_deleted INTEGER DEFAULT 0,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE effects(id INTEGER PRIMARY KEY,body TEXT);
CREATE TABLE jarred_queue_gate(id INTEGER PRIMARY KEY); INSERT INTO jarred_queue_gate VALUES(1);
CREATE TABLE jarred_jobs(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,recipient_id INTEGER,conversation_id INTEGER,source_message_id INTEGER UNIQUE,active_server_id INTEGER,surface_json TEXT,state TEXT DEFAULT 'queued',attempts INTEGER DEFAULT 0,lease_token TEXT,action_checked INTEGER DEFAULT 0,response_message_id INTEGER,action_json TEXT,usage_json TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,finished_at TEXT);
INSERT INTO users VALUES(1,NULL,'active',0,'student','Student','',''),(2,NULL,'active',1,'ecollab_ai','Jarred','',''),(3,NULL,'active',0,'other','Other','','');
INSERT INTO user_settings VALUES(1,1),(3,1); INSERT INTO dm_conversations VALUES(1,1,2,NULL,NULL),(2,3,2,NULL,NULL);";
if($dsn!=='sqlite::memory:'){
 check((int)$db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()===0,'Fixture schema must be empty');
 $schema=str_replace('INTEGER PRIMARY KEY AUTOINCREMENT','BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',$schema);
 $schema=preg_replace('/CREATE TABLE jarred_jobs\(.*?\);/s','',$schema);
 $schema=str_replace('CREATE TABLE jarred_queue_gate(id INTEGER PRIMARY KEY); INSERT INTO jarred_queue_gate VALUES(1);','',$schema);
}
$db->exec($schema);
if($dsn!=='sqlite::memory:')$db->exec(file_get_contents(__DIR__.'/../database/migrations/066_jarred_jobs.sql'));
$jobs=new JarredJobService($db);
function enqueue():int{global $db,$jobs;$db->beginTransaction();try{$jobs->reserveConversation(1,1);$db->exec("INSERT INTO dm_messages(conversation_id,sender_id,body) VALUES(1,1,'hello')");$id=$jobs->enqueue(1,2,1,(int)$db->lastInsertId(),null,[]);$db->commit();return $id;}catch(Throwable $e){$db->rollBack();throw $e;}}
$id=enqueue();try{enqueue();throw new LogicException('busy not rejected');}catch(RuntimeException $e){check($e->getCode()===409,'Concurrent turn rejected');}
check((int)$db->query('SELECT COUNT(*) FROM dm_messages')->fetchColumn()===1,'Rejected turn never persists');
$actions=0;$calls=0;
$action=function($j)use(&$actions){$actions++;return null;};
$generate=function($j)use(&$calls){$calls++;if($calls===1)throw new RuntimeException('provider outage');return 'hello back';};
$jobs->workOne($action,$generate);$jobs->workOne($action,$generate);
check($actions===1&&$calls===2,'Inference retry does not repeat action routing');
check($jobs->status(1,1,$id)['job']['ai_message']['body']==='hello back','Reply stored and owned');
check(!$jobs->workOne($action,$generate),'Completed job never runs twice');
try{$jobs->status(3,1,$id);throw new LogicException('isolation failed');}catch(RuntimeException $e){check($e->getCode()===403,'Other student denied');}
$id=enqueue();$jobs->workOne(fn()=>null,function()use($jobs,$id){$jobs->cancel(1,1,$id);return 'discard me';});
check($jobs->status(1,1,$id)['job']['state']==='cancelled','Cancellation fences in-flight response');
check((int)$db->query("SELECT COUNT(*) FROM dm_messages WHERE body='discard me'")->fetchColumn()===0,'Cancelled reply not published');
$id=enqueue();$effects=0;
$action=function()use($db,&$effects){$effects++;$db->exec("INSERT INTO effects VALUES(1,'room')");if($effects===1)throw new RuntimeException('crash before commit');return ['reply'=>'Created','action'=>['type'=>'open_channel','channel_id'=>5]];};
$jobs->workOne($action,fn()=>throw new LogicException('No inference for actions'));
check((int)$db->query('SELECT COUNT(*) FROM effects')->fetchColumn()===0,'Failed action rolled back');
$jobs->workOne($action,fn()=>throw new LogicException('No inference for actions'));
check((int)$db->query('SELECT COUNT(*) FROM effects')->fetchColumn()===1,'Single committed tool effect');
check($jobs->status(1,1,$id)['job']['ai_action']['channel_id']===5,'Action result retained');
$id=enqueue();$db->exec("UPDATE jarred_jobs SET state='running',attempts=1,action_checked=1 WHERE id=$id");$jobs->recover();
$jobs->workOne(fn()=>throw new LogicException('Do not replay checkpoint'),fn()=>['text'=>'recovered','usage'=>['input_tokens'=>3,'output_tokens'=>2,'model'=>'fixture']]);
check($jobs->status(1,1,$id)['job']['state']==='done','Worker restart resumes checkpoint');
check($jobs->status(1,1,$id)['job']['ai_usage']['input_tokens']===3,'Provider usage retained');
$id=enqueue();$db->exec('UPDATE user_settings SET jarred_enabled=0 WHERE user_id=1');$jobs->workOne(fn()=>throw new LogicException('Disabled'),fn()=>throw new LogicException('Disabled'));
check($jobs->status(1,1,$id)['job']['state']==='failed','Current preferences revoke queued work');
// The database clock need not be UTC; fresh jobs must not expire or evade expiry.
if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
 $db->exec('UPDATE user_settings SET jarred_enabled=1');
 foreach(['-07:00','+08:00'] as $zone){
  $db->exec("SET time_zone=".$db->quote($zone));
  $id=enqueue();$jobs->workOne(fn()=>null,fn()=>'timezone-safe');
  check($jobs->status(1,1,$id)['job']['state']==='done','Fresh job in '.$zone);
  $id=enqueue();$db->exec("UPDATE jarred_jobs SET created_at=DATE_SUB(CURRENT_TIMESTAMP,INTERVAL 31 MINUTE) WHERE id=".$id);
  $jobs->workOne(fn()=>throw new LogicException('Expired action'),fn()=>throw new LogicException('Expired inference'));
  check($jobs->status(1,1,$id)['job']['state']==='failed','Expired job in '.$zone);
 }
}
echo "PASS Jarred admission, atomic source/job, retry checkpoint, action rollback, cancellation fencing, recovery, ownership and preferences\n";
