<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root=dirname(__DIR__);$path=$root.'/.env';$mode=$argv[1]??'prepare';
if(!in_array($mode,['prepare','enable','disable'],true)){fwrite(STDERR,"Use prepare, enable or disable\n");exit(1);}
require_once $root.'/config.php';
$text=file_get_contents($path);if($text===false)throw new RuntimeException('Cannot read application environment');
$secret=(string)env('CENTRIFUGO_TOKEN_SECRET','');$key=(string)env('CENTRIFUGO_API_KEY','');
if($mode!=='prepare' && (strlen($secret)<32 || strlen($key)<32))throw new RuntimeException('Prepare broker configuration first');
if(strlen($secret)<32)$secret=bin2hex(random_bytes(32));
if(strlen($key)<32)$key=bin2hex(random_bytes(32));
if(!preg_match('/^[a-zA-Z0-9_-]{32,}$/',$secret) || !preg_match('/^[a-zA-Z0-9_-]{32,}$/',$key))throw new RuntimeException('Broker keys must use safe alphanumeric characters');
if($mode==='enable'){
    require_once $root.'/database/config/db.php';require_once $root.'/services/CentrifugoService.php';
    Database::getInstance()->query('SELECT id FROM realtime_outbox LIMIT 1');
    (new CentrifugoService($secret,$key))->command('info',[]);
}
$backupDir=dirname($root).'/ecollab-backups';
if(!is_dir($backupDir) && !mkdir($backupDir,0700,true))throw new RuntimeException('Cannot create private backup directory');
$backup=$backupDir.'/environment-before-centrifugo-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
if(file_put_contents($backup,$text)===false || !chmod($backup,0600))throw new RuntimeException('Cannot create environment backup');
$values=['CENTRIFUGO_ENABLED'=>$mode==='enable'?'true':'false','CENTRIFUGO_TOKEN_SECRET'=>$secret,'CENTRIFUGO_API_KEY'=>$key,'CENTRIFUGO_API_URL'=>'http://127.0.0.1:8001/api'];
foreach($values as $name=>$value){$pattern='/^'.preg_quote($name,'/').'\s*=.*$/m';
    $line=$name.'='.$value;
    $text=preg_match($pattern,$text)?preg_replace($pattern,$line,$text):rtrim($text)."\n".$line."\n";
}
if($mode==='prepare'){
    $brokerEnv=$root.'/deploy/centrifugo/centrifugo.env';
    if(file_put_contents($brokerEnv,"CENTRIFUGO_CLIENT_TOKEN_HMAC_SECRET_KEY=$secret\nCENTRIFUGO_HTTP_API_KEY=$key\n")===false || !chmod($brokerEnv,0600))throw new RuntimeException('Cannot write broker environment');
}
// Write in place to preserve deployment ownership and www-data group access.
if(file_put_contents($path,$text,LOCK_EX)===false)throw new RuntimeException('Cannot update application environment');
echo "Centrifugo: ".$mode." complete. Private environment backup: ".$backup."\n";
