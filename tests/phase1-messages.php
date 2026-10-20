<?php
declare(strict_types=1);
require_once __DIR__.'/../services/MessageService.php';
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,status TEXT,deleted_at TEXT,username TEXT,full_name TEXT,avatar_url TEXT,avatar_color_gradient TEXT,is_verified INTEGER);
CREATE TABLE servers(id INTEGER PRIMARY KEY,status TEXT);
CREATE TABLE channels(id INTEGER PRIMARY KEY,server_id INTEGER,type TEXT,is_private INTEGER,is_locked INTEGER,created_by INTEGER);
CREATE TABLE server_members(server_id INTEGER,user_id INTEGER,server_role TEXT);
CREATE TABLE channel_members(channel_id INTEGER,user_id INTEGER);
CREATE TABLE moderation_actions(target_user_id INTEGER,server_id INTEGER,action_type TEXT,is_active INTEGER,expires_at TEXT);
CREATE TABLE messages(id INTEGER PRIMARY KEY,channel_id INTEGER,sender_id INTEGER,content TEXT,content_type TEXT,parent_id INTEGER,is_edited INTEGER DEFAULT 0,is_pinned INTEGER DEFAULT 0,reaction_count INTEGER DEFAULT 0,created_at TEXT,updated_at TEXT,is_deleted INTEGER DEFAULT 0);
CREATE TABLE message_reactions(message_id INTEGER,user_id INTEGER,emoji TEXT);
CREATE TABLE message_attachments(message_id INTEGER,file_name TEXT,file_path TEXT,file_size INTEGER,mime_type TEXT);
CREATE TABLE polls(id INTEGER PRIMARY KEY,message_id INTEGER,question TEXT,total_votes INTEGER);
CREATE TABLE poll_options(id INTEGER PRIMARY KEY,poll_id INTEGER,option_text TEXT,vote_count INTEGER,position INTEGER);
CREATE TABLE poll_votes(poll_id INTEGER,option_id INTEGER,user_id INTEGER);
INSERT INTO users VALUES(1,'student','active',NULL,'reader','Reader',NULL,NULL,1);
INSERT INTO servers VALUES(1,'active'); INSERT INTO channels VALUES(10,1,'text',1,0,99),(20,1,'text',0,0,99);
INSERT INTO server_members VALUES(1,1,'member');");
$q=$db->prepare("INSERT INTO messages(id,channel_id,sender_id,content,content_type,created_at) VALUES(?,10,1,?,'text','2026-01-01')");
for($i=1;$i<=105;$i++)$q->execute([$i,"message $i"]);
$db->exec("INSERT INTO messages(id,channel_id,sender_id,content,content_type) VALUES(200,20,1,'other channel','text'); UPDATE messages SET is_deleted=1 WHERE id=50; UPDATE messages SET is_pinned=1,content_type='poll' WHERE id=105;
INSERT INTO message_reactions VALUES(105,1,'like'),(105,2,'like');
INSERT INTO message_attachments VALUES(105,'file.txt','uploads/test.txt',12,'text/plain');
INSERT INTO polls VALUES(1,105,'Question?',1); INSERT INTO poll_options VALUES(1,1,'Yes',1,0),(2,1,'No',0,1); INSERT INTO poll_votes VALUES(1,1,1)");
$s=new MessageService($db);
foreach(['getMessages','getPinnedMessages']as$method){try{$s->$method(10,1);throw new LogicException('Private data leaked');}catch(RuntimeException $e){check($e->getCode()===403,'Denied private service read');}}
$db->exec('INSERT INTO channel_members VALUES(10,1)');
foreach([-100,0,1,50,100,101,10000]as$limit){$page=$s->getMessages(10,1,null,$limit);check(count($page)===MessageService::normalizeLimit($limit),'Normalized page size');check(end($page)['id']===105,'Newest page boundary');}
$page=$s->getMessages(10,1,10,3);check(array_column($page,'id')===[7,8,9],'Before keyset');
$page=$s->getMessages(10,1,1,3,49);check(array_column($page,'id')===[51,52,53],'After takes precedence and skips deleted');
check($s->getMessages(10,1,null,100,105)===[],'End cursor');
$page=$s->getMessages(10,1,null,1);$message=$page[0];
check((int)$message['reactions'][0]['cnt']===2&&(int)$message['reactions'][0]['reacted_by_me']===1,'Reaction aggregation');
check($message['attachments'][0]['file_name']==='file.txt','Attachments');
check($message['poll']['my_vote']===1&&count($message['poll']['options'])===2,'Poll and vote batching');
check(array_column($s->getPinnedMessages(10,1),'id')===[105],'Pinned read');
$db->exec('DELETE FROM channel_members');
try{$s->getPinnedMessages(10,1);throw new LogicException('Revoked membership cached');}catch(RuntimeException $e){check($e->getCode()===403,'Recheck revoked access');}
echo "PASS message limits, before/after, private and revoked access, deleted/cross-channel exclusion, pinned, reactions, attachments, polls\n";
