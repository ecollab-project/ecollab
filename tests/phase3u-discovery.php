<?php
declare(strict_types=1);
require_once __DIR__.'/dashboard-profile.php';
require_once __DIR__.'/../services/LearningWorkspaceService.php';
$dsn=getenv('CLASSROOM_DSN');if(!$dsn||!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=classroom_perf_test$/D',$dsn))throw new RuntimeException('Owned fixture required');
$db=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_STATEMENT_CLASS=>[DashboardStatement::class]]);$db->exec('SET timestamp=1792800000');
$source=file_get_contents(getenv('PHASE3U_BASELINE_SERVICE'));$source=preg_replace('/^<\?php\s*/','',$source);$source=preg_replace('/^require_once .*;\s*$/m','',$source);eval(str_replace('class LearningWorkspaceService','class DiscoveryWorkspaceBase',$source));
DashboardProfile::$queries=[];(new DiscoveryWorkspaceBase($db))->data(900,true,1);
$out=['full_profile'=>DashboardProfile::$queries,'indexes'=>[],'variants'=>[]];
foreach(['messages','channels','server_members']as$table)$out['indexes'][$table]=$db->query('SHOW INDEX FROM '.$table)->fetchAll();
$q=array_values(array_filter(DashboardProfile::$queries,fn($q)=>str_contains($q['sql'],'COALESCE(activity')))[0];
$variants=['base'=>$q['sql'],'in'=>str_replace('FROM messages m JOIN channels c ON c.id=m.channel_id\n                    WHERE c.server_id=?','FROM messages m WHERE m.channel_id IN (SELECT id FROM channels WHERE server_id=?)',$q['sql'])];
// Whitespace in captured SQL is normalized by the PDO measurement shim.
$variants['in']=preg_replace('/FROM messages m JOIN channels c ON c.id=m.channel_id\s+WHERE c.server_id=\?/','FROM messages m WHERE m.channel_id IN (SELECT id FROM channels WHERE server_id=?)',$q['sql']);
$variants['exists']=preg_replace('/FROM messages m JOIN channels c ON c.id=m.channel_id\s+WHERE c.server_id=\?/','FROM messages m WHERE EXISTS (SELECT 1 FROM channels c WHERE c.id=m.channel_id AND c.server_id=?)',$q['sql']);
$variants['channel_first']=str_replace('FROM messages m JOIN channels c ON c.id=m.channel_id','FROM channels c STRAIGHT_JOIN messages m ON m.channel_id=c.id',$q['sql']);
$variants['materialized_in']=preg_replace('/FROM messages m JOIN channels c ON c.id=m.channel_id\s+WHERE c.server_id=\?/','FROM messages m WHERE m.channel_id IN (SELECT id FROM channels WHERE server_id=? GROUP BY id HAVING COUNT(*)=1)',$q['sql']);
$reference=null;
for($i=0;$i<6;$i++)foreach($i%2?array_reverse($variants,true):$variants as$name=>$query){$s=$db->prepare($query);$t=hrtime(true);$s->execute([1,1,900]);$rows=$s->fetchAll();$out['variants'][$name]['ms'][]=(hrtime(true)-$t)/1e6;if($reference===null)$reference=$rows;if($rows!==$reference)throw new RuntimeException('Discovery mismatch '.$name);}
foreach($variants as$name=>$query){$out['variants'][$name]['sql']=$query;$out['variants'][$name]['params']=[1,1,900];$s=$db->prepare('ANALYZE FORMAT=JSON '.$query);$s->execute([1,1,900]);$out['variants'][$name]['analyze']=json_decode($s->fetchColumn(),true);}
foreach(['dense'=>'UPDATE channels SET server_id=1','empty'=>'DELETE FROM channels WHERE server_id=1']as$case=>$setup){$db->beginTransaction();try{$db->exec($setup);$reference=null;for($i=0;$i<4;$i++)foreach($i%2?array_reverse($variants,true):$variants as$name=>$query){$s=$db->prepare($query);$t=hrtime(true);$s->execute([1,1,900]);$rows=$s->fetchAll();$out['distribution'][$case][$name][]=(hrtime(true)-$t)/1e6;if($reference===null)$reference=$rows;if($rows!==$reference)throw new RuntimeException('Distribution mismatch');}}finally{$db->rollBack();}}
echo json_encode($out,JSON_THROW_ON_ERROR);
