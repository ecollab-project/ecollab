<?php
declare(strict_types=1);
require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__.'/../services/ChannelAccessService.php';
require_once __DIR__.'/../services/RealtimeOutbox.php';
require_once __DIR__.'/../services/JarredTools.php';
require_once __DIR__.'/../services/JarredJobService.php';
require_once __DIR__.'/../services/RealtimeRevocationService.php';
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
function denied(callable $f):bool{try{$f();return false;}catch(RuntimeException $e){if(!in_array($e->getCode(),[403,404],true))throw $e;return true;}}
$dsn=getenv('R7_TEST_DSN')?:'sqlite::memory:';
if($dsn!=='sqlite::memory:'&&!preg_match('/^mysql:host=127[.]0[.]0[.]1;port=[0-9]+;dbname=ecollab_r7_test$/D',$dsn))throw new RuntimeException('Disposable local R7 test database required');
class R7PDO extends PDO {public int $prepared=0;public function prepare(string $query,array $options=[]):PDOStatement|false{$this->prepared++;return parent::prepare($query,$options);}}
$db=new R7PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
if($dsn==='sqlite::memory:')$db->sqliteCreateFunction('NOW',fn()=>gmdate('Y-m-d H:i:s'));
else check((int)$db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()===0,'R7 schema must be empty');
$schema="CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,status TEXT,deleted_at TEXT,is_system INTEGER,username TEXT,full_name TEXT,avatar_url TEXT,avatar_color_gradient TEXT);
INSERT INTO users VALUES(1,'student','active',NULL,0,'student','Student','',''),(2,'student','active',NULL,0,'other','Other','',''),(3,'moderator','active',NULL,0,'mod','Mod','',''),(4,'admin','active',NULL,0,'admin','Admin','',''),(5,'student','active',NULL,1,'ecollab_ai','Jarred','','');
CREATE TABLE servers(id INTEGER PRIMARY KEY,status TEXT,name TEXT);INSERT INTO servers VALUES(1,'active','Server');
CREATE TABLE server_members(server_id INTEGER,user_id INTEGER,server_role TEXT);INSERT INTO server_members VALUES(1,1,'member'),(1,2,'owner'),(1,3,'moderator');
CREATE TABLE channels(id INTEGER PRIMARY KEY,server_id INTEGER,name TEXT,slug TEXT,type TEXT,is_private INTEGER,is_locked INTEGER,created_by INTEGER);INSERT INTO channels VALUES(10,1,'private','private','voice',1,0,2);
CREATE TABLE channel_members(channel_id INTEGER,user_id INTEGER);INSERT INTO channel_members VALUES(10,1);
CREATE TABLE moderation_actions(target_user_id INTEGER,server_id INTEGER,action_type TEXT,is_active INTEGER,expires_at TEXT);
CREATE TABLE messages(id INTEGER PRIMARY KEY,channel_id INTEGER,sender_id INTEGER,content TEXT,created_at TEXT,is_deleted INTEGER,parent_id INTEGER);INSERT INTO messages VALUES(1,10,2,'private needle',CURRENT_TIMESTAMP,0,NULL);
CREATE TABLE dm_conversations(id INTEGER PRIMARY KEY,user_a INTEGER,user_b INTEGER,last_message TEXT,last_msg_at TEXT);INSERT INTO dm_conversations VALUES(1,1,5,NULL,NULL),(2,1,2,NULL,NULL);
CREATE TABLE dm_messages(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,group_id INTEGER,sender_id INTEGER,body TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,is_deleted INTEGER DEFAULT 0);INSERT INTO dm_messages(id,conversation_id,sender_id,body) VALUES(1,1,1,'hello'),(2,2,2,'human');
CREATE TABLE dm_groups(id INTEGER PRIMARY KEY);INSERT INTO dm_groups VALUES(1);
CREATE TABLE dm_group_members(group_id INTEGER,user_id INTEGER);INSERT INTO dm_group_members VALUES(1,1),(1,2);
INSERT INTO dm_messages(id,group_id,sender_id,body) VALUES(3,1,2,'group');
CREATE TABLE dm_call_history(id INTEGER PRIMARY KEY,group_id INTEGER,caller_id INTEGER,callee_id INTEGER,status TEXT,started_at TEXT);INSERT INTO dm_call_history VALUES(1,NULL,1,2,'answered',CURRENT_TIMESTAMP);
CREATE TABLE code_lab_jobs(id INTEGER PRIMARY KEY,server_id INTEGER,user_id INTEGER,state TEXT);INSERT INTO code_lab_jobs VALUES(1,1,1,'done');
CREATE TABLE collab_workspaces(id INTEGER PRIMARY KEY,server_id INTEGER,channel_id INTEGER,host_id INTEGER,visibility TEXT,archived INTEGER);INSERT INTO collab_workspaces VALUES(1,1,10,2,'public',0);
CREATE TABLE collab_workspace_members(workspace_id INTEGER,user_id INTEGER,role TEXT);INSERT INTO collab_workspace_members VALUES(1,1,'member');
CREATE TABLE collab_resource_permissions(workspace_id INTEGER,resource_type TEXT,resource_id INTEGER,user_id INTEGER,permission TEXT);INSERT INTO collab_resource_permissions VALUES(1,'document',1,1,'edit'),(1,'whiteboard',1,1,'edit');
CREATE TABLE collab_documents(id INTEGER PRIMARY KEY,workspace_id INTEGER,created_by INTEGER,visibility TEXT,public_permission TEXT);INSERT INTO collab_documents VALUES(1,1,2,'private','view');
CREATE TABLE collab_whiteboards(id INTEGER PRIMARY KEY,workspace_id INTEGER,created_by INTEGER,visibility TEXT,public_permission TEXT);INSERT INTO collab_whiteboards VALUES(1,1,2,'private','view');
CREATE TABLE user_settings(user_id INTEGER PRIMARY KEY,jarred_enabled INTEGER);INSERT INTO user_settings VALUES(1,1);
CREATE TABLE jarred_queue_gate(id INTEGER PRIMARY KEY);INSERT INTO jarred_queue_gate VALUES(1);
CREATE TABLE jarred_jobs(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,recipient_id INTEGER,conversation_id INTEGER,source_message_id INTEGER,active_server_id INTEGER,surface_json TEXT,state TEXT DEFAULT 'queued',attempts INTEGER DEFAULT 0,lease_token TEXT,action_checked INTEGER DEFAULT 0,response_message_id INTEGER,action_json TEXT,usage_json TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,finished_at TEXT);";
if($dsn!=='sqlite::memory:')$schema=str_replace('INTEGER PRIMARY KEY AUTOINCREMENT','BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',$schema);
$db->exec($schema);
(new ReflectionProperty(Database::class,'instance'))->setValue(null,$db);
$policy=new AccessPolicy($db);$channel=new ChannelAccessService($db);$tools=new JarredTools();
function audience(string $kind,int $target,int $id):array{global $db;$out=RealtimeOutbox::recipients($db,['kind'=>$kind,'target_id'=>$target,'message_id'=>$id]);sort($out);return $out;}
$db->prepared=0;$channel->readers(10,range(1,450));check($db->prepared===5,'450-recipient fanout uses five bounded policy queries');
check(audience('channel',10,1)===[1,2,4],'Member/owner/platform admin, never moderator bypass');
check(count($tools->searchMessages(1,'needle',12,1))===1,'Authorized AI search');
check($tools->searchMessages(3,'needle',12,1)===[],'Moderator cannot search private channel');
$db->exec('DELETE FROM channel_members WHERE user_id=1');
check(denied(fn()=>$channel->read(10,1)),'Revoked channel member read');
check(!isset($channel->readers(10,[1,2,3,4])[1])&&audience('channel',10,1)===[2,4],'Revoked fanout and outbox recipient');
check($tools->searchMessages(1,'needle',12,1)===[],'Revoked AI private search');
$db->exec('INSERT INTO channel_members VALUES(10,1)');
foreach(['suspend','mute','ban'] as $action){
 $db->exec('DELETE FROM moderation_actions');$db->prepare('INSERT INTO moderation_actions VALUES(1,1,?,1,NULL)')->execute([$action]);
 check($policy->server(1,1)===($action!=='ban'),'Server read distinction '.$action);
 check($policy->server(1,1,'voice')===($action==='mute'),'Voice distinction '.$action);
 check(denied(fn()=>$channel->write(10,1)),'Chat write blocked '.$action);
 if($action!=='ban'){
  check(DocumentAccessService::get($db,1,1)['permission']===($action==='mute'?'edit':'view'),'Document mute/suspend distinction');
  check(CoworkspaceService::resolveWhiteboardAccess($db,1,10,1)['permission']===($action==='mute'?'edit':'view'),'Board mute/suspend distinction');
 } else {
  check(denied(fn()=>DocumentAccessService::get($db,1,1)),'Ban document boundary');
  check(!in_array(1,audience('code_lab',1,1),true),'Ban removes completion metadata');
 }
}
$db->exec("UPDATE moderation_actions SET expires_at='2000-01-01'");check($policy->server(1,1,'voice'),'Expired moderation ignored');
$db->exec('DELETE FROM moderation_actions;DELETE FROM server_members WHERE user_id=1');
check(!$policy->server(1,1)&&$tools->searchMessages(1,'needle',12,1)===[],'Removed membership cannot broaden scoped search');
check(denied(fn()=>DocumentAccessService::get($db,1,1)),'Removed server member resource');
$db->exec("INSERT INTO server_members VALUES(1,1,'member')");
foreach(['suspended','banned','deactivated'] as $status){
 $db->prepare('UPDATE users SET status=? WHERE id=1')->execute([$status]);
 check(!$policy->account(1)&&!in_array(1,audience('dm',2,2),true)&&!in_array(1,audience('group',1,3),true),'Inactive account excluded from DM/group '.$status);
}
$db->exec("UPDATE users SET status='offline' WHERE id=1");check($policy->account(1),'Offline remains valid');
$db->exec("UPDATE users SET deleted_at='2026-01-01' WHERE id=1");check(!$policy->account(1),'Deletion revokes');$db->exec('UPDATE users SET deleted_at=NULL WHERE id=1');
$db->exec('DELETE FROM dm_group_members WHERE user_id=1');check(audience('group',1,3)===[2],'Removed group member');$db->exec('INSERT INTO dm_group_members VALUES(1,1)');
// Every inference result is reauthorized before persistence, including resource permission revocation.
$jobs=new JarredJobService($db);
function runRevoked(array $surface,callable $revoke):void{global $db,$jobs;
 $db->prepare("INSERT INTO jarred_jobs(user_id,recipient_id,conversation_id,source_message_id,active_server_id,surface_json) VALUES(1,5,1,1,1,?)")->execute([json_encode($surface)]);
 $id=(int)$db->lastInsertId();$stamp=(new JarredTools())->authorizationStamp(1);$jobs->workOne(fn()=>null,function()use($revoke,$stamp){$revoke();return ['text'=>'must not persist','authorization_stamp'=>$stamp];});
 check($db->query("SELECT state FROM jarred_jobs WHERE id=$id")->fetchColumn()==='failed','Revoked inference fenced');
 check((int)$db->query("SELECT COUNT(*) FROM dm_messages WHERE body='must not persist'")->fetchColumn()===0,'No revoked assistant response');
}
runRevoked(['channel_id'=>10],fn()=>$db->exec('DELETE FROM channel_members WHERE user_id=1'));$db->exec('INSERT INTO channel_members VALUES(10,1)');
runRevoked([],fn()=>$db->exec('DELETE FROM channel_members WHERE user_id=1'));$db->exec('INSERT INTO channel_members VALUES(10,1)');
runRevoked(['document_id'=>1],fn()=>$db->exec("DELETE FROM collab_resource_permissions WHERE resource_type='document' AND user_id=1"));
$db->exec("INSERT INTO collab_resource_permissions VALUES(1,'document',1,1,'edit')");
runRevoked(['whiteboard_id'=>1],fn()=>$db->exec("DELETE FROM collab_resource_permissions WHERE resource_type='whiteboard' AND user_id=1"));
$db->exec("INSERT INTO collab_resource_permissions VALUES(1,'whiteboard',1,1,'edit')");
runRevoked([],fn()=>$db->exec('DELETE FROM server_members WHERE user_id=1'));$db->exec("INSERT INTO server_members VALUES(1,1,'member')");
runRevoked([],fn()=>$db->exec("UPDATE users SET status='suspended' WHERE id=1"));$db->exec("UPDATE users SET status='active' WHERE id=1");
$before=$tools->authorizationStamp(1);$db->exec("UPDATE collab_documents SET visibility='public' WHERE id=1");check($before!==$tools->authorizationStamp(1),'Permission stamp changes, content excluded');
// Test active media removal and repeated stale-token rejoin without treating removal as token revocation.
$removed=[];$disconnected=[];
$api=function(string $method,array $body,array $grant)use(&$removed):array{
 if($method==='ListRooms')return ['rooms'=>[['name'=>'ecollab-channel-10'],['name'=>'ecollab-dm-group-v2-1'],['name'=>'other-application']]];
 if($method==='ListParticipants')return ['participants'=>[['identity'=>'user-1'],['identity'=>'user-2']]];
 if($method==='RemoveParticipant'){$removed[]=$body;return [];}
 throw new LogicException('Unexpected method');
};
$revoke=new RealtimeRevocationService($db,$api,function(int $uid)use(&$disconnected){$disconnected[]=$uid;});
check($revoke->roomAllowed('ecollab-channel-10',1),'Existing voice allowed');
$db->exec('DELETE FROM channel_members WHERE user_id=1;DELETE FROM dm_group_members WHERE user_id=1');
$counts=$revoke->reconcile();check($counts['media_removals']===2,'Removed private-channel/group participants');
$revoke->reconcile();check(count($removed)===4,'Replay/rejoin evicted again, not assumed revoked');
$db->exec("UPDATE users SET status='deactivated' WHERE id=1");$revoke->reconcile();check(in_array(1,$disconnected,true),'Broker active account disconnect requested');
$db->exec("CREATE TABLE ws_tokens(user_id INTEGER,token_hash TEXT,expires_at TEXT)");
$oldToken='fixture-old-token';$db->prepare('INSERT INTO ws_tokens VALUES(?,?,?)')->execute([1,hash('sha256',$oldToken),'2099-01-01']);
$independent=new RealtimeRevocationService($db,$api,fn()=>throw new RuntimeException('Broker offline'));
$failed=$independent->reconcile();check($failed['failures']>0&&$failed['media_removals']>0,'Broker outage does not suppress media eviction');
$db->exec('ALTER TABLE users ADD COLUMN voice_channel_id INTEGER;UPDATE users SET voice_channel_id=10 WHERE id=1;CREATE TABLE temporary_voice_rooms(channel_id INTEGER)');
// Real ChatServer memory state: no constructor DDL, fake socket only, same authorization methods.
final class RevocationSocket implements Ratchet\ConnectionInterface{
 public array $sent=[];public bool $closed=false;public function __construct(public int $resourceId){}
 public function send($v){$this->sent[]=$v;return $this;}public function close(){$this->closed=true;}
}
$server=(new ReflectionClass(ChatServer::class))->newInstanceWithoutConstructor();
$socket=new RevocationSocket(1);$storage=new SplObjectStorage();$storage->attach($socket);
foreach(['db'=>$db,'wbPresence'=>new WhiteboardPresenceService(),'clients'=>$storage,'userConns'=>[1=>[$socket]],'connMeta'=>[1=>['authed'=>true,'user_id'=>1,'channel_id'=>10]],'channelSubs'=>[10=>[$socket]]] as $field=>$value)(new ReflectionProperty(ChatServer::class,$field))->setValue($server,$value);
$handleAuth=new ReflectionMethod(ChatServer::class,'handleAuth');$meta=['authed'=>false];
$handleAuth->invokeArgs($server,[$socket,['ws_token'=>$oldToken],&$meta]);check(!$meta['authed'],'Previously issued WS token cannot authenticate blocked account');
$server->reconcileAccess(100);check($socket->closed,'Active blocked WebSocket closed by timer');
$db->exec("UPDATE users SET status='active' WHERE id=1");$socket->closed=false;
(new ReflectionProperty(ChatServer::class,'connMeta'))->setValue($server,[1=>['authed'=>true,'user_id'=>1,'username'=>'student','channel_id'=>10,'voice_channel_id'=>10]]);
(new ReflectionProperty(ChatServer::class,'voiceRooms'))->setValue($server,[10=>[['user_id'=>1,'username'=>'student','resourceId'=>1]]]);
$broadcast=new ReflectionMethod(ChatServer::class,'broadcastToChannel');$count=count($socket->sent);$broadcast->invoke($server,10,'private payload');check(count($socket->sent)===$count,'Revoked active subscription receives no new payload');
$server->reconcileAccess(106);check((new ReflectionProperty(ChatServer::class,'channelSubs'))->getValue($server)===[],'Timer removes revoked subscription');
check($db->query('SELECT voice_channel_id FROM users WHERE id=1')->fetchColumn()===null,'Revoked voice presence cleared by timer');
echo "PASS R7 account/status/moderation matrix, no moderator bypass, channel/DM/group/outbox/resource revocation, inference fences, active transport eviction and stale-media-token rejoin simulation\n";
