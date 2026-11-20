<?php
declare(strict_types=1);
// Exercise actual PHP HTTP headers with synthetic responses and no app/DB session.
$listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
if($listener===false)throw new RuntimeException('Cannot reserve test port');
$address=stream_socket_get_name($listener,false);fclose($listener);
$router=tempnam(sys_get_temp_dir(),'ecollab-frame-policy-');
if($router===false)throw new RuntimeException('Cannot create test router');
file_put_contents($router, <<<'PHP'
<?php
$root=getenv('ECOLLAB_FRAME_POLICY_TEST_ROOT');
if($_SERVER['REQUEST_URI']==='/workspace')require $root.'/includes/calls/workspace-frame-policy.php';
else {require $root.'/security/SecurityHeaders.php';SecurityHeaders::send(true);}
echo 'synthetic-policy-response';
PHP);
$env=getenv();$env['ECOLLAB_FRAME_POLICY_TEST_ROOT']=dirname(__DIR__);
$process=proc_open([PHP_BINARY,'-S',$address,$router],[0=>['pipe','r'],1=>['file','/dev/null','a'],2=>['file','/dev/null','a']],$pipes,dirname(__DIR__),$env);
try{
 if(!is_resource($process))throw new RuntimeException('Cannot start PHP header fixture');
 $headers=[];
 for($i=0;$i<30;$i++){
  $http_response_header=[];$response=@file_get_contents('http://'.$address.'/workspace');
  if($response==='synthetic-policy-response'){$headers=$http_response_header;break;}
  usleep(100000);
 }
 $workspace=implode("\n",$headers);
 if(!str_contains($workspace,'X-Frame-Options: SAMEORIGIN')||!str_contains($workspace,"frame-ancestors 'self'"))throw new RuntimeException('Same-origin workspace framing headers missing');
 $http_response_header=[];file_get_contents('http://'.$address.'/api');$api=implode("\n",$http_response_header);
 if(!str_contains($api,'X-Frame-Options: DENY')||!str_contains($api,"frame-ancestors 'none'"))throw new RuntimeException('API frame protection changed');
 echo "PASS: actual PHP workspace headers permit only same-origin ancestors; API framing remains denied\n";
}finally{
 if(is_resource($process)){proc_terminate($process);proc_close($process);}
 foreach($pipes??[] as $pipe)if(is_resource($pipe))fclose($pipe);
 unlink($router);
}
