<?php
declare(strict_types=1);
// Existing synthetic classroom and SQLite-only application queue; no deployed DB.
require __DIR__.'/code-lab-regression.php';
require_once __DIR__.'/../services/CodeLabExecutionService.php';
$db->exec("UPDATE users SET status='active';DELETE FROM server_members WHERE server_id=1 AND user_id=2;INSERT INTO server_members VALUES(1,2)");
$task=$input;$task['grading_mode']='automatic';$task['test_cases']=[['input'=>'fixture-private-input','expected_output'=>'42','hidden'=>true,'weight'=>1]];
$tid=$service->create(1,1,$task)['id'];$service->start(1,2,$tid);
// PHP-FPM need not read the worker's private key to persist a deadline submission.
$key=getenv('CODE_LAB_RUNNER_TLS_KEY');putenv('CODE_LAB_RUNNER_TLS_KEY=/nonexistent-fixture-worker-key');
$a=$service->save(1,2,$tid,['version'=>1,'code'=>'print(42)'],true);$id=(int)$a['grade_job']['id'];
clCheck($a['attempt']['state']==='submitted','Worker credential outage blocked submission persistence');putenv('CODE_LAB_RUNNER_TLS_KEY='.$key);
$executor=new CodeLabExecutionService($db,new CodeLabRunner(),fn()=>$GLOBALS['now']);
echo "FIXTURE_RUNNER_OUTAGE\n";flush();clCheck(trim((string)fgets(STDIN))==='continue','Missing outage acknowledgement');
clCheck($executor->workOne(),'Queue work missing');clCheck($executor->result(1,2,$id)['state']==='failed','TLS outage not classified as infrastructure failure');
clCheck($service->start(1,2,$tid)['attempt']['score']===null,'TLS outage assigned zero');
echo "FIXTURE_RUNNER_RECOVERY\n";flush();clCheck(trim((string)fgets(STDIN))==='continue','Missing recovery acknowledgement');
clCheck((int)$executor->grade(1,2,$tid)['job_id']===$id,'Retry created a duplicate job');
clCheck($executor->workOne(),'Recovered queue work missing');$result=$executor->result(1,2,$id);
clCheck($result['state']==='done'&&(float)$result['result']['score']===100.,'Recovered grading incorrect');
clCheck(!str_contains(json_encode($result),'fixture-private-input'),'Hidden input leaked');
clCheck((int)$executor->grade(1,2,$tid)['job_id']===$id&&!$executor->workOne(),'Completed grade re-executed');
clCheck((int)$db->query('SELECT COUNT(*) FROM code_lab_jobs WHERE attempt_id='.(int)$a['attempt']['id'])->fetchColumn()===1,'Duplicate grade record');
clCheck($service->start(1,2,$tid)['attempt']['code']==='print(42)','Frozen submission changed on retry');
echo "PASS actual mTLS outage/recovery through application queue: no zero marks, same job, immutable submission, hidden result filtering and no duplicate grades\n";
