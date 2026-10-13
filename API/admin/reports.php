<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once ROOT_PATH.'/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH.'/security/middleware/RoleMiddleware.php';
require_once ROOT_PATH.'/services/AdminReportService.php';
header('Content-Type: application/json');header('Cache-Control: no-store');
$user=AuthMiddleware::requireAuth(true);RoleMiddleware::requireRole(['admin','super_admin'],true);
$method=$_SERVER['REQUEST_METHOD'];if(!in_array($method,['GET','POST'],true)){http_response_code(405);header('Allow: GET, POST');exit;}
if($method==='POST')AuthMiddleware::verifyCsrf();session_write_close();
try{$s=new AdminReportService();if($method==='POST'){$b=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);if(!is_array($b))throw new InvalidArgumentException('Invalid request.');$data=$s->resolve($b,(int)$user['id']);}else $data=$s->listing($_GET);echo json_encode(['success'=>true,'data'=>$data],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);}
catch(InvalidArgumentException|JsonException $e){http_response_code(422);echo json_encode(['error'=>$e->getMessage()]);}
catch(Throwable $e){$code=in_array($e->getCode(),[403,404,409],true)?$e->getCode():503;http_response_code($code);if($code===503)error_log('[Admin reports] '.$e->getMessage());echo json_encode(['error'=>$code===503?'Report data is temporarily unavailable.':$e->getMessage()]);}
