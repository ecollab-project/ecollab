<?php
declare(strict_types=1);
// Synthetic provider-only smoke test. No application accounts/messages are read or written.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/config.php';
require ROOT_PATH.'/services/OllamaService.php';
if(($argv[1]??'')==='--worker'){
    $index=(int)($argv[2]??-1);$run=(string)($argv[3]??'');
    if($index<0||$index>4||!preg_match('/^[a-f0-9]{8}$/',$run))exit(2);
    $expected='JARRED-CHECK-'.$run.'-'.$index;$started=microtime(true);
    try{
        $reply=(new OllamaService())->generate([['role'=>'user','content'=>'Reply with exactly this text and nothing else: '.$expected]],'You are Jarred. Follow the current synthetic test instruction exactly.',48);
        echo json_encode(['index'=>$index,'accepted'=>true,'correct'=>trim($reply['text'])===$expected,'model'=>$reply['model'],'elapsed_ms'=>(int)round((microtime(true)-$started)*1000),'timing_ms'=>$reply['timing_ms']]);
    }catch(Throwable $e){echo json_encode(['index'=>$index,'accepted'=>false,'correct'=>false,'error_code'=>$e->getCode(),'elapsed_ms'=>(int)round((microtime(true)-$started)*1000)]);}
    exit;
}
if($argc>1){fwrite(STDERR,"Usage: php scripts/benchmark-jarred-concurrency.php\n");exit(2);}
$run=bin2hex(random_bytes(4));$jobs=[];$started=microtime(true);
try{
    for($i=0;$i<5;$i++){
        $process=proc_open([(string)(getenv('JARRED_PHP_BIN')?:PHP_BINARY),__FILE__,'--worker',(string)$i,$run],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes,ROOT_PATH);
        if(!is_resource($process))throw new RuntimeException('Cannot start smoke-test worker');
        stream_set_blocking($pipes[1],false);$jobs[]=['process'=>$process,'output'=>$pipes[1],'text'=>'','done'=>false];
    }
    do{
        $running=false;
        foreach($jobs as &$job){
            $job['text'].=stream_get_contents($job['output']);
            if($job['done'])continue;
            $status=proc_get_status($job['process']);$job['done']=!$status['running'];$running=$running||$status['running'];
        }unset($job);
        if($running)usleep(20000);
    }while($running&&microtime(true)-$started<50);
    $results=[];
    foreach($jobs as $i=>$job){$job['text'].=stream_get_contents($job['output']);$results[]=json_decode($job['text'],true)??['index'=>$i,'accepted'=>false,'correct'=>false,'error'=>'Worker did not finish within 50 seconds'];}
    $accepted=count(array_filter($results,fn($r)=>!empty($r['accepted'])));$correct=count(array_filter($results,fn($r)=>!empty($r['correct'])));
    echo json_encode(['scope'=>'Five synthetic local-provider requests only; not an authenticated web/FPM load test','accepted'=>$accepted,'correct'=>$correct,'elapsed_ms'=>(int)round((microtime(true)-$started)*1000),'results'=>$results],JSON_PRETTY_PRINT)."\n";
    $exit=$accepted===5&&$correct===5?0:1;
}finally{foreach($jobs as $job){if(!$job['done'])proc_terminate($job['process']);fclose($job['output']);proc_close($job['process']);}}
exit($exit);
