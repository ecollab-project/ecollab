<?php
/** Test-only prepend for an owned FPM pool. Never install in an application pool. */
declare(strict_types=1);
$root=getenv('CLASSROOM_ROOT');$folder=getenv('CLASSROOM_FOLDER');$dsn=getenv('CLASSROOM_DSN');
if(!$root||!$folder||!$dsn||!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=classroom_perf_test$/D',$dsn))throw new RuntimeException('Owned fixture required');
require_once __DIR__.'/dashboard-profile.php';
require_once $root.'/config.php';require_once $root.'/database/config/db.php';
final class ClassroomPDO extends DashboardPDO {}
$db=new ClassroomPDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STATEMENT_CLASS=>[DashboardStatement::class]]);
$db->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
if(getenv('CLASSROOM_FIXED_CLOCK'))$db->exec('SET timestamp='.(int)getenv('CLASSROOM_FIXED_CLOCK'));
(new ReflectionProperty(Database::class,'instance'))->setValue(null,$db);
if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/fixture/seed'){
 require_once $root.'/security/middleware/AuthMiddleware.php';AuthMiddleware::startSession();$uid=(int)($_GET['uid']??1);
 if(($uid<1||$uid>100)&&$uid!==900)throw new RuntimeException('Synthetic actor only');
 $_SESSION=['user_id'=>$uid,'username'=>'student'.$uid,'full_name'=>'Fixture Student '.$uid,'role'=>$uid===900?'facilitator':'student','csrf_token'=>'classroom-fixture'];session_write_close();$opcache=function_exists('opcache_get_status')?opcache_get_status(false):false;echo json_encode(['php'=>PHP_VERSION,'sapi'=>PHP_SAPI,'opcache_loaded'=>extension_loaded('Zend OPcache'),'opcache_enabled'=>(bool)($opcache['opcache_enabled']??false),'opcache_ini_enabled'=>ini_get('opcache.enable'),'opcache_statistics'=>$opcache['opcache_statistics']??null,'opcache_memory'=>$opcache['memory_usage']??null]);exit;
}
DashboardProfile::$queries=[];$start=microtime(true);$cpu=DashboardProfile::cpu();if(function_exists('memory_reset_peak_usage'))memory_reset_peak_usage();ob_start();
register_shutdown_function(static function()use($start,$cpu,$folder){
 $queries=DashboardProfile::$queries;$probe=0;$full=0;$sql=[];
 foreach($queries as$q){$text=preg_replace('/\s+/',' ',trim($q['sql']));if(str_starts_with($text,'SELECT m.id,m.content,m.created_at,c.name')){if(str_contains($text,'LIMIT 256'))$probe++;else $full++;}$sql[]=array_replace($q,['sql'=>$text]);}
 $record=['id'=>$_SERVER['HTTP_X_FIXTURE_REQUEST']??'browser','route'=>$_SERVER['REQUEST_URI'],'pid'=>getmypid(),'start'=>$start,'end'=>microtime(true),'cpu_ms'=>DashboardProfile::cpu()-$cpu,'peak_bytes'=>memory_get_peak_usage(),'payload_bytes'=>strlen((string)ob_get_contents()),'status'=>http_response_code()?:200,'recent_probe'=>$probe,'recent_fallback'=>$probe&&$full,'queries'=>$sql];
 file_put_contents($folder.'/requests.jsonl',json_encode($record,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
});
