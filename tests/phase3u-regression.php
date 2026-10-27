<?php
declare(strict_types=1);
require_once __DIR__.'/dashboard-profile.php';
require_once __DIR__.'/../services/LearningWorkspaceService.php';
set_exception_handler(function(Throwable $e):void{fwrite(STDERR,(string)$e);exit(1);});
$dsn=getenv('CLASSROOM_DSN');if(!$dsn||!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=classroom_perf_test$/D',$dsn))throw new RuntimeException('Owned fixture required');
$source=file_get_contents(getenv('PHASE3U_BASELINE_SERVICE'));
$source=preg_replace('/^<\?php\s*/','',$source);$source=preg_replace('/^require_once .*;\s*$/m','',$source);
eval(str_replace('class LearningWorkspaceService','class BaselineWorkspace3U',$source));
$db=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_STATEMENT_CLASS=>[DashboardStatement::class]]);$db->exec('SET timestamp=1792800000');
$services=[new BaselineWorkspace3U($db),new LearningWorkspaceService($db)];
$cases=['populated'=>null,'database_utc'=>null,'database_plus8'=>null,'database_minus7'=>null,'empty_messages'=>'DELETE FROM messages','no_channels'=>'DELETE FROM channels WHERE server_id=1',
 'empty_members'=>'DELETE FROM server_members WHERE server_id=1 AND user_id<>900',
 'only_one_member'=>'DELETE FROM server_members WHERE server_id=1 AND user_id NOT IN(1,900)',
 'one_channel'=>'DELETE FROM channels WHERE server_id=1 AND id<>1',
 'deleted_users'=>'UPDATE users SET deleted_at=NOW() WHERE MOD(id,7)=0',
 'deleted_messages'=>'UPDATE messages SET is_deleted=1 WHERE MOD(id,3)=0',
 'all_deleted_messages'=>'UPDATE messages SET is_deleted=1',
 'null_message_fields'=>'UPDATE messages SET created_at=NULL,is_deleted=NULL WHERE MOD(id,3)=0',
 'null_author_channel'=>'UPDATE messages SET channel_id=IF(MOD(id,2)=0,NULL,channel_id),sender_id=IF(MOD(id,3)=0,NULL,sender_id)',
 'null_server'=>'UPDATE channels SET server_id=NULL WHERE MOD(id,3)=0',
 'orphan_channel'=>'UPDATE messages SET channel_id=999999 WHERE MOD(id,3)=0',
 'old_future_boundary'=>"UPDATE messages SET created_at=CASE MOD(id,5) WHEN 0 THEN NULL WHEN 1 THEN '1999-01-01' WHEN 2 THEN DATE_SUB(NOW(),INTERVAL 30 DAY) WHEN 3 THEN DATE_SUB(NOW(),INTERVAL 30 DAY)-INTERVAL 1 SECOND ELSE '2099-01-01' END",
 'nonmember_authors'=>'DELETE FROM server_members WHERE server_id=1 AND MOD(user_id,2)=0 AND user_id<>900',
 'missing_user'=>'DELETE FROM users WHERE MOD(id,5)=0 AND id<>900',
 'equal_counts'=>'UPDATE messages SET is_deleted=1',
 'null_names'=>"UPDATE users SET full_name=NULL,username=IF(id=1,NULL,username)",
 'all_channels_in_server'=>'UPDATE channels SET server_id=1',
 'over_500_members'=>"INSERT INTO server_members SELECT 1,id,'member',NOW() FROM users WHERE id<>900 ON DUPLICATE KEY UPDATE server_role=server_role",
 'owner_not_member'=>'DELETE FROM server_members WHERE user_id=900',
 'private_channels'=>'UPDATE channels SET is_private=1,created_by=2',
 'archived_server'=>"UPDATE servers SET status='archived' WHERE id=1",
 'inactive_server'=>"UPDATE servers SET status='inactive' WHERE id=1",
 'foreign_owner'=>'UPDATE servers SET owner_id=1 WHERE id=1',
 'unknown_server'=>null,'zero_server'=>null,'student_mode'=>null,'nonowner_facilitator'=>null];
foreach(['owner','admin','moderator','member']as$role)$cases['role_'.$role]="UPDATE server_members SET server_role='$role' WHERE user_id<>900";
$out=[];$tables='users,servers,channels,server_members,messages,student_notes,notifications,calendar_events,study_room_sessions,uploaded_files';
foreach($cases as$name=>$setup){$db->beginTransaction();try{
 $db->exec("SET time_zone='".match($name){'database_plus8'=>'+08:00','database_minus7'=>'-07:00',default=>'+00:00'}."'");
 if($setup)$db->exec($setup);
 $before=$db->query('CHECKSUM TABLE '.$tables)->fetchAll();$outputs=[];$queries=[];$plans=[];
 $sid=match($name){'unknown_server'=>999999,'zero_server'=>0,default=>1};$uid=$name==='nonowner_facilitator'?1:900;$fac=$name!=='student_mode';
 foreach($services as$i=>$service){DashboardProfile::$queries=[];try{$data=$service->data($uid,$fac,$sid);foreach($data as$key=>$value)if($key!=='limits'&&!$value['available'])throw new LogicException('Unavailable source '.$key);$outputs[$i]=['data'=>$data];}catch(RuntimeException $e){$outputs[$i]=['exception'=>get_class($e),'message'=>$e->getMessage(),'code'=>$e->getCode()];}
  foreach(DashboardProfile::$queries as$q){if(!$q['success'])throw new LogicException('Silent SQL failure');if(str_contains($q['sql'],'COALESCE(activity')){$queries[$i]=$q;if(in_array($name,['populated','all_channels_in_server','no_channels','old_future_boundary'],true)){$s=$db->prepare('ANALYZE FORMAT=JSON '.$q['sql']);$s->execute($q['params']);$plans[$i]=json_decode($s->fetchColumn(),true);}}}
 }
 if($outputs[0]!==$outputs[1])throw new LogicException('Full workspace output/type/order/failure mismatch '.$name);
 if(in_array($name,['archived_server','foreign_owner','unknown_server','nonowner_facilitator'],true)&&($outputs[1]['code']??null)!==403)throw new LogicException('Ownership guard lost');
 if($name==='over_500_members'&&count($outputs[1]['data']['members']['items'])!==500)throw new LogicException('Limit incomplete');
 if(in_array($name,['empty_messages','all_deleted_messages','equal_counts'],true))foreach($outputs[1]['data']['members']['items']as$row)if((int)$row['messages']!==0||$row['last_message']!==null)throw new LogicException('Zero/null aggregate mismatch');
 // Independent count/window/last-message oracle for each eligible returned member.
 foreach($outputs[1]['data']['members']['items']??[]as$row){$s=$db->prepare('SELECT COUNT(CASE WHEN m.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) THEN 1 END) messages,MAX(m.created_at) last_message FROM messages m JOIN channels c ON c.id=m.channel_id WHERE c.server_id=? AND m.sender_id=? AND m.is_deleted=0');$s->execute([$sid,$row['id']]);$expected=$s->fetch();$last=$expected['last_message'];if($last!==null)$last=(new DateTimeImmutable($last,new DateTimeZone('UTC')))->modify(sprintf('%+d seconds',-(int)$db->query('SELECT TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),NOW())')->fetchColumn()))->format('Y-m-d\TH:i:s\Z');if((string)$row['messages']!==(string)$expected['messages']||$row['last_message']!==$last)throw new LogicException('Count/time oracle mismatch '.$name.' '.json_encode([$row,$expected,$last]));}
 $after=$db->query('CHECKSUM TABLE '.$tables)->fetchAll();if($before!==$after)throw new LogicException('Read modified stored data');
 $out[$name]=['equal'=>true,'data_checksums_equal'=>true,'complete_output'=>$outputs[1],'queries'=>$queries,'analyze'=>$plans];
 }finally{$db->rollBack();}}
echo json_encode(['status'=>'PASS','cases'=>$out],JSON_THROW_ON_ERROR);
