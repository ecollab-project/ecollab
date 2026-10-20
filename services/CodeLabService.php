<?php
declare(strict_types=1);

/** Classroom metadata and private source storage. Execution is delegated to a queue. */
final class CodeLabService
{
    public const LANGUAGES = ['c','cpp','python','java'];
    private Closure $clock;
    public function __construct(private PDO $db, ?callable $clock = null)
    {
        $this->clock = Closure::fromCallable($clock ?? fn() => time());
    }
    private function query(string $sql, array $args = []): PDOStatement
    {
        $q = $this->db->prepare($sql); $q->execute($args); return $q;
    }
    private static function fail(string $message, int $code = 400): never { throw new RuntimeException($message, $code); }
    public function member(int $sid, int $uid): array
    {
        $row = $this->query('SELECT s.id,s.name,s.owner_id,u.role FROM servers s JOIN server_members m ON m.server_id=s.id JOIN users u ON u.id=m.user_id WHERE s.id=? AND m.user_id=? AND s.status=? AND u.deleted_at IS NULL AND u.status NOT IN ("banned","suspended","deactivated")', [$sid,$uid,'active'])->fetch(PDO::FETCH_ASSOC);
        if (!$row) self::fail('You are not a member of this active server.',403);
        $row['can_manage'] = in_array($row['role'],['facilitator','admin','super_admin'],true);
        return $row;
    }
    private function task(int $tid, int $sid): array
    {
        $task=$this->query('SELECT * FROM code_lab_tasks WHERE id=? AND server_id=?',[$tid,$sid])->fetch(PDO::FETCH_ASSOC);
        if (!$task) self::fail('Task not found in this server.',404);
        return $task;
    }
    private function timestamp(?string $date): ?int { return $date===null ? null : (new DateTimeImmutable($date,new DateTimeZone('UTC')))->getTimestamp(); }
    public function endTime(array $task, array $attempt): ?int
    {
        $end=$this->timestamp($task['due_at']);
        if ($task['duration_minutes']!==null) {
            $timer=$this->timestamp($attempt['started_at'])+(int)$task['duration_minutes']*60;
            $end=$end===null ? $timer : min($end,$timer);
        }
        return $end;
    }
    private function publicAttempt(array $task,array $attempt): array
    {
        $end=$this->endTime($task,$attempt); $attempt['ends_at']=$end;
        $attempt['state']=$attempt['submitted_at']!==null?'submitted':($end!==null && ($this->clock)()>=$end?'expired':'in_progress');
        return $attempt;
    }
    public function overview(int $sid,int $uid): array
    {
        $server=$this->member($sid,$uid);
        $tasks=$this->query('SELECT t.*,a.id AS attempt_id,a.started_at,a.submitted_at,a.score FROM code_lab_tasks t LEFT JOIN code_lab_attempts a ON a.task_id=t.id AND a.user_id=? WHERE t.server_id=? ORDER BY t.id DESC',[$uid,$sid])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tasks as &$task) {
            $task['state']=$task['attempt_id']!==null?$this->publicAttempt($task,$task)['state']:($this->timestamp($task['opens_at'])!==null && ($this->clock)()<$this->timestamp($task['opens_at'])?'scheduled':($this->timestamp($task['due_at'])!==null && ($this->clock)()>=$this->timestamp($task['due_at'])?'closed':'available'));
            unset($task['starter_code']);
        }
        require_once __DIR__.'/CodeLabRunner.php';
        $r=new CodeLabRunner();$languages=[];foreach(self::LANGUAGES as$l)if($r->enabled()&&CodeLabRunner::runtime($l))$languages[]=$l;
        return ['server'=>$server,'tasks'=>$tasks,'server_now'=>($this->clock)(),'runner_available'=>$r->enabled(),'runnable_languages'=>$languages];
    }
    private static function text(mixed $value,int $max,string $name,bool $required=false): string
    {
        if (!is_string($value) || strlen($value)>$max || ($required && trim($value)==='')) self::fail('Invalid '.$name.'.');
        return $value;
    }
    private static function date(mixed $value): ?string
    {
        if ($value===null || $value==='') return null;
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{3})?Z$/D',$value)) self::fail('Dates must be valid UTC timestamps.');
        try {$date=new DateTimeImmutable($value);} catch (Exception $e) {self::fail('Invalid calendar date.');}
        if ($date->format('Y-m-d\TH:i:s')!==substr($value,0,19)) self::fail('Invalid calendar date.');
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
    public function create(int $sid,int $uid,array $input): array
    {
        if (!$this->member($sid,$uid)['can_manage']) self::fail('Only server facilitators can create tasks.',403);
        $title=self::text($input['title']??null,160,'title',true); $instructions=self::text($input['instructions']??null,20000,'instructions',true);
        $language=$input['language']??''; $kind=$input['kind']??'';
        if (!in_array($language,self::LANGUAGES,true) || !in_array($kind,['assignment','practice'],true)) self::fail('Invalid task type or language.');
        $starter=self::text($input['starter_code']??'',100000,'starter code');
        $opens=self::date($input['opens_at']??null);$due=self::date($input['due_at']??null);
        if ($opens!==null && $due!==null && $due<=$opens) self::fail('Deadline must be after the opening time.');
        $duration=$input['duration_minutes']??null;
        if ($duration!==null && (!is_int($duration) || $duration<1 || $duration>1440)) self::fail('Timer must be 1–1440 minutes or disabled.');
        require_once __DIR__.'/CodeLabExecutionService.php';
        $mode=$input['grading_mode']??'manual';if(!in_array($mode,['manual','automatic'],true))self::fail('Invalid grading mode.');
        $cases=$mode==='automatic'?CodeLabExecutionService::validateCases($input['test_cases']??null,$language):[];
        $runtime=$language==='web'?null:CodeLabRunner::runtime($language);
        if($mode==='automatic'&&$language!=='web'&&(!(new CodeLabRunner())->enabled()||!$runtime))self::fail('Configure the runner and language before publishing an automatically graded task.');
        $this->db->beginTransaction();
        try {
            $this->query('INSERT INTO code_lab_tasks (server_id,created_by,title,instructions,language,kind,starter_code,opens_at,due_at,duration_minutes,grading_mode,runtime_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',[$sid,$uid,$title,$instructions,$language,$kind,$starter,$opens,$due,$duration,$mode,$runtime]);
            $id=(int)$this->db->lastInsertId();
            foreach($cases as$pos=>$case)$this->query('INSERT INTO code_lab_test_cases(task_id,position,input_data,expected_output,hidden,weight) VALUES(?,?,?,?,?,?)',[$id,$pos,$case['input'],$case['expected_output'],(int)$case['hidden'],$case['weight']]);
            $this->db->commit();
            require_once __DIR__.'/NotificationService.php';
            try { foreach($this->query('SELECT user_id FROM server_members WHERE server_id=?',[$sid])->fetchAll(PDO::FETCH_COLUMN)as$recipient)NotificationService::create($this->db,(int)$recipient,$uid,'class_update','New Code Lab task','Your facilitator published a coding activity.','/modules/code-lab/index.php?server_id='.$sid,'💻'); } catch(Throwable $notificationError) { error_log('[code-lab/notifications] delivery unavailable'); }
            return ['id'=>$id];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    /** Unique (task,user) makes double clicks and refreshes resume the same session. */
    public function start(int $sid,int $uid,int $tid): array
    {
        $this->member($sid,$uid);$task=$this->task($tid,$sid);
        $existing=$this->query('SELECT * FROM code_lab_attempts WHERE task_id=? AND user_id=?',[$tid,$uid])->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            $now=($this->clock)();
            if ($task['opens_at']!==null && $now<$this->timestamp($task['opens_at'])) self::fail('This task has not opened yet.',403);
            if ($task['due_at']!==null && $now>=$this->timestamp($task['due_at'])) self::fail('This task is closed.',403);
            try {$this->query('INSERT INTO code_lab_attempts (task_id,user_id,code,started_at) VALUES (?,?,?,?)',[$tid,$uid,$task['starter_code'],gmdate('Y-m-d H:i:s',$now)]);}
            catch (PDOException $e) {if (!in_array((string)$e->getCode(),['23000','23505'],true)) throw $e;}
            $existing=$this->query('SELECT * FROM code_lab_attempts WHERE task_id=? AND user_id=?',[$tid,$uid])->fetch(PDO::FETCH_ASSOC);
        }
        require_once __DIR__.'/CodeLabExecutionService.php';
        $execution=new CodeLabExecutionService($this->db,new CodeLabRunner());
        $task['public_cases']=$execution->publicCases($tid);
        $grade=$this->query('SELECT id,state FROM code_lab_jobs WHERE attempt_id=?',[$existing['id']])->fetch(PDO::FETCH_ASSOC);
        return ['task'=>$task,'attempt'=>$this->publicAttempt($task,$existing),'grade_job'=>$grade?:null,'server_now'=>($this->clock)()];
    }
    public function save(int $sid,int $uid,int $tid,array $input,bool $submit=false): array
    {
        $this->member($sid,$uid);$task=$this->task($tid,$sid);
        $attempt=$this->query('SELECT * FROM code_lab_attempts WHERE task_id=? AND user_id=?',[$tid,$uid])->fetch(PDO::FETCH_ASSOC);
        if (!$attempt) self::fail('Start your own attempt first.',404);
        if ($this->publicAttempt($task,$attempt)['state']!=='in_progress') self::fail('This attempt is read-only.',403);
        $code=self::text($input['code']??null,100000,'code');$version=$input['version']??null;
        if (!is_int($version)||$version<1) self::fail('Invalid save version.');
        $this->db->beginTransaction();
        try {
        $q=$this->query('UPDATE code_lab_attempts SET code=?,version=version+1,submitted_at=? WHERE task_id=? AND user_id=? AND version=? AND submitted_at IS NULL',[$code,$submit?gmdate('Y-m-d H:i:s',($this->clock)()):null,$tid,$uid,$version]);
        if ($q->rowCount()!==1) self::fail('A newer save exists. Reopen your attempt before editing.',409);
            if($submit&&$task['grading_mode']==='automatic'){
                require_once __DIR__.'/CodeLabExecutionService.php';
                (new CodeLabExecutionService($this->db,new CodeLabRunner(),$this->clock))->grade($sid,$uid,$tid);
            }
            $this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        return $this->start($sid,$uid,$tid);
    }
    public function submissions(int $sid,int $uid,int $tid): array
    {
        if (!$this->member($sid,$uid)['can_manage']) self::fail('Facilitator access required.',403);
        $this->task($tid,$sid);
        return $this->query('SELECT a.*,u.full_name,u.username FROM code_lab_attempts a JOIN users u ON u.id=a.user_id WHERE a.task_id=? AND a.submitted_at IS NOT NULL ORDER BY a.submitted_at',[$tid])->fetchAll(PDO::FETCH_ASSOC);
    }
    public function review(int $sid,int $uid,int $tid,array $input): array
    {
        if (!$this->member($sid,$uid)['can_manage']) self::fail('Facilitator access required.',403);
        $this->task($tid,$sid);$score=$input['score']??null;
        if (!is_numeric($score)||!is_finite((float)$score)||(float)$score<0||(float)$score>100) self::fail('Score must be 0–100.');
        $feedback=self::text($input['feedback']??'',20000,'feedback');$student=(int)($input['student_id']??0);
        $q=$this->query('UPDATE code_lab_attempts SET score=?,feedback=?,reviewed_by=?,reviewed_at=? WHERE task_id=? AND user_id=? AND submitted_at IS NOT NULL',[(float)$score,$feedback,$uid,gmdate('Y-m-d H:i:s',($this->clock)()),$tid,$student]);
        if (!$q->rowCount()) self::fail('Submitted attempt not found.',404);
        require_once __DIR__.'/NotificationService.php';
        NotificationService::create($this->db,$student,$uid,'class_update','Code Lab review ready','Your facilitator reviewed your submission.','/modules/code-lab/index.php?server_id='.$sid,'💻');
        return ['reviewed'=>true];
    }
    /** Fixed bundled fixtures only; caller cannot supply a filesystem path. */
    public function sample(int $sid,int $uid,string $language): array
    {
        $this->member($sid,$uid);
        $files=['c'=>'main.c','cpp'=>'main.cpp','python'=>'main.py','java'=>'Main.java'];
        if (!isset($files[$language])) self::fail('Invalid language.');
        $directory=dirname(__DIR__).'/examples/code-lab/advanced/';
        $cases=json_decode(file_get_contents($directory.'cases.json'),true,512,JSON_THROW_ON_ERROR);
        return ['code'=>file_get_contents($directory.$files[$language]),'input'=>$cases[0]['input'],'expected_output'=>$cases[0]['expected_output']];
    }
    public function playground(int $sid,int $uid,string $language,?array $input=null): array
    {
        $this->member($sid,$uid);if (!in_array($language,self::LANGUAGES,true)) self::fail('Invalid language.');
        if ($input!==null) {
            $code=self::text($input['code']??null,100000,'code');$version=$input['version']??null;
            if (!is_int($version)||$version<0) self::fail('Invalid save version.');
            if ($version===0) {
                try {$this->query('INSERT INTO code_lab_playgrounds (server_id,user_id,language,code,updated_at) VALUES (?,?,?,?,?)',[$sid,$uid,$language,$code,gmdate('Y-m-d H:i:s',($this->clock)())]);}
                catch (PDOException $e) {if (!in_array((string)$e->getCode(),['23000','23505'],true)) throw $e; self::fail('A newer playground save exists. Reload before editing.',409);}
            } else {
                $q=$this->query('UPDATE code_lab_playgrounds SET code=?,version=version+1,updated_at=? WHERE server_id=? AND user_id=? AND language=? AND version=?',[$code,gmdate('Y-m-d H:i:s',($this->clock)()),$sid,$uid,$language,$version]);
                if ($q->rowCount()!==1) self::fail('A newer playground save exists. Reload before editing.',409);
            }
        }
        return $this->query('SELECT code,version FROM code_lab_playgrounds WHERE server_id=? AND user_id=? AND language=?',[$sid,$uid,$language])->fetch(PDO::FETCH_ASSOC) ?: ['code'=>'','version'=>0];
    }
}
