<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/FeatureCapacityFixture.php';
function ensure($condition,$message){if(!$condition)throw new RuntimeException($message);}
$dsn=getenv('TEST_LOAD_DSN');if(!$dsn)throw new RuntimeException('Use the disposable CI database');
$db=new PDO($dsn,getenv('TEST_LOAD_USER')?:'root',getenv('TEST_LOAD_PASSWORD')?:'root',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
ensure($db->query('SELECT DATABASE()')->fetchColumn()==='load_fixture_test','Refuse non-test database');
$db->exec('CREATE DATABASE feature_capacity_test');$db->exec('USE feature_capacity_test');
try{
    $db->exec("CREATE TABLE users(id INT PRIMARY KEY,username VARCHAR(80),email VARCHAR(120),role VARCHAR(20),deleted_at DATETIME NULL,status VARCHAR(20));CREATE TABLE servers(id INT PRIMARY KEY,slug VARCHAR(80),owner_id INT,type VARCHAR(20),status VARCHAR(20));CREATE TABLE server_members(server_id INT,user_id INT)");
    $db->exec("INSERT INTO users VALUES(1,'k6_1234abcd_1','k6_1234abcd_1@example.invalid','student',NULL,'offline'),(2,'k6_1234abcd_2','k6_1234abcd_2@example.invalid','student',NULL,'offline'),(3,'real','real@example.com','student',NULL,'offline');INSERT INTO servers VALUES(19,'k6-20261018-000000-1234abcd',1,'private','active');INSERT INTO server_members VALUES(19,1),(19,2)");
    $db->exec("CREATE TABLE code_lab_tasks(id INT AUTO_INCREMENT PRIMARY KEY,server_id INT,created_by INT,title VARCHAR(160),instructions TEXT,language VARCHAR(16),kind VARCHAR(16),starter_code TEXT,grading_mode VARCHAR(16),runtime_id INT);CREATE TABLE code_lab_test_cases(id INT AUTO_INCREMENT PRIMARY KEY,task_id INT,position INT,input_data TEXT,expected_output TEXT,hidden INT,weight INT);CREATE TABLE code_lab_attempts(id INT PRIMARY KEY,task_id INT,user_id INT);CREATE TABLE code_lab_jobs(id INT PRIMARY KEY,server_id INT,user_id INT,attempt_id INT NULL,state VARCHAR(20),source_code TEXT);CREATE TABLE ai_sessions(id INT PRIMARY KEY,user_id INT,session_title VARCHAR(160));CREATE TABLE ai_messages(id INT PRIMARY KEY,session_id INT)");
    $fixture=['version'=>1,'fixture_id'=>'k6-20261018-000000-1234abcd','server_id'=>19,'base_url'=>'https://example.test','users'=>array_map(fn($i)=>['id'=>$i,'email'=>'k6_1234abcd_'.$i.'@example.invalid','password'=>str_repeat('x',48)],[1,2])];
    putenv('CODE_LAB_JUDGE0_URL=http://127.0.0.1:2358');putenv('CODE_LAB_LANGUAGE_IDS={"c":10001,"cpp":10002,"python":10003,"java":10004}');putenv('LIVEKIT_API_KEY=devkey');putenv('LIVEKIT_API_SECRET=secret');putenv('LIVEKIT_URL=ws://127.0.0.1:7880');
    $tool=new FeatureCapacityFixture($db);$tool->validate($fixture);
    $db->exec('INSERT INTO server_members VALUES(19,3)');try{$tool->prepare($fixture,'code-grade');throw new LogicException('Real member accepted');}catch(RuntimeException $e){ensure(str_contains($e->getMessage(),'membership differs'),'Reject outsiders');}
    ensure((int)$db->query('SELECT COUNT(*) FROM code_lab_tasks')->fetchColumn()===0,'No mutation after validation rejection');$db->exec('DELETE FROM server_members WHERE user_id=3');
    $target=$tool->prepare($fixture,'code-grade');ensure(count($target['task_ids'])===52,'Fresh assignments for all stages and smoke sizes');ensure((int)$db->query('SELECT COUNT(*) FROM code_lab_test_cases')->fetchColumn()===104,'Two grading cases per language/stage');
    foreach(['c','cpp','python','java']as$language)ensure(isset($target['task_ids']['125:'.$language]),'125-user assignments exist for every runtime');ensure(count(array_unique($target['task_ids']))===52,'Each level and runtime receives an independent assignment');
    $id=$target['task_ids']['125:c'];$db->exec("INSERT INTO code_lab_attempts VALUES(1,$id,1);INSERT INTO code_lab_jobs VALUES(1,19,1,1,'running','test')");
    try{$tool->cleanup($fixture,$target);throw new LogicException('Active job cleanup accepted');}catch(RuntimeException $e){ensure(str_contains($e->getMessage(),'drain'),'Do not delete active jobs');}
    ensure((int)$db->query('SELECT COUNT(*) FROM code_lab_tasks')->fetchColumn()===52,'Cleanup rollback retains all tasks');
    $db->exec("UPDATE code_lab_jobs SET state='done'");$tool->cleanup($fixture,$target);ensure((int)$db->query('SELECT COUNT(*) FROM code_lab_tasks')->fetchColumn()===0,'Synthetic grading data removed');
    $media=$tool->prepare($fixture,'media',2);ensure(count($media['media'])===2,'Scoped participant tokens');ensure(!str_contains(json_encode($media),'secret'),'No server secret export');
    $db->exec("UPDATE users SET role='facilitator' WHERE id=1");try{$tool->validate($fixture);throw new LogicException('Role elevation accepted');}catch(RuntimeException $e){ensure(str_contains($e->getMessage(),'student'),'Reject changed account roles');}
    echo "PASS: real-data guards, isolated assignments per stage, cleanup rollback/active job protection, scoped media tokens and no secret export\n";
}finally{if($db->inTransaction())$db->rollBack();$db->exec('DROP DATABASE feature_capacity_test');}
