<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/config.php';
require_once ROOT_PATH.'/database/config/db.php';
require_once ROOT_PATH.'/services/PeerBehaviorService.php';
// Stable app-specific lock across overlapping timer/manual invocations.
$lock=fopen(sys_get_temp_dir().'/ecollab-peer-'.hash('sha256',ROOT_PATH).'.lock','c');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){fwrite(STDERR,"Peer refresh already running or lock unavailable\n");exit(1);}
try{echo json_encode((new PeerBehaviorService(Database::getInstance()))->refresh(),JSON_THROW_ON_ERROR).PHP_EOL;}
catch(Throwable $e){error_log('[peer-refresh] '.$e->getMessage());fwrite(STDERR,"Peer refresh failed; inspect application logs.\n");exit(1);}
finally{flock($lock,LOCK_UN);fclose($lock);}
