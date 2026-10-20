<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/config.php';
require_once ROOT_PATH.'/database/config/db.php';
require_once ROOT_PATH.'/services/RealtimeOutbox.php';
require_once ROOT_PATH.'/services/CentrifugoService.php';
$lockDb=null;$lastCleanup=time();$lastReport=time();$delivered=0;$dispatchMs=0.0;
$broker=null;$metrics=new RealtimeDispatchMetrics();$windowStarted=microtime(true);
try {
    while(true){
        if(!RealtimeOutbox::enabled()){sleep(1);continue;}
        $db=Database::getLiveInstance();
        if($lockDb!==$db){
            if((int)$db->query("SELECT GET_LOCK('ecollab_realtime_delivery',0)")->fetchColumn()!==1)throw new RuntimeException('A delivery worker is already running');
            $lockDb=$db;
        }
        $broker ??= new CentrifugoService();
        $started=microtime(true);
        $sent=RealtimeOutbox::dispatch($db,$broker,$metrics);
        $delivered+=$sent;$dispatchMs+=(microtime(true)-$started)*1000;
        if(time()-$lastReport>=30){
            $queue=$db->query('SELECT COUNT(*) AS pending, COALESCE(MAX(TIMESTAMPDIFF(SECOND,created_at,NOW())),0) AS oldest_seconds FROM realtime_outbox WHERE published_at IS NULL')->fetch(PDO::FETCH_ASSOC);
            $summary=array_merge(['type'=>'delivery_summary','window_seconds'=>round(microtime(true)-$windowStarted,3),'delivered'=>$delivered,'dispatch_ms'=>round($dispatchMs),'pending'=>(int)$queue['pending'],'oldest_seconds'=>(int)$queue['oldest_seconds']],$metrics->drain());
            if($delivered || (int)$queue['pending'])fwrite(STDOUT,json_encode($summary).PHP_EOL);
            $windowStarted=microtime(true);
            $delivered=0;$dispatchMs=0.0;$lastReport=time();
        }
        if(time()-$lastCleanup>=3600){$db->exec('DELETE FROM realtime_outbox WHERE published_at<NOW()-INTERVAL 3 DAY LIMIT 10000');$lastCleanup=time();}
        // A full successful batch can mean more ready work; drain it without an idle pause.
        usleep($sent===25 ? 1000 : 250000);
    }
} catch(Throwable $e){fwrite(STDERR,"Realtime worker stopped; check configuration/database availability.\n");exit(1);}

