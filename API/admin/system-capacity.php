<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once ROOT_PATH.'/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH.'/security/middleware/RoleMiddleware.php';
require_once ROOT_PATH.'/security/csrf/csrf.php';
require_once ROOT_PATH.'/services/SystemCapacityService.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
$user=AuthMiddleware::requireAuth(true);
RoleMiddleware::requireRole(['admin','super_admin'],true);
$method=$_SERVER['REQUEST_METHOD'];
if (!in_array($method,['GET','POST'],true)) { http_response_code(405); header('Allow: GET, POST'); echo json_encode(['success'=>false,'error'=>'Method not allowed']); exit; }
if ($method === 'POST') CSRF::verify();
session_write_close();
try {
    $service=new SystemCapacityService();
    if ($method === 'POST') {
        $body=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);
        if (!is_array($body)) throw new InvalidArgumentException('Invalid request body.');
        $service->save($body,(int)$user['id']);
    }
    echo json_encode(['success'=>true,'data'=>$service->overview()],JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException|JsonException $e) {
    http_response_code(422); echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
} catch (Throwable $e) {
    $conflict=$e->getCode()===409; http_response_code($conflict?409:503);
    if (!$conflict) error_log('System capacity: '.$e->getMessage());
    echo json_encode(['success'=>false,'error'=>$conflict?$e->getMessage():'Capacity data unavailable. Apply migration 053 and check the collector.']);
}
