<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php'; require_once dirname(__DIR__,2).'/database/config/db.php'; require_once dirname(__DIR__,2).'/security/ApiErrorResponder.php'; require_once dirname(__DIR__,2).'/security/middleware/AuthMiddleware.php'; require_once dirname(__DIR__,2).'/services/WhiteboardService.php';
header('Content-Type: application/json'); AuthMiddleware::startSession(); $user=AuthMiddleware::requireAuth(true); AuthMiddleware::verifyCsrf();
try{
 $method=$_SERVER['REQUEST_METHOD']; $input=json_decode(file_get_contents('php://input'),true)??[]; $channelId=filter_input(INPUT_GET,'channel_id',FILTER_VALIDATE_INT)?:filter_var($input['channel_id']??0,FILTER_VALIDATE_INT);
 if(!$channelId){http_response_code(400);echo json_encode(['error'=>'channel_id is required']);exit;}
 $service=new WhiteboardService();
 if($method==='GET'){echo json_encode(['success'=>true,'whiteboard'=>$service->getState((int)$channelId,$user['id'])]);}
 elseif($method==='POST'){if(($input['state_json']??'')===''){http_response_code(400);echo json_encode(['error'=>'state_json is required']);exit;}echo json_encode(['success'=>true,'whiteboard'=>$service->syncState((int)$channelId,$user['id'],$input['state_json'])]);}
 else{http_response_code(405);echo json_encode(['error'=>'Method not allowed']);}
}catch(Throwable $e){$code=($e->getCode()>=400&&$e->getCode()<600)?$e->getCode():500;ApiErrorResponder::throwable('chat/whiteboard-sync',$e,$code,'Unable to synchronize whiteboard.');}
