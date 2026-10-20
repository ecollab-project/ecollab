<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/config.php';
require_once ROOT_PATH.'/services/SystemCapacityService.php';
try {
    $row=(new SystemCapacityService())->collect();
    echo json_encode(['sampled_at'=>gmdate(DATE_ATOM),'users'=>$row]).PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR,'Capacity collection failed: '.$e->getMessage().PHP_EOL); exit(1); }
