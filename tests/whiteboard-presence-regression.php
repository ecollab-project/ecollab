<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/WhiteboardPresenceService.php';
function checkPresence(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function rejected(callable $fn):void{try{$fn();}catch(InvalidArgumentException){return;}throw new RuntimeException('Expected rejection');}
$p=new WhiteboardPresenceService();$room='20:10';$user=['id'=>18,'name'=>'Actual user'];
$packet=['client_id'=>12,'clock'=>1,'state'=>['user'=>['id'=>5,'name'=>'Spoofed'],'pointer'=>['x'=>10,'y'=>20],'selectedElementIds'=>['rectangle'=>true]]];
$state=$p->publish($room,100,$packet,$user,100);
checkPresence($state['state']['user']['id']===18&&$state['state']['user']['name']==='Actual user','Identity must come from authenticated connection');
checkPresence(count($p->snapshot('20:11',100))===0,'Rooms must be isolated');
checkPresence($p->publish($room,100,$packet,$user,101)===null,'Stale clocks must be ignored');
rejected(fn()=>$p->publish($room,101,$packet,['id'=>5,'name'=>'Other'],101));
rejected(fn()=>$p->publish($room,100,array_replace($packet,['client_id'=>13,'clock'=>2]),$user,101));
rejected(fn()=>$p->publish($room,100,array_replace($packet,['clock'=>2,'state'=>['pointer'=>['x'=>INF,'y'=>0]]]),$user,101));
checkPresence(count($p->snapshot($room,131))===0,'Expired cursors must not enter join snapshots');
$p->publish($room,101,array_replace($packet,['client_id'=>14]),$user,100);
checkPresence($p->leave($room,100)===12,'Disconnect removes only this client');
checkPresence(count($p->snapshot($room,100))===1,'Other tab remains present');
checkPresence($p->publish($room,101,['client_id'=>14,'clock'=>2,'state'=>null],$user,102)['state']===null,'Explicit removal accepted');
checkPresence($p->snapshot($room,102)===[],'Removed states are not advertised');
$chat=$p->publish('chat:20',200,['client_id'=>20,'clock'=>1,'state'=>['typing'=>true,'text'=>'SECRET DRAFT']],$user,100,'chat');
checkPresence($chat['state']['typing']===true && !isset($chat['state']['text']),'Chat presence shares only typing state, never draft content');
checkPresence($p->snapshot('chat:21',100)===[],'Chat channels remain isolated');
echo "Presence checks passed: authenticated identity, room isolation, clocks, validation, expiry and multiple tabs.\n";
