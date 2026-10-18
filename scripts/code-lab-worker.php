<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/config.php';
require ROOT_PATH.'/database/config/db.php';
require ROOT_PATH.'/services/CodeLabExecutionService.php';
require ROOT_PATH.'/services/CodeLabWorkerMetrics.php';
$loop=in_array('--loop',$argv,true);
$metrics=new CodeLabWorkerMetrics();$reported=hrtime(true);
$report=static function()use($metrics,&$reported):void{$now=hrtime(true);$summary=$metrics->drain(($now-$reported)/1e9);if($summary!==null)echo json_encode($summary,JSON_THROW_ON_ERROR)."\n";$reported=$now;};
try {
 $service=new CodeLabExecutionService(Database::getInstance(),new CodeLabRunner());
 do { $worked=$service->workOne();if(($timing=$service->lastTiming())!==null)$metrics->record($timing);if(hrtime(true)-$reported>=30_000_000_000||!$loop)$report();if(!$worked&&$loop)sleep(1); } while($loop);
} catch(Throwable $e){$report();fwrite(STDERR,"Code Lab worker unavailable. Check runner configuration, PHP extensions and migrations.\n");exit(1);}
