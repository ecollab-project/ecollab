<?php
declare(strict_types=1);
require_once __DIR__.'/../vendor/autoload.php';
class HeartbeatConnection implements Ratchet\ConnectionInterface{public int $resourceId=1;public array $sent=[];public function send($v){$this->sent[]=$v;return $this;}public function close(){}}
$server=(new ReflectionClass(ChatServer::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(ChatServer::class,'connMeta'))->setValue($server,[1=>['authed'=>true]]);
$conn=new HeartbeatConnection();$start=hrtime(true);
foreach([50,100,200,300,350,400,450] as $clients){for($i=0;$i<$clients;$i++)$server->onMessage($conn,'{"type":"ping"}');}
// PDO is deliberately uninitialized: any DB access on these paths fails this test.
$server->onMessage($conn,'invalid');$server->onMessage($conn,'{"type":[]}');
if(count($conn->sent)!==1850)throw new RuntimeException('Missing heartbeats');
(new ReflectionProperty(ChatServer::class,'connMeta'))->setValue($server,[1=>['authed'=>false]]);
$server->onMessage($conn,'{"type":"ping"}');
if(!str_contains(end($conn->sent),'Unauthenticated'))throw new RuntimeException('Unauthenticated heartbeat');
echo 'PASS 1850 authenticated heartbeats and malformed frames, zero DB access; total dispatch ms='.round((hrtime(true)-$start)/1e6,3)."\n";
