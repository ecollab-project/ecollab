<?php
declare(strict_types=1);
require_once __DIR__.'/../services/ChatSearchService.php';
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,username TEXT,full_name TEXT,deleted_at TEXT,status TEXT);CREATE TABLE servers(id INTEGER,status TEXT);CREATE TABLE server_members(server_id INTEGER,user_id INTEGER);CREATE TABLE channels(id INTEGER,server_id INTEGER,name TEXT,is_private INTEGER,created_by INTEGER);CREATE TABLE messages(id INTEGER PRIMARY KEY,channel_id INTEGER,sender_id INTEGER,content TEXT,created_at TEXT,is_deleted INTEGER DEFAULT 0);CREATE TABLE channel_members(channel_id INTEGER,user_id INTEGER);CREATE TABLE moderation_actions(target_user_id INTEGER,server_id INTEGER,is_active INTEGER,action_type TEXT,expires_at TEXT);
INSERT INTO users VALUES(1,'student','Student',NULL,'active'),(2,'author','Author',NULL,'idle');INSERT INTO servers VALUES(1,'active'),(2,'active');INSERT INTO server_members VALUES(1,1);INSERT INTO channels VALUES(10,1,'public',0,2),(11,1,'private',1,2),(12,2,'other',0,2);");
$q=$db->prepare("INSERT INTO messages(id,channel_id,sender_id,content,created_at)VALUES(?,?,2,?,'2026-10-20 00:00:00')");
for($i=1;$i<=55;$i++)$q->execute([$i,10,'substringneedlecontent']);
$q->execute([56,11,'needle secret']);$q->execute([57,12,'needle other']);
$s=new ChatSearchService($db);
function check($v,$msg){if(!$v)throw new RuntimeException($msg);}
$r=$s->search(1,1,10,'needle');check(count($r['results'])===50,'Bounded first page');
$c=json_decode(base64_decode($r['next_cursor']),true);$next=$s->search(1,1,10,'needle',$c);check(array_column($next['results'],'id')===[5,4,3,2,1],'Stable tied-timestamp keyset');
check(count($s->search(1,1,11,'needle')['results'])===0,'Private denied');
check(count($s->search(1,1,12,'needle')['results'])===0,'Cross-server denied');
$db->exec('INSERT INTO channel_members VALUES(11,1)');check(count($s->search(1,1,11,'needle')['results'])===1,'Private membership allowed');
$db->exec("INSERT INTO moderation_actions VALUES(1,1,1,'ban',NULL)");check(!$s->search(1,1,10,'needle')['results'],'Banned visibility denied');
try{$s->search(1,1,10,str_repeat('a',121));throw new LogicException('Missing bound');}catch(InvalidArgumentException $e){check($e->getCode()===400,'Input bound');}
echo "PASS substring search, private visibility, cross-server isolation, moderation, bounded input and tied-timestamp pagination\n";
