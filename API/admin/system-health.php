<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once ROOT_PATH.'/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH.'/security/middleware/RoleMiddleware.php';
header('Content-Type: application/json');
AuthMiddleware::startSession(); AuthMiddleware::requireAuth(true); RoleMiddleware::requireRole(['admin','super_admin'],true);
function readMem(): array { $m=[]; foreach(@file('/proc/meminfo')?:[] as $l){ if(preg_match('/^(\w+):\s+(\d+)/',$l,$x))$m[$x[1]]=(int)$x[2]; } $total=$m['MemTotal']??0;$avail=$m['MemAvailable']??0;return ['total_kb'=>$total,'used_kb'=>max(0,$total-$avail),'percent'=>$total?round(($total-$avail)*100/$total,1):null];}
$load=@file_get_contents('/proc/loadavg');$parts=preg_split('/\s+/',trim((string)$load));$cores=(int)trim((string)@shell_exec('nproc 2>/dev/null'));if($cores<1)$cores=1;$cpu=isset($parts[0])?round(min(100,((float)$parts[0]/$cores)*100),1):null;
$up=(float)trim((string)@file_get_contents('/proc/uptime'));$diskTotal=@disk_total_space(ROOT_PATH);$diskFree=@disk_free_space(ROOT_PATH);
echo json_encode(['success'=>true,'health'=>['cpu_percent'=>$cpu,'load_1m'=>isset($parts[0])?(float)$parts[0]:null,'memory'=>readMem(),'uptime_seconds'=>$up?:null,'disk_percent'=>$diskTotal?round((($diskTotal-$diskFree)/$diskTotal)*100,1):null,'php_version'=>PHP_VERSION,'checked_at'=>date(DATE_ATOM)]]);
