<?php
declare(strict_types=1);
require __DIR__.'/code-lab-regression.php';
require_once dirname(__DIR__).'/services/CodeLabExecutionService.php';
// Reuse isolated classroom schema, not application data.
$db->exec("UPDATE users SET status='active';INSERT INTO server_members VALUES(1,2)");
putenv('CODE_LAB_JUDGE0_URL=http://127.0.0.1:2358');putenv('CODE_LAB_LANGUAGE_IDS={"c":50,"cpp":54,"python":71,"java":62,"javascript":63}');
$payloads=[];$submitted=[];$counter=0;$broken=false;$compileError=false;
$transport=function(string $method,string $path,?array $body)use(&$payloads,&$submitted,&$counter,&$broken,&$compileError){if($broken)throw new RuntimeException('outage');if($method==='POST'){$payloads[]=$body;$token='token-'.(++$counter).'-fixture';$submitted[$token]=$body;return ['token'=>$token];}$token=explode('?',basename($path))[0];$p=$submitted[$token];$out=base64_decode($p['stdin'])==='private-case'?'SECRET_INPUT_OUTPUT':'42\n';return ['status'=>['id'=>$compileError?6:3],'stdout'=>base64_encode(str_replace('\\n',"\n",$out)),'stderr'=>base64_encode(''),'compile_output'=>base64_encode($compileError?'SECRET_INPUT_OUTPUT compiler':''),'exit_code'=>0,'time'=>'0.01','memory'=>1000];};
$runner=new CodeLabRunner('http://127.0.0.1:2358','token',$transport);$executor=new CodeLabExecutionService($db,$runner,fn()=>$GLOBALS['now']);
foreach(['web','javascript','html','css','js'] as $removed)clReject(fn()=>$executor->run(1,3,['language'=>$removed,'code'=>'test','stdin'=>'']),400,'Removed playground execution rejected');
$auto=$input;$auto['grading_mode']='automatic';$auto['test_cases']=[['input'=>'public-case','expected_output'=>'42','hidden'=>false,'weight'=>1],['input'=>'private-case','expected_output'=>'different','hidden'=>true,'weight'=>3]];
$tid=$service->create(1,1,$auto)['id'];$a=$service->start(1,2,$tid);
clCheck(count($a['task']['public_cases'])===1,'Only public cases reach students');clCheck($a['task']['runtime_id']==71,'Runtime pinned when task created');
$run=$executor->run(1,2,['task_id'=>$tid,'language'=>'shell','runtime_id'=>999,'code'=>'print(42)','stdin'=>'']);
$db->exec('UPDATE code_lab_run_gates SET request_count=60 WHERE user_id=2');
clReject(fn()=>$service->save(1,2,$tid,['version'=>1,'code'=>'print(42)'],true),429,'Quota failure rolls back submit');
clCheck($service->start(1,2,$tid)['attempt']['submitted_at']===null,'Failed enqueue cannot freeze student code');
$db->exec('UPDATE code_lab_run_gates SET request_count=1 WHERE user_id=2');
$a=$service->save(1,2,$tid,['version'=>1,'code'=>'print(42)'],true);$job=(int)$a['grade_job']['id'];
clCheck($a['attempt']['state']==='submitted','A pending run cannot block timely submission');
$executor->workOne();$result=$executor->result(1,2,$run['job_id']);clCheck($result['state']==='done'&&$result['result']['stdout']==="42\n",'Run output decoded');
clReject(fn()=>$executor->result(1,3,$run['job_id']),404,'Other student cannot read run results');
clCheck($payloads[0]['language_id']==71&&$payloads[0]['enable_network']===false&&!isset($payloads[0]['callback_url']),'Client cannot alter runtime, network or callback');
clCheck($payloads[0]['cpu_time_limit']===2&&$payloads[0]['wall_time_limit']===8&&$payloads[0]['memory_limit']===262144&&$payloads[0]['stack_limit']===65536,'Resource limits enforced in runner payload');
clCheck($executor->grade(1,2,$tid)['job_id']===$job,'Grading idempotent');$executor->workOne();$grade=$executor->result(1,2,$job);
clCheck($grade['result']['score']===25&&$grade['result']['passed']===1,'Weighted grading correct');
clCheck(!str_contains(json_encode($grade),'SECRET')&&!str_contains(json_encode($grade),'private-case'),'Hidden inputs, outputs and compiler errors are never returned');
clCheck((int)$service->start(1,2,$tid)['attempt']['score']===25,'Automatic grade persisted');
// Infrastructure failures do not become student failures; retry uses immutable submitted code.
$tid2=$service->create(1,1,$auto)['id'];$service->start(1,3,$tid2);$a2=$service->save(1,3,$tid2,['version'=>1,'code'=>'frozen'],true);$broken=true;$executor->workOne();
clCheck($executor->result(1,3,(int)$a2['grade_job']['id'])['state']==='failed','Outage reported');clCheck($service->start(1,3,$tid2)['attempt']['score']===null,'Outage gives no zero grade');
$broken=false;$service->review(1,1,$tid2,['student_id'=>3,'score'=>80,'feedback'=>'Manual assessment']);$executor->grade(1,3,$tid2);$executor->workOne();
clCheck((int)$service->start(1,3,$tid2)['attempt']['score']===80,'Late automatic grade preserves manual override');clCheck((int)$service->start(1,3,$tid2)['attempt']['auto_score']===25,'Automatic score retained separately');
$payloads=[];$compileError=true;$tid3=$service->create(1,1,$auto)['id'];$service->start(1,2,$tid3);$a3=$service->save(1,2,$tid3,['version'=>1,'code'=>'bad'],true);$executor->workOne();$g=$executor->result(1,2,(int)$a3['grade_job']['id']);clCheck($g['result']['score']===0&&!str_contains(json_encode($g),'SECRET'),'Compilation failure graded without leaking hidden diagnostics');$compileError=false;
$db->exec('UPDATE code_lab_run_gates SET request_count=60 WHERE user_id=2');clReject(fn()=>$executor->run(1,2,['language'=>'python','code'=>'x']),429,'Hourly quota');$now+=3600;clCheck($executor->run(1,2,['language'=>'python','code'=>'x'])['job_id']>0,'Quota window resets');$executor->workOne();
clReject(fn()=>CodeLabExecutionService::validateCases([['input'=>'x','expected_output'=>'y','weight'=>0]],'python'),400,'Invalid weights');
clReject(fn()=>CodeLabExecutionService::validateCases([['input'=>"#id' or true()",'expected_output'=>'']], 'web'),400,'Selector injection rejected');
clReject(fn()=>new CodeLabRunner('http://example.com'),0,'Public plaintext endpoint rejected');
if(class_exists('DOMDocument')){clCheck(CodeLabExecutionService::webCheck('<h1 id="title">Hello</h1>','#title','Hello'),'HTML check');clCheck(!CodeLabExecutionService::webCheck('<h1>Hello</h1>','button',''),'Missing element fails');clCheck(CodeLabExecutionService::webCheck('<script>throw Error()</script><p class="welcome">Hi</p>','.welcome','Hi'),'Web grading never executes scripts');}
$offlineId=$service->create(1,1,$auto)['id'];$service->start(1,3,$offlineId);putenv('CODE_LAB_JUDGE0_URL=');
$offlineSubmission=$service->save(1,3,$offlineId,['version'=>1,'code'=>'accepted before deadline'],true);
clCheck($offlineSubmission['attempt']['state']==='submitted','Configuration outage cannot block existing task submission');
$offlineExecutor=new CodeLabExecutionService($db,new CodeLabRunner(''),fn()=>$GLOBALS['now']);$offlineExecutor->workOne();
clCheck($service->start(1,3,$offlineId)['attempt']['score']===null,'Offline runner does not assign a zero');putenv('CODE_LAB_JUDGE0_URL=http://127.0.0.1:2358');
if(class_exists('DOMDocument')){
 $web=$auto;$web['language']='web';$web['duration_minutes']=null;$web['test_cases']=[['input'=>'#title','expected_output'=>'Hello','hidden'=>false],['input'=>'.missing','expected_output'=>'','hidden'=>true]];
 $legacy=$web;$legacy['language']='python';$legacy['test_cases']=[['input'=>'','expected_output'=>'Hello']];$webId=$service->create(1,1,$legacy)['id'];$db->exec("UPDATE code_lab_tasks SET language='web',runtime_id=NULL WHERE id=".(int)$webId);$db->exec('DELETE FROM code_lab_test_cases WHERE task_id='.(int)$webId);$insert=$db->prepare('INSERT INTO code_lab_test_cases(task_id,position,input_data,expected_output,hidden,weight) VALUES(?,?,?,?,?,?)');foreach($web['test_cases'] as $pos=>$case)$insert->execute([$webId,$pos,$case['input'],$case['expected_output'],(int)$case['hidden'],1]);$service->start(1,3,$webId);$submittedWeb=$service->save(1,3,$webId,['version'=>1,'code'=>'<h1 id="title">Hello</h1><script>throw Error()</script>'],true);$executor->workOne();
 clCheck($executor->result(1,3,(int)$submittedWeb['grade_job']['id'])['result']['score']===50,'HTML structure graded through private queue');
}
clCheck(CodeLabExecutionService::matches("a\r\nb\n","a\nb"),'Newline normalization');clCheck(!CodeLabExecutionService::matches(' a','a'),'Meaningful whitespace preserved');
// Maintenance must not rescan retained history before every fast execution.
// Recovery still runs when idle and leaves active/queued/grade rows intact.
$maintenance=new CodeLabExecutionService($db,$runner,fn()=>$GLOBALS['now']);$maintenance->workOne();
$insert=$db->prepare("INSERT INTO code_lab_jobs(server_id,user_id,language,runtime_id,kind,source_code,stdin,state,created_at,started_at) VALUES(1,2,'python',71,?,'private-source','',?,?,?)");
$old=gmdate('Y-m-d H:i:s',$now-90000);$stale=gmdate('Y-m-d H:i:s',$now-601);$recent=gmdate('Y-m-d H:i:s',$now);
$ids=[];foreach([['run','done',$old,null],['run','running',$recent,$stale],['grade','done',$old,null],['run','running',$recent,$recent]]as$row){$insert->execute($row);$ids[]=(int)$db->lastInsertId();}
$now+=59;$maintenance->workOne();
clCheck((int)$db->query('SELECT COUNT(*) FROM code_lab_jobs WHERE id IN ('.implode(',',$ids).')')->fetchColumn()===4,'No per-job retention scan during maintenance interval');
clCheck($db->query('SELECT state FROM code_lab_jobs WHERE id='.$ids[1])->fetchColumn()==='running','Recovery is deferred for at most maintenance interval');
$now++;$maintenance->workOne();
clCheck($db->query('SELECT state FROM code_lab_jobs WHERE id='.$ids[0])->fetchColumn()===false,'Expired run removed at maintenance boundary');
clCheck($db->query('SELECT state FROM code_lab_jobs WHERE id='.$ids[1])->fetchColumn()==='failed','Stale running claim recovered while idle');
clCheck($db->query('SELECT state FROM code_lab_jobs WHERE id='.$ids[2])->fetchColumn()==='done','Grades retained');
clCheck($db->query('SELECT state FROM code_lab_jobs WHERE id='.$ids[3])->fetchColumn()==='running','Live execution preserved');
clCheck($maintenance->lastTiming()===null,'Idle maintenance is not counted as a completed job');
$db->exec('DELETE FROM code_lab_jobs WHERE id IN ('.implode(',',$ids).')');
$run=$maintenance->run(1,2,['language'=>'python','code'=>'private-source','stdin'=>'private-input']);$maintenance->workOne();
$timing=$maintenance->lastTiming();clCheck($timing['state']==='done'&&$timing['language']==='python'&&$timing['runner_ms']>=0&&$timing['work_ms']>=$timing['runner_ms'],'Execution timings cover actual runner wait and worker overhead');
clCheck(!str_contains(json_encode($timing),'private-'),'Timing metadata contains no source or input');
require_once dirname(__DIR__).'/services/CodeLabWorkerMetrics.php';
$metrics=new CodeLabWorkerMetrics();$metrics->record(['language'=>'python','kind'=>'run','state'=>'done','runner_ms'=>40,'work_ms'=>50,'source'=>'PRIVATE']);$metrics->record(['language'=>'python','kind'=>'run','state'=>'failed','runner_ms'=>60,'work_ms'=>70]);
$summary=$metrics->drain(30);$g=$summary['groups']['run/python'];clCheck($summary['jobs']===2&&$g['failed']===1&&$g['runner_ms']['avg']===50.0&&$g['overhead_avg_ms']===10.0,'Bounded timing summary includes failures and correct aggregates');
clCheck(!str_contains(json_encode($summary),'PRIVATE')&&$metrics->drain(30)===null,'Only safe aggregates emitted and drained');
echo "PASS: queue ownership, frozen submissions, weighted grading, hidden-case privacy, quotas, runtime/limit enforcement, outage retry and manual overrides\n";
