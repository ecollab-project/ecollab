<?php
declare(strict_types=1);
/** CLI fixture preparation only: never changes application limits or real accounts. */
final class FeatureCapacityFixture
{
    public function __construct(private PDO $db) {}
    public function validate(array $fixture): array
    {
        if (($fixture['version'] ?? null)!==1 || !preg_match('/^k6-\d{8}-\d{6}-[a-f0-9]{8}$/D', $fixture['fixture_id'] ?? '') || isset($fixture['cleaned_at'])) throw new RuntimeException('Use an active generated load-test manifest.');
        $users=$fixture['users']??[];
        if (!is_array($users) || count($users)<2 || count($users)>1000) throw new RuntimeException('Invalid fixture accounts.');
        $ids=array_column($users,'id');
        if(count($ids)!==count($users) || count(array_unique($ids))!==count($ids)) throw new RuntimeException('Invalid fixture IDs.');
        $prefix='k6_'.substr($fixture['fixture_id'],-8).'_';
        $query=$this->db->prepare('SELECT username,email,role,deleted_at,status FROM users WHERE id=?');
        foreach($users as $user){
            if(!is_int($user['id']) || $user['id']<1 || !is_string($user['password']??null) || strlen($user['password'])<32) throw new RuntimeException('Invalid synthetic credentials.');
            $query->execute([$user['id']]);$row=$query->fetch(PDO::FETCH_ASSOC);
            if(!$row || !str_starts_with($row['username'],$prefix) || $row['email']!==$row['username'].'@example.invalid' || $row['email']!==($user['email']??'') || $row['role']!=='student' || $row['deleted_at']!==null || in_array($row['status'],['banned','suspended','deactivated'],true)) throw new RuntimeException('Fixture account differs from generated student.');
        }
        $q=$this->db->prepare('SELECT slug,owner_id,type,status FROM servers WHERE id=?');$q->execute([$fixture['server_id']??0]);$server=$q->fetch(PDO::FETCH_ASSOC);
        if(!$server || $server['slug']!==$fixture['fixture_id'] || !in_array((int)$server['owner_id'],$ids,true) || $server['type']!=='private' || $server['status']!=='active') throw new RuntimeException('Private fixture server differs.');
        $q=$this->db->prepare('SELECT user_id FROM server_members WHERE server_id=?');$q->execute([$fixture['server_id']]);$members=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
        sort($ids);sort($members);if($ids!==$members)throw new RuntimeException('Fixture membership differs; no changes performed.');
        return $server;
    }
    public function prepare(array $fixture,string $mode,int $roomSize=10): array
    {
        if(!in_array($mode,['ai','code-run','code-grade','media'],true) || $roomSize<2 || $roomSize>50)throw new InvalidArgumentException('Invalid workload or room size.');
        $server=$this->validate($fixture);$runId='capacity-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
        $target=['version'=>1,'run_id'=>$runId,'fixture_id'=>$fixture['fixture_id'],'base_url'=>$fixture['base_url'],'server_id'=>(int)$fixture['server_id'],'mode'=>$mode,'room_size'=>$roomSize,'prepared_at'=>gmdate('c'),'task_ids'=>[]];
        if($mode==='media'){
            require_once __DIR__.'/LiveKitService.php';$broker=new LiveKitService();$media=[];
            foreach($fixture['users'] as $i=>$user){
                $room='ecollab-'.$runId.'-'.intdiv($i,$roomSize);
                $token=$broker->issueNamedRoomToken(['id'=>$user['id'],'username'=>'Capacity student '.$i],$room,'Private capacity test',['capacity_fixture'=>$fixture['fixture_id']]);
                $media[]=['id'=>$user['id'],'slot'=>$i,'room'=>$room,'token'=>$token['token']];$target['livekit_url']=$token['url'];
            }
            $target['expires_at']=time()+590;$target['media']=$media;
        }
        if($mode==='ai')$target['configured_provider']=getenv('GEMINI_API_KEY') && getenv('GEMINI_API_KEY')!=='your_gemini_api_key_here'?'gemini-with-local-fallback':'local-ollama';
        if($mode==='code-grade'){
            require_once __DIR__.'/CodeLabRunner.php';$runner=new CodeLabRunner();if(!$runner->enabled())throw new RuntimeException('Configure the Code Lab runner first.');
            $this->db->beginTransaction();
            try{
                // Provision known synthetic assignments directly; do not elevate a student role.
                $task=$this->db->prepare('INSERT INTO code_lab_tasks(server_id,created_by,title,instructions,language,kind,starter_code,grading_mode,runtime_id) VALUES(?,?,?,?,?,?,?,?,?)');
                $test=$this->db->prepare('INSERT INTO code_lab_test_cases(task_id,position,input_data,expected_output,hidden,weight) VALUES(?,?,?,?,?,1)');
                foreach([2,4,50,100,150,200,250,300] as $level)foreach(['c','cpp','python','java'] as $language){
                    $runtime=CodeLabRunner::runtime($language);if(!$runtime)throw new RuntimeException('All four language runtimes must be configured.');
                    $task->execute([$target['server_id'],(int)$server['owner_id'],$runId.' '.$level.':'.$language,'Synthetic sieve benchmark; count primes up to the input integer.',$language,'assignment','','automatic',$runtime]);$id=(int)$this->db->lastInsertId();
                    $test->execute([$id,0,"1000\n","168\n",0]);$test->execute([$id,1,"2000\n","303\n",1]);$target['task_ids'][$level.':'.$language]=$id;
                }
                $this->db->commit();
            }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        }
        return $target;
    }
    public function cleanup(array $fixture,array $target): void
    {
        $server=$this->validate($fixture);
        if(($target['fixture_id']??'')!==$fixture['fixture_id'] || ($target['server_id']??0)!==$fixture['server_id'] || !preg_match('/^capacity-\d{8}-\d{6}-[a-f0-9]{8}$/D',$target['run_id']??''))throw new RuntimeException('Target differs from fixture.');
        $this->db->beginTransaction();
        try{
            foreach($target['task_ids']??[] as $language=>$id){
                $q=$this->db->prepare('SELECT server_id,created_by,title FROM code_lab_tasks WHERE id=? FOR UPDATE');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
                if(!$row || (int)$row['server_id']!==$fixture['server_id'] || (int)$row['created_by']!==(int)$server['owner_id'] || $row['title']!==$target['run_id'].' '.$language)throw new RuntimeException('Task ownership differs; cleanup refused.');
                $q=$this->db->prepare('SELECT user_id FROM code_lab_attempts WHERE task_id=?');$q->execute([$id]);
                foreach($q->fetchAll(PDO::FETCH_COLUMN) as $uid)if(!in_array((int)$uid,array_column($fixture['users'],'id'),true))throw new RuntimeException('Non-fixture attempt; cleanup refused.');
                $q=$this->db->prepare("SELECT COUNT(*) FROM code_lab_jobs WHERE attempt_id IN (SELECT id FROM code_lab_attempts WHERE task_id=?) AND state IN ('queued','running')");$q->execute([$id]);if((int)$q->fetchColumn())throw new RuntimeException('Wait for queued/running grading jobs to drain before cleanup.');
                $this->db->prepare('DELETE FROM code_lab_jobs WHERE attempt_id IN (SELECT id FROM code_lab_attempts WHERE task_id=?)')->execute([$id]);
                foreach(['code_lab_attempts','code_lab_test_cases']as$table)$this->db->prepare('DELETE FROM '.$table.' WHERE task_id=?')->execute([$id]);
                $this->db->prepare('DELETE FROM code_lab_tasks WHERE id=?')->execute([$id]);
            }
            $q=$this->db->prepare("SELECT id,user_id,state FROM code_lab_jobs WHERE server_id=? AND attempt_id IS NULL AND (source_code LIKE ? OR source_code LIKE ?) FOR UPDATE");
            $q->execute([$fixture['server_id'],'/* '.$target['run_id'].' */%','# '.$target['run_id']."\n%"]);
            foreach($q->fetchAll(PDO::FETCH_ASSOC)as$job){
                if(!in_array((int)$job['user_id'],array_column($fixture['users'],'id'),true)||in_array($job['state'],['queued','running'],true))throw new RuntimeException('Foreign or active execution; wait before cleanup.');
                $this->db->prepare('DELETE FROM code_lab_jobs WHERE id=?')->execute([$job['id']]);
            }
            $q=$this->db->prepare('SELECT id,user_id FROM ai_sessions WHERE session_title LIKE ?');$q->execute([$target['run_id'].'%']);
            foreach($q->fetchAll(PDO::FETCH_ASSOC)as$session){
                if(!in_array((int)$session['user_id'],array_column($fixture['users'],'id'),true))throw new RuntimeException('Non-fixture AI session; cleanup refused.');
                $this->db->prepare('DELETE FROM ai_messages WHERE session_id=?')->execute([$session['id']]);
                $this->db->prepare('DELETE FROM ai_sessions WHERE id=?')->execute([$session['id']]);
            }
            $this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
