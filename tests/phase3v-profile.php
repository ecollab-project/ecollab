<?php
declare(strict_types=1);
require_once __DIR__.'/dashboard-profile.php';
require_once __DIR__.'/../services/StudentDashboardService.php';
require_once __DIR__.'/../services/LearningWorkspaceService.php';
set_exception_handler(function(Throwable $e):void{fwrite(STDERR,(string)$e);exit(1);});
$dsn=getenv('CLASSROOM_DSN');if(!$dsn||!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=classroom_perf_test$/D',$dsn))throw new RuntimeException('Owned fixture required');
$db=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_STATEMENT_CLASS=>[DashboardStatement::class]]);$db->exec("SET timestamp=1792800000;SET time_zone='+00:00'");
$service=new StudentDashboardService($db);$workspace=new LearningWorkspaceService($db);$out=['service_sha256'=>hash_file('sha256',__DIR__.'/../services/StudentDashboardService.php'),'profile_sha256'=>hash_file('sha256',__FILE__),'php_version'=>PHP_VERSION,'database_version'=>$db->query('SELECT VERSION()')->fetchColumn(),'stats_configuration'=>$db->query('SELECT @@innodb_stats_persistent persistent_stats,@@innodb_stats_auto_recalc auto_recalc,@@innodb_stats_persistent_sample_pages sample_pages')->fetch(),'clock'=>1792800000,'sql_mode'=>$db->query('SELECT @@sql_mode')->fetchColumn(),'cases'=>[]];
DashboardProfile::$queries=[];$service->getStudentDashboardData(1);$unread=array_values(array_filter(DashboardProfile::$queries,fn($q)=>str_contains($q['sql'],'SUM((SELECT COUNT(*) FROM messages')))[0]['sql'];
$flat="SELECT COUNT(*) FROM channels c JOIN server_members sm ON sm.server_id=c.server_id AND sm.user_id=:uid LEFT JOIN message_reads mr ON mr.channel_id=c.id AND mr.user_id=:uid2 JOIN messages m ON m.channel_id=c.id WHERE m.is_deleted=0 AND m.sender_id<>:uid3 AND m.created_at>COALESCE(mr.last_read_at,'1970-01-01')";
foreach(['messages','channels','server_members','message_reads']as$t)$out['initial_indexes'][$t]=$db->query('SHOW INDEX FROM '.$t)->fetchAll();
$db->exec('CREATE TABLE v_messages AS SELECT * FROM messages;CREATE TABLE v_channels AS SELECT * FROM channels');
foreach(['legacy','existing_067']as$index){
 if($index==='existing_067')$db->exec(file_get_contents(__DIR__.'/../database/migrations/067_dashboard_unread_covering.sql'));
 foreach(['small','large','dense_server','hot_channel','empty']as$dataset){
  $db->exec('TRUNCATE messages;INSERT INTO messages SELECT * FROM v_messages'.($dataset==='small'?' WHERE id<=10000 OR id>100000':''));$db->exec('TRUNCATE channels;INSERT INTO channels SELECT * FROM v_channels');
  if($dataset==='dense_server')$db->exec('UPDATE channels SET server_id=1');if($dataset==='hot_channel')$db->exec('UPDATE messages SET channel_id=1');if($dataset==='empty')$db->exec('DELETE FROM messages');
  $row=['rows'=>$db->query('SELECT COUNT(*) messages,COUNT(DISTINCT channel_id) channels,COUNT(DISTINCT sender_id) senders FROM messages')->fetch(),'profiles'=>[],'unread_variants'=>[]];
  foreach(['initial','analyzed']as$phase){
   if($phase==='analyzed')$row['statistics_refresh']=$db->query('ANALYZE TABLE messages,channels,server_members,users,message_reads')->fetchAll();
   $row['statistics_before'][$phase]=$db->query("SELECT table_name,last_update,n_rows FROM mysql.innodb_table_stats WHERE database_name=DATABASE() ORDER BY table_name")->fetchAll();
   for($i=0;$i<3;$i++){DashboardProfile::$queries=[];$t=hrtime(true);$data=$service->getStudentDashboardData(1);$row['profiles'][$phase][]=['ms'=>(hrtime(true)-$t)/1e6,'query_count'=>count(DashboardProfile::$queries),'queries'=>DashboardProfile::$queries,'chat_unread'=>$data['chat_unread']];}
   $ranked=$row['profiles'][$phase][2]['queries'];usort($ranked,fn($a,$b)=>$b['ms']<=>$a['ms']);$seen=[];
   foreach($ranked as$q){if(isset($seen[$q['sql']]))continue;$seen[$q['sql']]=true;$p=$db->prepare('EXPLAIN FORMAT=JSON '.$q['sql']);$p->execute($q['params']);$row['ranked_plans'][$phase][]=['query'=>$q,'plan'=>json_decode($p->fetchColumn(),true)];if(count($seen)>=8)break;}
   foreach([1,900,899]as$uid){$p=[':uid'=>$uid,':uid2'=>$uid,':uid3'=>$uid];$expected=null;
    foreach(['base','flat','flat','base','base','flat','flat','base']as$side){$sql=$side==='base'?$unread:$flat;$s=$db->prepare($sql);$t=hrtime(true);$s->execute($p);$value=(int)$s->fetchColumn();$row['unread_variants'][$phase][$uid]['samples'][]=['side'=>$side,'ms'=>(hrtime(true)-$t)/1e6,'value'=>$value];if($expected===null)$expected=$value;if($value!==$expected)throw new LogicException('Unread mismatch');}
    foreach(['base'=>$unread,'flat'=>$flat]as$side=>$sql){$s=$db->prepare('ANALYZE FORMAT=JSON '.$sql);$s->execute($p);$row['unread_variants'][$phase][$uid]['queries'][$side]=['sql'=>$sql,'params'=>$p,'analyze'=>json_decode($s->fetchColumn(),true)];}
   }
   DashboardProfile::$queries=[];$workspace->data(900,true,1);foreach(DashboardProfile::$queries as$q)if(str_contains($q['sql'],'COALESCE(activity')){$s=$db->prepare('EXPLAIN FORMAT=JSON '.$q['sql']);$s->execute($q['params']);$row['workspace_control'][$phase]=['query'=>$q,'explain'=>json_decode($s->fetchColumn(),true)];}
  }
  $out['cases'][$index][$dataset]=$row;
 }
}
echo json_encode($out,JSON_THROW_ON_ERROR);
