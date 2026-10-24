<?php
declare(strict_types=1);
require_once __DIR__.'/dashboard-profile.php';
require_once __DIR__.'/../services/LearningWorkspaceService.php';
require getenv('CLASSROOM_BASELINE_SERVICE');
$dsn=getenv('CLASSROOM_DSN');if(!$dsn||!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=classroom_perf_test$/D',$dsn))throw new RuntimeException('Owned fixture only');
$db=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_STATEMENT_CLASS=>[DashboardStatement::class]]);
$db->exec('SET timestamp=1792800000');
$old=new BaselineLearningWorkspaceService($db);$new=new LearningWorkspaceService($db);$out=[];
$cases=['populated'=>null,'second_server'=>null,'empty_members'=>"DELETE FROM server_members WHERE server_id=1 AND user_id<>900",'deleted_users'=>"UPDATE users SET deleted_at=NOW() WHERE MOD(id,7)=0",'null_old_future_deleted'=>"UPDATE messages SET created_at=CASE MOD(id,4) WHEN 0 THEN NULL WHEN 1 THEN '1999-01-01' WHEN 2 THEN DATE_SUB(NOW(),INTERVAL 30 DAY) ELSE '2099-01-01' END,is_deleted=IF(MOD(id,5)=0,1,0)",'nonmember_authors'=>"DELETE FROM server_members WHERE server_id=1 AND MOD(user_id,2)=0 AND user_id<>900",'over_500_members'=>"INSERT INTO server_members SELECT 1,id,'member',NOW() FROM users WHERE id<>900 ON DUPLICATE KEY UPDATE server_role=server_role",'empty_messages'=>"DELETE FROM messages"];
foreach($cases as$name=>$setup){fwrite(STDERR,'PROFILE '.$name."\n");$db->beginTransaction();if($setup)$db->exec($setup);$sid=$name==='second_server'?2:1;$runs=['baseline'=>[],'candidate'=>[]];$plan=[];
 for($i=0;$i<3;$i++)foreach($i%2?['candidate','baseline']:['baseline','candidate']as$side){DashboardProfile::$queries=[];$cpu=DashboardProfile::cpu();if(function_exists('memory_reset_peak_usage'))memory_reset_peak_usage();$memory=memory_get_usage();$t=hrtime(true);$data=($side==='baseline'?$old:$new)->data(900,true,$sid);$ms=(hrtime(true)-$t)/1e6;
  foreach($data as$key=>$source)if($key!=='limits'&&!$source['available'])throw new RuntimeException('Unavailable fixture '.$name.' '.$key);
  foreach(DashboardProfile::$queries as$q){if(!$q['success'])throw new RuntimeException('Profile SQL failed');if(str_starts_with($q['sql'],'SELECT u.id,u.username,u.full_name,sm.server_role,'))$plan[$side]=$q;}
  $runs[$side][]=['data'=>$data,'ms'=>$ms,'cpu_ms'=>DashboardProfile::cpu()-$cpu,'peak_delta_bytes'=>memory_get_peak_usage()-$memory,'queries'=>count(DashboardProfile::$queries)];
 }
 foreach($runs['candidate']as$i=>$run)if($run['data']!==$runs['baseline'][$i]['data'])throw new RuntimeException('Exact workspace mismatch '.$name);
 if($name==='over_500_members'&&count($runs['candidate'][0]['data']['members']['items'])!==500)throw new RuntimeException('Limit fixture incomplete');
 foreach($plan as&$q){$explain=$db->prepare('EXPLAIN '.$q['sql']);$explain->execute($q['params']);$q['explain']=$explain->fetchAll(PDO::FETCH_ASSOC);}unset($q);
 foreach($runs as&$samples)foreach($samples as&$sample)unset($sample['data']);unset($samples,$sample);
 $out[$name]=['runs'=>$runs,'plans'=>$plan];$db->rollBack();
}
foreach([$old,$new]as$service){try{$service->data(1,true,1);throw new LogicException('Owner guard lost');}catch(RuntimeException $e){if($e->getCode()!==403)throw $e;}}
echo json_encode($out,JSON_THROW_ON_ERROR);
