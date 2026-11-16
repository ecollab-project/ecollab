<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/MessageService.php';
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,username TEXT,full_name TEXT,avatar_url TEXT,avatar_color_gradient TEXT,role TEXT);
CREATE TABLE channels(id INTEGER PRIMARY KEY,server_id INTEGER);
CREATE TABLE server_members(server_id INTEGER,user_id INTEGER);
CREATE TABLE messages(id INTEGER PRIMARY KEY,channel_id INTEGER,sender_id INTEGER,content TEXT,content_type TEXT,is_pinned INTEGER,created_at TEXT,updated_at TEXT,is_deleted INTEGER);
CREATE TABLE message_attachments(id INTEGER PRIMARY KEY,message_id INTEGER,file_name TEXT,file_path TEXT,file_size INTEGER,mime_type TEXT);
INSERT INTO users VALUES(1,'member','Member',NULL,'','student'),(2,'outsider','Outsider',NULL,'','student');
INSERT INTO channels VALUES(20,5);INSERT INTO server_members VALUES(5,1);
INSERT INTO messages VALUES(1,20,1,'','image',1,'2026-11-03','2026-11-03',0),(2,20,1,'','image',1,'2026-11-04','2026-11-04',1);
INSERT INTO message_attachments VALUES(1,1,'photo.png','photo.png',10,'image/png'),(2,2,'deleted.png','deleted.png',10,'image/png');");
$reflection=new ReflectionClass(MessageService::class);$service=$reflection->newInstanceWithoutConstructor();$reflection->getProperty('db')->setValue($service,$db);
$pins=$service->getPinnedMessages(20,1);
if(count($pins)!==1||($pins[0]['attachments'][0]['file_name']??'')!=='photo.png')throw new RuntimeException('Pin attachments missing or deleted pin leaked');
try{$service->getPinnedMessages(20,2);throw new RuntimeException('Outsider read pins');}catch(RuntimeException $e){if($e->getCode()!==403)throw $e;}
echo "PASS: pinned attachment metadata, deleted message exclusion and outsider denial\n";
