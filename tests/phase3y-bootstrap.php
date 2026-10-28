<?php
declare(strict_types=1);
// Owned FPM/CLI connection only. No authentication, CSRF or rate-limit state is seeded here.
$dsn=getenv('CLASSROOM_DSN');$folder=getenv('PHASE3Y_PRIVATE');
if(!$dsn||!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=classroom_perf_test$/D',$dsn)||!$folder||!is_dir($folder))throw new RuntimeException('Owned Phase3Y runtime required');
require_once dirname(__DIR__).'/vendor/autoload.php';
if(APP_DEBUG||APP_ENV!=='testing'||!preg_match('#^http://127\.0\.0\.1:\d+$#D',BREVO_API_URL))throw new RuntimeException('Local delivery with debug disabled required');
ini_set('error_log',$folder.'/php-errors.log');
$db=new PDO($dsn.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
(new ReflectionProperty(Database::class,'instance'))->setValue(null,$db);

if(PHP_SAPI!=='cli')file_put_contents($folder.'/fpm-runtime.jsonl',json_encode(['php'=>PHP_VERSION,'sapi'=>PHP_SAPI,'php_zone'=>date_default_timezone_get(),'app_env'=>APP_ENV,'app_debug'=>APP_DEBUG,'session_lifetime'=>SESSION_LIFETIME,'session_secure'=>SESSION_SECURE,'session_samesite'=>SESSION_SAMESITE,'delivery_is_owned_loopback'=>(bool)preg_match('#^http://127\.0\.0\.1:\d+$#D',BREVO_API_URL)],JSON_THROW_ON_ERROR).PHP_EOL,FILE_APPEND|LOCK_EX);
