<?php
declare(strict_types=1);
require dirname(__DIR__).'/services/PeerMatchingService.php';
function expect(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE users(id INTEGER,status TEXT,deleted_at TEXT,is_system INTEGER);CREATE TABLE user_settings(user_id INTEGER,ai_matching INTEGER);CREATE TABLE peer_activity_days(user_id INTEGER,feature TEXT,event_day TEXT,PRIMARY KEY(user_id,feature,event_day));CREATE TABLE peer_behavior_profiles(user_id INTEGER PRIMARY KEY,features_json TEXT,event_count INTEGER,refreshed_at TEXT);CREATE TABLE servers(id INTEGER,status TEXT);CREATE TABLE server_members(user_id INTEGER,server_id INTEGER);CREATE TABLE channels(id INTEGER,server_id INTEGER,is_private INTEGER,name TEXT);CREATE TABLE messages(sender_id INTEGER,channel_id INTEGER,created_at TEXT,is_deleted INTEGER,content_type TEXT DEFAULT 'text');CREATE TABLE code_lab_tasks(id INTEGER,language TEXT);CREATE TABLE code_lab_attempts(user_id INTEGER,task_id INTEGER,submitted_at TEXT,score REAL);CREATE TABLE recommendation_books(work_key TEXT,features_json TEXT);CREATE TABLE recommendation_book_events(user_id INTEGER,work_key TEXT,created_at TEXT);
INSERT INTO users VALUES(1,'offline',NULL,0),(2,'active',NULL,0),(3,'active',NULL,0);INSERT INTO user_settings VALUES(1,1),(2,1),(3,0);INSERT INTO servers VALUES(1,'active');INSERT INTO server_members VALUES(1,1),(2,1);INSERT INTO channels VALUES(1,1,0,'PHP'),(2,1,1,'secret-topic');INSERT INTO code_lab_tasks VALUES(1,'php');");
$s=new PeerBehaviorService($db);
expect($s->record(1,'chat'),'offline eligible user recorded');$s->record(1,'chat');
expect((int)$db->query('SELECT COUNT(*) FROM peer_activity_days')->fetchColumn()===1,'daily clicks deduplicated');
expect(!$s->record(3,'chat'),'opt-out never collected');
try{$s->record(1,'forged-grade:100');throw new RuntimeException('arbitrary features accepted');}catch(InvalidArgumentException $e){}
$now=gmdate('Y-m-d H:i:s');$old=gmdate('Y-m-d',time()-100*86400);
$db->prepare('INSERT INTO peer_activity_days VALUES(?,?,?)')->execute([1,'page:old',$old]);
foreach([1,2] as $uid){
 foreach(['library','code_lab','collaboration','dashboard'] as $page)$s->record($uid,$page);
 $db->prepare('INSERT INTO messages(sender_id,channel_id,created_at,is_deleted) VALUES(?,1,?,0),(?,2,?,0)')->execute([$uid,$now,$uid,$now]);
 $db->prepare('INSERT INTO code_lab_attempts VALUES(?,1,?,85)')->execute([$uid,$now]);
}
$db->prepare('INSERT INTO recommendation_books VALUES(?,?)')->execute(['/works/OL1W','["subject:programming"]']);
$db->prepare('INSERT INTO recommendation_book_events VALUES(1,?,?)')->execute(['/works/OL1W',$now]);
$db->prepare('INSERT INTO recommendation_books VALUES(?,?)')->execute(['/works/OL2W','["subject:programming"]']);
$db->prepare('INSERT INTO recommendation_book_events VALUES(1,?,?)')->execute(['/works/OL2W',$now]);
$db->exec("INSERT INTO users VALUES(4,'active',NULL,0)");
$db->prepare('INSERT INTO recommendation_books VALUES(?,?)')->execute(['/works/OL3W','["subject:a","subject:b","subject:c","subject:d","subject:e","subject:f"]']);
$db->prepare('INSERT INTO recommendation_book_events VALUES(4,?,?)')->execute(['/works/OL3W',$now]);
$s->refresh();expect($s->profile(4)===null,'one book with many subjects is still sparse history');$a=$s->profile(1);$b=$s->profile(2);
expect($a!==null&&$b!==null,'real aggregates loaded');
expect(abs($a['features']['book:subject:programming']-log(3))<.00001,'shared book features accumulate before normalization');
expect(isset($a['features']['topic:php'],$a['features']['task:php:proficient'],$a['features']['book:subject:programming']),'trusted message/task/book signals');
expect(!isset($a['features']['topic:secret-topic'],$a['features']['page:old']),'private topic and expired events excluded');
$first=$db->query('SELECT features_json FROM peer_behavior_profiles WHERE user_id=1')->fetchColumn();$s->refresh();
expect($first===$db->query('SELECT features_json FROM peer_behavior_profiles WHERE user_id=1')->fetchColumn(),'repeated refresh idempotent');
$matcher=new PeerMatchingService();$plain=$matcher->scoreProfiles([],[]);$with=$matcher->scoreProfiles(['behavior'=>$a],['behavior'=>$b]);
expect($with['behavior_weight']===20,'behavior weight bounded');
expect(array_sum($with['weights'])===100.0,'explanation weights include behavioral contribution');
expect(abs($with['total']-round($plain['total']*.8+$with['behavior']*.2,2))<=.01,'bounded blend');
expect($matcher->scoreProfiles(['behavior'=>$a],[])===$plain,'cold start exact fallback');
$db->exec('UPDATE user_settings SET ai_matching=0 WHERE user_id=1');expect($s->profile(1)===null,'optout immediately excludes stale snapshots');
$s->clear(1);expect((int)$db->query('SELECT COUNT(*) FROM peer_activity_days WHERE user_id=1')->fetchColumn()===0,'optout clears collected history');
$s->refresh();expect((int)$db->query('SELECT COUNT(*) FROM peer_behavior_profiles WHERE user_id=1')->fetchColumn()===0,'refresh excludes optout');
$db->exec('DROP TABLE peer_behavior_profiles');expect($s->profile(2)===null,'missing optional migration preserves fallback');
echo "PASS: bounded matching, sparse fallback, dedup, optout, retention, privacy, task/book signals and repeat refresh\n";
