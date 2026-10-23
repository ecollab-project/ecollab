<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/database/config/db.php';
require_once dirname(__DIR__).'/services/JarredJobService.php';
require_once dirname(__DIR__).'/services/JarredActionService.php';
require_once dirname(__DIR__).'/services/JarredReplyGenerator.php';
$db=null;$name=null;$locked=false;
try {
    $shared=(string)(getenv('OLLAMA_INFERENCE_LOCK_FILE') ?: '');
    if(!str_starts_with($shared,'/')||!is_file($shared)||is_link($shared)||!is_readable($shared)||((int)fileperms($shared)&0007)!==0)
        throw new RuntimeException('Shared inference lock must be provisioned before worker activation');
    $db=Database::getInstance();
    $name='ec-jarred-worker-'.hash('sha224',(string)$db->query('SELECT DATABASE()')->fetchColumn());
    $q=$db->prepare('SELECT GET_LOCK(?,0)');$q->execute([$name]);
    if((int)$q->fetchColumn()!==1)exit(0);
    $locked=true;
    $jobs=new JarredJobService($db);$jobs->recover();
    do {
        // Do not reconnect/recover without reacquiring this singleton connection lock.
        $q=$db->prepare('SELECT IS_USED_LOCK(?)=CONNECTION_ID()');$q->execute([$name]);
        if(!(int)$q->fetchColumn())throw new RuntimeException('Worker lock lost');
        $worked=$jobs->workOne(
            static fn($j)=>(new JarredActionService())->handleMessage((int)$j['user_id'],(int)$j['conversation_id'],$j['body'],$j['active_server_id']===null?null:(int)$j['active_server_id'],json_decode($j['surface_json'],true)),
            static fn($j)=>JarredReplyGenerator::generate($db,$j));
        if(in_array('--once',$argv,true))break;
        if(!$worked)usleep(500000);
    }while(true);
} catch(Throwable) {
    error_log('[jarred_worker] worker_unavailable');
    $failed=true;
} finally {
    if($locked)try{$db->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);}
    catch(Throwable){error_log('[jarred_worker] lock_release_unavailable');}
}
if(!empty($failed))exit(1);
