<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
header('Content-Type: application/json');
AuthMiddleware::startSession();
$me = AuthMiddleware::requireAuth(true);
session_write_close();
header('Cache-Control: no-store, private');
require_once dirname(__DIR__,2).'/services/ChatSearchService.php';
$started=hrtime(true);
try {
    if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') { http_response_code(405); exit; }
    if (!is_string($_GET['q']??'')) throw new InvalidArgumentException('Invalid search',400);
    $cursor=null;
    if (isset($_GET['cursor'])) {
        if (!is_string($_GET['cursor']) || strlen($_GET['cursor'])>200) throw new InvalidArgumentException('Invalid cursor',400);
        $cursor=json_decode(base64_decode($_GET['cursor'],true)?:'',true);
        if (!is_array($cursor)) throw new InvalidArgumentException('Invalid cursor',400);
    }
    if(mb_strlen(trim($_GET['q']??''))<2){echo json_encode(['success'=>true,'results'=>[],'next_cursor'=>null]);return;}
    $result=(new ChatSearchService(Database::getInstance()))->search((int)$me['id'],(int)($_GET['server_id']??0),(int)($_GET['channel_id']??0),trim($_GET['q']??''),$cursor);
    echo json_encode(['success'=>true]+$result);
} catch(Throwable $e) {
    $status=in_array($e->getCode(),[400,403],true)?$e->getCode():500;
    http_response_code($status);echo json_encode(['error'=>$status===500?'Search unavailable':$e->getMessage()]);
} finally {
    if (filter_var(getenv('SEARCH_METRICS_ENABLED'),FILTER_VALIDATE_BOOLEAN)) error_log(json_encode(['metric'=>'chat_search','duration_ms'=>round((hrtime(true)-$started)/1e6,2)]));
}
