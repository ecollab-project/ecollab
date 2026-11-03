<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/config.php';
require ROOT_PATH.'/database/config/db.php';
require ROOT_PATH.'/services/JarredDmReplyWorker.php';
$watch=in_array('--watch',$argv,true);$stop=false;
if(function_exists('pcntl_async_signals')){pcntl_async_signals(true);pcntl_signal(SIGTERM,function()use(&$stop){$stop=true;});pcntl_signal(SIGINT,function()use(&$stop){$stop=true;});}
$worker=new JarredDmReplyWorker(Database::getInstance());
do{try{$worked=$worker->runOne();}catch(Throwable $e){fwrite(STDERR,"Jarred worker could not access the queue; check migration/configuration.\n");exit(1);}
 if($watch&&!$worked&&!$stop)usleep(500000);
}while($watch&&!$stop);
