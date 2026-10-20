<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once ROOT_PATH.'/database/config/db.php';
require_once ROOT_PATH.'/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH.'/services/CodeLabService.php';
require_once ROOT_PATH.'/services/CodeLabExecutionService.php';
require_once ROOT_PATH.'/services/CodeLabSimilarityService.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
$user=AuthMiddleware::requireAuth(true);
$method=$_SERVER['REQUEST_METHOD'];
if (!in_array($method,['GET','POST'],true)) {http_response_code(405);header('Allow: GET, POST');echo json_encode(['success'=>false,'error'=>'Method not allowed.']);exit;}
if ($method==='POST') AuthMiddleware::verifyCsrf();
session_write_close();
try {
    $raw=$method==='POST'?file_get_contents('php://input',false,null,0,262145):'';
    if (strlen($raw)>262144) throw new RuntimeException('Request is too large.',400);
    $input=$method==='POST'?json_decode($raw,true):$_GET;
    if (!is_array($input)) throw new RuntimeException('Invalid request.',400);
    $sid=(int)($input['server_id']??0);$tid=(int)($input['task_id']??0);$uid=(int)$user['id'];
    $service=new CodeLabService(Database::getInstance());$action=$input['action']??'overview';
    $execution=new CodeLabExecutionService(Database::getInstance(),new CodeLabRunner());
    $data=match ($method.':'.$action) {
        'GET:sample'=>$service->sample($sid,$uid,(string)($input['language']??'')),
        'POST:similarity'=>(new CodeLabSimilarityService(Database::getInstance()))->queue($sid,$uid,$tid),
        'GET:similarity'=>(new CodeLabSimilarityService(Database::getInstance()))->latest($sid,$uid,$tid),
        'GET:job'=>$execution->result($sid,$uid,(int)($input['job_id']??0)),
        'POST:run'=>$execution->run($sid,$uid,$input),
        'POST:grade'=>$execution->grade($sid,$uid,$tid),
        'GET:overview'=>$service->overview($sid,$uid),
        'GET:submissions'=>['submissions'=>$service->submissions($sid,$uid,$tid)],
        'GET:playground'=>$service->playground($sid,$uid,(string)($input['language']??'')),
        'POST:create'=>$service->create($sid,$uid,$input),
        'POST:start'=>$service->start($sid,$uid,$tid),
        'POST:save'=>$service->save($sid,$uid,$tid,$input),
        'POST:submit'=>$service->save($sid,$uid,$tid,$input,true),
        'POST:review'=>$service->review($sid,$uid,$tid,$input),
        'POST:playground'=>$service->playground($sid,$uid,(string)($input['language']??''),$input),
        default=>throw new RuntimeException('Unsupported action.',400)
    };
    echo json_encode(['success'=>true,'data'=>$data],JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    $code=$e instanceof RuntimeException && in_array($e->getCode(),[400,403,404,409,429],true)?$e->getCode():503;
    http_response_code($code);echo json_encode(['success'=>false,'error'=>$code===503?'Code Lab unavailable. Check deployment and migration.':$e->getMessage()]);
}
