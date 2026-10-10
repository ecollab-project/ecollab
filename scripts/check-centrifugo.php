<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/config.php';require_once ROOT_PATH.'/database/config/db.php';
require_once ROOT_PATH.'/services/CentrifugoService.php';
try {
    (new CentrifugoService())->command('info',[]);
    $db=Database::getInstance();
    $pending=(int)$db->query('SELECT COUNT(*) FROM realtime_outbox WHERE published_at IS NULL')->fetchColumn();
    echo "Broker API OK; pending saved-message notifications: $pending\n";
} catch(Throwable $e){fwrite(STDERR,"Broker/database check failed; verify container, keys and migration.\n");exit(1);}
