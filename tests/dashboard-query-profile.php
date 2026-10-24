<?php
declare(strict_types=1);
require_once __DIR__.'/dashboard-profile.php';
require_once __DIR__.'/../services/StudentDashboardService.php';
require getenv('DASHBOARD_BASELINE_CLASS');
$db=dashboardFixtureDb();$dashboard=new BaselineStudentDashboardService($db);
DashboardProfile::$queries=[];$t=hrtime(true);$data=$dashboard->getStudentDashboardData(1);$queries=DashboardProfile::$queries;
if(count($queries)!==25||array_filter($queries,fn($q)=>!$q['success']))throw new RuntimeException('Incomplete dashboard profile');
$unread=array_values(array_filter($queries,fn($q)=>str_contains($q['sql'],'message_reads')))[0];
$candidate="SELECT COALESCE(SUM((SELECT COUNT(*) FROM messages m WHERE m.channel_id=c.id AND m.is_deleted=0 AND m.sender_id<>:uid3 AND m.created_at>COALESCE(mr.last_read_at,'1970-01-01'))),0) FROM channels c JOIN server_members sm ON sm.server_id=c.server_id AND sm.user_id=:uid LEFT JOIN message_reads mr ON mr.channel_id=c.id AND mr.user_id=:uid2";
$scenarios=[
 'no_markers'=>"DELETE FROM message_reads",
 'recent_markers'=>"DELETE FROM message_reads;INSERT INTO message_reads SELECT id,1,DATE_SUB(NOW(),INTERVAL 1 DAY) FROM channels",
 'fully_read'=>"DELETE FROM message_reads;INSERT INTO message_reads SELECT id,1,DATE_ADD(NOW(),INTERVAL 1 DAY) FROM channels",
 'mixed_markers'=>"DELETE FROM message_reads;INSERT INTO message_reads SELECT id,1,DATE_SUB(NOW(),INTERVAL MOD(id,30) DAY) FROM channels WHERE MOD(id,3)<>0",
 'null_markers'=>"DELETE FROM message_reads;INSERT INTO message_reads SELECT id,1,NULL FROM channels",
 'many_memberships'=>"INSERT IGNORE INTO server_members SELECT id,1,'member',NOW() FROM servers",
 'deleted_and_own'=>"UPDATE messages SET is_deleted=1 WHERE MOD(id,3)=0;UPDATE messages SET sender_id=1 WHERE MOD(id,5)=0",
 'few_memberships'=>"DELETE FROM server_members WHERE user_id=1 AND server_id<>1",
 'no_memberships'=>"DELETE FROM server_members WHERE user_id=1",
];
$indexCosts=[];
$distribution=[];foreach(['existing','covering']as$indexState){
 if($indexState==='covering')$db->exec('ALTER TABLE messages ADD INDEX dashboard_unread (channel_id,is_deleted,created_at,sender_id), ALGORITHM=INPLACE, LOCK=NONE');
 $db->query('ANALYZE TABLE messages')->fetchAll();$size=$db->query("SELECT INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='messages'")->fetchColumn();
 $writes=[];for($i=0;$i<5;$i++){$db->beginTransaction();$t=hrtime(true);$db->exec("INSERT INTO messages(id,channel_id,sender_id,content,is_deleted,created_at) SELECT 200000+seq,1,1,'Synthetic write',0,NOW() FROM seq_1_to_1000");$writes[]=(hrtime(true)-$t)/1e6;$db->rollBack();}$indexCosts[$indexState]=['index_length_bytes'=>(int)$size,'insert_1000_ms'=>$writes];
 foreach($scenarios as$name=>$setup){$db->beginTransaction();$db->exec($setup);$case=[];foreach(['baseline'=>$unread['sql'],'candidate'=>$candidate]as$label=>$sql){$args=[':uid'=>1,':uid2'=>1,':uid3'=>1];$q=$db->prepare($sql);$plan=$db->prepare('EXPLAIN '.$sql);$plan->execute($args);$times=[];for($i=0;$i<7;$i++){$t=hrtime(true);$q->execute($args);$count=(int)$q->fetchColumn();$times[]=(hrtime(true)-$t)/1e6;}$case[$label]=['count'=>$count,'samples_ms'=>$times,'plan'=>$plan->fetchAll()];}if($case['baseline']['count']!==$case['candidate']['count'])throw new RuntimeException('Unread mismatch '.$name);$distribution[$indexState][$name]=$case;$db->rollBack();}
}
echo json_encode(['query_profile'=>$queries,'unread_distributions'=>$distribution,'index_costs'=>$indexCosts],JSON_THROW_ON_ERROR);
