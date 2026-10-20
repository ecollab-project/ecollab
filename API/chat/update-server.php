<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';require_once ROOT_PATH.'/database/config/db.php';require_once ROOT_PATH.'/security/middleware/AuthMiddleware.php';
header('Content-Type: application/json');AuthMiddleware::startSession();$user=AuthMiddleware::requireAuth(true);
if(!in_array($_SERVER['REQUEST_METHOD'],['PATCH','POST'],true)){http_response_code(405);echo json_encode(['error'=>'Method not allowed']);exit;}AuthMiddleware::verifyCsrf();
$b=json_decode(file_get_contents('php://input'),true)??$_POST;$id=(int)($b['server_id']??$b['id']??0);if(!$id){http_response_code(400);echo json_encode(['error'=>'server_id is required']);exit;}
$db=Database::getInstance();$q=$db->prepare('SELECT * FROM servers WHERE id=:id');$q->execute([':id'=>$id]);$s=$q->fetch(PDO::FETCH_ASSOC);if(!$s){http_response_code(404);echo json_encode(['error'=>'Server not found']);exit;}
$uid=(int)$user['id'];$role=(string)($user['role']??'student');if(!in_array($role,['admin','super_admin'],true)&&$uid!==(int)$s['owner_id']){http_response_code(403);echo json_encode(['error'=>'Only the server owner or SysAdmin can edit this server']);exit;}
$sets=[];$p=[':id'=>$id];if(array_key_exists('name',$b)){if(trim((string)$b['name'])===''){http_response_code(422);echo json_encode(['error'=>'Server name cannot be empty']);exit;}$sets[]='name=:name';$p[':name']=trim((string)$b['name']);}
if(array_key_exists('visibility',$b)){$v=strtolower((string)$b['visibility']);if(!in_array($v,['public','private'],true)){http_response_code(422);echo json_encode(['error'=>'visibility must be public or private']);exit;}$sets[]='visibility=:visibility';$p[':visibility']=$v;}
if(!$sets){echo json_encode(['success'=>true,'server'=>$s]);exit;}$st=$db->prepare('UPDATE servers SET '.implode(',',$sets).' WHERE id=:id');$st->execute($p);$q->execute([':id'=>$id]);echo json_encode(['success'=>true,'server'=>$q->fetch(PDO::FETCH_ASSOC)]);
