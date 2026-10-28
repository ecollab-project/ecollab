<?php
declare(strict_types=1);
require_once __DIR__.'/phase3y-bootstrap.php';
require_once __DIR__.'/experiments/phase3aa/UtcOtpTrial.php';
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,get_class($e).': owned race worker failed');exit(1);});
$in=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$dir=realpath((string)$in['barrier']);$owned=realpath((string)getenv('PHASE3Y_PRIVATE'));
if(!$dir||!$owned||!str_starts_with($dir,$owned.DIRECTORY_SEPARATOR)||!in_array($in['worker'],[0,1,2,3],true))throw new RuntimeException('Private barrier required');
$db->exec("SET SESSION time_zone='+00:00'");$db->exec('SET timestamp=0');date_default_timezone_set('UTC');
AuthMiddleware::startSession();$_SESSION=[];$fingerprint=hash('sha256',session_id());$auth=new AuthService();
if($in['variant']!=='candidate')throw new RuntimeException('Candidate required');
$id=(int)$db->query('SELECT CONNECTION_ID()')->fetchColumn();file_put_contents($dir.'/ready-'.$in['worker'],'ready');$limit=microtime(true)+15;
while(!is_file($dir.'/start')){if(microtime(true)>$limit)throw new RuntimeException('Start gate timeout');usleep(1000);}
$begin=hrtime(true);$r=$auth->verifyOtp((int)$in['user_id'],(string)$in['code'],'reset_password');$end=hrtime(true);
$out=['worker'=>$in['worker'],'connection_id'=>$id,'session_fingerprint_private'=>$fingerprint,'start_monotonic_ns'=>$begin,'end_monotonic_ns'=>$end,'success'=>$r['success'],'error'=>$r['error']??null,'reset_token_present'=>!empty($r['reset_token']),'authenticated'=>!empty($_SESSION['user_id']),'reset_token_ttl_seconds'=>(int)($_SESSION['pwd_reset_expires']??0)-time()];
$_SESSION=[];session_destroy();echo json_encode($out,JSON_THROW_ON_ERROR);
