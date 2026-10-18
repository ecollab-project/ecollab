<?php
declare(strict_types=1);
/** CLI-only supervisor. Fork before opening any database or HTTP connections. */
final class CodeLabWorkerPool
{
    public static function size(mixed $value):int
    {
        if(!in_array($value,['1','2',1,2],true))throw new RuntimeException('Code Lab pool must have one or two slots.');
        return (int)$value;
    }
    public static function run(int $size,callable $worker):int
    {
        self::size($size);
        if(!function_exists('pcntl_fork')||!function_exists('posix_kill'))throw new RuntimeException('PHP CLI pcntl and posix required.');
        $children=[];$stopping=false;$failed=false;$signaled=false;
        pcntl_async_signals(true);
        $stop=static function()use(&$stopping):void{$stopping=true;};
        pcntl_signal(SIGTERM,$stop);pcntl_signal(SIGINT,$stop);
        for($slot=1;$slot<=$size;$slot++){
            if($stopping)break;
            $pid=pcntl_fork();
            if($pid===-1){$failed=true;$stopping=true;break;}
            if($pid===0){
                pcntl_signal(SIGTERM,SIG_DFL);pcntl_signal(SIGINT,SIG_DFL);
                try{$worker($slot);exit(0);}catch(Throwable $e){fwrite(STDERR,"Code Lab child stopped unexpectedly.\n");exit(1);}
            }
            $children[$pid]=true;
        }
        while($children){
            if($stopping&&!$signaled){foreach($children as$pid=>$_)posix_kill($pid,SIGTERM);$signaled=true;}
            foreach($children as$pid=>$_){
                $ended=pcntl_waitpid($pid,$status,WNOHANG);
                if($ended===$pid||$ended===-1){unset($children[$pid]);if(!$stopping){$failed=true;$stopping=true;}}
            }
            if($children)usleep(100000);
        }
        return $failed?1:0;
    }
    public static function slotName(PDO $db,int $slot):string
    {
        self::size($slot);
        return 'ec-cl-slot-'.hash('sha224',(string)$db->query('SELECT DATABASE()')->fetchColumn().':'.$slot);
    }
    public static function acquireSlot(PDO $db,int $slot):string
    {
        $name=self::slotName($db,$slot);
        $lock=$db->prepare('SELECT GET_LOCK(?,0)');$lock->execute([$name]);
        if((int)$lock->fetchColumn()!==1)throw new RuntimeException('Worker slot already occupied.');
        return $name;
    }
}
