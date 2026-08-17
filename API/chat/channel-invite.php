<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once dirname(__DIR__,2).'/database/config/db.php';
require_once dirname(__DIR__,2).'/security/middleware/AuthMiddleware.php';
header('Content-Type: application/json; charset=utf-8');
AuthMiddleware::startSession(); $me=AuthMiddleware::requireAuth(true); $db=Database::getInstance();
function ciJson(array $d,int $s=200):never{http_response_code($s);echo json_encode(['success'=>$s<400,...$d],JSON_UNESCAPED_UNICODE);exit;}
function ciChannel(PDO $db,int $id):?array{$s=$db->prepare('SELECT id,server_id,name,slug,is_private,created_by FROM channels WHERE id=? LIMIT 1');$s->execute([$id]);return $s->fetch(PDO::FETCH_ASSOC)?:null;}
function ciRole(PDO $db,int $sid,int $uid):?string{$s=$db->prepare('SELECT server_role FROM server_members WHERE server_id=? AND user_id=? LIMIT 1');$s->execute([$sid,$uid]);return $s->fetchColumn()?:null;}
function ciManager(PDO $db,array $ch,int $uid):bool{return in_array(ciRole($db,(int)$ch['server_id'],$uid),['owner','admin','moderator'],true)||(int)$ch['created_by']===$uid;}
function ciCreateAllowed(PDO $db,array $ch,int $uid):bool{$r=ciRole($db,(int)$ch['server_id'],$uid);return $r!==null&&((int)$ch['is_private']===0||in_array($r,['owner','admin','moderator'],true)||(int)$ch['created_by']===$uid);}
function ciUrl(string $t):string{return rtrim((string)BASE_URL,'/').'/modules/chat/chat.php?channel_invite='.rawurlencode($t);}
try{
 $method=strtoupper($_SERVER['REQUEST_METHOD']??'GET');$action=(string)($_GET['action']??'');$body=$method==='POST'?(json_decode(file_get_contents('php://input'),true)?:$_POST):$_GET;if($method==='POST')AuthMiddleware::verifyCsrf();
 if($action==='create'){
  $cid=(int)($body['channel_id']??0);$ch=ciChannel($db,$cid);if(!$ch)ciJson(['error'=>'Channel not found'],404);if(!ciCreateAllowed($db,$ch,(int)$me['id']))ciJson(['error'=>'Channel invite permission denied'],403);
  $max=max(0,min(100000,(int)($body['max_uses']??0)));$hours=max(0,min(8760,(int)($body['expires_hours']??0)));$exp=$hours?date('Y-m-d H:i:s',time()+$hours*3600):null;$token=rtrim(strtr(base64_encode(random_bytes(24)),'+/','-_'),'=');$hash=hash('sha256',$token);
  $s=$db->prepare('INSERT INTO channel_invites(channel_id,created_by,token_hash,max_uses,expires_at) VALUES(?,?,?,?,?)');$s->execute([$cid,$me['id'],$hash,$max,$exp]);ciJson(['invite'=>['id'=>(int)$db->lastInsertId(),'channel_id'=>$cid,'max_uses'=>$max,'expires_at'=>$exp,'invite_url'=>ciUrl($token)]]);
 }
 if($action==='list'){
  $cid=(int)($_GET['channel_id']??0);$ch=ciChannel($db,$cid);if(!$ch||!ciManager($db,$ch,(int)$me['id']))ciJson(['error'=>'Insufficient permissions'],403);$s=$db->prepare('SELECT id,channel_id,max_uses,use_count,expires_at,revoked_at,created_at FROM channel_invites WHERE channel_id=? ORDER BY created_at DESC LIMIT 50');$s->execute([$cid]);ciJson(['invites'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
 }
 if($action==='revoke'){
  $iid=(int)($body['invite_id']??0);$s=$db->prepare('SELECT channel_id FROM channel_invites WHERE id=? LIMIT 1');$s->execute([$iid]);$cid=(int)($s->fetchColumn()?:0);$ch=ciChannel($db,$cid);if(!$ch||!ciManager($db,$ch,(int)$me['id']))ciJson(['error'=>'Insufficient permissions'],403);$db->prepare('UPDATE channel_invites SET revoked_at=NOW() WHERE id=?')->execute([$iid]);ciJson(['message'=>'Channel invite revoked']);
 }
 if($action==='join'){
  $raw=trim((string)($body['invite_code']??$body['token']??''));if($raw==='')ciJson(['error'=>'Invite code required'],400);if(filter_var($raw,FILTER_VALIDATE_URL)){$p=parse_url($raw);parse_str((string)($p['query']??''),$q);$raw=(string)($q['channel_invite']??'');}$hash=hash('sha256',$raw);$db->beginTransaction();
  try{
   $s=$db->prepare("SELECT ci.*,c.name channel_name,c.server_id,c.is_private,s.name server_name FROM channel_invites ci JOIN channels c ON c.id=ci.channel_id JOIN servers s ON s.id=c.server_id WHERE ci.token_hash=? AND ci.revoked_at IS NULL AND (ci.expires_at IS NULL OR ci.expires_at>NOW()) AND (ci.max_uses=0 OR ci.use_count<ci.max_uses) LIMIT 1 FOR UPDATE");$s->execute([$hash]);$i=$s->fetch(PDO::FETCH_ASSOC);if(!$i){$db->rollBack();ciJson(['error'=>'Invalid or expired channel invite'],404);}
   $m=$db->prepare('SELECT id FROM server_members WHERE server_id=? AND user_id=? LIMIT 1');$m->execute([(int)$i['server_id'],(int)$me['id']]);$alreadyServer=(bool)$m->fetchColumn();if(!$alreadyServer){$db->prepare("INSERT INTO server_members(server_id,user_id,server_role,joined_at) VALUES(?,?,'member',NOW())")->execute([(int)$i['server_id'],(int)$me['id']]);$db->prepare('UPDATE servers SET member_count=(SELECT COUNT(*) FROM server_members WHERE server_id=?) WHERE id=?')->execute([(int)$i['server_id'],(int)$i['server_id']]);}
   $cm=$db->prepare('SELECT 1 FROM channel_members WHERE channel_id=? AND user_id=? LIMIT 1');$cm->execute([(int)$i['channel_id'],(int)$me['id']]);$already=(bool)$cm->fetchColumn();if((int)$i['is_private']===1&&!$already)$db->prepare('INSERT IGNORE INTO channel_members(channel_id,user_id) VALUES(?,?)')->execute([(int)$i['channel_id'],(int)$me['id']]);$db->prepare('UPDATE channel_invites SET use_count=use_count+1 WHERE id=?')->execute([(int)$i['id']]);$db->commit();ciJson(['server_id'=>(int)$i['server_id'],'channel_id'=>(int)$i['channel_id'],'channel_name'=>$i['channel_name'],'server_name'=>$i['server_name'],'already_member'=>$already]);
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 }
 ciJson(['error'=>'Unknown action'],404);
}catch(Throwable $e){error_log('[chat/channel-invite] '.$e->getMessage());ciJson(['error'=>defined('APP_DEBUG')&&APP_DEBUG?$e->getMessage():'Channel invite service unavailable'],500);}
