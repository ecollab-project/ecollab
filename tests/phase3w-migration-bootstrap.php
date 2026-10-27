<?php
declare(strict_types=1);
$dsn=getenv('CLASSROOM_DSN');if(!$dsn||!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=classroom_perf_test$/D',$dsn))throw new RuntimeException('Owned migration database required');
foreach(['BREVO_API_KEY','BREVO_SENDER_EMAIL']as$key){$_ENV[$key]='';putenv($key.'=');}
$_ENV['DB_NAME']='classroom_perf_test';putenv('DB_NAME=classroom_perf_test');
require_once __DIR__.'/../database/config/db.php';
$db=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
(new ReflectionProperty(Database::class,'instance'))->setValue(null,$db);
