<?php
declare(strict_types=1);
require_once __DIR__.'/dashboard-profile.php';require_once __DIR__.'/../services/StudentDashboardService.php';
set_exception_handler(function(Throwable $e):void{fwrite(STDERR,(string)$e);exit(1);});
$dsn=getenv('CLASSROOM_DSN');if(!$dsn||!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=classroom_perf_test$/D',$dsn))throw new RuntimeException('Owned fixture required');
$db=new DashboardPDO($dsn.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$db->exec("SET timestamp=1792800000;SET time_zone='+00:00'");$service=new StudentDashboardService($db);$method=new ReflectionMethod($service,'getChatUnreadCount');
$db->exec('CREATE TABLE w_native_messages AS SELECT * FROM messages;CREATE TABLE w_native_channels AS SELECT * FROM channels');
$out=['source_sha256'=>hash_file('sha256',__DIR__.'/../services/StudentDashboardService.php'),'config'=>$db->query('SELECT VERSION() version,@@innodb_stats_persistent persistent_stats,@@innodb_stats_auto_recalc auto_recalc,@@innodb_stats_persistent_sample_pages sample_pages,@@foreign_key_checks foreign_key_checks')->fetch(),'cases'=>[]];
foreach(['sparse_small','sparse_large','dense_server','hot_channel','empty']as$dataset){
 $db->exec('DELETE FROM messages;INSERT INTO messages SELECT * FROM w_native_messages'.($dataset==='sparse_small'?' WHERE id<=10000 OR id>100000':''));$db->exec('UPDATE channels c JOIN w_native_channels b ON b.id=c.id SET c.server_id=b.server_id');if($dataset==='dense_server')$db->exec('UPDATE channels SET server_id=1');if($dataset==='hot_channel')$db->exec('UPDATE messages SET channel_id=1');if($dataset==='empty')$db->exec('DELETE FROM messages');
 $row=['data'=>$db->query('SELECT COUNT(*) messages,COUNT(DISTINCT channel_id) channels,COUNT(DISTINCT sender_id) senders FROM messages')->fetch()];$expected=null;
 foreach(['initial_unrefreshed','analyzed']as$phase){
  if($phase==='analyzed'){$row['analyze_table']=$db->query('ANALYZE TABLE messages,channels,users,server_members,message_reads')->fetchAll();foreach($row['analyze_table']as$r)if($r['Msg_type']!=='status'||$r['Msg_text']!=='OK')throw new RuntimeException('Statistics refresh incomplete');}
  $row['stats'][$phase]=$db->query("SELECT table_name,index_name,stat_name,stat_value,sample_size FROM mysql.innodb_index_stats WHERE database_name=DATABASE() AND table_name IN ('messages','channels','server_members','message_reads') ORDER BY table_name,index_name,stat_name")->fetchAll();
  for($i=0;$i<4;$i++){DashboardProfile::$queries=[];$t=hrtime(true);$value=$method->invoke($service,1);$sample=['ms'=>(hrtime(true)-$t)/1e6,'unread'=>$value,'queries'=>DashboardProfile::$queries];if($expected===null)$expected=$value;if($value!==$expected)throw new RuntimeException('Statistics phase changed unread count');$row['samples'][$phase][]=$sample;}
  foreach($row['samples'][$phase][3]['queries']as$q){if(!$q['success'])throw new RuntimeException('Unexpected unread SQL error');$s=$db->prepare('ANALYZE FORMAT=JSON '.$q['sql']);foreach($q['bindings']as$b)$s->bindValue($b['parameter'],$b['value'],$b['pdo_type']);$s->execute();$row['plans'][$phase][]=['query'=>$q,'analyze'=>json_decode($s->fetchColumn(),true)];}
 }
 $out['cases'][$dataset]=$row;
}
$db->exec('DELETE FROM messages;UPDATE channels c JOIN w_native_channels b ON b.id=c.id SET c.server_id=b.server_id;INSERT INTO messages SELECT * FROM w_native_messages;DROP TABLE w_native_messages;DROP TABLE w_native_channels');echo json_encode($out,JSON_THROW_ON_ERROR);
