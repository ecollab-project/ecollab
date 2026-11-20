<?php
declare(strict_types=1);
require dirname(__DIR__).'/services/CodeLabIntegrityPolicy.php';
$db=new PDO('sqlite::memory:');$db->exec("CREATE TABLE users(id INTEGER,role TEXT,deleted_at TEXT,status TEXT);CREATE TABLE servers(id INTEGER,status TEXT);CREATE TABLE server_members(server_id INTEGER,user_id INTEGER);CREATE TABLE code_lab_tasks(id INTEGER,server_id INTEGER,title TEXT,due_at TEXT,duration_minutes INTEGER);CREATE TABLE code_lab_attempts(id INTEGER,user_id INTEGER,task_id INTEGER,started_at TEXT,submitted_at TEXT);
INSERT INTO users VALUES(1,'student',NULL,'active'),(2,'facilitator',NULL,'active');INSERT INTO servers VALUES(1,'active');INSERT INTO server_members VALUES(1,1),(1,2);INSERT INTO code_lab_tasks VALUES(10,1,'Loops','2026-11-04 00:00:00',60);INSERT INTO code_lab_attempts VALUES(1,1,10,'2026-11-03 00:00:00',NULL);");
$now=strtotime('2026-11-03 00:10:00 UTC');
function aiCheck(bool $ok,string $name):void{if(!$ok)throw new RuntimeException($name);}
aiCheck(CodeLabIntegrityPolicy::activeAttempt($db,1,$now)['id']===10,'server active task found without browser flag');
aiCheck(CodeLabIntegrityPolicy::activeAttempt($db,2,$now)===null,'facilitator assistance unaffected');
aiCheck(CodeLabIntegrityPolicy::activeAttempt($db,1,$now+3600)===null,'expired task does not restrict normal AI');
$db->exec("UPDATE code_lab_attempts SET submitted_at='2026-11-03 00:05:00'");aiCheck(CodeLabIntegrityPolicy::activeAttempt($db,1,$now)===null,'submitted attempt restores normal assistance');
$db->exec('UPDATE code_lab_attempts SET submitted_at=NULL;DELETE FROM server_members WHERE user_id=1');aiCheck(CodeLabIntegrityPolicy::activeAttempt($db,1,$now)===null,'revoked membership excludes task context');
aiCheck(str_contains(CodeLabIntegrityPolicy::workflow(['title'=>'Loops']),'test cases'),'workflow provides learning help');
require dirname(__DIR__).'/services/JarredAssistancePolicy.php';
aiCheck(JarredAssistancePolicy::unsupported('Please delete this server')===true,'unsupported admin mutation denied');
aiCheck(JarredAssistancePolicy::unsupported('Explain how to delete a node in a linked list')===false,'academic explanation remains available');
aiCheck(JarredAssistancePolicy::REFUSAL==="I'm sorry, the task that you want from me is not part of my capacity",'exact refusal copy');
echo "PASS: persisted active task, expiry/submission/role/membership, learning workflow and capability boundaries\n";
