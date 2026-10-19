<?php
declare(strict_types=1);
require __DIR__.'/code-lab-regression.php';
require_once dirname(__DIR__).'/services/CodeLabExecutionService.php';
$db->exec("UPDATE users SET status='active';INSERT INTO server_members VALUES(1,2);DELETE FROM code_lab_jobs");
$seen=[];
$transport=function($method,$path,$body)use(&$seen,$db):array{
    clCheck(!$db->inTransaction(),'Compiler never runs inside dispatch transaction');
    if($method==='POST'){$seen[]=base64_decode($body['source_code']);return ['token'=>'synthetic-token'];}
    return ['status'=>['id'=>3],'stdout'=>base64_encode('42'),'stderr'=>'','compile_output'=>''];
};
$runner=new CodeLabRunner('http://127.0.0.1:2358','fixture',$transport);
$worker=new CodeLabExecutionService($db,$runner,fn()=>$GLOBALS['now']);
$insert=$db->prepare("INSERT INTO code_lab_jobs(server_id,user_id,language,runtime_id,kind,source_code,stdin,state,created_at) VALUES(?,?,'python',71,'run',?,'','queued',?)");
$ids=[];foreach([[1,2,'A1',-3],[1,2,'A2',-2],[1,2,'A3',-1],[2,4,'B1',-2],[2,4,'B2',-1]]as[$sid,$uid,$source,$offset]){$insert->execute([$sid,$uid,$source,gmdate('Y-m-d H:i:s',$now+$offset)]);$ids[$source]=(int)$db->lastInsertId();}
clCheck($worker->result(1,2,$ids['A3'])['queue_position']===3,'Position excludes other servers');
clReject(fn()=>$worker->result(1,3,$ids['A3']),404,'Another student cannot inspect queue position');
clReject(fn()=>$worker->result(1,2,$ids['B1']),404,'Cross-server job remains private');
$worker->workOne();$worker=new CodeLabExecutionService($db,$runner,fn()=>$GLOBALS['now']); // cursor survives restart
while($worker->workOne()){}
clCheck($seen===['A1','B1','A2','B2','A3'],'Round-robin persists across restart, skips empty servers and preserves FIFO');
clCheck($worker->result(1,2,$ids['A3'])['queue_position']===null,'Finished jobs have no queue position');
// A retry with an older ID must follow already-waiting work by arrival time.
$insert->execute([1,2,'Waiting',gmdate('Y-m-d H:i:s',$now)]);
$q=$db->prepare("UPDATE code_lab_jobs SET state='queued',created_at=?,started_at=NULL WHERE id=?");$q->execute([gmdate('Y-m-d H:i:s',$now+1),$ids['A1']]);
$worker->workOne();$worker->workOne();clCheck(array_slice($seen,-2)===['Waiting','A1'],'Retried job returns to server queue tail');
$insert->execute([2,4,'New server arrival',gmdate('Y-m-d H:i:s',$now)]);$worker->workOne();
clCheck(end($seen)==='New server arrival','Previously empty server resumes immediately');
clCheck(!$worker->workOne(),'Empty queues return idle');
echo "PASS: server fairness, persistent cursor, retry arrival order, private advisory positions, late arrivals and unlocked compilation\n";
