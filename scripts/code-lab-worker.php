<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/config.php';
require ROOT_PATH.'/database/config/db.php';
require ROOT_PATH.'/services/CodeLabExecutionService.php';
require ROOT_PATH.'/services/CodeLabWorkerMetrics.php';
require ROOT_PATH.'/services/CodeLabWorkerPool.php';
$loop=in_array('--loop',$argv,true);
$worker=static function(int $slot)use($loop):void{
    $stop=false;
    if(function_exists('pcntl_async_signals')){
        pcntl_async_signals(true);$signal=static function()use(&$stop):void{$stop=true;};
        pcntl_signal(SIGTERM,$signal);pcntl_signal(SIGINT,$signal);
    }
    $metrics=new CodeLabWorkerMetrics();$reported=hrtime(true);
    $report=static function()use($metrics,&$reported,$slot):void{$now=hrtime(true);$summary=$metrics->drain(($now-$reported)/1e9);if($summary!==null)echo json_encode($summary+['slot'=>$slot],JSON_THROW_ON_ERROR)."\n";$reported=$now;};
    // Open after fork; a connection-scoped lock prevents duplicate supervisors.
    $db=Database::getInstance();$name=CodeLabWorkerPool::acquireSlot($db,$slot);
    try{
        $service=new CodeLabExecutionService($db,new CodeLabRunner());
        do{
            if($stop)break;
            $worked=$service->workOne();if(($timing=$service->lastTiming())!==null)$metrics->record($timing);
            if(hrtime(true)-$reported>=30_000_000_000||!$loop)$report();
            if(!$worked&&$loop&&!$stop)sleep(1);
        }while($loop&&!$stop);
    }finally{$report();$release=$db->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$name]);}
};
try{
    $size=CodeLabWorkerPool::size(getenv('CODE_LAB_EXECUTION_SLOTS')?:'2');
    if($loop)exit(CodeLabWorkerPool::run($size,$worker));
    $worker(1);
}catch(Throwable $e){fwrite(STDERR,"Code Lab worker unavailable. Check runner, database, CLI extensions and worker slots.\n");exit(1);}
