<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
define('APP_NAME','Test');define('DB_HOST','unused');
require dirname(__DIR__).'/security/rate-limit/RateLimiter.php';
$dsn=getenv('SETTINGS_TEST_DSN');if(!$dsn)throw new RuntimeException('Use an isolated SETTINGS_TEST_DSN.');
function connection():PDO{return new PDO(getenv('SETTINGS_TEST_DSN'),'root','test-password',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
if(($argv[1]??'')==='worker'){
 $db=connection();$deadline=microtime(true)+8;while(!is_file($argv[2])){if(microtime(true)>$deadline)exit(2);usleep(1000);}
 echo json_encode((new RateLimiter($db))->attempt('parallel',$argv[3],3,60));exit;
}
$db=connection();$sql=preg_replace('/^\s*--.*$/m','',file_get_contents(dirname(__DIR__).'/database/migrations/061_request_hardening.sql'));
for($i=0;$i<2;$i++)foreach(explode(';',$sql) as $statement)if(trim($statement)!=='')$db->exec($statement);
$identity=bin2hex(random_bytes(8));$barrier=tempnam(sys_get_temp_dir(),'ec-rate-');unlink($barrier);$workers=[];
try{
 for($i=0;$i<12;$i++){$pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'worker',$barrier,$identity],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$workers[]=[$proc,$pipes];}
 touch($barrier);$allowed=0;
 foreach($workers as [$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($proc)!==0)throw new RuntimeException($err);$result=json_decode($out,true,512,JSON_THROW_ON_ERROR);$allowed+=(int)$result['allowed'];}
 if($allowed!==3)throw new RuntimeException('Concurrent attempts exceeded or failed the quota: '.$allowed);
 $limiter=new RateLimiter($db);$long='signup_email_otp_verify';$limiter->clear($long,$identity);
 if(!$limiter->attempt($long,$identity,1,60)['allowed']||$limiter->attempt($long,$identity,1,60)['allowed'])throw new RuntimeException('Long action key bypassed quota.');
 date_default_timezone_set('Asia/Tokyo');$blocked=$limiter->attempt($long,$identity,1,60);if($blocked['retry_after']<1||$blocked['retry_after']>60)throw new RuntimeException('Retry-after depends on PHP timezone.');
 $limiter->clear($long,$identity);if($limiter->remaining($long,$identity,1,60)!==1)throw new RuntimeException('Clear failed.');
 // Force an insert error and confirm finally releases the named lock to another DB connection.
 $db->exec("CREATE TRIGGER rate_test_fail BEFORE INSERT ON rate_limit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture failure'");
 try{$limiter->attempt('fault',$identity);throw new LogicException('Expected insert error.');}catch(PDOException $e){}finally{$db->exec('DROP TRIGGER rate_test_fail');}
 $name='ec-rl-'.hash('sha224',$db->query('SELECT DATABASE()')->fetchColumn().':fault:'.hash('sha256',$identity));
 $other=connection();$lock=$other->prepare('SELECT GET_LOCK(?,0)');$lock->execute([$name]);if((int)$lock->fetchColumn()!==1)throw new RuntimeException('Lock leaked after error.');$other->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
 $index=$db->query("SHOW INDEX FROM rate_limit_log WHERE Key_name='idx_rate_window'")->fetchAll();if(count($index)!==2)throw new RuntimeException('Composite window index missing.');
 echo "PASS: 12 simultaneous requests admit exactly 3, long action keys, DB-clock retries, clear, error lock release and repeatable indexed migration\n";
}finally{if(is_file($barrier))unlink($barrier);$limiter??=new RateLimiter($db);foreach(['parallel','fault','signup_email_otp_verify'] as $a)$limiter->clear($a,$identity);}
