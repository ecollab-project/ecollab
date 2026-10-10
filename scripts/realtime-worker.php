<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/config.php';
require_once ROOT_PATH.'/database/config/db.php';
require_once ROOT_PATH.'/services/RealtimeOutbox.php';
require_once ROOT_PATH.'/services/CentrifugoService.php';
$lockDb=null;$lastCleanup=time();
try {
    while(true){
        if(!RealtimeOutbox::enabled()){sleep(1);continue;}
        $db=Database::getLiveInstance();
        if($lockDb!==$db){
            if((int)$db->query("SELECT GET_LOCK('ecollab_realtime_delivery',0)")->fetchColumn()!==1)throw new RuntimeException('A delivery worker is already running');
            $lockDb=$db;
        }
        $broker=new CentrifugoService();
        RealtimeOutbox::dispatch($db,$broker);
        if(time()-$lastCleanup>=3600){$db->exec('DELETE FROM realtime_outbox WHERE published_at<NOW()-INTERVAL 3 DAY LIMIT 10000');$lastCleanup=time();}
        usleep(250000);
    }
} catch(Throwable $e){fwrite(STDERR,"Realtime worker stopped; check configuration/database availability.\n");exit(1);}
