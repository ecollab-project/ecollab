<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once dirname(__DIR__,2).'/database/config/db.php';
require_once dirname(__DIR__,2).'/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__,2).'/services/JarredJobService.php';
require_once dirname(__DIR__,2).'/services/JarredErrorResponse.php';
header('Content-Type: application/json');header('Cache-Control: no-store');
$me=AuthMiddleware::requireAuth(true);$method=$_SERVER['REQUEST_METHOD'];
if(!in_array($method,['GET','POST'],true)){http_response_code(405);exit;}
if($method==='POST')AuthMiddleware::verifyCsrf();session_write_close();
try{
    if($method==='POST'){
        $raw=file_get_contents('php://input',false,null,0,4097);
        if($raw===false||strlen($raw)>4096)throw new RuntimeException('Invalid request',400);
        try{$in=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}catch(JsonException){throw new RuntimeException('Invalid request',400);}
    }else{$in=$_GET;}
    if(!is_array($in))throw new RuntimeException('Invalid request',400);
    $cid=filter_var($in['conversation_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $id=isset($in['job_id'])?filter_var($in['job_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]):null;
    if($cid===false||$id===false)throw new RuntimeException('Invalid request',400);
    $jobs=new JarredJobService(Database::getInstance());
    if($method==='POST'){
        if(($in['action']??'')!=='cancel'||!$id)throw new RuntimeException('Invalid cancellation',400);
        $jobs->cancel((int)$me['id'],$cid,$id);
    }
    echo json_encode(['success'=>true]+$jobs->status((int)$me['id'],$cid,$id));
 }catch(Throwable $e){
    [$code,$message]=JarredErrorResponse::map($e);
    if(!in_array($code,[400,403],true)){$code=503;$message='Jarred status is temporarily unavailable.';}
    error_log('[jarred/status] request_rejected status='.$code);
    http_response_code($code);echo json_encode(['error'=>$message]);
}
