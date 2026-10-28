<?php
declare(strict_types=1);
require_once __DIR__.'/phase3y-bootstrap.php';
$in=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$path=(string)$in['ready'];$owned=realpath((string)getenv('PHASE3Y_PRIVATE'));
if(!$owned||dirname($path)!==$owned)throw new RuntimeException('Private readiness path required');
$db->exec('SET SESSION innodb_lock_wait_timeout='.(($in['case']??'')==='lock_timeout'?3:30));
$auth=new AuthService();
file_put_contents($path,(string)$db->query('SELECT CONNECTION_ID()')->fetchColumn());
$limit=microtime(true)+15;while(!is_file($path.'-start')){if(microtime(true)>$limit)throw new RuntimeException('Start barrier timeout');usleep(1000);}
try{$r=$auth->verifyOtp((int)$in['user_id'],(string)$in['code'],'reset_password');$out=['success'=>$r['success'],'reset_token_present'=>!empty($r['reset_token']),'exception'=>null];}
catch(Throwable $e){$out=['success'=>false,'reset_token_present'=>false,'exception'=>get_class($e),'sqlstate'=>$e instanceof PDOException?$e->getCode():null];}
$out['transaction_open']=$db->inTransaction();echo json_encode($out,JSON_THROW_ON_ERROR);
