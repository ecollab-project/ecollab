<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/database/config/db.php';
require_once dirname(__DIR__).'/services/JarredJobService.php';
require_once dirname(__DIR__).'/services/JarredActionService.php';
require_once dirname(__DIR__).'/services/JarredReplyGenerator.php';
$db=Database::getInstance();
$name='ec-jarred-worker-'.hash('sha224',(string)$db->query('SELECT DATABASE()')->fetchColumn());
$q=$db->prepare('SELECT GET_LOCK(?,0)');$q->execute([$name]);if((int)$q->fetchColumn()!==1)exit(0);
$jobs=new JarredJobService($db);$jobs->recover();
try {
    do {
        // Losing this DB connection terminates the worker; never reconnect without reacquiring the lock.
        $q=$db->prepare('SELECT IS_USED_LOCK(?)=CONNECTION_ID()');$q->execute([$name]);if(!(int)$q->fetchColumn())throw new RuntimeException('Worker lock lost');
        $worked=$jobs->workOne(static fn($j)=>(new JarredActionService())->handleMessage((int)$j['user_id'],(int)$j['conversation_id'],$j['body'],$j['active_server_id']===null?null:(int)$j['active_server_id'],json_decode($j['surface_json'],true)),static fn($j)=>JarredReplyGenerator::generate($db,$j));
        if(in_array('--once',$argv,true))break;
        if(!$worked)usleep(500000);
    }while(true);
}finally{$db->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);}
