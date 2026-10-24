<?php
declare(strict_types=1);
require_once __DIR__.'/dashboard-profile.php';
require_once __DIR__.'/../services/StudentDashboardService.php';
$dsn=getenv('CHAT_PERF_DSN');if(!$dsn||!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=chat_perf_test$/D',$dsn))throw new RuntimeException('Owned fixture only');
require getenv('CHAT_BASELINE_CLASS');
$db=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STATEMENT_CLASS=>[DashboardStatement::class]]);
function bench(callable $read):array{DashboardProfile::$queries=[];if(function_exists('memory_reset_peak_usage'))memory_reset_peak_usage();$memory=memory_get_usage();$cpu=DashboardProfile::cpu();$t=hrtime(true);$data=$read();return ['data'=>$data,'ms'=>(hrtime(true)-$t)/1e6,'php_cpu_ms'=>DashboardProfile::cpu()-$cpu,'peak_delta_bytes'=>memory_get_peak_usage()-$memory,'queries'=>count(DashboardProfile::$queries),'sql_success'=>!array_filter(DashboardProfile::$queries,fn($q)=>!$q['success'])];}
$beforeService=new BaselineStudentDashboardService($db);$afterService=new StudentDashboardService($db);
$beforeRead=new ReflectionMethod($beforeService,'getRecentChat');$afterRead=new ReflectionMethod($afterService,'getRecentChat');
DashboardProfile::$queries=[];$beforeRead->invoke($beforeService,1);$old=DashboardProfile::$queries[0]['sql'];
DashboardProfile::$queries=[];$afterRead->invoke($afterService,1);$fast=DashboardProfile::$queries[0]['sql'];
$cases=[
 'distributed'=>null,
 'sparse_membership'=>"DELETE FROM server_members WHERE user_id=1 AND server_id<>1",
 'no_membership'=>"DELETE FROM server_members WHERE user_id=1",
 'recent_busy_channel'=>"UPDATE messages SET channel_id=1 WHERE id>99000;UPDATE messages SET created_at=FROM_UNIXTIME(1800000000+id) WHERE id>99000",
 'old_accessible_only'=>"UPDATE messages m JOIN channels c ON c.id=m.channel_id JOIN server_members sm ON sm.server_id=c.server_id AND sm.user_id=1 SET m.created_at='2000-01-01'",
 'deleted_recent'=>"UPDATE messages SET is_deleted=1 WHERE id>98000",
 'all_timestamps_tied'=>"UPDATE messages SET created_at='2026-10-24 00:00:00'",
 'only_eight_visible'=>"UPDATE messages SET is_deleted=1 WHERE id NOT IN(SELECT id FROM (SELECT m.id FROM messages m JOIN channels c ON c.id=m.channel_id JOIN server_members sm ON sm.server_id=c.server_id AND sm.user_id=1 ORDER BY m.created_at DESC LIMIT 8) keep_rows)",
 'null_dates'=>"UPDATE messages SET created_at=NULL",
 'empty_messages'=>"DELETE FROM messages",
 'many_memberships'=>"INSERT IGNORE INTO server_members SELECT id,1,'member',NOW() FROM servers",
];
$out=[];foreach(['without067','with067']as$state){if($state==='with067')$db->exec(file_get_contents(__DIR__.'/../database/migrations/067_dashboard_unread_covering.sql'));foreach($cases as$name=>$setup){$db->beginTransaction();if($setup)$db->exec($setup);$runs=['baseline'=>[],'candidate'=>[]];$used=[];for($i=0;$i<7;$i++)foreach($i%2?['candidate','baseline']:['baseline','candidate']as$side){$r=bench(function()use($side,$db,$old,$fast,&$used){global $beforeService,$afterService,$beforeRead,$afterRead;if($side==='baseline')return $beforeRead->invoke($beforeService,1);$rows=$afterRead->invoke($afterService,1);$used[]=count(DashboardProfile::$queries)===1;return $rows;});if(!$r['sql_success'])throw new RuntimeException('Profile SQL failed');$runs[$side][]=$r;}
 foreach($runs['candidate']as$i=>$run)if($run['data']!==$runs['baseline'][$i]['data'])throw new RuntimeException('Exact recent output mismatch '.$state.' '.$name);
 $plans=[];foreach(['baseline'=>$old,'fast'=>$fast]as$key=>$sql){$q=$db->prepare('EXPLAIN '.$sql);$args=[':uid'=>1];if(str_contains($sql,':gate_uid'))$args[':gate_uid']=1;if(str_contains($sql,':membership_uid'))$args[':membership_uid']=1;$q->execute($args);$plans[$key]=$q->fetchAll(PDO::FETCH_ASSOC);}
 foreach($runs as&$samples)foreach($samples as&$run)unset($run['data']);unset($run,$samples);
 $out[$state][$name]=['runs'=>$runs,'fast_path_hits'=>count(array_filter($used)),'plan'=>$plans];$db->rollBack();}}
$db->beginTransaction();$db->exec("INSERT INTO messages(id,channel_id,sender_id,content,is_deleted,created_at) VALUES(100006,1,1,'New fixture',0,'2099-01-01')");
if((int)$afterRead->invoke($afterService,1)[0]['id']!==100006)throw new RuntimeException('Fresh read lost');
if($afterRead->invoke($afterService,2)!==$beforeRead->invoke($beforeService,2))throw new RuntimeException('User isolation');
$db->exec('DELETE FROM server_members WHERE user_id=1');if($afterRead->invoke($afterService,1)!==[])throw new RuntimeException('Membership freshness');$db->rollBack();
echo json_encode($out,JSON_THROW_ON_ERROR);
