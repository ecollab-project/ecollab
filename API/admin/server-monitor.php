<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once ROOT_PATH.'/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH.'/security/middleware/RoleMiddleware.php';
require_once ROOT_PATH.'/services/ServerMonitoringService.php';
header('Content-Type: application/json');
AuthMiddleware::startSession();
$user=AuthMiddleware::requireAuth(true);
$id=filter_input(INPUT_GET,'server_id',FILTER_VALIDATE_INT);
$scope=(string)($_GET['scope']??'admin');
if(!$id){http_response_code(400);echo json_encode(['error'=>'Invalid server']);exit;}
$svc=new ServerMonitoringService();
if($scope==='facilitator'){
    RoleMiddleware::requireRole(['facilitator','admin','super_admin','moderator'],true);
    $data=$svc->getForFacilitator((int)$id,(int)$user['id']);
    if(!$data){http_response_code(403);echo json_encode(['error'=>'You can only monitor servers you facilitate or manage.']);exit;}
}else{
    RoleMiddleware::requireRole(['admin','super_admin'],true);
    $data=$svc->getForAdmin((int)$id);
    if(!$data){http_response_code(404);echo json_encode(['error'=>'Server not found']);exit;}
}
echo json_encode(['success'=>true,'data'=>$data],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
