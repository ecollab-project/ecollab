<?php
declare(strict_types=1);
/** Fixed-size CLI aggregates. Never accepts user content or submission identifiers. */
final class CodeLabWorkerMetrics
{
    private array $groups=[];
    public function record(array $timing):void
    {
        $language=in_array($timing['language']??null,['c','cpp','python','java','javascript','web'],true)?$timing['language']:'other';
        $kind=($timing['kind']??null)==='grade'?'grade':'run';$key=$kind.'/'.$language;
        $g=$this->groups[$key]??['jobs'=>0,'failed'=>0,'runner_sum'=>0.,'runner_max'=>0.,'work_sum'=>0.,'work_max'=>0.,'overhead_sum'=>0.];
        $runner=max(0.,(float)($timing['runner_ms']??0));$work=max(0.,(float)($timing['work_ms']??0));
        $g['jobs']++;$g['failed']+=(($timing['state']??null)==='done'?0:1);
        $g['runner_sum']+=$runner;$g['runner_max']=max($g['runner_max'],$runner);
        $g['work_sum']+=$work;$g['work_max']=max($g['work_max'],$work);$g['overhead_sum']+=max(0.,$work-$runner);
        $this->groups[$key]=$g;
    }
    public function drain(float $windowSeconds):?array
    {
        if(!$this->groups)return null;
        $groups=[];$jobs=0;
        foreach($this->groups as$key=>$g){$jobs+=$g['jobs'];$groups[$key]=['jobs'=>$g['jobs'],'failed'=>$g['failed'],
            'runner_ms'=>['avg'=>round($g['runner_sum']/$g['jobs'],2),'max'=>round($g['runner_max'],2)],
            'work_ms'=>['avg'=>round($g['work_sum']/$g['jobs'],2),'max'=>round($g['work_max'],2)],
            'overhead_avg_ms'=>round($g['overhead_sum']/$g['jobs'],2)];}
        $this->groups=[];
        return ['type'=>'code_lab_worker_summary','window_seconds'=>round(max(0.,$windowSeconds),2),'jobs'=>$jobs,'groups'=>$groups];
    }
}
