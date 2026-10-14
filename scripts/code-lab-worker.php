<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/config.php';
require ROOT_PATH.'/database/config/db.php';
require ROOT_PATH.'/services/CodeLabExecutionService.php';
$loop=in_array('--loop',$argv,true);
try {
 $service=new CodeLabExecutionService(Database::getInstance(),new CodeLabRunner());
 do { $worked=$service->workOne();if(!$worked&&$loop)sleep(1); } while($loop);
} catch(Throwable $e){fwrite(STDERR,"Code Lab worker unavailable. Check runner configuration, PHP extensions and migrations.\n");exit(1);}
