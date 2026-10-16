<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);require dirname(__DIR__).'/config.php';require ROOT_PATH.'/database/config/db.php';require ROOT_PATH.'/services/WebPushService.php';
try{$service=new WebPushService(Database::getInstance());$loop=in_array('--loop',$argv,true);$deadlineAt=0;do{if(WebPushService::configured()&&time()>=$deadlineAt){$service->deadlineNotices();$deadlineAt=time()+60;}$worked=$service->workOne();if(!$worked&&$loop)sleep(5);}while($loop);}catch(Throwable $e){fwrite(STDERR,"Push worker unavailable. Check migration 057, dependencies and VAPID settings.\n");exit(1);}
