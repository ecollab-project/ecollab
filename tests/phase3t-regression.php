<?php
declare(strict_types=1);
require_once __DIR__.'/dashboard-profile.php';
require_once __DIR__.'/../services/FacilitatorDashboardService.php';
$dsn=getenv('CLASSROOM_DSN');if(!$dsn||!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=classroom_perf_test$/D',$dsn))throw new RuntimeException('Owned fixture required');
$source=file_get_contents(getenv('PHASE3T_BASELINE_SERVICE'));
$source=preg_replace('/^<\?php\s*/','',$source);$source=preg_replace('/^require_once .*;\s*$/m','',$source);
eval(str_replace('class FacilitatorDashboardService','class BaselinePrimaryChannelService',$source));
$db=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_STATEMENT_CLASS=>[DashboardStatement::class]]);$db->exec('SET timestamp=1792800000');
$services=[new BaselinePrimaryChannelService($db),new FacilitatorDashboardService($db)];$methods=array_map(fn($s)=>new ReflectionMethod($s,'getFacilitatorPrimaryChannel'),$services);
$cases=['populated'=>null,'no_channels'=>'DELETE FROM channels','one_channel'=>'DELETE FROM channels WHERE id<>1',
 'equal_positions'=>'UPDATE channels SET position=0','two_minimum_positions'=>'UPDATE channels SET position=IF(id IN(1,2),-1,id)',
 'tie_varying_width'=>"UPDATE channels SET position=0,description=REPEAT('x',MOD(id,255)+1)",
 'reverse_positions'=>'UPDATE channels SET position=102-id','nullable_fields'=>'UPDATE channels SET description=NULL,created_at=NULL,position=IF(id IN(1,2),NULL,id)',
 'no_membership'=>'DELETE FROM server_members WHERE user_id=900',
 'nonmember_channel'=>'DELETE FROM server_members WHERE user_id=900 AND server_id=1',
 'deleted_messages'=>'UPDATE messages SET is_deleted=1 WHERE channel_id=1',
 'null_message_fields'=>'UPDATE messages SET is_deleted=NULL,created_at=NULL WHERE channel_id=1',
 'attachments'=>"INSERT INTO message_attachments(id,message_id,file_name,file_path,file_size,mime_type,created_at) VALUES(1,100001,'A','/synthetic',1,'text/plain',NOW()),(2,100001,'B','/synthetic',1,'text/plain',NOW()),(3,NULL,'Orphan','/synthetic',1,'text/plain',NOW());UPDATE messages SET is_deleted=1 WHERE id=100001"];
foreach(['owner','admin','moderator','member']as$role)$cases['role_'.$role]="UPDATE server_members SET server_role='$role' WHERE user_id=900";
for($i=1;$i<=24;$i++)$cases['tie_permutation_'.$i]='UPDATE channels SET position=MOD(id*'.$i.',7);UPDATE server_members SET server_role=CASE MOD(server_id+'.$i.',4) WHEN 0 THEN \'member\' WHEN 1 THEN \'owner\' WHEN 2 THEN \'admin\' ELSE \'moderator\' END WHERE user_id=900';
$results=[];
foreach($cases as$name=>$setup){$db->beginTransaction();try{
 if($setup)$db->exec($setup);
 $before=$db->query('CHECKSUM TABLE channels,servers,server_members,messages,message_attachments')->fetchAll();
 $outputs=[];$plans=[];$queries=[];
 foreach($services as$i=>$service){DashboardProfile::$queries=[];$outputs[$i]=$methods[$i]->invoke($service,900);
  foreach(DashboardProfile::$queries as$q){if(!$q['success'])throw new RuntimeException('Silent SQL failure');$queries[$i]=$q;
   if(in_array($name,['populated','equal_positions','attachments'],true)){$s=$db->prepare('ANALYZE FORMAT=JSON '.$q['sql']);$s->execute($q['params']);$plans[$i]=json_decode($s->fetchColumn(),true);}
  }
 }
 if($outputs[0]!==$outputs[1])throw new RuntimeException('Channel selection/count/type mismatch '.$name.' '.json_encode($outputs));
 if(in_array($name,['no_channels','no_membership','role_member'],true)&&$outputs[1]!==null)throw new RuntimeException('Expected empty result '.$name);
 if($name==='attachments'&&((int)$outputs[1]['file_count']!==2||(int)$outputs[1]['message_count']!== (int)$db->query('SELECT COUNT(*) FROM messages WHERE channel_id=1 AND is_deleted=0')->fetchColumn()))throw new RuntimeException('Attachment/deleted-message semantics');
 if($name==='populated'){
  if($services[0]->getFacilitatorDashboardData(900)!==$services[1]->getFacilitatorDashboardData(900))throw new RuntimeException('Complete dashboard mismatch');
 }
 $after=$db->query('CHECKSUM TABLE channels,servers,server_members,messages,message_attachments')->fetchAll();if($before!==$after)throw new RuntimeException('Read mutated fixture data');
 $results[$name]=['equal'=>true,'output'=>$outputs[1],'data_checksums_equal'=>true,'queries'=>$queries,'analyze'=>$plans];
}finally{$db->rollBack();}}
echo json_encode(['status'=>'PASS','cases'=>$results],JSON_THROW_ON_ERROR);
