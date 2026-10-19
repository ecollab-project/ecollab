<?php
declare(strict_types=1);

/** Bounded, content-free wall timings for the CLI dispatcher's reporting window. */
final class RealtimeDispatchMetrics
{
    private array $phases=[];
    private int $attempted=0;
    private int $failed=0;
    private int $recipients=0;

    public function measure(string $phase,callable $work):mixed
    {
        if(!in_array($phase,['select','recipients','broadcast','acknowledge','retry'],true))throw new InvalidArgumentException('Unknown dispatch phase');
        $started=hrtime(true);
        try{return $work();}
        finally{
            $ms=(hrtime(true)-$started)/1e6;
            $p=$this->phases[$phase]??['calls'=>0,'total_ms'=>0.,'max_ms'=>0.];
            $p['calls']++;$p['total_ms']+=$ms;$p['max_ms']=max($p['max_ms'],$ms);$this->phases[$phase]=$p;
        }
    }

    public function outcome(bool $success,int $recipientCount):void
    {
        $this->attempted++;if(!$success)$this->failed++;$this->recipients+=max(0,$recipientCount);
    }

    public function drain():array
    {
        $phases=[];
        foreach($this->phases as$name=>$p)$phases[$name]=['calls'=>$p['calls'],
            'total_ms'=>round($p['total_ms'],3),'avg_ms'=>round($p['total_ms']/$p['calls'],3),'max_ms'=>round($p['max_ms'],3)];
        $result=['attempted'=>$this->attempted,'failed'=>$this->failed,'recipient_targets'=>$this->recipients,'phases'=>$phases];
        $this->phases=[];$this->attempted=0;$this->failed=0;$this->recipients=0;return $result;
    }
}
