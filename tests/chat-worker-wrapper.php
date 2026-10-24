<?php
// Execute the real supervisor/worker scripts with normal connection creation after fork.
$dsn=getenv('WORKER_PERF_DSN');if(!$dsn||!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=(jarred_async_test|code_worker_perf)$/D',$dsn))throw new RuntimeException('Owned fixture only');
if(str_ends_with($dsn,'jarred_async_test'))require __DIR__.'/../scripts/jarred-worker.php';
else require __DIR__.'/../scripts/code-lab-worker.php';
