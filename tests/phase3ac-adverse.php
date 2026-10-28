<?php
declare(strict_types=1);
require_once __DIR__.'/phase3y-bootstrap.php';
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,get_class($e).': '.$e->getMessage());exit(1);});
$in=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$uid=(int)$in['user_id'];
$s=$db->prepare('SELECT email FROM users WHERE id=?');$s->execute([$uid]);if(!preg_match('/^phase3y[a-z0-9_-]+@example\.test$/D',(string)$s->fetchColumn()))throw new RuntimeException('Owned actor');
$otp=new OtpService();$records=[];
foreach(['expires_while_waiting','regenerated_while_verifying','lock_timeout','connection_lost']as$case){
 $code=$otp->generate($uid);$s=$db->prepare('SELECT id FROM otp_codes WHERE user_id=? AND used_at IS NULL ORDER BY id DESC LIMIT 1');$s->execute([$uid]);$id=(int)$s->fetchColumn();
 $ready=getenv('PHASE3Y_PRIVATE').'/adverse-ready';@unlink($ready);@unlink($ready.'-start');$cmd=[getenv('PHP_BIN')?:PHP_BINARY,__DIR__.'/phase3ac-adverse-worker.php'];$pipes=[];$p=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 fwrite($pipes[0],json_encode(['user_id'=>$uid,'code'=>$code,'ready'=>$ready,'case'=>$case],JSON_THROW_ON_ERROR));fclose($pipes[0]);
 try{
  $limit=microtime(true)+5;while(!is_file($ready)){if(microtime(true)>$limit)throw new RuntimeException('Ready timeout');usleep(1000);}$cid=(int)file_get_contents($ready);
  $db->beginTransaction();$db->query('SELECT id FROM otp_codes WHERE id='.$id.' FOR UPDATE')->fetch();
  if($case==='expires_while_waiting')$db->prepare('UPDATE otp_codes SET expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 SECOND) WHERE id=?')->execute([$id]);
  file_put_contents($ready.'-start','start');
  $waiting=false;while(microtime(true)<$limit){$q=$db->query('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID='.$cid);$info=(string)$q->fetchColumn();if(str_contains($info,'FOR UPDATE')){$waiting=true;break;}usleep(1000);}if(!$waiting)throw new RuntimeException('No observed row-lock wait: '.$case);
  if($case==='expires_while_waiting'){$deadline=microtime(true)+15;while(!(bool)$db->query('SELECT expires_at<=UTC_TIMESTAMP() FROM otp_codes WHERE id='.$id)->fetchColumn()){if(microtime(true)>$deadline)throw new RuntimeException('Expiry wait timeout');usleep(10000);}}
  if($case==='regenerated_while_verifying')$db->prepare('UPDATE otp_codes SET used_at=UTC_TIMESTAMP() WHERE id=?')->execute([$id]);
  if($case==='connection_lost')$db->exec('KILL CONNECTION '.$cid);
  if($case==='lock_timeout')usleep(3200000);
  $db->commit();$stdout=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);
  if($exit!==0||$err!=='')throw new RuntimeException('Adverse worker failed');$r=json_decode($stdout,true,512,JSON_THROW_ON_ERROR);
  if($r['success']||$r['reset_token_present']||($r['transaction_open']&&$case!=='connection_lost'))throw new RuntimeException('Adverse verification escaped success');
  if($case==='expires_while_waiting'&&$r['exception']!==null)throw new RuntimeException('Expiry case must reject by predicate,not timeout');
  $records[]=['case'=>$case,'observed_database_row_lock_wait'=>$waiting,'exit_code'=>$exit,'result'=>$r];
 }finally{if($db->inTransaction())$db->rollBack();@unlink($ready);@unlink($ready.'-start');}
}
echo json_encode(['status'=>'PASS','records'=>$records],JSON_THROW_ON_ERROR);
