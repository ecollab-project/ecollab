<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/config.php';require_once ROOT_PATH.'/database/config/db.php';
require_once ROOT_PATH.'/services/CentrifugoService.php';
try {
    (new CentrifugoService())->command('info',[]);
    $db=Database::getInstance();
    $queue=$db->query('SELECT COUNT(*) AS pending, COALESCE(MAX(TIMESTAMPDIFF(SECOND,created_at,NOW())),0) AS oldest_seconds, COALESCE(SUM(attempts>0),0) AS retrying FROM realtime_outbox WHERE published_at IS NULL')->fetch(PDO::FETCH_ASSOC);
    echo "Broker API OK; pending saved-message notifications: ".(int)$queue['pending']."; oldest age: ".(int)$queue['oldest_seconds']."s; retrying: ".(int)$queue['retrying']."\n";
} catch(Throwable $e){fwrite(STDERR,"Broker/database check failed; verify container, keys and migration.\n");exit(1);}
