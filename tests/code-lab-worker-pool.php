<?php
declare(strict_types=1);
require dirname(__DIR__).'/services/CodeLabWorkerPool.php';
if(($argv[1]??'')==='fixture'){
    [$script,$mode,$folder,$scenario]=$argv;
    exit(CodeLabWorkerPool::run(2,static function(int $slot)use($folder,$scenario):void{
        $stop=false;pcntl_async_signals(true);pcntl_signal(SIGTERM,static function()use(&$stop):void{$stop=true;});
        file_put_contents($folder.'/ready-'.$slot,(string)getmypid());
        while(!is_file($folder.'/ready-1')||!is_file($folder.'/ready-2'))usleep(1000);
        if($scenario==='crash'&&$slot===1)exit(7);
        // Signals must finish the current synthetic job, then claim no more work.
        $until=hrtime(true)+400_000_000;
        while(hrtime(true)<$until)usleep(10000);
        file_put_contents($folder.'/finished-'.$slot,'done');
        while(!$stop)usleep(10000);
    }));
}
foreach(['0','3','-1','auto','2.0',true]as$bad){try{CodeLabWorkerPool::size($bad);throw new LogicException('Invalid slot count accepted');}catch(RuntimeException $e){}}
foreach(['graceful','crash']as$scenario){
    $folder=sys_get_temp_dir().'/ecollab-pool-'.bin2hex(random_bytes(8));mkdir($folder,0700);
    $process=proc_open([PHP_BINARY,__FILE__,'fixture',$folder,$scenario],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $deadline=microtime(true)+5;
    while((!is_file($folder.'/ready-1')||!is_file($folder.'/ready-2'))&&microtime(true)<$deadline)usleep(10000);
    if(!is_file($folder.'/ready-2'))throw new RuntimeException('Pool did not start two workers');
    $pid=proc_get_status($process)['pid'];if($scenario==='graceful')posix_kill($pid,SIGTERM);
    foreach($pipes as$pipe)fclose($pipe);
    $exit=proc_close($process);
    if($exit!==($scenario==='graceful'?0:1))throw new RuntimeException('Unexpected supervisor status');
    foreach([1,2]as$slot){$child=(int)file_get_contents($folder.'/ready-'.$slot);if(posix_kill($child,0))throw new RuntimeException('Orphaned child');}
    if(!is_file($folder.'/finished-2')||($scenario==='graceful'&&!is_file($folder.'/finished-1')))throw new RuntimeException('Active job was interrupted');
    foreach(glob($folder.'/*')as$file)unlink($file);rmdir($folder);
}
echo "PASS: two bounded workers, graceful drain, child-failure propagation, no orphan processes\n";
