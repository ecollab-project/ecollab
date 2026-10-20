<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once ROOT_PATH.'/database/config/db.php';
require_once ROOT_PATH.'/security/middleware/AuthMiddleware.php';
header('Content-Type: application/json');
AuthMiddleware::startSession();$user=AuthMiddleware::requireAuth(true);
if(!in_array($_SERVER['REQUEST_METHOD'],['PATCH','POST'],true)){http_response_code(405);echo json_encode(['error'=>'Method not allowed']);exit;}
AuthMiddleware::verifyCsrf();$b=json_decode(file_get_contents('php://input'),true)??$_POST;$id=(int)($b['channel_id']??$b['id']??0);if(!$id){http_response_code(400);echo json_encode(['error'=>'channel_id is required']);exit;}
$db=Database::getInstance();$q=$db->prepare("SELECT c.*,s.owner_id AS server_owner_id FROM channels c JOIN servers s ON s.id=c.server_id WHERE c.id=:id");$q->execute([':id'=>$id]);$ch=$q->fetch(PDO::FETCH_ASSOC);if(!$ch){http_response_code(404);echo json_encode(['error'=>'Channel not found']);exit;}
$role=(string)($user['role']??'student');$uid=(int)$user['id'];$allowed=$role==='super_admin'||$role==='admin'||$uid===(int)$ch['owner_id']||$uid===(int)$ch['server_owner_id'];if(!$allowed){http_response_code(403);echo json_encode(['error'=>'Only the channel owner, server owner, or SysAdmin can edit this channel']);exit;}
$sets=[];$p=[':id'=>$id];if(array_key_exists('name',$b)){if(trim((string)$b['name'])===''){http_response_code(422);echo json_encode(['error'=>'Channel name cannot be empty']);exit;}$sets[]='name=:name';$p[':name']=trim((string)$b['name']);}
if(array_key_exists('description',$b)){$sets[]='description=:description';$p[':description']=trim((string)$b['description']);}
if(array_key_exists('visibility',$b)){$v=strtolower((string)$b['visibility']);if(!in_array($v,['public','private','inherit'],true)){http_response_code(422);echo json_encode(['error'=>'visibility must be public, private, or inherit']);exit;}$sets[]='visibility=:visibility';$sets[]='is_private=:private';$p[':visibility']=$v;$p[':private']=$v==='private'?1:0;}
if(!$sets){echo json_encode(['success'=>true,'channel'=>$ch]);exit;}$st=$db->prepare('UPDATE channels SET '.implode(',',$sets).' WHERE id=:id');$st->execute($p);$q->execute([':id'=>$id]);echo json_encode(['success'=>true,'channel'=>$q->fetch(PDO::FETCH_ASSOC)]);
