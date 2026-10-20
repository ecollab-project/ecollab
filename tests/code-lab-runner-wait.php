<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/CodeLabRunner.php';
// Called only by the loopback protocol fixture, never against student submissions.
$url=$argv[1]??'';
if(!preg_match('#^http://127\.0\.0\.1:\d+$#D',$url))throw new RuntimeException('Use the loopback test adapter');
$runner=new CodeLabRunner($url,str_repeat('private-',8));$start=microtime(true);
for($i=0;$i<8;$i++){
    $result=$runner->execute(10003,'print(42)','');
    if($result['status']['id']!==3||trim($result['stdout'])!=='42')throw new RuntimeException('Unexpected synthetic result');
}
echo json_encode(['elapsed_ms'=>round((microtime(true)-$start)*1000,2),'finished'=>8]);
