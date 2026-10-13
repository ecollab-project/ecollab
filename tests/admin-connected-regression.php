<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/AdminReportService.php';
require_once dirname(__DIR__).'/services/AdminSpaceMonitorService.php';
ini_set('display_errors','1');ini_set('error_log','/dev/null');error_reporting(E_ALL);
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
function denied(callable $fn,int $code=0):void{try{$fn();}catch(Throwable $e){check(!$code||$e->getCode()===$code,'Unexpected rejection: '.$e->getMessage());return;}throw new RuntimeException('Expected rejection');}
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,username TEXT,full_name TEXT,role TEXT,status TEXT,deleted_at TEXT);
CREATE TABLE servers(id INTEGER PRIMARY KEY,owner_id INTEGER,name TEXT,description TEXT,type TEXT,status TEXT);
CREATE TABLE channels(id INTEGER PRIMARY KEY,server_id INTEGER,name TEXT,type TEXT,is_private INTEGER,is_locked INTEGER);
CREATE TABLE server_members(server_id INTEGER,user_id INTEGER,server_role TEXT);
CREATE TABLE messages(id INTEGER PRIMARY KEY,channel_id INTEGER,sender_id INTEGER,content TEXT,is_deleted INTEGER DEFAULT 0,deleted_at TEXT,created_at TEXT);
CREATE TABLE content_reports(id INTEGER PRIMARY KEY,reporter_id INTEGER,reported_user_id INTEGER,message_id INTEGER,server_id INTEGER,reason TEXT,description TEXT,status TEXT,created_at TEXT,resolved_by INTEGER,resolved_at TEXT,resolution_note TEXT);
CREATE TABLE activity_logs(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,action TEXT,entity_type TEXT,entity_id INTEGER,description TEXT,level TEXT,created_at TEXT);
INSERT INTO users VALUES(1,'owner','Owner','student','active',NULL),(2,'member','Member','student','active',NULL),(3,'facilitator','Facilitator','facilitator','active',NULL),(4,'other','Other','student','active',NULL);
INSERT INTO servers VALUES(10,1,'Student space','Community','public','active'),(20,3,'Facilitator space','Class','private','active');
INSERT INTO channels VALUES(11,10,'general','text',0,0),(12,10,'private','text',1,0),(21,20,'other','text',0,0);
INSERT INTO server_members VALUES(10,1,'owner'),(10,2,'member'),(20,3,'owner'),(20,4,'member');
");
$today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');$time=$today.' 02:00:00';
$q=$db->prepare('INSERT INTO messages(id,channel_id,sender_id,content,created_at) VALUES(?,?,?,?,?)');foreach([[1,11,1,'Owner message'],[2,11,2,'Member message'],[3,12,2,'Private evidence'],[4,21,4,'Other space']]as $r)$q->execute([...$r,$time]);
$q=$db->prepare("INSERT INTO content_reports(id,reporter_id,reported_user_id,message_id,server_id,reason,description,status,created_at) VALUES(?,?,?,?,?,?,?,'pending',?)");foreach([[1,1,2,2,10,'spam','Review this'],[2,1,2,3,10,'harassment','Private message'],[3,3,4,4,20,'spam','Facilitator review']]as $r)$q->execute([...$r,$time]);
$reports=new AdminReportService($db);$list=$reports->listing(['status'=>'all','limit'=>1]);check($list['total']===2&&count($list['items'])===1,'Report scope/pagination');check(count($reports->listing(['q'=>'Private'])['items'])===1,'Report search');check($reports->listing(['server_id'=>20])['total']===0,'Facilitator reports leaked into review queue');
$r=$reports->listing(['q'=>'Review this'])['items'][0];denied(fn()=>$reports->resolve(['report_id'=>3,'resolution'=>'resolved','revision'=>$r['revision']],9),403);denied(fn()=>$reports->resolve(['report_id'=>1,'resolution'=>'unknown','revision'=>$r['revision']],9));
$db->exec("UPDATE messages SET content='Edited evidence' WHERE id=2");denied(fn()=>$reports->resolve(['report_id'=>1,'resolution'=>'resolved','revision'=>$r['revision']],9),409);$r=$reports->listing(['q'=>'Review this'])['items'][0];$reports->resolve(['report_id'=>1,'resolution'=>'resolved','revision'=>$r['revision'],'note'=>'Verified by admin'],9);check((int)$db->query('SELECT is_deleted FROM messages WHERE id=2')->fetchColumn()===1,'Message not removed');check((int)$db->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn()===1,'Review not audited');denied(fn()=>$reports->resolve(['report_id'=>1,'resolution'=>'dismissed','revision'=>$r['revision']],9),409);
$monitor=new AdminSpaceMonitorService($db);$m=$monitor->data(10,7);check($m['stats']['members']===1,'Owner included in member count');check($m['stats']['contributors']===1,'Owner included in contributors');check($m['stats']['messages']===2,'Deleted message counted');check(count($m['daily'])===7,'Date range not filled');check(array_sum(array_column($m['daily'],'messages'))===2,'Daily message count mismatch');check($m['channels'][0]['messages']+$m['channels'][1]['messages']===2,'Channel aggregation inflated');check($m['can_review_reports']===true,'Student-owned review scope lost');check($monitor->data(20,30)['can_review_reports']===false,'Facilitator-owned review actions exposed');check(count($monitor->data(10,90)['daily'])===90,'90-day range');denied(fn()=>$monitor->data(999),404);denied(fn()=>$monitor->data(10,8));
$r=$reports->listing(['q'=>'Private'])['items'][0];$db->exec('DROP TABLE activity_logs');denied(fn()=>$reports->resolve(['report_id'=>2,'resolution'=>'resolved','revision'=>$r['revision']],9));check($db->query('SELECT status FROM content_reports WHERE id=2')->fetchColumn()==='pending','Decision persisted without audit');check((int)$db->query('SELECT is_deleted FROM messages WHERE id=3')->fetchColumn()===0,'Deletion persisted despite rollback');
$db->exec('DROP TABLE messages');$m=$monitor->data(10);check($m['daily']===null&&$m['stats']['messages']===null,'Unavailable source became zero');check(in_array('daily',$m['unavailable'],true),'Unavailable source not reported');
foreach(['reports','tools']as $name){$route=file_get_contents(dirname(__DIR__).'/modules/admin/'.$name.'.php');check(str_contains($route,"requireRole(['admin','super_admin'])"),'Admin route lacks role guard');check(str_contains($route,'dashboard.php?page='.$name),'Legacy route lacks workspace redirect');}
echo "Admin connected-page regression checks passed.\n";
