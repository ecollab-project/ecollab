<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once ROOT_PATH.'/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH.'/security/middleware/RoleMiddleware.php';
header('Content-Type: application/json');header('Cache-Control: no-store');
AuthMiddleware::requireAuth(true);RoleMiddleware::requireRole(['admin','super_admin'],true);
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);header('Allow: GET');exit;}
session_write_close();
$memory=[];foreach(@file('/proc/meminfo')?:[] as $line)if(preg_match('/^(\w+):\s+(\d+)/',$line,$match))$memory[$match[1]]=(int)$match[2];
$total=$memory['MemTotal']??0;$available=$memory['MemAvailable']??null;
$parts=preg_split('/\s+/',trim((string)@file_get_contents('/proc/loadavg')));
$cores=preg_match_all('/^processor\s*:/m',(string)@file_get_contents('/proc/cpuinfo'))?:null;
$uptime=@file_get_contents('/proc/uptime');$diskTotal=@disk_total_space(ROOT_PATH);$diskFree=@disk_free_space(ROOT_PATH);
echo json_encode(['success'=>true,'health'=>[
 'cpu_percent'=>null,'load_1m'=>isset($parts[0])&&is_numeric($parts[0])?(float)$parts[0]:null,'cpu_cores'=>$cores,
 'memory'=>['total_kb'=>$total?:null,'used_kb'=>$total&&$available!==null?max(0,$total-$available):null,'percent'=>$total&&$available!==null?round(($total-$available)*100/$total,1):null],
 'uptime_seconds'=>$uptime!==false?(float)$uptime:null,'disk_percent'=>$diskTotal&&$diskFree!==false?round(($diskTotal-$diskFree)/$diskTotal*100,1):null,
 'php_version'=>PHP_VERSION,'checked_at'=>gmdate(DATE_ATOM),'cpu_note'=>'System load is not CPU utilization.'
]],JSON_INVALID_UTF8_SUBSTITUTE);
