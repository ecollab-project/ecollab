<?php
declare(strict_types=1);
require_once __DIR__.'/CodeLabService.php';
require_once __DIR__.'/CodeLabRunner.php';
final class CodeLabExecutionService
{
    private Closure $clock;
    private ?int $maintenanceAt=null;
    private array $statements=[];
    private ?array $lastTiming=null;
    public function __construct(private PDO $db,private CodeLabRunner $runner,?callable $clock=null){$this->clock=Closure::fromCallable($clock??fn()=>time());}
    private function q(string $sql,array $args=[]):PDOStatement{$q=$this->statements[$sql]??=$this->db->prepare($sql);$q->closeCursor();$q->execute($args);return $q;}
    /** CLI diagnostics only: no IDs, source, inputs, outputs or hidden cases. */
    public function lastTiming():?array{return $this->lastTiming;}
    private function fail(string $message,int $code=400):never{throw new RuntimeException($message,$code);}
    public static function validateCases(mixed $cases,string $language):array
    {
        if(!is_array($cases)||!array_is_list($cases)||count($cases)<1||count($cases)>10)throw new RuntimeException('Add 1–10 grading checks.',400);
        foreach($cases as &$c){if(!is_array($c)||!is_string($c['input']??null)||!is_string($c['expected_output']??null)||strlen($c['input'])>2048||strlen($c['expected_output'])>2048)throw new RuntimeException('Each check must have input and expected output up to 2048 bytes.',400);$c['hidden']=$c['hidden']??true;$c['weight']=$c['weight']??1;if(!is_bool($c['hidden'])||!is_int($c['weight'])||$c['weight']<1||$c['weight']>100)throw new RuntimeException('Invalid check visibility or weight.',400);if($language==='web'&&!preg_match('/^(?:[a-zA-Z][a-zA-Z0-9-]*|[.#][a-zA-Z_][a-zA-Z0-9_-]*)$/D',$c['input']))throw new RuntimeException('Web checks accept one tag, #id or .class selector.',400);}
        return $cases;
    }
    public function publicCases(int $tid):array{return $this->q('SELECT position,input_data AS input,expected_output,weight FROM code_lab_test_cases WHERE task_id=? AND hidden=0 ORDER BY position',[$tid])->fetchAll(PDO::FETCH_ASSOC);}
    /** Called within the immutable submission transaction. Reserves the user's queue slot. */
    public function grade(int $sid,int $uid,int $tid):array
    {
        (new CodeLabService($this->db,$this->clock))->member($sid,$uid);
        $a=$this->q('SELECT a.*,t.language,t.runtime_id,t.grading_mode FROM code_lab_attempts a JOIN code_lab_tasks t ON t.id=a.task_id WHERE t.server_id=? AND t.id=? AND a.user_id=? AND a.submitted_at IS NOT NULL',[$sid,$tid,$uid])->fetch(PDO::FETCH_ASSOC);
        if(!$a)$this->fail('Submitted attempt not found.',404);
        if($a['grading_mode']!=='automatic')return ['job_id'=>null];
        return $this->enqueue($sid,$uid,$a['language'],$a['runtime_id']===null?null:(int)$a['runtime_id'],'grade',$a['code'],'',(int)$a['id']);
    }
    public function run(int $sid,int $uid,array $in):array
    {
        $lab=new CodeLabService($this->db,$this->clock);$lab->member($sid,$uid);$language=$in['language']??'';$runtime=null;
        $tid=(int)($in['task_id']??0);
        if($tid){$data=$lab->start($sid,$uid,$tid);if($data['attempt']['state']!=='in_progress')$this->fail('This attempt is read-only.',403);$language=$data['task']['language'];$runtime=$data['task']['runtime_id']===null?null:(int)$data['task']['runtime_id'];}
        if(!$tid&&!in_array($language,CodeLabService::LANGUAGES,true))$this->fail('This language is no longer available.');
        if(!in_array($language,['c','cpp','python','java','javascript'],true))$this->fail('Use Web preview for HTML/CSS/JS.');
        $runtime=$runtime??CodeLabRunner::runtime($language);
        $source=$in['code']??null;$stdin=$in['stdin']??'';
        if(!is_string($source)||strlen($source)>100000||!is_string($stdin)||strlen($stdin)>4096)$this->fail('Invalid source or input size.');
        return $this->enqueue($sid,$uid,$language,$runtime,'run',$source,$stdin,null);
    }
    private function enqueue(int $sid,int $uid,string $language,?int $runtime,string $kind,string $source,string $stdin,?int $aid):array
    {
        if($language!=='web'&&(!$runtime||($kind==='run'&&!$this->runner->enabled())))$this->fail('Configure the code runner and this language before running.',503);
        $own=!$this->db->inTransaction();if($own)$this->db->beginTransaction();
        try {
            $ignore=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'INSERT OR IGNORE':'INSERT IGNORE';
            $this->q($ignore.' INTO code_lab_run_gates (user_id,window_started) VALUES (?,?)',[$uid,gmdate('Y-m-d H:i:s',($this->clock)())]);
            $suffix=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' FOR UPDATE';
            $gate=$this->q('SELECT * FROM code_lab_run_gates WHERE user_id=?'.$suffix,[$uid])->fetch(PDO::FETCH_ASSOC);
            if($aid){$existing=$this->q('SELECT id,state FROM code_lab_jobs WHERE attempt_id=?',[$aid])->fetch(PDO::FETCH_ASSOC);if($existing&&$existing['state']!=='failed'){if($own)$this->db->commit();return ['job_id'=>(int)$existing['id']];}}
            if((int)$this->q("SELECT COUNT(*) FROM code_lab_jobs WHERE state IN ('queued','running')")->fetchColumn()>=1000)$this->fail('The execution queue is full. Try again shortly.',429);
            $active=$this->q("SELECT id FROM code_lab_jobs WHERE user_id=? AND state IN ('queued','running') LIMIT 1",[$uid])->fetchColumn();if($kind==='run'&&$active)$this->fail('Wait for your current execution to finish before running again.',409);
            $window=(new DateTimeImmutable($gate['window_started'],new DateTimeZone('UTC')))->getTimestamp();
            $count=$window<=($this->clock)()-3600?0:(int)$gate['request_count'];
            if($count>=60)$this->fail('Hourly execution limit reached. Try again later.',429);
            $this->q('UPDATE code_lab_run_gates SET window_started=?,request_count=? WHERE user_id=?',[$count===0?gmdate('Y-m-d H:i:s',($this->clock)()):$gate['window_started'],$count+1,$uid]);
            if(!empty($existing)){$this->q("UPDATE code_lab_jobs SET state='queued',result_json=NULL,started_at=NULL,finished_at=NULL,created_at=? WHERE id=?",[gmdate('Y-m-d H:i:s',($this->clock)()),$existing['id']]);$id=(int)$existing['id'];}
            else{$this->q('INSERT INTO code_lab_jobs(server_id,user_id,attempt_id,language,runtime_id,kind,source_code,stdin,created_at) VALUES(?,?,?,?,?,?,?,?,?)',[$sid,$uid,$aid,$language,$runtime,$kind,$source,$stdin,gmdate('Y-m-d H:i:s',($this->clock)())]);$id=(int)$this->db->lastInsertId();}
            if($own)$this->db->commit();return ['job_id'=>$id];
        }catch(Throwable $e){if($own&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function result(int $sid,int $uid,int $id):array
    {
        (new CodeLabService($this->db,$this->clock))->member($sid,$uid);
        $j=$this->q('SELECT id,kind,state,result_json,created_at FROM code_lab_jobs WHERE id=? AND server_id=? AND user_id=?',[$id,$sid,$uid])->fetch(PDO::FETCH_ASSOC);
        if(!$j)$this->fail('Execution not found.',404);
        // Advisory position within this server, never IDs or data from another tenant.
        $j['queue_position']=$j['state']==='queued'?1+(int)$this->q("SELECT COUNT(*) FROM code_lab_jobs WHERE server_id=? AND state='queued' AND (created_at<? OR (created_at=? AND id<?))",[$sid,$j['created_at'],$j['created_at'],$id])->fetchColumn():null;
        $j['result']=$j['result_json']===null?null:json_decode($j['result_json'],true);unset($j['result_json'],$j['created_at']);return $j;
    }
    public static function matches(string $actual,string $expected):bool{return rtrim(str_replace("\r\n","\n",$actual),"\n")===rtrim(str_replace("\r\n","\n",$expected),"\n");}
    public static function webCheck(string $source,string $selector,string $expected):bool
    {
        if($source==='')return false;
        if(!class_exists('DOMDocument'))throw new RuntimeException('DOM extension is required for web grading.');
        if(!preg_match('/^(?:[a-zA-Z][a-zA-Z0-9-]*|[.#][a-zA-Z_][a-zA-Z0-9_-]*)$/D',$selector))throw new RuntimeException('Invalid web check.');
        $dom=new DOMDocument();$old=libxml_use_internal_errors(true);
        try{$dom->loadHTML('<?xml encoding="UTF-8">'.$source,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$xp=new DOMXPath($dom);$expr=$selector[0]==='#'?"//*[@id='".substr($selector,1)."']":($selector[0]==='.'?"//*[contains(concat(' ',normalize-space(@class),' '),' ".substr($selector,1)." ')]":'//'.strtolower($selector));$nodes=$xp->query($expr);foreach($nodes as $node){if($expected===''||self::matches(trim($node->textContent),$expected))return true;}return false;}finally{libxml_clear_errors();libxml_use_internal_errors($old);}
    }
    /** Short dispatcher transaction; no database lock spans compilation/grading. */
    private function claim():?array
    {
        if($this->db->inTransaction())throw new LogicException('Dispatch requires its own transaction.');
        $this->db->beginTransaction();
        try{
            $lock=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' FOR UPDATE';
            $cursor=$this->q('SELECT last_server_id FROM code_lab_dispatch_cursor WHERE id=1'.$lock)->fetchColumn();
            if($cursor===false)throw new RuntimeException('Code Lab dispatcher migration is missing.');
            // Locking reads avoid stale repeatable-read snapshots after waiting for the cursor.
            $sid=$this->q("SELECT server_id FROM code_lab_jobs WHERE state='queued' AND server_id>? ORDER BY server_id LIMIT 1".$lock,[(int)$cursor])->fetchColumn();
            if($sid===false)$sid=$this->q("SELECT server_id FROM code_lab_jobs WHERE state='queued' ORDER BY server_id LIMIT 1".$lock)->fetchColumn();
            if($sid===false){$this->db->commit();return null;}
            $job=$this->q("SELECT * FROM code_lab_jobs WHERE state='queued' AND server_id=? ORDER BY created_at,id LIMIT 1".$lock,[(int)$sid])->fetch(PDO::FETCH_ASSOC);
            if(!$job)throw new RuntimeException('Queued job disappeared during dispatch.');
            if($this->q("UPDATE code_lab_jobs SET state='running',started_at=? WHERE id=? AND state='queued'",[gmdate('Y-m-d H:i:s',($this->clock)()),$job['id']])->rowCount()!==1)throw new RuntimeException('Execution claim lost.');
            $this->q('UPDATE code_lab_dispatch_cursor SET last_server_id=? WHERE id=1',[(int)$sid]);
            $this->db->commit();return $job;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    /** Two shared slots rotate between nonempty server queues; FIFO claim order per server. */
    public function workOne():bool
    {
        $this->lastTiming=null;$workStarted=hrtime(true);$runnerMs=0.;$finishedState='failed';
        $now=($this->clock)();
        // Retention/recovery are periodic maintenance, not per-submission work.
        // Recover at startup and the next work cycle after each 60s interval.
        if($this->maintenanceAt===null||$now<$this->maintenanceAt||$now-$this->maintenanceAt>=60){
        $this->q("UPDATE code_lab_jobs SET state='failed',result_json=?,finished_at=? WHERE state='running' AND started_at<?",[json_encode(['error'=>'Worker stopped. Retry grading or run again.']),gmdate('Y-m-d H:i:s',($this->clock)()),gmdate('Y-m-d H:i:s',($this->clock)()-600)]);
        $this->q("DELETE FROM code_lab_jobs WHERE kind='run' AND state IN ('done','failed') AND created_at<?",[gmdate('Y-m-d H:i:s',($this->clock)()-86400)]);
            $this->maintenanceAt=$now;
        }
        $j=$this->claim();if(!$j)return false;
        try {
            (new CodeLabService($this->db,$this->clock))->member((int)$j['server_id'],(int)$j['user_id']);
            $execute=function(string $stdin)use($j,&$runnerMs):array{$started=hrtime(true);try{return $this->runner->execute((int)$j['runtime_id'],$j['source_code'],$stdin);}finally{$runnerMs+=(hrtime(true)-$started)/1e6;}};
            if($j['kind']==='run'){$r=$execute($j['stdin']);$result=['status'=>$r['status']['description'],'stdout'=>$r['stdout'],'stderr'=>$r['stderr'],'compile_output'=>$r['compile_output'],'time'=>$r['time']??null,'memory'=>$r['memory']??null];}
            else {
                $a=$this->q('SELECT task_id FROM code_lab_attempts WHERE id=? AND user_id=? AND submitted_at IS NOT NULL',[$j['attempt_id'],$j['user_id']])->fetch(PDO::FETCH_ASSOC);if(!$a)throw new RuntimeException('Missing immutable submission.');
                $cases=$this->q('SELECT * FROM code_lab_test_cases WHERE task_id=? ORDER BY position',[$a['task_id']])->fetchAll(PDO::FETCH_ASSOC);if(!$cases)throw new RuntimeException('Missing grading checks.');
                $total=0;$earned=0;$results=[];
                foreach($cases as$c){$pass=$j['language']==='web'?self::webCheck($j['source_code'],$c['input_data'],$c['expected_output']):false;
                    if($j['language']!=='web'){$r=$execute($c['input_data']);$pass=$r['status']['id']===3&&self::matches($r['stdout'],$c['expected_output']);}
                    $total+=(int)$c['weight'];if($pass)$earned+=(int)$c['weight'];
                    // No student-code output/errors from grading: these can echo hidden inputs.
                    $results[]=['case'=>(int)$c['position']+1,'hidden'=>(bool)$c['hidden'],'passed'=>$pass];
                }
                $score=round(100*$earned/$total,2);$result=['score'=>$score,'passed'=>count(array_filter($results,fn($v)=>$v['passed'])),'total'=>count($cases),'cases'=>$results];

            }
            $this->db->beginTransaction();
            if($this->q("UPDATE code_lab_jobs SET state='done',result_json=?,finished_at=? WHERE id=? AND state='running'",[json_encode($result,JSON_THROW_ON_ERROR),gmdate('Y-m-d H:i:s',($this->clock)()),$j['id']])->rowCount()!==1)throw new RuntimeException('Execution claim expired.');
            if($j['kind']==='grade')$this->q('UPDATE code_lab_attempts SET auto_score=?,score=CASE WHEN reviewed_by IS NULL THEN ? ELSE score END WHERE id=? AND submitted_at IS NOT NULL',[$score,$score,$j['attempt_id']]);
            if($this->db->inTransaction())$this->db->commit();
            $finishedState='done';
            if($j['kind']==='grade'){ try {
                require_once __DIR__.'/NotificationService.php';
                $actor=$this->q('SELECT t.created_by FROM code_lab_tasks t JOIN code_lab_attempts a ON a.task_id=t.id WHERE a.id=?',[$j['attempt_id']])->fetchColumn();
                if($actor)NotificationService::create($this->db,(int)$j['user_id'],(int)$actor,'class_update','Code Lab grading ready','Automatic checks finished. Open your submission to review the result.','/modules/code-lab/index.php?server_id='.(int)$j['server_id'],'💻');
            } catch(Throwable $notificationError) { error_log('[code-lab/notifications] delivery unavailable'); } }
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();$this->q("UPDATE code_lab_jobs SET state='failed',result_json=?,finished_at=? WHERE id=? AND state='running'",[json_encode(['error'=>'Execution service could not finish. No automatic score was recorded. Retry when the runner is ready.']),gmdate('Y-m-d H:i:s',($this->clock)()),$j['id']]);}
        $this->lastTiming=['language'=>$j['language'],'kind'=>$j['kind'],'state'=>$finishedState,'runner_ms'=>round($runnerMs,3),'work_ms'=>round((hrtime(true)-$workStarted)/1e6,3)];
        return true;
    }
}
