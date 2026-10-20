<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/config.php';require ROOT_PATH.'/database/config/db.php';require ROOT_PATH.'/services/CodeLabSimilarityService.php';
try{$service=new CodeLabSimilarityService(Database::getInstance());$loop=in_array('--loop',$argv,true);do{$worked=$service->workOne();if(!$worked&&$loop)sleep(2);}while($loop);}catch(Throwable $e){fwrite(STDERR,"Similarity worker unavailable. Check migration 056 and worker configuration.\n");exit(1);}
