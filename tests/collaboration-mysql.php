<?php
declare(strict_types=1);
// Destructive fixtures are restricted to a dedicated disposable *_test database.
$dsn=getenv('FEATURE_TEST_DSN')?:'mysql:host=127.0.0.1;dbname=ecollab_feature_test';
if(!preg_match('/dbname=([a-zA-Z0-9_]+_test)(?:;|$)/',$dsn,$m))throw new RuntimeException('Dedicated *_test database required');
$db=new PDO($dsn,getenv('DB_USER')?:'root',getenv('DB_PASS')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
require_once dirname(__DIR__).'/services/JarredReplyQueue.php';
if(($argv[1]??'')==='--claim-jarred'){echo json_encode((new JarredReplyQueue($db))->claim());exit;}
foreach(['jarred_reply_jobs','jarred_reply_queue_guard','dm_client_identity','dm_messages','peer_behavior_profiles','peer_activity_days','recommendation_book_events','recommendation_books','code_lab_attempts','code_lab_tasks','messages','temporary_voice_rooms','channel_members','server_members','channels','servers','user_settings','users','ws_relay'] as $table)$db->exec("DROP TABLE IF EXISTS $table");
$db->exec("CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY,voice_channel_id INT UNSIGNED NULL,role VARCHAR(32),status VARCHAR(32),deleted_at DATETIME NULL,is_system TINYINT DEFAULT 0);CREATE TABLE servers(id INT PRIMARY KEY,status VARCHAR(32));CREATE TABLE server_members(user_id BIGINT,server_id INT,server_role VARCHAR(32));CREATE TABLE user_settings(user_id BIGINT PRIMARY KEY,ai_matching TINYINT);CREATE TABLE channels(id INT UNSIGNED PRIMARY KEY,server_id INT,type VARCHAR(16),is_private TINYINT,created_by BIGINT,name VARCHAR(100));CREATE TABLE channel_members(channel_id INT,user_id BIGINT);CREATE TABLE ws_relay(id BIGINT AUTO_INCREMENT PRIMARY KEY,channel_id INT,payload JSON);CREATE TABLE messages(sender_id BIGINT,channel_id INT,created_at DATETIME,is_deleted TINYINT,content_type VARCHAR(32));CREATE TABLE code_lab_tasks(id BIGINT PRIMARY KEY,language VARCHAR(16));CREATE TABLE code_lab_attempts(user_id BIGINT,task_id BIGINT,submitted_at DATETIME,score DECIMAL(5,2));");
foreach(['063_temporary_voice_lifecycle.sql','064_peer_behavior.sql','065_dm_client_identity.sql','066_jarred_reply_queue.sql'] as $migration){$sql=file_get_contents(dirname(__DIR__).'/database/migrations/'.$migration);$db->exec($sql);$db->exec($sql);}
$db->exec("INSERT INTO users VALUES(1,NULL,'student','offline',NULL,0),(2,NULL,'student','active',NULL,0);INSERT INTO user_settings VALUES(1,1),(2,1);INSERT INTO servers VALUES(1,'active');INSERT INTO server_members VALUES(1,1,'member'),(2,1,'member');INSERT INTO channels VALUES(10,1,'voice',0,1,'PHP'),(11,1,'voice',0,1,'Other');INSERT INTO temporary_voice_rooms(channel_id,server_id,owner_id) VALUES(10,1,1);");
require dirname(__DIR__).'/services/TemporaryVoiceService.php';
require dirname(__DIR__).'/services/PeerBehaviorService.php';
function demand(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$voice=new TemporaryVoiceService($db);$voice->joinChannel(1,10);$voice->joinChannel(2,10);$voice->leaveChannel(1,10);
demand((int)$db->query('SELECT COUNT(*) FROM channels WHERE id=10')->fetchColumn()===1,'occupied room survives SQL lifecycle');
$voice->joinChannel(1,11);$voice->leaveChannel(1,10);$voice->leaveChannel(2,10);
demand((int)$db->query('SELECT COUNT(*) FROM channels WHERE id=10')->fetchColumn()===0,'last leave deleted real SQL room');
demand((int)$db->query('SELECT voice_channel_id FROM users WHERE id=1')->fetchColumn()===11,'late leave protects new join');
$d=new PeerBehaviorService($db);foreach([1,2] as $uid)foreach(['chat','library','code_lab','collaboration','dashboard'] as $page)$d->record($uid,$page);
$db->exec("INSERT INTO messages VALUES(1,11,UTC_TIMESTAMP(),0,'text');INSERT INTO code_lab_tasks VALUES(1,'php');INSERT INTO code_lab_attempts VALUES(1,1,UTC_TIMESTAMP(),80)");
$d->refresh();demand($d->profile(1)!==null&&$d->profile(2)!==null,'weekly profiles on real SQL');$d->refresh();
$db->exec('UPDATE user_settings SET ai_matching=0 WHERE user_id=1');demand($d->profile(1)===null,'real SQL optout');$d->clear(1);
echo "PASS: SQL migrations rerun, transactional voice lifecycle, stale leave, weekly summaries and optout\n";

$db->exec('CREATE TABLE dm_messages(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,sender_id BIGINT UNSIGNED,is_deleted TINYINT NOT NULL DEFAULT 0)');
require dirname(__DIR__).'/services/DmMessageIdentity.php';
$save=function()use($db){$db->exec('INSERT INTO dm_messages(sender_id) VALUES(1)');return (int)$db->lastInsertId();};
$nonce='b7fce7d0-174e-42d5-8ff7-118cf44c7461';
$first=DmMessageIdentity::record($db,1,$nonce,'dm',1,['text'],$save);$replay=DmMessageIdentity::record($db,1,$nonce,'dm',1,['text'],$save);
demand($first['id']===$replay['id']&&$replay['replayed'],'SQL retry identity persists once');
$rows=DmMessageIdentity::enrich($db,[['id'=>$first['id'],'sender_id'=>1]],1);demand($rows[0]['client_message_id']===$nonce,'SQL history carries correlation');
echo "PASS: real SQL send identity uniqueness and correlation\n";

$queue=new JarredReplyQueue($db);$requests=[];
for($uid=1;$uid<=5;$uid++){$requests[$uid]=$queue->reserve($uid,100+$uid,99,'SQL question '.$uid,null,[],function()use($db,$uid){$db->prepare('INSERT INTO dm_messages(sender_id) VALUES(?)')->execute([$uid]);return (int)$db->lastInsertId();});$queue->activate($uid,$requests[$uid]);}
$children=[];for($i=0;$i<5;$i++){$proc=proc_open([PHP_BINARY,__FILE__,'--claim-jarred'],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);demand(is_resource($proc),'SQL claim process starts');$children[]=[$proc,$pipes];}
$claims=[];foreach($children as [$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);demand(proc_close($proc)===0,'SQL claim child: '.$err);$job=json_decode($out,true,512,JSON_THROW_ON_ERROR);if($job)$claims[]=$job;}
demand(count($claims)===2,'five concurrent SQL claimers acquire only two job leases');
foreach($claims as $job){$reply=$queue->finish($job,function()use($db){$db->exec('INSERT INTO dm_messages(sender_id) VALUES(99)');return (int)$db->lastInsertId();});demand($reply!==null&&$queue->finish($job,fn()=>999)===null,'SQL reply publishes exactly once');}
$old=$queue->claim();$db->prepare('UPDATE jarred_reply_jobs SET lease_until=? WHERE id=?')->execute(['2000-01-01 00:00:00',$old['id']]);$new=$queue->claim();demand($new['id']===$old['id']&&$new['worker_token']!==$old['worker_token'],'SQL expired lease reclaims safely');demand($queue->finish($old,fn()=>999)===null,'SQL stale worker cannot publish');$queue->fail($new,504);demand($queue->retry((int)$new['user_id'],(int)$new['conversation_id'],(int)$new['request_message_id']),'SQL retry reuses saved question');
echo "PASS: real SQL atomic queue admission, five process/two worker claims, exact-once completion, lease recovery and retry\n";
