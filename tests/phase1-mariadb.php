<?php
declare(strict_types=1);
// Refuse all non-local or non-dedicated targets. This script creates fixture tables.
$dsn=getenv('PHASE1_TEST_DSN')?:'';
if(!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=ecollab_phase1_test$/D',$dsn)){
 fwrite(STDERR,"Set PHASE1_TEST_DSN=mysql:host=127.0.0.1;port=3306;dbname=ecollab_phase1_test on a disposable local MariaDB.\n");exit(2);
}
require_once __DIR__.'/../services/TemporaryVoiceService.php';
require_once __DIR__.'/../services/PeerMatchingService.php';
function db():PDO{global $dsn;return new PDO($dsn,getenv('PHASE1_TEST_USER')?:'root',getenv('PHASE1_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);}
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
if(($argv[1]??'')==='worker'){
 $db=db();file_put_contents($argv[3],'ready');
 try{if($argv[2]==='join')(new TemporaryVoiceService($db))->join(1,10,fn()=>null);else(new TemporaryVoiceService($db))->markLeftAndSchedule(10);echo 'ok';}
 catch(RuntimeException $e){if($e->getCode()!==403)throw $e;echo 'denied';}exit;
}
$db=db();check(str_contains((string)$db->query('SELECT VERSION()')->fetchColumn(),'MariaDB'),'MariaDB required');
check((int)$db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()===0,'Use a new empty disposable test schema');
$db->exec('CREATE TABLE channels(id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB; CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY,voice_channel_id INT UNSIGNED NULL) ENGINE=InnoDB; CREATE TABLE channel_members(channel_id INT UNSIGNED,user_id BIGINT UNSIGNED) ENGINE=InnoDB');
$migration=file_get_contents(__DIR__.'/../database/migrations/063_temporary_voice.sql');$db->exec($migration);
$db->exec("INSERT INTO channels VALUES(10); INSERT INTO users VALUES(1,NULL); INSERT INTO temporary_voice_rooms(channel_id,server_id,owner_id,expires_at) VALUES(10,1,1,'2000-01-01')");
$db->exec($migration);check((int)$db->query('SELECT COUNT(*) FROM temporary_voice_rooms')->fetchColumn()===1,'Rerun retains records');
function race(PDO $db,string $operation,callable $finish,string $expected):void{
 $marker=tempnam(sys_get_temp_dir(),'phase1-');unlink($marker);
 $db->beginTransaction();$db->query('SELECT id FROM channels WHERE id=10 FOR UPDATE')->fetchColumn();
 $p=proc_open([PHP_BINARY,__FILE__,'worker',$operation,$marker],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 try{
  $until=microtime(true)+5;while(!is_file($marker)&&microtime(true)<$until)usleep(10000);
  check(is_file($marker),'Worker connected');usleep(100000);check(proc_get_status($p)['running'],'Worker waits for held channel lock');
  $finish($db);$db->commit();
  $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);foreach($pipes as$pipe)fclose($pipe);
  $code=proc_close($p);check($code===0&&$out===$expected,"Race result $out $err");
 }finally{if($db->inTransaction())$db->rollBack();if(is_file($marker))unlink($marker);}
}
// Deletion commits before waiting join: a deleted channel can never gain presence.
race($db,'join',function(PDO $db){$db->exec('DELETE FROM temporary_voice_rooms WHERE channel_id=10; DELETE FROM channels WHERE id=10');},'denied');
check($db->query('SELECT voice_channel_id FROM users WHERE id=1')->fetchColumn()===null,'No dangling presence');
$db->exec("INSERT INTO channels VALUES(10); INSERT INTO temporary_voice_rooms(channel_id,server_id,owner_id,expires_at) VALUES(10,1,1,'2000-01-01')");
// Join commits before waiting cleanup: fresh occupancy protects the channel.
race($db,'cleanup',function(PDO $db){$db->exec('UPDATE users SET voice_channel_id=10 WHERE id=1');},'ok');
check((int)$db->query('SELECT COUNT(*) FROM channels')->fetchColumn()===1,'Occupied room survives');
(new TemporaryVoiceService($db))->cleanupExpired();
check($db->query('SELECT expires_at FROM temporary_voice_rooms')->fetchColumn()===null,'Expired occupied room reset');
(new TemporaryVoiceService($db))->leave(1,10);check((int)$db->query('SELECT COUNT(*) FROM channels')->fetchColumn()===0,'Last leave deletes');
$other=db();check((int)$db->query("SELECT GET_LOCK('ecollab-phase1-test-singleton',0)")->fetchColumn()===1,'First maintenance lock');
check((int)$other->query("SELECT GET_LOCK('ecollab-phase1-test-singleton',0)")->fetchColumn()===0,'Concurrent maintenance excluded');$db->query("SELECT RELEASE_LOCK('ecollab-phase1-test-singleton')");
$db->exec('CREATE TABLE pm_compatibility(user_a_id INT,user_b_id INT,score_total DOUBLE,score_subjects DOUBLE,score_interests DOUBLE,score_hobbies DOUBLE,score_style DOUBLE,shared_subjects INT,shared_interests INT,shared_hobbies INT,match_tags TEXT,computed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(user_a_id,user_b_id))');
$s=new PeerMatchingService();$rows=[];for($i=2;$i<=101;$i++)$rows[]=[1,$i,20,30,40,50,60,1,2,3,'["tag"]'];$s->cacheCompatibility($db,$rows);
check((int)$db->query('SELECT COUNT(*) FROM pm_compatibility')->fetchColumn()===100,'100-row cache batch');
$rows[0][2]=75;$s->cacheCompatibility($db,$rows);check((float)$db->query('SELECT score_total FROM pm_compatibility WHERE user_b_id=2')->fetchColumn()===75.,'Cache update');
echo "PASS MariaDB: additive migration rerun, both channel lock orderings, expiry, last leave, singleton, 100-row cache insert/update\n";
