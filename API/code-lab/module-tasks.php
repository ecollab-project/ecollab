<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once ROOT_PATH.'/database/config/db.php';
require_once ROOT_PATH.'/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH.'/security/rate-limit/RateLimiter.php';
require_once ROOT_PATH.'/services/CodeLabModuleReader.php';
require_once ROOT_PATH.'/services/CodeLabModuleTasks.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');
$user=AuthMiddleware::requireAuth(true);$method=$_SERVER['REQUEST_METHOD']??'GET';
try{
    if(!in_array($method,['GET','POST'],true))throw new RuntimeException('Method not allowed.',405);
    if($method==='POST')AuthMiddleware::verifyCsrf();if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    $multipart=str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'multipart/form-data');
    $input=$method==='GET'?$_GET:($multipart?$_POST:json_decode(file_get_contents('php://input',false,null,0,262145)?:'{}',true,512,JSON_THROW_ON_ERROR));
    if(!is_array($input))throw new RuntimeException('Invalid request.',400);
    $sid=(int)($input['server_id']??0);$id=(int)($input['job_id']??0);$uid=(int)$user['id'];$service=new CodeLabModuleTasks(Database::getInstance());$action=(string)($input['action']??'status');
    $service->authorize($sid,$uid,false);
    if($method==='GET'&&$action==='status')$data=$service->status($sid,$uid,$id);
    elseif($method==='POST'&&$action==='generate'&&$multipart){
        $service->authorize($sid,$uid);
        $limited=(new RateLimiter())->attempt('code_lab_module',(string)$uid,6,3600);if(!$limited['allowed'])throw new RuntimeException('Module generation limit reached. Try again later.',429);
        $module=CodeLabModuleReader::upload($_FILES['module']??[]);$data=$service->queue($sid,$uid,$module,(string)($input['language']??''),(string)($input['difficulty']??''));
    }elseif($method==='POST'&&$action==='retry'){
        $limited=(new RateLimiter())->attempt('code_lab_module',(string)$uid,6,3600);if(!$limited['allowed'])throw new RuntimeException('Module generation limit reached. Try again later.',429);
        $data=$service->retry($sid,$uid,$id);
    }elseif($method==='POST'&&$action==='cancel')$data=$service->cancel($sid,$uid,$id);
    elseif($method==='POST'&&$action==='publish')$data=$service->publish($sid,$uid,$id,$input);
    else throw new RuntimeException('Unsupported module action.',400);
    echo json_encode(['success'=>true,'data'=>$data],JSON_THROW_ON_ERROR);
}catch(Throwable $e){$code=$e instanceof RuntimeException&&in_array($e->getCode(),[400,403,404,405,409,422,429,503],true)?$e->getCode():($e instanceof JsonException?400:503);http_response_code($code);echo json_encode(['success'=>false,'error'=>$code===503?'Module drafting unavailable. Check the worker, reader and pending migrations.':($e instanceof JsonException?'Invalid request JSON.':$e->getMessage())]);}
