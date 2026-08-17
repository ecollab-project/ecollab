<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
header('Content-Type: application/json; charset=utf-8');
AuthMiddleware::startSession(); $me=AuthMiddleware::requireAuth(true); $db=Database::getInstance();
function cmJson(array $d,int $s=200):never{http_response_code($s);echo json_encode(['success'=>$s<400,...$d],JSON_UNESCAPED_UNICODE);exit;}
function cmChannel(PDO $db,int $id):?array{$s=$db->prepare('SELECT id,server_id,name,is_private,created_by FROM channels WHERE id=? LIMIT 1');$s->execute([$id]);return $s->fetch(PDO::FETCH_ASSOC)?:null;}
function cmRole(PDO $db,int $sid,int $uid):?string{$s=$db->prepare('SELECT server_role FROM server_members WHERE server_id=? AND user_id=? LIMIT 1');$s->execute([$sid,$uid]);return $s->fetchColumn()?:null;}
function cmCanManage(PDO $db,array $ch,int $uid):bool{return in_array(cmRole($db,(int)$ch['server_id'],$uid),['owner','admin','moderator'],true)||(int)$ch['created_by']===$uid;}
try{
 $method=strtoupper($_SERVER['REQUEST_METHOD']??'GET');
 if($method==='GET'){
  $cid=(int)($_GET['channel_id']??0);if(!$cid)cmJson(['error'=>'channel_id required'],400);$ch=cmChannel($db,$cid);if(!$ch)cmJson(['error'=>'Channel not found'],404);
  // Server members may view access state so the channel-invite dialog works for normal members.
  if(!cmRole($db,(int)$ch['server_id'],(int)$me['id']))cmJson(['error'=>'Server membership required'],403);
  $s=$db->prepare("SELECT u.id,u.username,u.full_name,u.role,u.avatar_color_gradient AS grad,u.is_online,sm.server_role,sm.nickname,CASE WHEN cm.user_id IS NOT NULL THEN 1 ELSE 0 END AS has_access FROM users u JOIN server_members sm ON sm.user_id=u.id AND sm.server_id=? LEFT JOIN channel_members cm ON cm.channel_id=? AND cm.user_id=u.id WHERE u.id<>? AND u.deleted_at IS NULL ORDER BY has_access DESC,u.full_name,u.username");$s->execute([(int)$ch['server_id'],$cid,(int)$me['id']]);cmJson(['channel'=>$ch,'members'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
 }
 if($method!=='POST')cmJson(['error'=>'Method not allowed'],405);AuthMiddleware::verifyCsrf();$b=json_decode(file_get_contents('php://input'),true)?:$_POST;$action=(string)($b['action']??'');$cid=(int)($b['channel_id']??0);$tid=(int)($b['user_id']??0);if(!$cid||!$tid||!in_array($action,['add','remove'],true))cmJson(['error'=>'action, channel_id, and user_id required'],400);$ch=cmChannel($db,$cid);if(!$ch)cmJson(['error'=>'Channel not found'],404);if(!cmCanManage($db,$ch,(int)$me['id']))cmJson(['error'=>'Insufficient permissions'],403);
 $sm=$db->prepare('SELECT 1 FROM server_members WHERE server_id=? AND user_id=? LIMIT 1');$sm->execute([(int)$ch['server_id'],$tid]);if(!$sm->fetchColumn())cmJson(['error'=>'User must be a member of this server first'],409);
 if($action==='add'){$db->prepare('INSERT IGNORE INTO channel_members(channel_id,user_id) VALUES(?,?)')->execute([$cid,$tid]);cmJson(['message'=>'Channel access granted']);}$db->prepare('DELETE FROM channel_members WHERE channel_id=? AND user_id=?')->execute([$cid,$tid]);cmJson(['message'=>'Channel access revoked']);
}catch(Throwable $e){error_log('[chat/channel-members] '.$e->getMessage());cmJson(['error'=>defined('APP_DEBUG')&&APP_DEBUG?$e->getMessage():'Channel member service unavailable'],500);}
