<?php
declare(strict_types=1);
// Abrupt child exits use temporary SQLite files only. No application database.
function env($key,$default=''){return $key==='CENTRIFUGO_ENABLED'?'true':$default;}
require_once __DIR__.'/../services/JarredJobService.php';
final class CrashPDO extends PDO {
 public string $point=''; public int $commits=0;
 public function commit():bool {
  $this->commits++;
  if($this->point==='before_response_commit'&&$this->commits===3)exit(77);
  $ok=parent::commit();
  if(($this->point==='after_claim'&&$this->commits===1)||($this->point==='after_response_commit'&&$this->commits===3))exit(77);
  return $ok;
 }
}
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
function openDb(string $path):CrashPDO{return new CrashPDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
function admit(PDO $db,string $point=''):void{
 $jobs=new JarredJobService($db);$db->beginTransaction();$jobs->reserveConversation(1,1);
 if($point==='before_message')exit(77);
 RealtimeOutbox::record($db,'dm',1,function()use($db){$db->exec("INSERT INTO dm_messages(conversation_id,sender_id,body) VALUES(1,1,'source')");return (int)$db->lastInsertId();});
 if(in_array($point,['after_message','before_job'],true))exit(77);
 $source=(int)$db->query("SELECT id FROM dm_messages WHERE sender_id=1")->fetchColumn();
 $jobs->enqueue(1,2,1,$source,null,[]);$db->commit();
}
$schema="CREATE TABLE users(id INTEGER PRIMARY KEY,deleted_at TEXT,status TEXT,is_system INTEGER,username TEXT);
CREATE TABLE user_settings(user_id INTEGER PRIMARY KEY,jarred_enabled INTEGER);
CREATE TABLE dm_conversations(id INTEGER PRIMARY KEY,user_a INTEGER,user_b INTEGER,last_message TEXT,last_msg_at TEXT);
CREATE TABLE dm_messages(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,sender_id INTEGER,body TEXT,is_deleted INTEGER DEFAULT 0,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE jarred_queue_gate(id INTEGER PRIMARY KEY);INSERT INTO jarred_queue_gate VALUES(1);
CREATE TABLE jarred_jobs(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,recipient_id INTEGER,conversation_id INTEGER,source_message_id INTEGER UNIQUE,active_server_id INTEGER,surface_json TEXT,state TEXT DEFAULT 'queued',attempts INTEGER DEFAULT 0,lease_token TEXT,action_checked INTEGER DEFAULT 0,response_message_id INTEGER,action_json TEXT,usage_json TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,finished_at TEXT);
CREATE TABLE realtime_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT,kind TEXT,target_id INTEGER,message_id INTEGER);
INSERT INTO users VALUES(1,NULL,'active',0,'student'),(2,NULL,'active',1,'ecollab_ai');INSERT INTO user_settings VALUES(1,1);INSERT INTO dm_conversations VALUES(1,1,2,NULL,NULL);";
foreach(['before_message','after_message','before_job','after_claim','during_inference','before_response_commit','after_response_commit','during_outbox_insert'] as $point){
 $path=tempnam(sys_get_temp_dir(),'jarred-crash-');$db=openDb($path);$db->exec($schema);
 $admission=in_array($point,['before_message','after_message','before_job'],true);
 if(!$admission)admit($db);
 $db=null;$pid=pcntl_fork();check($pid>=0,'fork');
 if($pid===0){
  $db=openDb($path);$db->point=$point;
  if($admission)admit($db,$point);
  if($point==='during_outbox_insert'){
   $db->sqliteCreateFunction('crash_process',function(){exit(77);});
   $db->exec("CREATE TEMP TRIGGER crash_delivery BEFORE INSERT ON realtime_outbox BEGIN SELECT crash_process(); END");
  }
  (new JarredJobService($db))->workOne(fn()=>null,function()use($point){if($point==='during_inference')exit(77);return 'answer';});exit(0);
 }
 pcntl_waitpid($pid,$status);check(pcntl_wexitstatus($status)===77,'Crash hook reached: '.$point);
 $db=openDb($path);$sources=(int)$db->query('SELECT COUNT(*) FROM dm_messages WHERE sender_id=1')->fetchColumn();
 check($sources===($admission?0:1),'Source atomicity: '.$point);
 if($admission){check((int)$db->query('SELECT COUNT(*) FROM jarred_jobs')->fetchColumn()===0,'No orphan job');admit($db);}
 $jobs=new JarredJobService($db);$jobs->recover();$jobs->workOne(fn()=>null,fn()=>'answer');$jobs->recover();$jobs->workOne(fn()=>null,fn()=>'duplicate');
 check((int)$db->query('SELECT COUNT(*) FROM dm_messages WHERE sender_id=2')->fetchColumn()===1,'Exactly one persisted reply: '.$point);
 check((int)$db->query('SELECT COUNT(*) FROM realtime_outbox')->fetchColumn()===2,'One source and one response intent: '.$point);
 check($db->query('SELECT state FROM jarred_jobs')->fetchColumn()==='done','Terminal state: '.$point);
 $db=null;unlink($path);echo "PASS abrupt crash $point\n";
}
// Optional local mock broker verifies the publish-before-ack crash boundary.
if($api=getenv('REVIEW_BROKER_API')){
 require_once __DIR__.'/../services/CentrifugoService.php';
 check((bool)preg_match('~^http://127\.0\.0\.1:[0-9]+/api$~D',$api),'Local mock only');
 $path=tempnam(sys_get_temp_dir(),'jarred-delivery-');$db=openDb($path);$db->exec($schema);
 $db->exec("ALTER TABLE realtime_outbox ADD COLUMN published_at TEXT;ALTER TABLE realtime_outbox ADD COLUMN available_at TEXT DEFAULT '2000-01-01';ALTER TABLE realtime_outbox ADD COLUMN attempts INTEGER DEFAULT 0;ALTER TABLE realtime_outbox ADD COLUMN last_error TEXT;");
 admit($db);(new JarredJobService($db))->workOne(fn()=>null,fn()=>'answer');$db=null;
 $pid=pcntl_fork();check($pid>=0,'fork delivery');
 if($pid===0){
  $db=openDb($path);$db->sqliteCreateFunction('NOW',fn()=>gmdate('Y-m-d H:i:s'));
  $db->sqliteCreateFunction('crash_ack',function(){exit(77);});
  $db->exec('CREATE TEMP TRIGGER crash_ack BEFORE UPDATE OF published_at ON realtime_outbox BEGIN SELECT crash_ack(); END');
  RealtimeOutbox::dispatch($db,new CentrifugoService(str_repeat('a',64),str_repeat('b',64),$api));exit(0);
 }
 pcntl_waitpid($pid,$status);check(pcntl_wexitstatus($status)===77,'Crash after broadcast before ACK');
 $db=openDb($path);$db->sqliteCreateFunction('NOW',fn()=>gmdate('Y-m-d H:i:s'));
 check(RealtimeOutbox::dispatch($db,new CentrifugoService(str_repeat('a',64),str_repeat('b',64),$api))===2,'Unacknowledged hints retried');
 check((int)$db->query('SELECT COUNT(*) FROM dm_messages WHERE sender_id=2')->fetchColumn()===1,'Broadcast retry never inserts assistant response');
 $db=null;unlink($path);echo "PASS crash after broker broadcast before ACK; durable response unchanged\n";
}
