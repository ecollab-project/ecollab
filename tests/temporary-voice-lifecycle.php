<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/TemporaryVoiceService.php';
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE channels(id INTEGER PRIMARY KEY,server_id INTEGER,type TEXT,is_private INTEGER,created_by INTEGER);CREATE TABLE users(id INTEGER PRIMARY KEY,voice_channel_id INTEGER,role TEXT,deleted_at TEXT,status TEXT);CREATE TABLE server_members(server_id INTEGER,user_id INTEGER,server_role TEXT);CREATE TABLE channel_members(channel_id INTEGER,user_id INTEGER);CREATE TABLE temporary_voice_rooms(channel_id INTEGER PRIMARY KEY,empty_since TEXT,expires_at TEXT);CREATE TABLE ws_relay(id INTEGER PRIMARY KEY,channel_id INTEGER,payload TEXT);
INSERT INTO channels VALUES(10,1,'voice',0,1),(11,1,'voice',0,1),(12,1,'voice',1,1);
INSERT INTO users VALUES(1,NULL,'student',NULL,'active'),(2,NULL,'student',NULL,'active');INSERT INTO server_members VALUES(1,1,'member'),(1,2,'member');INSERT INTO channel_members VALUES(12,1);INSERT INTO temporary_voice_rooms VALUES(10,NULL,NULL),(12,NULL,NULL);");
$s=(new ReflectionClass(TemporaryVoiceService::class))->newInstanceWithoutConstructor();(new ReflectionProperty($s,'db'))->setValue($s,$db);
function vcCheck(bool $ok,string $name):void{if(!$ok)throw new RuntimeException($name);}
$s->joinChannel(1,10);$s->joinChannel(2,10);$s->leaveChannel(1,10);vcCheck((int)$db->query('SELECT COUNT(*) FROM channels WHERE id=10')->fetchColumn()===1,'occupied room survives');
$s->joinChannel(1,11);$s->leaveChannel(1,10);vcCheck((int)$db->query('SELECT voice_channel_id FROM users WHERE id=1')->fetchColumn()===11,'stale leave cannot clear a different room');
$s->leaveChannel(2,10);vcCheck((int)$db->query('SELECT COUNT(*) FROM channels WHERE id=10')->fetchColumn()===0,'last leave deletes temporary room');
$s->leaveChannel(1,11);vcCheck((int)$db->query('SELECT COUNT(*) FROM channels WHERE id=11')->fetchColumn()===1,'ordinary room survives');
try{$s->joinChannel(2,12);throw new RuntimeException('private bypass');}catch(RuntimeException $e){vcCheck($e->getCode()===403,'private membership denied');}
$s->joinChannel(1,12);$s->joinChannel(1,11);vcCheck((int)$db->query('SELECT COUNT(*) FROM channels WHERE id=12')->fetchColumn()===0,'switching deletes empty old temporary room');
vcCheck((int)$db->query('SELECT COUNT(*) FROM ws_relay')->fetchColumn()===2,'deletion updates queued');
echo "PASS: temporary room last leave/switch, occupied/ordinary preservation, private access and stale leave\n";

$http=file_get_contents(dirname(__DIR__).'/API/chat/active-now.php');
vcCheck(str_contains($http, '$expectedChannelId=(int)($_POST[\'channel_id\']??0)'), 'HTTP leave binds to posted expected channel');
