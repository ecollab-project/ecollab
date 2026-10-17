<?php

declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once ROOT_PATH.'/database/config/db.php';
require_once ROOT_PATH.'/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH.'/services/CoworkspaceService.php';
AuthMiddleware::startSession();
$user=AuthMiddleware::requireAuth(true);
header('Content-Type: application/json; charset=utf-8');
$db=Database::getInstance(); $uid=(int)$user['id'];
function wbFail(string $m,int $s=400):never{http_response_code($s);echo json_encode(['success'=>false,'error'=>$m]);exit;}
try{
 $method=strtoupper($_SERVER['REQUEST_METHOD']??'GET');
 $body=json_decode(file_get_contents('php://input'),true)?:[];
 $wid=(int)($body['workspace_id']??$_GET['workspace_id']??0);
 if($wid<1)wbFail('Coworkspace is required.');
 $workspace=CoworkspaceService::get($db,$wid,$uid);
 if($method==='GET'){
  $s=$db->prepare('SELECT state_json,updated_by,updated_at FROM collab_whiteboards WHERE workspace_id=? LIMIT 1');
  $s->execute([$wid]);$row=$s->fetch(PDO::FETCH_ASSOC);
  echo json_encode(['success'=>true,'workspace_id'=>$wid,'state'=>$row?json_decode($row['state_json'],true):['strokes'=>[],'text'=>[]],'updated_by'=>$row?(int)$row['updated_by']:null,'updated_at'=>$row['updated_at']??null]);exit;
 }
 if($method!=='POST')wbFail('Method not allowed.',405);
 AuthMiddleware::verifyCsrf();
 $role=(string)($workspace['member_role']??'');
 if(!in_array($role,['host','editor','member'],true) || (int)($workspace['allow_edit_documents']??1)!==1)wbFail('You do not have permission to edit this Coworkspace.',403);
 $state=$body['state']??null;
 if(!is_array($state))wbFail('Invalid whiteboard state.');
 $json=json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
 $s=$db->prepare('INSERT INTO collab_whiteboards(workspace_id,state_json,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE state_json=VALUES(state_json),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP');
 $s->execute([$wid,$json,$uid]);
 echo json_encode(['success'=>true,'updated_at'=>date('Y-m-d H:i:s')]);
}catch(Throwable $e){error_log('[collaboration/whiteboard] '.$e->getMessage());wbFail('Unable to save whiteboard.',500);}
