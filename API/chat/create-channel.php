<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once dirname(__DIR__,2).'/database/config/db.php';
require_once dirname(__DIR__,2).'/security/ApiErrorResponder.php';
require_once dirname(__DIR__,2).'/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__,2).'/services/ChannelService.php';
header('Content-Type: application/json'); AuthMiddleware::startSession(); $user=AuthMiddleware::requireAuth(true);
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['error'=>'Method not allowed']);exit;} AuthMiddleware::verifyCsrf();
try{
 $body=json_decode(file_get_contents('php://input'),true)??$_POST; $serverId=filter_var($body['server_id']??0,FILTER_VALIDATE_INT);
 if(!$serverId){http_response_code(400);echo json_encode(['error'=>'server_id is required']);exit;}
 $channel=(new ChannelService())->createChannel((int)$serverId,$user['id'],$body); http_response_code(201); echo json_encode(['success'=>true,'channel'=>$channel]);
}catch(InvalidArgumentException $e){ApiErrorResponder::throwable('chat/create-channel validation',$e,400,'Invalid request.');
}catch(PDOException $e){ApiErrorResponder::throwable('chat/create-channel database',$e,500,'Unable to create channel.');
}catch(RuntimeException $e){$code=($e->getCode()>=400&&$e->getCode()<600)?$e->getCode():500; ApiErrorResponder::throwable('chat/create-channel runtime',$e,$code,$e->getMessage());
}catch(Throwable $e){ApiErrorResponder::throwable('chat/create-channel throwable',$e,500,'Server error.');}
