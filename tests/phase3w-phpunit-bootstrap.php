<?php
declare(strict_types=1);
$dsn=getenv('CLASSROOM_DSN');if(!$dsn||!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=classroom_perf_test$/D',$dsn))throw new RuntimeException('Owned PHPUnit fixture required');
foreach(['BREVO_API_KEY'=>'','BREVO_SENDER_EMAIL'=>'','DB_NAME'=>'classroom_perf_test']as$key=>$value){$_ENV[$key]=$value;putenv($key.'='.$value);}
register_shutdown_function(static function():void{$e=error_get_last();if($e&&in_array($e['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true))fwrite(STDERR,json_encode($e,JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL);});
// Composer owns its autoload.files registry. Load it before injecting Database,
// so PHPUnit's later autoload does not redeclare an already-loaded class.
$suiteRoot=getenv('PHASE3W_SUITE_ROOT')?:dirname(__DIR__);
require_once $suiteRoot.'/vendor/autoload.php';
$db=new PDO($dsn.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
(new ReflectionProperty(Database::class,'instance'))->setValue(null,$db);
