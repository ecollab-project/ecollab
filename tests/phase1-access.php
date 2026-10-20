<?php
declare(strict_types=1);
require_once __DIR__.'/../services/ChannelAccessService.php';
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,status TEXT,deleted_at TEXT); CREATE TABLE servers(id INTEGER PRIMARY KEY,status TEXT); CREATE TABLE channels(id INTEGER PRIMARY KEY,server_id INTEGER,name TEXT,type TEXT,is_private INTEGER,is_locked INTEGER,created_by INTEGER); CREATE TABLE server_members(server_id INTEGER,user_id INTEGER,server_role TEXT); CREATE TABLE channel_members(channel_id INTEGER,user_id INTEGER); CREATE TABLE moderation_actions(target_user_id INTEGER,server_id INTEGER,action_type TEXT,is_active INTEGER,expires_at TEXT)');
$db->exec("INSERT INTO servers VALUES(1,'active'),(2,'active'); INSERT INTO channels VALUES(10,1,'Voice','voice',1,0,99),(20,2,'Other','voice',0,0,99); INSERT INTO users VALUES(1,'student','active',NULL)");
$policy=new ChannelAccessService($db);$cases=0;
function permits(ChannelAccessService $policy,bool $voice):bool{try{$voice?$policy->voice(10,1):$policy->read(10,1);return true;}catch(RuntimeException $e){check($e->getCode()===403,'Expected access denial');return false;}}
foreach(['student','facilitator','moderator','admin','super_admin']as$role){
 foreach([null,'member','moderator','owner','admin']as$serverRole){
  foreach([0,1]as$private)foreach([0,1]as$explicit)foreach([0,1]as$creator){
   $db->exec('DELETE FROM server_members; DELETE FROM channel_members');
   $db->prepare('UPDATE users SET role=? WHERE id=1')->execute([$role]);
   $db->prepare('UPDATE channels SET is_private=?,created_by=? WHERE id=10')->execute([$private,$creator?1:99]);
   if($serverRole)$db->prepare('INSERT INTO server_members VALUES(1,1,?)')->execute([$serverRole]);
   if($explicit)$db->exec('INSERT INTO channel_members VALUES(10,1)');
   $expected=in_array($role,['admin','super_admin'],true)||($serverRole!==null&&(!$private||$explicit||$creator||in_array($serverRole,['owner','admin'],true)));
   check(permits($policy,true)===$expected,"Voice matrix $role/$serverRole/$private/$explicit/$creator");
   check(permits($policy,false)===$expected,'Read matrix');
   check(isset($policy->voiceChannels([10],1)[10])===$expected,'Counts matches token/join policy');$cases++;
  }
 }
}
$db->exec("UPDATE users SET role='student'; DELETE FROM server_members; INSERT INTO server_members VALUES(1,1,'member'); UPDATE channels SET is_private=0,is_locked=0,created_by=99 WHERE id=10");
check(!isset($policy->voiceChannels([20],1)[20]),'Cross-server denied');
foreach(['offline','idle','active']as$status){$db->prepare('UPDATE users SET status=?')->execute([$status]);check(permits($policy,true)&&permits($policy,false),'Presence status must not revoke account access');}
foreach(['banned','suspended','deactivated']as$status){$db->prepare('UPDATE users SET status=?')->execute([$status]);check(!permits($policy,true)&&!permits($policy,false),'Global inactive denied');}
$db->exec("UPDATE users SET status='active',deleted_at='2026-01-01'");check(!permits($policy,true),'Deleted user denied');$db->exec('UPDATE users SET deleted_at=NULL');
$db->exec("INSERT INTO moderation_actions VALUES(1,1,'suspend',1,NULL)");check(!permits($policy,true)&&permits($policy,false),'Suspend is view-only');
$db->exec("UPDATE moderation_actions SET action_type='mute'");check(permits($policy,true),'Chat-only mute preserves voice');
$db->exec("UPDATE moderation_actions SET action_type='ban'");check(!permits($policy,true)&&!permits($policy,false),'Ban even with stale membership');
$db->exec("UPDATE moderation_actions SET expires_at='2000-01-01'");check(permits($policy,true),'Expired action ignored');
$db->exec("UPDATE moderation_actions SET expires_at=NULL,is_active=0");check(permits($policy,true),'Revoked action ignored');
$db->exec("UPDATE servers SET status='archived' WHERE id=1");check(!permits($policy,true),'Inactive server denied');$db->exec("UPDATE servers SET status='active'");
$db->exec('UPDATE channels SET is_locked=1 WHERE id=10');check(!permits($policy,true)&&permits($policy,false),'Locked voice denies join, keeps reads');
$db->exec("UPDATE users SET role='admin'");check(permits($policy,true),'Existing platform admin lock bypass');
$db->exec("UPDATE channels SET type='text' WHERE id=10");check(!permits($policy,true),'Non-voice denied');
$db->exec("UPDATE users SET role='student'; DELETE FROM server_members");check(!permits($policy,false),'Revoked membership observed on reused policy instance');
echo "PASS $cases role/membership/privacy/member/creator combinations, counts parity, ban/suspend/mute, revocation and server/channel state\n";
