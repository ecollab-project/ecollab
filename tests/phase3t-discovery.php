<?php
declare(strict_types=1);
require_once __DIR__.'/dashboard-profile.php';
require_once __DIR__.'/../services/FacilitatorDashboardService.php';
require_once __DIR__.'/../services/StudentDashboardService.php';
$dsn=getenv('CLASSROOM_DSN');if(!$dsn||!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=classroom_perf_test$/D',$dsn))throw new RuntimeException('Owned fixture required');
$db=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_STATEMENT_CLASS=>[DashboardStatement::class]]);$db->exec('SET timestamp=1792800000');
$service=new FacilitatorDashboardService($db);$method=new ReflectionMethod($service,'getFacilitatorPrimaryChannel');
$late="SELECT chosen.id,chosen.name,chosen.description,chosen.member_count,chosen.created_at,chosen.server_name,
 (SELECT COUNT(*) FROM messages m WHERE m.channel_id=chosen.id AND m.is_deleted=0) message_count,
 (SELECT COUNT(*) FROM message_attachments ma JOIN messages m2 ON m2.id=ma.message_id WHERE m2.channel_id=chosen.id) file_count
 FROM (SELECT c.id,c.name,c.description,c.member_count,c.created_at,s.name server_name
 FROM channels c JOIN servers s ON s.id=c.server_id JOIN server_members sm ON sm.server_id=c.server_id AND sm.user_id=:uid
 WHERE sm.server_role IN ('owner','admin','moderator') ORDER BY c.position ASC LIMIT 1) chosen";
$out=[];
foreach(['unique_positions'=>"UPDATE channels SET position=id",'all_tied'=>"UPDATE channels SET position=0",'two_tied'=>"UPDATE channels SET position=IF(id IN(1,2),-1,id)",'null_positions'=>"UPDATE channels SET position=IF(id IN(1,2),NULL,id)",'reverse_positions'=>"UPDATE channels SET position=102-id"]as$name=>$setup){
 $db->beginTransaction();try{$db->exec($setup);$runs=[];$rows=[];$queries=[];
 foreach(['old','late']as$side){$samples=[];for($i=0;$i<8;$i++){DashboardProfile::$queries=[];$t=hrtime(true);if($side==='old')$r=$method->invoke($service,900);else{$s=$db->prepare($late);$s->execute([':uid'=>900]);$r=$s->fetch()?:null;}$samples[]=(hrtime(true)-$t)/1e6;$rows[$side]=$r;$queries[$side]=DashboardProfile::$queries[0];}$runs[$side]=$samples;}
 $plans=[];foreach($queries as$side=>$q){$s=$db->prepare('ANALYZE FORMAT=JSON '.$q['sql']);$s->execute($q['params']);$plans[$side]=json_decode($s->fetchColumn(),true);}
 $out[$name]=['equal'=>$rows['old']===$rows['late'],'rows'=>$rows,'ms'=>$runs,'queries'=>$queries,'analyze'=>$plans];
 }finally{$db->rollBack();}}
// Rank the unchanged student unread query separately, without changing application code.
$student=new StudentDashboardService($db);$m=new ReflectionMethod($student,'getChatUnreadCount');DashboardProfile::$queries=[];
try{$r=$m->invoke($student,1);$out['unread']=['value'=>$r,'queries'=>DashboardProfile::$queries];}catch(Throwable $e){$out['unread']=['error'=>$e->getMessage()];}
echo json_encode($out,JSON_THROW_ON_ERROR);
