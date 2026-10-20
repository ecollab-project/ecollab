<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/SystemCapacityService.php';
function expect(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejects(callable $fn,string $message): void { try {$fn();} catch (InvalidArgumentException|RuntimeException $e) {return;} throw new LogicException($message); }
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->sqliteCreateFunction('NOW',fn()=>gmdate('Y-m-d H:i:s'));
$sql=file_get_contents(dirname(__DIR__).'/database/migrations/053_system_capacity.sql');
$sql=str_replace(['BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY','INSERT IGNORE'],['INTEGER PRIMARY KEY AUTOINCREMENT','INSERT OR IGNORE'],$sql);
$sql=preg_replace('/\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/',');',$sql);
$db->exec($sql);
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY,email TEXT,is_online INTEGER,status TEXT,deleted_at TEXT,last_active_at TEXT,last_seen_at TEXT)');
$now=gmdate('Y-m-d H:i:s'); $old=gmdate('Y-m-d H:i:s',time()-3600); $idle=gmdate('Y-m-d H:i:s',time()-300);
$insert=$db->prepare('INSERT INTO users VALUES (?,?,?,?,?,?,?)');
foreach ([[1,'student@example.com',1,'active',null,$now,$old], [2,'k6_fixture_1@example.invalid',1,'active',null,$now,$now],
 [3,'idle@example.com',1,'active',null,$idle,$old], [4,'stale@example.com',1,'active',null,$old,$old],
 [5,'deleted@example.com',1,'active',$now,$now,$now], [6,'banned@example.com',1,'banned',null,$now,$now],
 [7,'loggedout@example.com',0,'offline',null,$now,$now], [8,'login@example.com',1,'active',null,null,$now]] as $user) $insert->execute($user);
$service=new SystemCapacityService($db);
expect($service->current()===['active_users'=>2,'presence_users'=>3],'Fresh presence, idle, exclusions and login fallback');
$service->collect();$service->collect();
expect((int)$db->query('SELECT COUNT(*) FROM capacity_samples')->fetchColumn()===1,'Concurrent/minute duplicate collection');
$view=$service->overview();
expect((int)$view['baseline']['tested_users']===300 && count($view['records'])===2,'Seeded evidence');
expect($view['history']['peak_users']===2 && $view['history']['samples_today']===1,'Sampled peak');
expect(count(array_filter($view['history']['hourly_peaks'],fn($v)=>$v===null))===23,'Missing hours remain gaps');
$service->save(['action'=>'settings','version'=>1,'operational_target'=>250],42);
expect($service->overview()['settings']['operational_target']===250,'Operational target saved');
rejects(fn()=>$service->save(['action'=>'settings','version'=>1,'operational_target'=>200],42),'Stale edit should fail');
rejects(fn()=>$service->save(['action'=>'settings','version'=>2,'operational_target'=>301],42),'Target above baseline should fail');
$record=['action'=>'record','version'=>2,'tested_users'=>350,'hold_seconds'=>300,'result'=>'failed','completed'=>false,
 'tested_on'=>(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d'),'workload'=>'Chat','notes'=>'Observed reliability failure.',
 'api_success_percent'=>99.8,'http_p95_ms'=>1000,'delivery_p95_ms'=>3300,'set_baseline'=>true];
rejects(fn()=>$service->save($record,42),'Failed baseline should be rejected');
expect((int)$db->query('SELECT COUNT(*) FROM capacity_test_records')->fetchColumn()===2,'Rejected record rolls back');
$record['set_baseline']=false;$service->save($record,42);
expect((int)$service->overview()['baseline']['tested_users']===300,'Failed record cannot replace capacity');
$record['version']=3;$record['result']='passed';$record['completed']=true;$record['tested_users']=325;$record['set_baseline']=true;
$service->save($record,42);
expect((int)$service->overview()['baseline']['tested_users']===325,'Completed passed reference saved');
expect((int)$db->query('SELECT COUNT(*) FROM capacity_settings_history')->fetchColumn()===3,'Audit every successful change');
$invalid=$record;$invalid['api_success_percent']=101;rejects(fn()=>SystemCapacityService::validateRecord($invalid),'Invalid metric');
$invalid=$record;$invalid['tested_on']='2026-02-30';rejects(fn()=>SystemCapacityService::validateRecord($invalid),'Invalid date');
$invalid=$record;$invalid['completed']=false;rejects(fn()=>SystemCapacityService::validateRecord($invalid),'Incomplete passed record');
$db->exec("INSERT INTO capacity_samples VALUES ('2000-01-01 00:00:00',99,99)");$service->collect();
expect((int)$db->query('SELECT COUNT(*) FROM capacity_samples')->fetchColumn()===1,'90-day retention');
echo "System capacity presence, history, validation, transactions and audit checks passed\n";
