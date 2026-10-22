<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/ChatUploadAccess.php';
function denied(callable $fn,int $code):void{try{$fn();}catch(RuntimeException $e){if($e->getCode()===$code)return;throw $e;}throw new RuntimeException('Expected access denial');}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,status TEXT,deleted_at TEXT);INSERT INTO users VALUES(18,'student','active',NULL);
CREATE TABLE servers(id INTEGER PRIMARY KEY,status TEXT);INSERT INTO servers VALUES(5,'active');
CREATE TABLE server_members(server_id INTEGER,user_id INTEGER,server_role TEXT);INSERT INTO server_members VALUES(5,18,'member');
CREATE TABLE channels(id INTEGER PRIMARY KEY,server_id INTEGER,type TEXT,is_private INTEGER,is_locked INTEGER,created_by INTEGER);INSERT INTO channels VALUES(20,5,'text',0,0,5);
CREATE TABLE channel_members(channel_id INTEGER,user_id INTEGER);
CREATE TABLE moderation_actions(target_user_id INTEGER,server_id INTEGER,action_type TEXT,is_active INTEGER,expires_at TEXT);
CREATE TABLE dm_conversations(id INTEGER PRIMARY KEY,user_a INTEGER,user_b INTEGER);INSERT INTO dm_conversations VALUES(1,18,19);
CREATE TABLE dm_group_members(group_id INTEGER,user_id INTEGER);INSERT INTO dm_group_members VALUES(2,18)");
ChatUploadAccess::check($db,18,'channel',20);
$db->exec('UPDATE channels SET is_private=1');denied(fn()=>ChatUploadAccess::check($db,18,'channel',20),403);
$db->exec('INSERT INTO channel_members VALUES(20,18)');ChatUploadAccess::check($db,18,'channel',20);
$db->exec("UPDATE channels SET type='announcement'");denied(fn()=>ChatUploadAccess::check($db,18,'channel',20),403);
$db->exec("UPDATE server_members SET server_role='moderator'");ChatUploadAccess::check($db,18,'channel',20);
$db->exec("DELETE FROM channel_members;UPDATE users SET role='moderator'");denied(fn()=>ChatUploadAccess::check($db,18,'channel',20),403);
$db->exec("UPDATE server_members SET server_role='owner';UPDATE users SET role='student'");ChatUploadAccess::check($db,18,'channel',20);
foreach(['mute','suspend','ban'] as $action){$db->prepare('INSERT INTO moderation_actions VALUES(18,5,?,1,NULL)')->execute([$action]);denied(fn()=>ChatUploadAccess::check($db,18,'channel',20),403);$db->exec('DELETE FROM moderation_actions');}
$db->exec('DELETE FROM server_members');denied(fn()=>ChatUploadAccess::check($db,18,'channel',20),403);
$db->exec("UPDATE users SET role='admin'");ChatUploadAccess::check($db,18,'channel',20);
ChatUploadAccess::check($db,18,'dm',1);ChatUploadAccess::check($db,18,'group',2);
$db->exec("UPDATE users SET status='suspended'");denied(fn()=>ChatUploadAccess::check($db,18,'dm',1),403);denied(fn()=>ChatUploadAccess::check($db,18,'group',2),403);
$db->exec("UPDATE users SET status='active';DELETE FROM dm_group_members;DELETE FROM dm_conversations");denied(fn()=>ChatUploadAccess::check($db,18,'dm',1),403);denied(fn()=>ChatUploadAccess::check($db,18,'group',2),403);
denied(fn()=>ChatUploadAccess::check($db,18,'unknown',2),400);
echo "PASS real upload policy: private grants, moderator denial, legitimate owner/admin, announcement roles, bans/mutes/suspensions, DM/group and account revocation\n";
