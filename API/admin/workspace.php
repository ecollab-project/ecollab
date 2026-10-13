<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once ROOT_PATH.'/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH.'/security/middleware/RoleMiddleware.php';
require_once ROOT_PATH.'/security/csrf/csrf.php';
require_once ROOT_PATH.'/services/AdminWorkspaceService.php';
header('Content-Type: application/json');header('Cache-Control: no-store');
$user=AuthMiddleware::requireAuth(true);RoleMiddleware::requireRole(['admin','super_admin'],true);
$method=$_SERVER['REQUEST_METHOD'];
if(!in_array($method,['GET','POST'],true)){http_response_code(405);header('Allow: GET, POST');echo json_encode(['success'=>false,'error'=>'Method not allowed']);exit;}
if($method==='POST')CSRF::verify();session_write_close();
try {
    $service=new AdminWorkspaceService();
    if($method==='POST') {
        $body=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);
        if(!is_array($body))throw new InvalidArgumentException('Invalid request.');
        $data=$service->mutate((string)($body['action']??''),$body,$user);
    } else {
        $data=match($_GET['action']??'overview') {
            'overview'=>$service->overview((int)($_GET['days']??7)),
            'users'=>$service->users($_GET),'spaces'=>$service->spaces($_GET),'audit'=>$service->audit($_GET),
            'catalog'=>$service->catalog(),'feedback'=>$service->feedback($_GET),'ai'=>$service->ai(),
            default=>throw new InvalidArgumentException('Unsupported view.')
        };
    }
    echo json_encode(['success'=>true,'data'=>$data],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(InvalidArgumentException|JsonException $e){http_response_code(422);echo json_encode(['success'=>false,'error'=>$e->getMessage()]);}
catch(Throwable $e){$code=in_array($e->getCode(),[403,404,409],true)?$e->getCode():($e instanceof PDOException&&($e->errorInfo[0]??'')==='23000'?409:503);http_response_code($code);error_log('Admin workspace: '.$e->getMessage());echo json_encode(['success'=>false,'error'=>$code===503?'Data unavailable. Check the application logs.':($code===409&&$e instanceof PDOException?'That username, email or tag already exists.':$e->getMessage())]);}
