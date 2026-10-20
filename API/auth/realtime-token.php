<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once ROOT_PATH.'/database/config/db.php';
require_once ROOT_PATH.'/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH.'/services/RealtimeOutbox.php';
require_once ROOT_PATH.'/services/CentrifugoService.php';
header('Content-Type: application/json');header('Cache-Control: no-store');
if(!RealtimeOutbox::enabled()){http_response_code(404);echo json_encode(['error'=>'Delivery transport disabled']);exit;}
$user=AuthMiddleware::requireAuth(true);
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
AuthMiddleware::verifyCsrf();session_write_close();
try {
    $db=Database::getInstance();$stmt=$db->prepare('SELECT id FROM users WHERE id=? AND deleted_at IS NULL');$stmt->execute([$user['id']]);
    if(!$stmt->fetchColumn())throw new RuntimeException('Unauthenticated',401);
    $body=json_decode(file_get_contents('php://input'),true)??[];
    $mode=$body['mode']??'connection';
    if(!in_array($mode,['connection','subscription'],true))throw new RuntimeException('Invalid token request',400);
    $broker=new CentrifugoService();$channel=$mode==='subscription'?(string)($body['channel']??''):null;
    echo json_encode(['token'=>$broker->token((int)$user['id'],$channel),'inbox'=>CentrifugoService::inbox((int)$user['id'])]);
} catch(Throwable $e){$code=$e instanceof RuntimeException && $e->getCode()>=400 && $e->getCode()<500?$e->getCode():503;
    http_response_code($code);echo json_encode(['error'=>$code===503?'Realtime service unavailable':$e->getMessage()]);}
