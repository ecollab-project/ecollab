<?php
declare(strict_types=1);
require dirname(__DIR__).'/config.php';
require ROOT_PATH.'/services/JarredDmReplyWorker.php';
require ROOT_PATH.'/services/DmMessageIdentity.php';
function jq(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function queueFixture(): PDO {
 $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
 $db->exec("CREATE TABLE jarred_reply_queue_guard(id INTEGER PRIMARY KEY);INSERT INTO jarred_reply_queue_guard VALUES(1);
 CREATE TABLE jarred_reply_jobs(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,conversation_id INTEGER,recipient_id INTEGER,active_conversation_id INTEGER UNIQUE,request_message_id INTEGER UNIQUE,reply_message_id INTEGER,prompt_hash TEXT,server_id INTEGER,surface_json TEXT,state TEXT,attempts INTEGER DEFAULT 0,worker_token TEXT,lease_until TEXT,error_code INTEGER,created_at TEXT,updated_at TEXT);
 CREATE TABLE users(id INTEGER PRIMARY KEY,username TEXT,role TEXT,is_system INTEGER DEFAULT 0,status TEXT DEFAULT 'active',deleted_at TEXT);
 CREATE TABLE user_settings(user_id INTEGER PRIMARY KEY,jarred_enabled INTEGER DEFAULT 1);
 CREATE TABLE dm_conversations(id INTEGER PRIMARY KEY,user_a INTEGER,user_b INTEGER,last_message TEXT,last_msg_at TEXT);
 CREATE TABLE dm_messages(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,sender_id INTEGER,body TEXT,is_deleted INTEGER DEFAULT 0,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
 CREATE TABLE dm_client_identity(sender_id INTEGER,client_id TEXT,kind TEXT,target_id INTEGER,payload_hash TEXT,message_id INTEGER,created_at TEXT DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(sender_id,client_id));
 INSERT INTO users(id,username,role,is_system) VALUES(99,'ecollab_ai','student',1);
 CREATE TABLE servers(id INTEGER,status TEXT);CREATE TABLE server_members(server_id INTEGER,user_id INTEGER);
 CREATE TABLE code_lab_tasks(id INTEGER,title TEXT,due_at TEXT,duration_minutes INTEGER,server_id INTEGER);CREATE TABLE code_lab_attempts(task_id INTEGER,user_id INTEGER,submitted_at TEXT,started_at TEXT);");
 for($i=1;$i<=5;$i++){$db->exec("INSERT INTO users(id,username,role) VALUES($i,'user$i','student');INSERT INTO user_settings(user_id) VALUES($i);INSERT INTO dm_conversations(id,user_a,user_b) VALUES($i,$i,99)");}
 return $db;
}
$db=queueFixture();$queue=new JarredReplyQueue($db);
$save=function(int $uid,string $text)use($db){$db->prepare('INSERT INTO dm_messages(conversation_id,sender_id,body) VALUES(?,?,?)')->execute([$uid,$uid,$text]);return (int)$db->lastInsertId();};
$requests=[];for($i=1;$i<=5;$i++){$requests[$i]=$queue->reserve($i,$i,99,'Question '.$i,null,[],fn()=>$save($i,'Question '.$i));$queue->activate($i,$requests[$i]);}
jq((int)$db->query("SELECT COUNT(*) FROM jarred_reply_jobs WHERE state='queued'")->fetchColumn()===5,'five questions persist without inference');
$called=0;try{$queue->reserve(1,1,99,'duplicate turn',null,[],function()use(&$called){$called++;return 100;});throw new RuntimeException('duplicate accepted');}catch(RuntimeException $e){jq($e->getCode()===409&&$called===0,'pending turn rejected before saving another message');}
$a=$queue->claim();$b=$queue->claim();jq($a&&$b&&$queue->claim()===null,'at most two leased generation jobs');
$worker=new JarredDmReplyWorker($db,function($messages)use($db){jq(!$db->inTransaction(),'inference never holds a database transaction');return ['text'=>'Answer to '.$messages[array_key_last($messages)]['content']];},fn()=>'');
$answer=$worker->generate($a);$id=$queue->finish($a,fn()=>$worker->persist($a,$answer));jq($id!==null,'first reply persisted');
jq($queue->finish($a,fn()=>throw new RuntimeException('duplicate publication'))===null,'repeated completion cannot create another message');
jq($queue->latest(2,1)===null,'another user cannot read job metadata');
$queue->fail($b,504);jq($queue->latest(2,2)['state']==='failed','failed job releases active conversation');
$before=(int)$db->query('SELECT COUNT(*) FROM dm_messages')->fetchColumn();jq($queue->retry(2,2,$requests[2],'Question 2'),'saved question can retry');jq((int)$db->query('SELECT COUNT(*) FROM dm_messages')->fetchColumn()===$before,'retry never duplicates user text');
// Kill/restart simulation: the old lease must not publish after another worker reclaims it.
$old=$queue->claim();$db->prepare('UPDATE jarred_reply_jobs SET lease_until=? WHERE id=?')->execute(['2000-01-01 00:00:00',$old['id']]);$new=$queue->claim();jq($new&&$old['id']===$new['id']&&$old['worker_token']!==$new['worker_token'],'expired lease is reclaimed with a fresh token');jq($queue->finish($old,fn()=>999)===null,'stale worker cannot publish');$queue->fail($new,503);
// Changed preferences and newly started Code Lab restrictions apply at publication.
while($worker->runOne()){}
$m=$queue->reserve(1,1,99,'Explain loops',null,[],fn()=>$save(1,'Explain loops'));$queue->activate(1,$m);$job=$queue->claim();$text=$worker->generate($job);
$db->exec("INSERT INTO servers VALUES(1,'active');INSERT INTO server_members VALUES(1,1);INSERT INTO code_lab_tasks VALUES(1,'Loops',NULL,NULL,1);INSERT INTO code_lab_attempts VALUES(1,1,NULL,'2026-11-03 00:00:00')");
$reply=$queue->finish($job,fn()=>$worker->persist($job,$text));$q=$db->prepare('SELECT body FROM dm_messages WHERE id=?');$q->execute([$reply]);jq(str_contains((string)$q->fetchColumn(),'workflow'),'task started during generation replaces ready-to-submit output with guidance');
$db->exec('DELETE FROM code_lab_attempts');$m=$queue->reserve(1,1,99,'Another question',null,[],fn()=>$save(1,'Another question'));$queue->activate(1,$m);$job=$queue->claim();$text=$worker->generate($job);$db->exec('UPDATE user_settings SET jarred_enabled=0 WHERE user_id=1');
try{$queue->finish($job,fn()=>$worker->persist($job,$text));throw new RuntimeException('disabled reply published');}catch(RuntimeException $e){jq($e->getCode()===403,'disabled assistance is rechecked');$queue->fail($job,403);}
// Revoked server membership and edited source text cannot publish stale evidence.
$db->exec('UPDATE user_settings SET jarred_enabled=1 WHERE user_id=1');
$m=$queue->reserve(1,1,99,'Server question',1,[],fn()=>$save(1,'Server question'));$queue->activate(1,$m);$job=$queue->claim();$text=$worker->generate($job);$db->exec('DELETE FROM server_members WHERE user_id=1');
try{$queue->finish($job,fn()=>$worker->persist($job,$text));throw new RuntimeException('revoked server reply published');}catch(RuntimeException $e){jq($e->getCode()===403,'server membership rechecked before publication');$queue->fail($job,403);}
$m=$queue->reserve(1,1,99,'Original text',null,[],fn()=>$save(1,'Original text'));$queue->activate(1,$m);$job=$queue->claim();$text=$worker->generate($job);$db->prepare('UPDATE dm_messages SET body=? WHERE id=?')->execute(['Edited text',$m]);
try{$queue->finish($job,fn()=>$worker->persist($job,$text));throw new RuntimeException('edited request reply published');}catch(RuntimeException $e){jq($e->getCode()===409,'source edits invalidate stale inference');$queue->fail($job,409);}
// Atomic rollback and sender UUID replay continue to work.
$db->exec('UPDATE user_settings SET jarred_enabled=1 WHERE user_id=1');$count=(int)$db->query('SELECT COUNT(*) FROM jarred_reply_jobs')->fetchColumn();
try{$queue->reserve(1,1,99,'rollback',null,[],function()use($save){$save(1,'rollback');throw new RuntimeException('fixture');});}catch(RuntimeException $e){}
jq((int)$db->query('SELECT COUNT(*) FROM jarred_reply_jobs')->fetchColumn()===$count,'failed save rolls back the reservation');
$nonce='b7fce7d0-174e-42d5-8ff7-118cf44c7461';$callback=fn()=>$queue->reserve(1,1,99,'Once',null,[],fn()=>$save(1,'Once'));
$first=DmMessageIdentity::record($db,1,$nonce,'dm',1,['Once'],$callback);$second=DmMessageIdentity::record($db,1,$nonce,'dm',1,['Once'],$callback);jq($first['id']===$second['id']&&$second['replayed'],'HTTP retry does not enqueue another job');
echo "PASS: five durable questions, two worker leases, exact-once publication, crash recovery, retry identity, rollback, authorization and fresh Code Lab policy\n";
