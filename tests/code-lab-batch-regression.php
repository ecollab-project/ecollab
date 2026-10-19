<?php
declare(strict_types=1);
require dirname(__DIR__).'/services/CodeLabRunner.php';
function verify(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
$calls=[];$malformed=false;
$row=['status'=>['id'=>3],'stdout'=>base64_encode('42'),'stderr'=>'','compile_output'=>''];
$runner=new CodeLabRunner('http://127.0.0.1:2358','fixture',function($method,$path,$body)use(&$calls,&$malformed,$row){
 $calls[]=[$method,$path,$body];
 if($path==='/languages')return [['id'=>10001,'ecollab_grade_v1'=>true]];
 if($method==='POST')return ['token'=>'batch-fixture-123','poll_wait_ms'=>1000];
 return ['status'=>['id'=>3],'results'=>$malformed?[$row]:[$row,$row]];
});
foreach([1,2]as$_){$results=$runner->executeCases(10001,'source',['private-A','private-B']);verify(count($results)===2&&$results[0]['stdout']==='42','Batch decode');}
verify(count(array_filter($calls,fn($c)=>$c[1]==='/languages'))===1,'Capabilities must be cached per client');
$posts=array_values(array_filter($calls,fn($c)=>$c[0]==='POST'));
verify($posts[0][1]==='/grade-submissions','Single private batch submission');
verify($posts[0][2]['test_inputs']===[base64_encode('private-A'),base64_encode('private-B')],'Only inputs enter batch payload');
verify(!str_contains(json_encode($posts),'expected_output'),'Expected answers stay outside runner');
$malformed=true;
try{$runner->executeCases(10001,'source',['a','b']);throw new LogicException('Accepted incomplete batch');}catch(RuntimeException $e){verify($e->getMessage()==='Incomplete grading execution.','Incomplete batch is infrastructure failure');}
$legacyPosts=0;
$legacy=new CodeLabRunner('http://127.0.0.1:2358','fixture',function($method,$path,$body)use(&$legacyPosts,$row){if($path==='/languages')return [['id'=>10001]];if($method==='POST'){$legacyPosts++;return ['token'=>'legacy-token-123'];}return $row;});
verify(count($legacy->executeCases(10001,'source',['a','b']))===2&&$legacyPosts===2,'Legacy adapter fallback');
echo "PASS: capability negotiation, one batch per grade, strict completion, output decoding and legacy fallback\n";
