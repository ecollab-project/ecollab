<?php
declare(strict_types=1);
require_once __DIR__.'/../services/TemporaryVoiceService.php';
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->sqliteCreateFunction('NOW',fn()=>gmdate('Y-m-d H:i:s'));
$db->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,deleted_at TEXT);INSERT INTO users VALUES(1,'active',NULL);
CREATE TABLE servers(id INTEGER PRIMARY KEY,status TEXT);INSERT INTO servers VALUES(1,'active');
CREATE TABLE moderation_actions(target_user_id INTEGER,server_id INTEGER,action_type TEXT,is_active INTEGER,expires_at TEXT);
CREATE TABLE server_members(server_id INTEGER,user_id INTEGER);INSERT INTO server_members VALUES(1,1);
CREATE TABLE channels(id INTEGER PRIMARY KEY,server_id INTEGER,name TEXT,slug TEXT,type TEXT,description TEXT,position INTEGER,is_private INTEGER,created_by INTEGER,owner_id INTEGER,visibility TEXT);
CREATE TABLE temporary_voice_rooms(channel_id INTEGER PRIMARY KEY,server_id INTEGER,owner_id INTEGER,privacy TEXT);
CREATE TABLE jarred_pending_actions(user_id INTEGER,conversation_id INTEGER,action_type TEXT,step TEXT,server_id INTEGER,payload_json TEXT,expires_at TEXT);
INSERT INTO jarred_pending_actions VALUES(1,1,'create_temp_voice','privacy',1,'{}','2099-01-01');");
$s=new TemporaryVoiceService($db);$db->beginTransaction();$r=$s->handleJarredMessage(1,1,'public',1);
if(!$db->inTransaction()||$r['action']['type']!=='open_temporary_voice')throw new RuntimeException('Action escaped worker transaction');
$db->rollBack();
if((int)$db->query('SELECT COUNT(*) FROM channels')->fetchColumn()!==0||(int)$db->query('SELECT COUNT(*) FROM jarred_pending_actions')->fetchColumn()!==1)throw new RuntimeException('Crash must rollback room and dialogue state');
$db->beginTransaction();$s->handleJarredMessage(1,1,'public',1);$db->commit();
if((int)$db->query('SELECT COUNT(*) FROM channels')->fetchColumn()!==1||(int)$db->query('SELECT COUNT(*) FROM jarred_pending_actions')->fetchColumn()!==0)throw new RuntimeException('Atomic tool action failed');
foreach(['suspend','ban'] as $action){
 $db->exec("INSERT INTO jarred_pending_actions VALUES(1,1,'create_temp_voice','privacy',1,'{}','2099-01-01')");
 $db->prepare('INSERT INTO moderation_actions VALUES(1,1,?,1,NULL)')->execute([$action]);
 try{$s->handleJarredMessage(1,1,'public',1);throw new LogicException('Revoked pending voice action succeeded');}
 catch(RuntimeException $e){if($e->getCode()!==403)throw $e;}
 if((int)$db->query('SELECT COUNT(*) FROM channels')->fetchColumn()!==1)throw new LogicException('Revoked action created room');
 $db->exec('DELETE FROM moderation_actions');
}
echo "PASS real temporary-room action joins outer transaction, rollback retains dialogue, retry creates one room\n";
