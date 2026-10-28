<?php
declare(strict_types=1);
require_once __DIR__.'/phase3y-bootstrap.php';
require_once __DIR__.'/experiments/phase3aa/UtcOtpTrial.php';
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,get_class($e).': owned race controller failed');exit(1);});
$in=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$uid=(int)$in['user_id'];$s=$db->prepare('SELECT email FROM users WHERE id=?');$s->execute([$uid]);
if(!preg_match('/^phase3y[a-z0-9_-]+@example\.test$/D',(string)$s->fetchColumn()))throw new RuntimeException('Owned race actor');
$db->exec("SET SESSION time_zone='+00:00'");$db->exec('SET timestamp=0');date_default_timezone_set('UTC');$rows=[];
foreach(['candidate']as $variant)for($round=0;$round<8;$round++){
 $otp=new OtpService();$code=$otp->generate($uid,'reset_password');$dir=getenv('PHASE3Y_PRIVATE').'/race-'.$variant.'-'.$round;mkdir($dir,0700);
 $children=[];$workerCount=$round<5?2:4;
 try{
  for($i=0;$i<$workerCount;$i++){
   $cmd=[getenv('PHP_BIN')?:PHP_BINARY,'-d','date.timezone=UTC','-d','session.save_path='.session_save_path(),__DIR__.'/phase3ac-race-worker.php'];$pipes=[];
   $p=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($p))throw new RuntimeException('Owned child');
   fwrite($pipes[0],json_encode(['variant'=>$variant,'user_id'=>$uid,'code'=>$code,'barrier'=>$dir,'worker'=>$i],JSON_THROW_ON_ERROR));fclose($pipes[0]);$children[]=[$p,$pipes,$cmd];
  }
  $limit=microtime(true)+15;while(count(glob($dir.'/ready-*')?:[])!==$workerCount){if(microtime(true)>$limit)throw new RuntimeException('Ready gate timeout');usleep(1000);}file_put_contents($dir.'/start','start');$results=[];$private=[];
  foreach($children as[$p,$pipes,$cmd]){$stdout=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);if($exit!==0||$err!=='')throw new RuntimeException('Race child unsuccessful');$r=json_decode($stdout,true,512,JSON_THROW_ON_ERROR);$private[]=$r['session_fingerprint_private'];unset($r['session_fingerprint_private']);$r['exit_code']=$exit;$results[]=$r;}
  $overlap=max(array_column($results,'start_monotonic_ns'))<min(array_column($results,'end_monotonic_ns'));$connections=count(array_unique(array_column($results,'connection_id')))===$workerCount;$sessions=count(array_unique($private))===$workerCount;
  if(!$connections||!$sessions)throw new RuntimeException('Independent connections/sessions required');foreach($results as$r)if($r['authenticated']||$r['success']!==$r['reset_token_present'])throw new RuntimeException('Unexpected auth outcome');
  $s=$db->prepare("SELECT expires_at,used_at FROM otp_codes WHERE user_id=? AND action='reset_password' ORDER BY id DESC LIMIT 1");$s->execute([$uid]);$row=$s->fetch();
  if(count(array_filter($results,fn($r)=>$r['success']))!==1)throw new RuntimeException('Candidate single-use violation');
  $rows[]=['variant'=>$variant,'round'=>$round,'workers'=>$results,'independent_connections'=>$connections,'independent_sessions'=>$sessions,'request_intervals_overlap'=>$overlap,'successful_consumptions'=>count(array_filter($results,fn($r)=>$r['success'])),'row_after'=>$row,'coordination'=>'Equal-start gate before method call; no pause, hook, lock or replacement inside application SELECT/hash/UPDATE'];
 }finally{foreach(glob($dir.'/*')?:[]as$f)unlink($f);rmdir($dir);}
}
echo json_encode(['status'=>'PASS for independent-connection characterization','records'=>$rows,'single_use_safety'=>'Report actual successful consumptions; multiple successes is FAIL, not a passing single-use guarantee','candidate_application_service'=>true],JSON_THROW_ON_ERROR);
