<?php
declare(strict_types=1);
require_once __DIR__.'/../services/LiveKitCountSnapshot.php';
require_once __DIR__.'/../services/LiveKitService.php';
require_once __DIR__.'/../services/JarredJobService.php';
$dsn=getenv('PHASE2_TEST_DSN')?:'';
if(!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=ecollab_phase2_test$/D',$dsn))throw new RuntimeException('Dedicated local fixture required');
function db():PDO{global $dsn;return new PDO($dsn,getenv('PHASE2_TEST_USER')?:'root',getenv('PHASE2_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
function check($ok,$label){if(!$ok)throw new RuntimeException($label);}
$db=db();$jobs=new JarredJobService($db);
if(($argv[1]??'')==='reserve'){
 echo "ready\n";flush();$db->beginTransaction();try{$jobs->reserveConversation(1,1);echo 'accepted';}catch(RuntimeException $e){echo $e->getCode()===409?'busy':'unexpected';}finally{$db->rollBack();}exit;
}
$db->exec('UPDATE user_settings SET jarred_enabled=1');
$db->beginTransaction();$jobs->reserveConversation(1,1);
$p=proc_open([PHP_BINARY,__FILE__,'reserve'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
check(trim(fgets($pipes[1]))==='ready','Contender connected');
usleep(100000);check(proc_get_status($p)['running'],'Admission waits on row lock');
$db->exec("INSERT INTO dm_messages(conversation_id,sender_id,body) VALUES(1,1,'lock fixture')");$id=$jobs->enqueue(1,2,1,(int)$db->lastInsertId(),null,[]);$db->commit();
check(stream_get_contents($pipes[1])==='busy','Second admission observes committed active job');foreach($pipes as$f)fclose($f);check(proc_close($p)===0,'Admission child succeeded');$jobs->cancel(1,1,$id);
$other=db();check((int)$db->query("SELECT GET_LOCK('phase2-fixture-worker',0)")->fetchColumn()===1,'First worker owns lock');check((int)$other->query("SELECT GET_LOCK('phase2-fixture-worker',0)")->fetchColumn()===0,'Second worker blocked');$db->query("SELECT RELEASE_LOCK('phase2-fixture-worker')");
$db->exec(file_get_contents(__DIR__.'/../database/migrations/064_livekit_count_snapshot.sql'));
$s=new LiveKitCountSnapshot($db);$s->publish(['rooms'=>[['name'=>'ecollab-channel-1','numParticipants'=>3],['name'=>'ecollab-dm-999','numParticipants'=>7]]],time());
$db->exec(file_get_contents(__DIR__.'/../database/migrations/064_livekit_count_snapshot.sql'));
check((array)$s->read([1,2],time())['counts']===[1=>3,2=>0],'Snapshot migration rerun and upsert');
$s->publish(['rooms'=>[]],time());check($s->read([1],time())['counts']->{1}===0,'Fresh empty collector is authoritative');
$db->exec("CREATE TABLE realtime_outbox (kind ENUM('channel','dm','group') NOT NULL,target_id BIGINT,message_id BIGINT)");
$db->exec(file_get_contents(__DIR__.'/../database/migrations/065_code_lab_delivery.sql'));
$db->exec("INSERT INTO realtime_outbox VALUES('code_lab',1,1)");
$db->exec(file_get_contents(__DIR__.'/../database/migrations/065_code_lab_delivery.sql'));
check((int)$db->query('SELECT COUNT(*) FROM realtime_outbox')->fetchColumn()===1,'Delivery migration retains rows');
$db->exec(file_get_contents(__DIR__.'/../database/migrations/066_jarred_jobs.sql'));
check($jobs->status(1,1,$id)['job']['state']==='cancelled','Queue migration rerun retains jobs');
if(getenv('LIVEKIT_FIXTURE_RPC')){
 $live=new LiveKitService();
 foreach([50,150,450] as $clients){
  for($i=0;$i<$clients;$i++)$live->roomRequest('ListRooms',['names'=>['ecollab-channel-1']],['roomList'=>true]);
  $s->publish($live->roomRequest('ListRooms',['names'=>[]],['roomList'=>true]),time());
  for($i=0;$i<$clients;$i++)check($s->read([1],time())['counts']->{1}===3,'RPC fixture snapshot');
  echo "$clients readers: mock ListRooms HTTP requests before=$clients after=1\n";
 }
}
echo "PASS MariaDB admission contention, worker singleton, snapshot upsert and all migration reruns\n";
