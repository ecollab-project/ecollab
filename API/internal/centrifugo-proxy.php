<?php
declare(strict_types=1);
header('Content-Type: application/json');header('Cache-Control: no-store');
header('X-Ecollab-Auth-Outcome: deny');
if (($_SERVER['ECOLLAB_INTERNAL_BROKER_PROXY']??'')!=='1'
    || !in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)) {http_response_code(404);exit;}
require_once dirname(__DIR__,2).'/config.php';
$secret=(string)env('CENTRIFUGO_PROXY_SECRET','');
if (strlen($secret)<32 || !hash_equals($secret,(string)($_SERVER['HTTP_X_ECOLLAB_BROKER_SECRET']??''))) {http_response_code(403);exit;}
$event=(string)($_SERVER['ECOLLAB_BROKER_PROXY_EVENT']??'');
if ($_SERVER['REQUEST_METHOD']!=='POST' || !in_array($event,['connect','refresh'],true)) {http_response_code(405);exit;}
require_once ROOT_PATH.'/services/CentrifugoProxyService.php';
if (!filter_var(env('CENTRIFUGO_ENABLED','false'),FILTER_VALIDATE_BOOLEAN)) {
    echo json_encode(CentrifugoProxyService::denied($event));exit;
}
try {
    $raw=file_get_contents('php://input',false,null,0,16385);
    if ($raw===false || strlen($raw)>16384) throw new InvalidArgumentException('Invalid request');
    $request=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
    if (!is_array($request)) throw new InvalidArgumentException('Invalid request');
    require_once ROOT_PATH.'/security/middleware/AuthMiddleware.php';
    $cookie=$_COOKIE[session_name()]??null;
    if (!is_string($cookie) || !preg_match('/^[A-Za-z0-9,-]{16,128}$/D',$cookie)) {
        echo json_encode(CentrifugoProxyService::denied($event));exit;
    }
    AuthMiddleware::startSession();
    $uid=(int)($_SESSION['user_id']??0);
    session_write_close(); // Release the session lock before the database read.
    require_once ROOT_PATH.'/database/config/db.php';
    $reply=(new CentrifugoProxyService(Database::getInstance(),(string)env('CENTRIFUGO_ALLOWED_ORIGIN','https://ecollab.tech'),(int)env('CENTRIFUGO_REFRESH_SECONDS',60)))
        ->authorize($event,$request,$uid,(string)($_SERVER['HTTP_ORIGIN']??''));
    header('X-Ecollab-Auth-Outcome: '.(isset($reply['result']['expire_at'])?'allow':'deny'));
    echo json_encode($reply,JSON_THROW_ON_ERROR);
} catch (Throwable) {
    header('X-Ecollab-Auth-Outcome: unavailable');
    if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
    // A refresh whose backend can answer but cannot authorize expires immediately.
    // Transport-level proxy outages have broker retry semantics, tested separately.
    echo json_encode($event==='refresh' ? CentrifugoProxyService::denied($event)
        : ['error'=>['code'=>100,'message'=>'Authentication unavailable','temporary'=>true]]);
}
