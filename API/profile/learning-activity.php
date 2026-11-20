<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once ROOT_PATH.'/database/config/db.php';
require_once ROOT_PATH.'/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH.'/services/PeerBehaviorService.php';
header('Content-Type: application/json');header('Cache-Control: no-store');
AuthMiddleware::startSession();$user=AuthMiddleware::requireAuth(true);
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);exit;}
AuthMiddleware::verifyCsrf();session_write_close();
$raw=file_get_contents('php://input',false,null,0,513);
$body=strlen($raw)<=512?json_decode($raw,true):null;
if(!is_array($body)||!is_string($body['page']??null)){http_response_code(422);echo json_encode(['error'=>'Invalid activity']);exit;}
try{(new PeerBehaviorService(Database::getInstance()))->record((int)$user['id'],$body['page']);echo json_encode(['success'=>true]);}
catch(InvalidArgumentException $e){http_response_code(422);echo json_encode(['error'=>$e->getMessage()]);}
catch(Throwable $e){error_log('[peer-activity] '.$e->getMessage());http_response_code(500);echo json_encode(['error'=>'Activity unavailable']);}
