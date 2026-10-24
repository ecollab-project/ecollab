<?php
// Isolated router. It instruments a source-identical endpoint copy and seeds synthetic sessions.
declare(strict_types=1);
require_once __DIR__.'/dashboard-profile.php';
$root=getenv('DASHBOARD_APP_ROOT');$folder=getenv('DASHBOARD_PERF_FOLDER');
if(!$root||!$folder)throw new RuntimeException('Fixture roots required');
require_once $root.'/database/config/db.php';
require_once $root.'/security/middleware/AuthMiddleware.php';
$db=dashboardFixtureDb();(new ReflectionProperty(Database::class,'instance'))->setValue(null,$db);
$route=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($route==='/fixture/seed'){
 AuthMiddleware::startSession();$uid=(int)($_GET['uid']??1);if(!in_array($uid,[1,2],true))throw new RuntimeException('Synthetic user only');
 $_SESSION=['user_id'=>$uid,'username'=>'student'.$uid,'full_name'=>'Fixture Student '.$uid,'role'=>'student','csrf_token'=>'synthetic-fixture'];session_write_close();echo 'seed';return;
}
if($route==='/API/dashboard/live.php'||$route==='/API/student/workspace.php'){
 $source=file_get_contents($root.$route);
 $source=str_replace(['dirname(__DIR__, 2)','dirname(__DIR__,2)'],var_export($root,true),$source);
 $source=str_replace('json_encode(', 'DashboardProfile::encode(', $source);
 $endpoint=$folder.'/endpoint-'.hash('sha256',$root.$route).'.php';file_put_contents($endpoint,$source);
 DashboardProfile::$queries=[];$start=hrtime(true);$cpu=DashboardProfile::cpu();if(function_exists('memory_reset_peak_usage'))memory_reset_peak_usage();$memory=memory_get_usage();ob_start();
 register_shutdown_function(static function()use($start,$cpu,$memory,$folder,$route){
  $content=ob_get_contents();$metrics=['route'=>$route,'php_ms'=>(hrtime(true)-$start)/1e6,'php_cpu_ms'=>DashboardProfile::cpu()-$cpu,'encode_ms'=>DashboardProfile::$encodeMs,'peak_delta_bytes'=>memory_get_peak_usage()-$memory,'payload_bytes'=>strlen((string)$content),'queries'=>DashboardProfile::$queries];
  file_put_contents($folder.'/http-metrics.jsonl',json_encode($metrics,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
 });
 require $endpoint;return;
}
if(str_starts_with($route,'/assets/')||$route==='/modules/student/dashboard.php')return false;
http_response_code(404);echo '{}';
