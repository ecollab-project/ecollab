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
clCheck($payloads[0]['cpu_time_limit']===2&&$payloads[0]['wall_time_limit']===8&&$payloads[0]['memory_limit']===262144,'Resource limits enforced in runner payload');
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
 $webId=$service->create(1,1,$web)['id'];$service->start(1,3,$webId);$submittedWeb=$service->save(1,3,$webId,['version'=>1,'code'=>'<h1 id="title">Hello</h1><script>throw Error()</script>'],true);$executor->workOne();
 clCheck($executor->result(1,3,(int)$submittedWeb['grade_job']['id'])['result']['score']===50,'HTML structure graded through private queue');
}
clCheck(CodeLabExecutionService::matches("a\r\nb\n","a\nb"),'Newline normalization');clCheck(!CodeLabExecutionService::matches(' a','a'),'Meaningful whitespace preserved');
echo "PASS: queue ownership, frozen submissions, weighted grading, hidden-case privacy, quotas, runtime/limit enforcement, outage retry and manual overrides\n";
