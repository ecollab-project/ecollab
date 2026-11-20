<?php
declare(strict_types=1);

/** Cross-worker admission limit; each request keeps its own prompt and response. */
final class JarredInferenceGate
{
    private string $directory;
    private int $slots;
    public function __construct(string $provider)
    {
        $this->slots=max(1,min(8,(int)(getenv('JARRED_MAX_INFLIGHT')?:5)));
        $owner=function_exists('posix_geteuid')?posix_geteuid():getmyuid();
        $this->directory=sys_get_temp_dir().'/ecollab-jarred-'.$owner.'-'.substr(hash('sha256',$provider),0,20);
        if(!is_dir($this->directory)&&!@mkdir($this->directory,0700,true)&&!is_dir($this->directory))throw new RuntimeException('Jarred request admission is unavailable.',503);
    }

    /** @return resource */
    public function acquire(float $deadline)
    {
        $until=min($deadline,microtime(true)+max(0,min(5000,(int)(getenv('JARRED_ADMISSION_WAIT_MS')?:1000)))/1000);
        do{
            for($i=0;$i<$this->slots;$i++){
                $lock=@fopen($this->directory.'/slot-'.$i.'.lock','c');
                if(!$lock)throw new RuntimeException('Jarred request admission is unavailable.',503);
                if(flock($lock,LOCK_EX|LOCK_NB))return $lock;
                fclose($lock);
            }
            if(microtime(true)>=$until)break;
            usleep(10000);
        }while(microtime(true)<$until);
        throw new RuntimeException('Jarred has reached its request limit. Please try again shortly.',503);
    }

    public function busy(): bool
    {
        for($i=0;$i<$this->slots;$i++){
            $lock=@fopen($this->directory.'/slot-'.$i.'.lock','c');
            if(!$lock)return true;
            $free=flock($lock,LOCK_EX|LOCK_NB);
            if($free)flock($lock,LOCK_UN);
            fclose($lock);
            if(!$free)return true;
        }
        return false;
    }

    /** @param resource|null $lock */
    public static function release($lock): void
    {
        if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
    }
}
