<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php'; require_once dirname(__DIR__,2).'/database/config/db.php'; require_once dirname(__DIR__,2).'/security/ApiErrorResponder.php'; require_once dirname(__DIR__,2).'/security/middleware/AuthMiddleware.php'; require_once dirname(__DIR__,2).'/services/MessageService.php';
header('Content-Type: application/json'); AuthMiddleware::startSession(); $user=AuthMiddleware::requireAuth(true);
if($_SERVER['REQUEST_METHOD']!=='POST'&&$_SERVER['REQUEST_METHOD']!=='PATCH'){http_response_code(405);echo json_encode(['error'=>'Method not allowed']);exit;} AuthMiddleware::verifyCsrf();
try{$body=json_decode(file_get_contents('php://input'),true)??$_POST;$messageId=filter_var($body['message_id']??0,FILTER_VALIDATE_INT);$content=trim($body['content']??'');if(!$messageId||$content===''){http_response_code(400);echo json_encode(['error'=>'message_id and content are required']);exit;}$message=(new MessageService())->editMessage((int)$messageId,$user['id'],$content,$user['role']);echo json_encode(['success'=>true,'message'=>$message]);}
catch(Throwable $e){$code=($e->getCode()>=400&&$e->getCode()<600)?$e->getCode():500;ApiErrorResponder::throwable('chat/edit-message',$e,$code,'Unable to edit message.');}
