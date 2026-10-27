<?php
declare(strict_types=1);
$dsn=getenv('CLASSROOM_DSN');if(!$dsn||!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=classroom_perf_test$/D',$dsn))throw new RuntimeException('Owned fixture required');
$db=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db->beginTransaction();$db->query('SELECT id FROM messages WHERE id=299999 FOR UPDATE')->fetchAll();echo "LOCK_READY\n";flush();try{fgets(STDIN);$db->commit();}finally{if($db->inTransaction())$db->rollBack();}
