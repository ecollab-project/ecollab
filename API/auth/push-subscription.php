<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';require_once ROOT_PATH.'/database/config/db.php';require_once ROOT_PATH.'/security/middleware/AuthMiddleware.php';require_once ROOT_PATH.'/services/WebPushService.php';
header('Content-Type: application/json');header('Cache-Control: no-store');
$user=AuthMiddleware::requireAuth(true);$method=$_SERVER['REQUEST_METHOD'];
if($method==='GET'){echo json_encode(['configured'=>WebPushService::configured(),'public_key'=>WebPushService::configured()?(string)getenv('WEB_PUSH_PUBLIC_KEY'):null]);exit;}
if($method!=='POST'){http_response_code(405);exit;}AuthMiddleware::verifyCsrf();session_write_close();
try{$raw=file_get_contents('php://input',false,null,0,8193);if(strlen($raw)>8192)throw new RuntimeException('Request too large.',400);$body=json_decode($raw,true);if(!is_array($body))throw new RuntimeException('Invalid request.',400);$service=new WebPushService(Database::getInstance());
 if(($body['action']??'')==='unsubscribe'){$service->unsubscribe((int)$user['id'],(string)($body['endpoint']??''));}
 elseif(($body['action']??'')==='subscribe'){if(!WebPushService::configured())throw new RuntimeException('Push is not configured.',503);$service->subscribe((int)$user['id'],(array)($body['subscription']??[]));$endpoint=(string)($body['subscription']['endpoint']??'');setcookie('ecollab_push_device',hash('sha256',$endpoint),['expires'=>time()+31536000,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);}
 else throw new RuntimeException('Invalid action.',400);echo json_encode(['success'=>true]);
}catch(Throwable $e){$status=in_array($e->getCode(),[400,429,503],true)?$e->getCode():503;http_response_code($status);echo json_encode(['error'=>$status===503?'Browser push is unavailable.':$e->getMessage()]);}
