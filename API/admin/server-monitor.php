<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once ROOT_PATH.'/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH.'/security/middleware/RoleMiddleware.php';
require_once ROOT_PATH.'/services/AdminSpaceMonitorService.php';
require_once ROOT_PATH.'/services/ServerMonitoringService.php';
header('Content-Type: application/json');header('Cache-Control: no-store');
$user=AuthMiddleware::requireAuth(true);$scope=(string)($_GET['scope']??'admin');
if(!in_array($scope,['admin','facilitator'],true)){http_response_code(400);echo json_encode(['error'=>'Invalid monitor scope']);exit;}
RoleMiddleware::requireRole($scope==='admin'?['admin','super_admin']:['facilitator','admin','super_admin','moderator'],true);
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);header('Allow: GET');exit;}
$id=(int)($_GET['server_id']??0);if($id<1){http_response_code(400);echo json_encode(['error'=>'Choose a valid space']);exit;}
session_write_close();
try{$data=$scope==='admin'?(new AdminSpaceMonitorService())->data($id,(int)($_GET['days']??7)):(new ServerMonitoringService())->getForFacilitator($id,(int)$user['id']);if(!$data)throw new RuntimeException('You cannot monitor this space.',403);echo json_encode(['success'=>true,'data'=>$data],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);}
catch(InvalidArgumentException $e){http_response_code(422);echo json_encode(['error'=>$e->getMessage()]);}
catch(Throwable $e){$code=in_array($e->getCode(),[403,404],true)?$e->getCode():503;http_response_code($code);if($code===503)error_log('[Admin monitor] '.$e->getMessage());echo json_encode(['error'=>$code===503?'Space activity is temporarily unavailable.':$e->getMessage()]);}
