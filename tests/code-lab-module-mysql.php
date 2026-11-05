<?php
declare(strict_types=1);
require dirname(__DIR__).'/config.php';
require ROOT_PATH.'/services/CodeLabModuleTasks.php';
$dsn=getenv('FEATURE_TEST_DSN')?:'mysql:host=127.0.0.1;dbname=ecollab_feature_test';
if(!preg_match('/dbname=([a-zA-Z0-9_]+_test)(?:;|$)/',$dsn))throw new RuntimeException('Dedicated *_test database required');
$db=new PDO($dsn,getenv('DB_USER')?:'root',getenv('DB_PASS')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$modules=new CodeLabModuleTasks($db);
if(($argv[1]??'')==='--claim'){echo json_encode($modules->claim());exit;}
if(($argv[1]??'')==='--publish'){echo json_encode($modules->publish(1,1,(int)$argv[2],['title'=>'Reviewed SQL task','instructions'=>'Read values and output their sum.','language'=>'python','kind'=>'assignment','starter_code'=>'','grading_mode'=>'manual']));exit;}
foreach(['code_lab_module_drafts','code_lab_jobs','code_lab_test_cases','code_lab_tasks','code_lab_attempts','code_lab_playgrounds','code_lab_run_gates','users','servers','server_members','user_settings','notifications'] as $t)$db->exec('DROP TABLE IF EXISTS '.$t);
$db->exec("CREATE TABLE users(id INT PRIMARY KEY,role VARCHAR(32),status VARCHAR(32),deleted_at DATETIME NULL);CREATE TABLE servers(id INT PRIMARY KEY,name VARCHAR(100),owner_id INT,status VARCHAR(32));CREATE TABLE server_members(server_id INT,user_id INT);CREATE TABLE user_settings(user_id INT PRIMARY KEY,jarred_enabled TINYINT);CREATE TABLE notifications(id BIGINT AUTO_INCREMENT PRIMARY KEY,recipient_id INT,actor_id INT,type VARCHAR(64),title TEXT,body TEXT,link_url TEXT,icon VARCHAR(32),is_read TINYINT) DEFAULT CHARSET=utf8mb4;INSERT INTO users VALUES(1,'facilitator','active',NULL),(2,'student','active',NULL);INSERT INTO servers VALUES(1,'Course',1,'active');INSERT INTO server_members VALUES(1,1),(1,2);INSERT INTO user_settings VALUES(1,1)");
foreach(['054_code_lab.sql','055_code_lab_execution.sql','066_jarred_reply_queue.sql','067_code_lab_module_drafts.sql'] as $m)$db->exec(file_get_contents(ROOT_PATH.'/database/migrations/'.$m));$db->exec(file_get_contents(ROOT_PATH.'/database/migrations/067_code_lab_module_drafts.sql'));
function moduleDemand(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function moduleChildren(array $args,int $count):array{$all=[];for($i=0;$i<$count;$i++){$p=proc_open(array_merge([PHP_BINARY,__FILE__],$args),[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);moduleDemand(is_resource($p),'Child starts');$all[]=[$p,$pipes];}$results=[];foreach($all as [$p,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);moduleDemand(proc_close($p)===0,'Child failed: '.$err);$results[]=json_decode($out,true,512,JSON_THROW_ON_ERROR);}return $results;}
$text='Use Python for loops to process each number in a list and compute a running sum. Handle zero, positive and negative values.';
$job=$modules->queue(1,1,['file_name'=>'module.txt','text'=>$text,'source_chars'=>strlen($text),'used_chars'=>strlen($text)],'python','medium')['job'];
$claims=array_values(array_filter(moduleChildren(['--claim'],5)));moduleDemand(count($claims)===1,'Five processes claim exactly one module job');
moduleDemand($modules->finish($claims[0],['title'=>'Draft task','instructions'=>'Sum values','language'=>'python','source_excerpt'=>substr($text,0,80)]),'SQL ready transition');
$published=moduleChildren(['--publish',(string)$job['id']],5);moduleDemand(count(array_unique(array_column($published,'id')))===1,'Concurrent publishing returns one task id');moduleDemand((int)$db->query('SELECT COUNT(*) FROM code_lab_tasks')->fetchColumn()===1,'Only one classroom task created');moduleDemand((int)$db->query('SELECT COUNT(*) FROM notifications')->fetchColumn()===1,'Notification created once');moduleDemand($db->query('SELECT module_text FROM code_lab_module_drafts')->fetchColumn()===null,'Published source cleared');
echo "PASS: MySQL/MariaDB module migration rerun, five-process single lease and exactly-once reviewed publication\n";
