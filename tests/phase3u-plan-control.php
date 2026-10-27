<?php
declare(strict_types=1);
require_once __DIR__.'/dashboard-profile.php';
require_once __DIR__.'/../services/LearningWorkspaceService.php';
set_exception_handler(function(Throwable $e):void{fwrite(STDERR,(string)$e);exit(1);});
$dsn=getenv('CLASSROOM_DSN');if(!$dsn||!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=classroom_perf_test$/D',$dsn))throw new RuntimeException('Owned fixture required');
$source=file_get_contents(getenv('PHASE3U_BASELINE_SERVICE'));$source=preg_replace('/^<\?php\s*/','',$source);$source=preg_replace('/^require_once .*;\s*$/m','',$source);eval(str_replace('class LearningWorkspaceService','class ControlWorkspaceBase',$source));
$trial=file_get_contents(getenv('PHASE3U_TRIAL_SERVICE'));$trial=preg_replace('/^<\?php\s*/','',$trial);$trial=preg_replace('/^require_once .*;\s*$/m','',$trial);eval(str_replace('class LearningWorkspaceService','class ControlWorkspaceTrial',$trial));
$db=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_STATEMENT_CLASS=>[DashboardStatement::class]]);$db->exec('SET timestamp=1792800000');$queries=[];
foreach([new ControlWorkspaceBase($db),new ControlWorkspaceTrial($db)]as$i=>$service){DashboardProfile::$queries=[];$service->data(900,true,1);foreach(DashboardProfile::$queries as$q)if(str_contains($q['sql'],'COALESCE(activity'))$queries[$i]=$q['sql'];}
$out=['base_commit'=>'f77506a874c4650ec6c69637461c368876fa178d','trial_commit'=>'5366f48f1a128aa40077bf01e938157202759911','base_sha256'=>hash_file('sha256',getenv('PHASE3U_BASELINE_SERVICE')),'trial_sha256'=>hash_file('sha256',getenv('PHASE3U_TRIAL_SERVICE')),'queries'=>$queries,'params'=>[1,1,900],'cases'=>[]];$db->exec('CREATE TABLE control_channels AS SELECT * FROM channels');$db->exec('CREATE TABLE control_messages AS SELECT * FROM messages');
foreach(['sparse_small','sparse_large','dense_large','one_channel_large','empty_large']as$case){
 $db->exec('TRUNCATE channels;INSERT INTO channels SELECT * FROM control_channels;TRUNCATE messages;INSERT INTO messages SELECT * FROM control_messages'.($case==='sparse_small'?' WHERE id<=10000 OR id>100000':''));
 if($case==='dense_large')$db->exec('UPDATE channels SET server_id=1');
 if($case==='one_channel_large')$db->exec('UPDATE messages SET channel_id=1');
 if($case==='empty_large')$db->exec('DELETE FROM channels WHERE server_id=1');
 $row=['messages'=>$db->query('SELECT COUNT(*) FROM messages')->fetchColumn(),'samples'=>[],'plans'=>[]];
 foreach(['unrefreshed','analyzed']as$phase){
  if($phase==='analyzed')$row['analyze_table']=$db->query('ANALYZE TABLE messages,channels,server_members,users')->fetchAll();
  $reference=null;
  foreach([0,1,1,0,0,1,1,0,0,1,1,0]as$side){$s=$db->prepare($queries[$side]);$t=hrtime(true);$s->execute([1,1,900]);$result=$s->fetchAll();$row['samples'][$phase][]=['side'=>$side===0?'baseline':'candidate','ms'=>(hrtime(true)-$t)/1e6];if($reference===null)$reference=$result;if($result!==$reference)throw new LogicException('Control output mismatch '.$case.' '.$phase);}
  foreach($queries as$side=>$query){$s=$db->prepare('ANALYZE FORMAT=JSON '.$query);$s->execute([1,1,900]);$row['plans'][$phase][$side]=json_decode($s->fetchColumn(),true);}
 }
 $out['cases'][$case]=$row;
}
echo json_encode($out,JSON_THROW_ON_ERROR);
