<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once ROOT_PATH.'/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH.'/services/LearningWorkspaceService.php';
header('Content-Type: application/json');header('Cache-Control: no-store');
$user=AuthMiddleware::requireAuth(true);$fac=($_GET['mode']??'student')==='facilitator';
if(!in_array($user['role']??'', $fac?['facilitator','admin','super_admin','moderator']:['student','facilitator','admin','super_admin','moderator'],true)){http_response_code(403);echo json_encode(['error'=>'Dashboard access denied']);exit;}
$method=$_SERVER['REQUEST_METHOD'];if(!in_array($method,['GET','POST'],true)){http_response_code(405);exit;}
if($method==='POST')AuthMiddleware::verifyCsrf();session_write_close();
try{$body=[];if($method==='POST'){$body=json_decode(file_get_contents('php://input'),true);if(!is_array($body))throw new InvalidArgumentException('Send a valid JSON request.');}$service=new LearningWorkspaceService();$result=$method==='GET'?$service->data((int)$user['id'],$fac,(int)($_GET['server_id']??0)):$service->mutate((int)$user['id'],$fac,(string)($_GET['action']??''),$body);echo json_encode(['success'=>true,'data'=>$result],JSON_THROW_ON_ERROR);}catch(Throwable $e){$code=$e instanceof InvalidArgumentException?422:($e instanceof RuntimeException&&in_array($e->getCode(),[403,404,409],true)?$e->getCode():503);http_response_code($code);if($code===503)error_log('[LearningWorkspace API] '.$e->getMessage());echo json_encode(['error'=>$code===503?'Learning data is temporarily unavailable.':$e->getMessage()]);}
