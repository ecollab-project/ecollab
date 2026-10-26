<?php
declare(strict_types=1);
require_once __DIR__.'/dashboard-profile.php';
require_once __DIR__.'/../services/FacilitatorDashboardService.php';
$dsn=getenv('CLASSROOM_DSN');
if(!$dsn||!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=classroom_perf_test$/D',$dsn))throw new RuntimeException('Owned loopback fixture required');
$source=file_get_contents(getenv('PHASE3S_BASELINE_SERVICE'));
$source=preg_replace('/^<\?php\s*/','',$source);
$source=preg_replace('/^require_once .*;\s*$/m','',$source);
$source=str_replace('class FacilitatorDashboardService','class BaselineFacilitatorDashboardService',$source);
eval($source);
$db=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_STATEMENT_CLASS=>[DashboardStatement::class]]);
$db->exec('SET timestamp=1792800000');
$base=new BaselineFacilitatorDashboardService($db);$candidate=new FacilitatorDashboardService($db);
$methods=[new ReflectionMethod($base,'getChannelMemberActivity'),new ReflectionMethod($candidate,'getChannelMemberActivity')];
$cases=['populated'=>null,'no_live_messages'=>'UPDATE messages SET is_deleted=1 WHERE channel_id=1',
 'empty_members'=>'DELETE FROM server_members WHERE server_id=1',
 'deleted_users'=>'UPDATE users SET deleted_at=NOW() WHERE MOD(id,3)=0',
 'nonmember_messages'=>'DELETE FROM server_members WHERE server_id=1 AND MOD(user_id,2)=0',
 'timestamp_boundaries'=>"UPDATE messages SET created_at=CASE MOD(id,3) WHEN 0 THEN NULL WHEN 1 THEN '1990-01-01' ELSE '2099-01-01' END WHERE channel_id=1",
 'ties_and_zero'=>"DELETE FROM messages WHERE channel_id=1;INSERT INTO messages(channel_id,sender_id,content,is_deleted,created_at) SELECT 1,id,'Tie',0,NOW() FROM users WHERE MOD(id,3)=1",
 'thresholds'=>"DELETE FROM messages WHERE channel_id=1;INSERT INTO messages(channel_id,sender_id,content,is_deleted,created_at) SELECT 1,CASE WHEN seq<=4 THEN 1 WHEN seq<=9 THEN 4 WHEN seq<=28 THEN 7 WHEN seq<=48 THEN 10 WHEN seq<=97 THEN 13 ELSE 16 END,'Threshold',0,NOW() FROM seq_1_to_147",
 'missing_channel'=>null,'zero_channel'=>null];
$results=[];
foreach($cases as$name=>$setup){
 $db->beginTransaction();try{
  if($setup)$db->exec($setup);$cid=$name==='missing_channel'?999999:($name==='zero_channel'?0:1);
  $outputs=[];$plans=[];
  foreach([$base,$candidate]as$i=>$service){DashboardProfile::$queries=[];$outputs[]=$methods[$i]->invoke($service,$cid);
   foreach(DashboardProfile::$queries as$q){if(!$q['success'])throw new RuntimeException('Silent SQL failure');$stmt=$db->prepare('EXPLAIN '.$q['sql']);$stmt->execute($q['params']);$plans[$i]=$stmt->fetchAll();}
  }
  if($outputs[0]!==$outputs[1])throw new RuntimeException('Exact member output mismatch: '.$name.' '.json_encode($outputs));
  if(count($outputs[1])>15)throw new RuntimeException('Member limit changed');
  if($name==='thresholds'){
   $levels=[];foreach($outputs[1]as$row)$levels[(int)$row['messages']]=$row['activity_status'];
   foreach([4=>'inactive',5=>'moderate',19=>'moderate',20=>'active',49=>'active',50=>'very_active']as$count=>$level)if(($levels[$count]??null)!==$level)throw new RuntimeException('Threshold '.$count);
  }
  if($name==='populated'){
   $old=$base->getFacilitatorDashboardData(900);$new=$candidate->getFacilitatorDashboardData(900);
   if($old!==$new)throw new RuntimeException('Complete facilitator dashboard mismatch');
  }
  $results[$name]=['equal'=>true,'rows'=>count($outputs[1]),'output'=>$outputs[1],'plans'=>$plans];
 }finally{$db->rollBack();}
}
echo json_encode(['status'=>'PASS','cases'=>$results],JSON_THROW_ON_ERROR);
