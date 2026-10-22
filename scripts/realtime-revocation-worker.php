<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/config.php';
require_once ROOT_PATH.'/database/config/db.php';
require_once ROOT_PATH.'/services/RealtimeRevocationService.php';
require_once ROOT_PATH.'/services/LiveKitService.php';
require_once ROOT_PATH.'/services/CentrifugoService.php';
require_once ROOT_PATH.'/services/RealtimeOutbox.php';
$db=Database::getInstance();
$name='ec-revocation-'.hash('sha224',(string)$db->query('SELECT DATABASE()')->fetchColumn());
$q=$db->prepare('SELECT GET_LOCK(?,0)');$q->execute([$name]);if((int)$q->fetchColumn()!==1)exit(0);
try{
    $livekit=new LiveKitService();$broker=RealtimeOutbox::enabled()?new CentrifugoService():null;
    $service=new RealtimeRevocationService($db,[$livekit,'roomRequest'],static function(int $uid)use($broker):void{$broker?->disconnect($uid);});
    do{
        $q=$db->prepare('SELECT IS_USED_LOCK(?)=CONNECTION_ID()');$q->execute([$name]);if(!(int)$q->fetchColumn())throw new RuntimeException('Lock lost');
        try{$counts=$service->reconcile();error_log(json_encode(['metric'=>'realtime_revocation']+$counts,JSON_THROW_ON_ERROR));}
        catch(Throwable){error_log('[revocation_worker] Reconciliation incomplete; access enforcement requires recovery');}
        if(in_array('--once',$argv,true))break;
        sleep(5);
    }while(true);
}finally{$db->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);}
