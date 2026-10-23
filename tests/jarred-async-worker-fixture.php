<?php
// Isolated test wrapper uses the real production CLI worker, action router and generator.
$dsn=getenv('JARRED_ASYNC_TEST_DSN');
if(!$dsn||!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=jarred_async_test$/D',$dsn))throw new RuntimeException('Owned loopback fixture required');
require_once __DIR__.'/../database/config/db.php';
$db=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
(new ReflectionProperty(Database::class,'instance'))->setValue(null,$db);
require __DIR__.'/../scripts/jarred-worker.php';
