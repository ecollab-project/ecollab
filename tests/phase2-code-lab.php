<?php
declare(strict_types=1);
require __DIR__.'/code-lab-execution-regression.php';
class CountStatusStatement extends PDOStatement { public static int $calls=0; public function execute(?array $params=null):bool{self::$calls++;return parent::execute($params);} }
$db->setAttribute(PDO::ATTR_STATEMENT_CLASS,[CountStatusStatement::class]);
$db->exec("UPDATE code_lab_jobs SET state='queued' WHERE id=".(int)$run['job_id']);
$reader=new CodeLabExecutionService($db,$runner);
foreach([50,150,450] as $clients){
 CountStatusStatement::$calls=0;for($i=0;$i<$clients;$i++)$reader->result(1,2,(int)$run['job_id'],true);$before=CountStatusStatement::$calls;
 CountStatusStatement::$calls=0;for($i=0;$i<$clients;$i++)$reader->result(1,2,(int)$run['job_id'],false);$after=CountStatusStatement::$calls;
 clCheck($before===$clients*3&&$after===$clients*2,'Reduced SQL execution count');echo "$clients clients: status SQL executions $before -> $after (position omitted)\n";
}
$db->exec("CREATE TABLE moderation_actions(target_user_id INTEGER,server_id INTEGER,action_type TEXT,is_active INTEGER,expires_at TEXT);CREATE TABLE realtime_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT,kind TEXT,target_id INTEGER,message_id INTEGER)");
putenv('CENTRIFUGO_ENABLED=true');
$db->beginTransaction();RealtimeOutbox::record($db,'code_lab',1,fn()=>(int)$run['job_id']);$db->commit();
$db->exec("UPDATE code_lab_jobs SET state='done' WHERE id=".(int)$run['job_id']);
$event=$db->query('SELECT * FROM realtime_outbox')->fetch(PDO::FETCH_ASSOC);
$db->beginTransaction();RealtimeOutbox::codeLabState($db,1,(int)$run['job_id']);$db->commit();
$next=$db->query('SELECT * FROM realtime_outbox')->fetch(PDO::FETCH_ASSOC);
clCheck($next['id']>$event['id'],'Retry completion gets fresh event identity');$event=$next;
clCheck(RealtimeOutbox::recipients($db,$event)===[2],'Only owning student receives event');
$db->exec('DELETE FROM server_members WHERE server_id=1 AND user_id=2');
clCheck(RealtimeOutbox::recipients($db,$event)===[],'Revoked membership excludes event');
echo "PASS durable metadata-only event and current ownership/membership\n";
