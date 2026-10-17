<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once ROOT_PATH.'/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH.'/security/middleware/RoleMiddleware.php';
$user=AuthMiddleware::requireAuth();$scope=(string)($_GET['scope']??'admin');
$sid=(int)($_GET['server_id']??0);if($sid<1){http_response_code(400);exit('Invalid space.');}
if($scope==='admin'){
 RoleMiddleware::requireRole(['admin','super_admin']);$tab=(string)($_GET['tab']??'overview');if(!in_array($tab,['overview','channels','members','activity','reports'],true))$tab='overview';
 header('Location: '.BASE_URL.'/modules/admin/dashboard.php?page=monitor&server_id='.$sid.'&tab='.$tab);exit;
}
if($scope!=='facilitator'){http_response_code(400);exit('Invalid monitor scope.');}
RoleMiddleware::requireRole(['facilitator','admin','super_admin','moderator']);
require_once ROOT_PATH.'/services/ServerMonitoringService.php';
$data=(new ServerMonitoringService())->getForFacilitator($sid,(int)$user['id']);
if(!$data){http_response_code(403);exit('You cannot monitor this space.');}
$parameter=(int)$data['server']['owner_id']===(int)$user['id']?'server_id':'monitor_server_id';
header('Location: '.BASE_URL.'/modules/facilitator/dashboard.php?page=monitor&'.$parameter.'='.$sid);exit;
