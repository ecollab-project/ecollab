<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once dirname(__DIR__,2).'/database/config/db.php';
require_once dirname(__DIR__,2).'/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__,2).'/services/JarredJobService.php';
header('Content-Type: application/json');header('Cache-Control: no-store');
$me=AuthMiddleware::requireAuth(true);$method=$_SERVER['REQUEST_METHOD'];
if(!in_array($method,['GET','POST'],true)){http_response_code(405);exit;}
if($method==='POST')AuthMiddleware::verifyCsrf();session_write_close();
try{
    $in=$method==='POST'?json_decode(file_get_contents('php://input',false,null,0,4096),true):$_GET;
    if(!is_array($in))throw new RuntimeException('Invalid request',400);
    $cid=(int)($in['conversation_id']??0);$id=isset($in['job_id'])?(int)$in['job_id']:null;
    $jobs=new JarredJobService(Database::getInstance());
    if($method==='POST'){
        if(($in['action']??'')!=='cancel'||!$id)throw new RuntimeException('Invalid cancellation',400);
        $jobs->cancel((int)$me['id'],$cid,$id);
    }
    echo json_encode(['success'=>true]+$jobs->status((int)$me['id'],$cid,$id));
}catch(Throwable $e){$code=in_array($e->getCode(),[400,403],true)?$e->getCode():503;http_response_code($code);echo json_encode(['error'=>$code===503?'Jarred status unavailable':$e->getMessage()]);}
