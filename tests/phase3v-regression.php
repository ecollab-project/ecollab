<?php
declare(strict_types=1);
require_once __DIR__.'/dashboard-profile.php';
require_once __DIR__.'/../services/StudentDashboardService.php';
set_exception_handler(function(Throwable $e):void{fwrite(STDERR,(string)$e);exit(1);});
$dsn=getenv('CLASSROOM_DSN');if(!$dsn||!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=classroom_perf_test$/D',$dsn))throw new RuntimeException('Owned fixture required');
$source=file_get_contents(getenv('PHASE3V_BASELINE_SERVICE'));$source=preg_replace('/^<\?php\s*/','',$source);$source=preg_replace('/^require_once .*;\s*$/m','',$source);eval(str_replace('class StudentDashboardService','class BaselineStudent3V',$source));
$db=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_STATEMENT_CLASS=>[DashboardStatement::class]]);$db->exec("SET timestamp=1792800000;SET time_zone='+00:00'");
$services=[new BaselineStudent3V($db),new StudentDashboardService($db)];$methods=array_map(fn($s)=>new ReflectionMethod($s,'getChatUnreadCount'),$services);
$cases=['populated'=>null,'no_messages'=>'DELETE FROM messages','no_channels'=>'DELETE FROM channels',
 'no_membership'=>'DELETE FROM server_members WHERE user_id=1','one_membership'=>'DELETE FROM server_members WHERE user_id=1 AND server_id<>1',
 'hot_channel'=>'UPDATE messages SET channel_id=1','dense_server'=>'UPDATE channels SET server_id=1',
 'nonmember_hot_channel'=>'UPDATE messages SET channel_id=1;DELETE FROM server_members WHERE user_id=1 AND server_id=1',
 'all_deleted'=>'UPDATE messages SET is_deleted=1','deleted_flags'=>'UPDATE messages SET is_deleted=CASE MOD(id,3) WHEN 0 THEN NULL WHEN 1 THEN 2 ELSE 0 END',
 'null_sender_channel'=>'UPDATE messages SET sender_id=IF(MOD(id,2)=0,NULL,sender_id),channel_id=IF(MOD(id,3)=0,NULL,channel_id)',
 'own_messages'=>'UPDATE messages SET sender_id=1','deleted_authors'=>'UPDATE users SET deleted_at=NOW() WHERE MOD(id,2)=0',
 'nonmember_authors'=>'DELETE FROM server_members WHERE user_id<>1',
 'cursor_null'=>"INSERT INTO message_reads SELECT id,1,NULL FROM channels ON DUPLICATE KEY UPDATE last_read_at=NULL",
 'cursor_old'=>"INSERT INTO message_reads SELECT id,1,'1990-01-01' FROM channels ON DUPLICATE KEY UPDATE last_read_at='1990-01-01'",
 'cursor_future'=>"INSERT INTO message_reads SELECT id,1,'2099-01-01' FROM channels ON DUPLICATE KEY UPDATE last_read_at='2099-01-01'",
 'cursor_boundaries'=>"INSERT INTO message_reads SELECT id,1,'2026-10-24 00:00:00' FROM channels ON DUPLICATE KEY UPDATE last_read_at='2026-10-24 00:00:00';UPDATE messages SET created_at=CASE MOD(id,4) WHEN 0 THEN '2026-10-24 00:00:00' WHEN 1 THEN '2026-10-23 23:59:59' WHEN 2 THEN '2026-10-24 00:00:01' ELSE NULL END",
 'epoch_boundary'=>"UPDATE messages SET created_at=CASE MOD(id,3) WHEN 0 THEN '1969-12-31 23:59:59' WHEN 1 THEN '1970-01-01 00:00:00' ELSE '1970-01-01 00:00:01' END",
 'private_without_channel_members'=>'UPDATE channels SET is_private=1;DELETE FROM channel_members WHERE user_id=1',
 'private_explicit_membership'=>'UPDATE channels SET is_private=1;INSERT INTO channel_members SELECT id,1 FROM channels',
 'archived_servers'=>"UPDATE servers SET status='archived'",'inactive_servers'=>"UPDATE servers SET status='inactive'",
 'nullable_times'=>'UPDATE messages SET created_at=NULL WHERE MOD(id,2)=0',
 'future_messages'=>"UPDATE messages SET created_at='2099-01-02'",
 'mixed_dm'=>'UPDATE direct_messages SET is_read=IF(MOD(id,2)=0,0,NULL),is_deleted=IF(MOD(id,3)=0,1,0)',
 'all_dm_deleted'=>'UPDATE direct_messages SET is_deleted=1','large_cursor_skew'=>"INSERT INTO message_reads SELECT id,1,IF(MOD(id,3)=0,'2099-01-01',NULL) FROM channels ON DUPLICATE KEY UPDATE last_read_at=VALUES(last_read_at)"];
foreach(['owner','admin','moderator','member']as$role)$cases['role_'.$role]="UPDATE server_members SET server_role='$role' WHERE user_id=1";
$out=[];$tables='users,servers,channels,server_members,messages,message_reads,direct_messages';
foreach($cases as$name=>$setup){$db->beginTransaction();try{
 if($setup)$db->exec($setup);$before=$db->query('CHECKSUM TABLE '.$tables)->fetchAll();$outputs=[];$queries=[];
 foreach($services as$i=>$service){DashboardProfile::$queries=[];$outputs[$i]=['unread'=>$methods[$i]->invoke($service,1),'dashboard'=>$service->getStudentDashboardData(1)];$queries[$i]=DashboardProfile::$queries;foreach($queries[$i]as$q)if(!$q['success'])throw new LogicException('Unexpected silent SQL failure '.$name);}
 if($outputs[0]!==$outputs[1])throw new LogicException('Full dashboard mismatch '.$name);
 $eligible=$db->query('SELECT c.id,mr.last_read_at FROM channels c JOIN server_members sm ON sm.server_id=c.server_id AND sm.user_id=1 LEFT JOIN message_reads mr ON mr.channel_id=c.id AND mr.user_id=1')->fetchAll();$map=[];foreach($eligible as$r)$map[$r['id']][]=$r['last_read_at']??'1970-01-01 00:00:00';
 $expected=0;foreach($db->query('SELECT channel_id,sender_id,is_deleted,created_at FROM messages')->fetchAll()as$m){if($m['sender_id']===null||(int)$m['sender_id']===1||$m['is_deleted']===null||(int)$m['is_deleted']!==0||$m['created_at']===null)continue;foreach($map[$m['channel_id']]??[]as$cursor)if(strcmp($m['created_at'],$cursor)>0)$expected++;}
 foreach($db->query('SELECT * FROM direct_messages WHERE recipient_id=1')->fetchAll()as$m)if($m['is_read']!==null&&(int)$m['is_read']===0&&$m['is_deleted']!==null&&(int)$m['is_deleted']===0)$expected++;
 if($outputs[1]['unread']!==$expected)throw new LogicException('Independent unread oracle mismatch '.$name);
 $after=$db->query('CHECKSUM TABLE '.$tables)->fetchAll();if($before!==$after)throw new LogicException('Read mutated stored data');
 $out[$name]=['equal'=>true,'oracle'=>$expected,'data_checksums_equal'=>true,'complete_output'=>$outputs[1],'queries'=>$queries];
 }finally{$db->rollBack();}}
final class Phase3VFailureStatement extends PDOStatement {
 public static string $mode='';
 public function execute(?array $params=null):bool{if((self::$mode==='channel'||self::$mode==='both')&&str_contains($this->queryString,'SUM((SELECT COUNT(*) FROM messages'))throw new RuntimeException('Owned channel-count fault');if((self::$mode==='dm'||self::$mode==='both')&&str_contains($this->queryString,'FROM direct_messages WHERE recipient_id'))throw new RuntimeException('Owned DM-count fault');return parent::execute($params);}
}
$normal=$methods[0]->invoke($services[0],1);$dm=(int)$db->query('SELECT COUNT(*) FROM direct_messages WHERE recipient_id=1 AND is_read=0 AND is_deleted=0')->fetchColumn();
$faultDb=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STATEMENT_CLASS=>[Phase3VFailureStatement::class]]);$faultDb->exec("SET timestamp=1792800000;SET time_zone='+00:00'");
foreach(['channel'=>$dm,'dm'=>$normal-$dm,'both'=>0]as$mode=>$expected){Phase3VFailureStatement::$mode=$mode;$values=[];foreach([new BaselineStudent3V($faultDb),new StudentDashboardService($faultDb)]as$s)$values[]=(new ReflectionMethod($s,'getChatUnreadCount'))->invoke($s,1);if($values!==[$expected,$expected])throw new LogicException('Partial-failure behavior mismatch');$out['expected_fault_'.$mode]=['equal'=>true,'unread'=>$expected,'expected_fault'=>true];}
echo json_encode(['status'=>'PASS','cases'=>$out,'scope'=>'Identical accepted application source; no flat-join optimization retained'],JSON_THROW_ON_ERROR);
