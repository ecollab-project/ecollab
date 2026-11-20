<?php
declare(strict_types=1);
require_once __DIR__.'/CodeLabService.php';
require_once __DIR__.'/CodeLabRunner.php';

/** Browser-visible IDs never expose adapter credentials or another user's process. */
final class CodeLabInteractiveService
{
    private Closure $clock;
    public function __construct(private PDO $db,private CodeLabRunner $runner,?callable $clock=null){$this->clock=Closure::fromCallable($clock??static fn()=>time());}
    private function q(string $sql,array $params=[]):PDOStatement{$q=$this->db->prepare($sql);$q->execute($params);return $q;}
    private function lab():CodeLabService{return new CodeLabService($this->db,$this->clock);}
    private function fail(string $text,int $code=400):never{throw new RuntimeException($text,$code);}
    private function finish(array $row):void
    {
        if($row['adapter_token'])try{$this->runner->request('POST','/interactive/'.rawurlencode($row['adapter_token']).'/stop',[]);}catch(Throwable $e){}
        $this->q('UPDATE code_lab_live_sessions SET active_user_id=NULL WHERE id=?',[$row['id']]);
    }
    private function row(int $sid,int $uid,int $id,bool $check=true):array
    {
        $row=$this->q('SELECT * FROM code_lab_live_sessions WHERE id=? AND server_id=? AND user_id=?',[$id,$sid,$uid])->fetch(PDO::FETCH_ASSOC);
        if(!$row)$this->fail('Console session not found.',404);
        if($check){try{
            $this->lab()->member($sid,$uid);
            if((string)$row['expires_at']<=gmdate('Y-m-d H:i:s',($this->clock)()))$this->fail('Console session ended. Run again.',409);
            if($row['task_id']){
                $a=$this->lab()->start($sid,$uid,(int)$row['task_id']);
                if($a['attempt']['state']!=='in_progress')$this->fail('This attempt is read-only.',403);
            }
        }catch(Throwable $e){$this->finish($row);throw $e;}}
        return $row;
    }
    public function start(int $sid,int $uid,array $input):array
    {
        $lab=$this->lab();$lab->member($sid,$uid);
        $language=$input['language']??'';$tid=(int)($input['task_id']??0);$runtime=null;$ends=($this->clock)()+180;
        if($tid){$a=$lab->start($sid,$uid,$tid);if($a['attempt']['state']!=='in_progress')$this->fail('This attempt is read-only.',403);$language=$a['task']['language'];$runtime=$a['task']['runtime_id'];if($a['attempt']['ends_at']!==null)$ends=min($ends,(int)$a['attempt']['ends_at']);}
        elseif(!in_array($language,CodeLabService::LANGUAGES,true))$this->fail('Unsupported language.');
        $runtime=$runtime??CodeLabRunner::runtime($language);$code=$input['code']??null;
        if(!$runtime||!$this->runner->enabled())$this->fail('Interactive runner is not configured.',503);
        if(!is_string($code)||strlen($code)>100000)$this->fail('Invalid program size.');
        if(!empty($input['stdin']))$this->fail('Start the program, then send input when it runs.');
        // Recover abandoned sessions without replaying their program.
        foreach($this->q('SELECT * FROM code_lab_live_sessions WHERE active_user_id=?',[$uid])->fetchAll(PDO::FETCH_ASSOC) as $old){
            if(!$old['adapter_token']){if($old['expires_at']<=gmdate('Y-m-d H:i:s',($this->clock)()))$this->finish($old);continue;}
            try{$previous=$this->runner->request('GET','/interactive/'.rawurlencode($old['adapter_token']));if(in_array($previous['state']??'',['finished','failed'],true))$this->finish($old);}
            catch(Throwable $e){$this->finish($old);}
        }
        $this->db->beginTransaction();
        try{
            $ignore=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'INSERT OR IGNORE':'INSERT IGNORE';$suffix=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' FOR UPDATE';
            $this->q($ignore.' INTO code_lab_run_gates(user_id,window_started) VALUES(?,?)',[$uid,gmdate('Y-m-d H:i:s',($this->clock)())]);
            $gate=$this->q('SELECT * FROM code_lab_run_gates WHERE user_id=?'.$suffix,[$uid])->fetch(PDO::FETCH_ASSOC);
            $this->q('UPDATE code_lab_live_sessions SET active_user_id=NULL WHERE active_user_id=? AND expires_at<=?',[$uid,gmdate('Y-m-d H:i:s',($this->clock)())]);
            if($this->q('SELECT id FROM code_lab_live_sessions WHERE active_user_id=?',[$uid])->fetchColumn())$this->fail('Stop your running console before starting another.',409);
            if($this->q("SELECT id FROM code_lab_jobs WHERE user_id=? AND state IN ('queued','running')",[$uid])->fetchColumn())$this->fail('Wait for your current run or grading to finish.',409);
            $window=strtotime($gate['window_started'].' UTC');$count=$window<=($this->clock)()-3600?0:(int)$gate['request_count'];
            if($count>=60)$this->fail('Hourly execution limit reached.',429);
            $now=gmdate('Y-m-d H:i:s',($this->clock)());
            $this->q('UPDATE code_lab_run_gates SET request_count=?,window_started=? WHERE user_id=?',[$count+1,$count===0?$now:$gate['window_started'],$uid]);
            $this->q('INSERT INTO code_lab_live_sessions(server_id,user_id,task_id,active_user_id,created_at,expires_at) VALUES(?,?,?,?,?,?)',[$sid,$uid,$tid?:null,$uid,$now,gmdate('Y-m-d H:i:s',$ends)]);
            $id=(int)$this->db->lastInsertId();$this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        try{
            $created=$this->runner->request('POST','/interactive',['language_id'=>(int)$runtime,'source_code'=>base64_encode($code),'enable_network'=>false]);
            $token=$created['token']??'';if(!is_string($token)||!preg_match('/^[a-zA-Z0-9-]{8,64}$/D',$token))throw new RuntimeException('Invalid interactive session.');
            $this->q('UPDATE code_lab_live_sessions SET adapter_token=? WHERE id=?',[$token,$id]);
            return ['session_id'=>$id];
        }catch(Throwable $e){$this->q('UPDATE code_lab_live_sessions SET active_user_id=NULL WHERE id=?',[$id]);$this->fail('Live console unavailable. Check the interactive runner deployment.',503);}
    }
    public function status(int $sid,int $uid,int $id):array
    {
        $row=$this->row($sid,$uid,$id);
        if(!$row['adapter_token'])$this->fail('Console is still starting. Retry shortly.',409);
        try{$data=$this->runner->request('GET','/interactive/'.rawurlencode($row['adapter_token']));}
        catch(Throwable $e){$this->finish($row);$this->fail('Console connection ended. Run again.',503);}
        $state=$data['state']??'';
        if(!in_array($state,['queued','starting','compiling','running','finished','failed'],true)||!is_string($data['output']??null)||strlen($data['output'])>65536){$this->finish($row);$this->fail('Invalid console response.',503);}
        if(in_array($state,['finished','failed'],true))$this->q('UPDATE code_lab_live_sessions SET active_user_id=NULL WHERE id=?',[$id]);
        return ['state'=>$state,'output'=>$data['output'],'input_seq'=>(int)($data['input_seq']??0),'reason'=>(string)($data['reason']??''),'exit_code'=>$data['exit_code']??null];
    }
    public function input(int $sid,int $uid,int $id,array $input):array
    {
        $row=$this->row($sid,$uid,$id);$text=$input['data']??'';$seq=$input['sequence']??null;$eof=$input['eof']??false;
        if(!is_string($text)||strlen($text)>4096||!is_int($seq)||$seq<1||!is_bool($eof))$this->fail('Invalid console input.');
        if(!$row['active_user_id']||!$row['adapter_token'])$this->fail('Console session ended.',409);
        try{$r=$this->runner->request('POST','/interactive/'.rawurlencode($row['adapter_token']).'/input',['sequence'=>$seq,'data'=>$text,'eof'=>$eof]);}
        catch(Throwable $e){$this->fail('Input delivery uncertain. Retry the same input or check the console.',409);}
        return ['input_seq'=>(int)($r['input_seq']??0)];
    }
    public function stop(int $sid,int $uid,int $id):array
    {
        $row=$this->row($sid,$uid,$id,false);$this->finish($row);return ['stopped'=>true];
    }
}
