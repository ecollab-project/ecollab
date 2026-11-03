<?php
declare(strict_types=1);

/** Durable per-conversation turns. No inference runs in a database transaction. */
final class JarredReplyQueue
{
    public function __construct(private PDO $db) {}
    private function now(): string {return gmdate('Y-m-d H:i:s');}
    private function lockSuffix(): string {return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';}
    private function guard(): void {if((int)$this->db->query('SELECT id FROM jarred_reply_queue_guard WHERE id=1'.$this->lockSuffix())->fetchColumn()!==1)throw new RuntimeException('Apply the Jarred queue migration before sending.',503);}
    private function transaction(callable $work): mixed
    {
        $owns=!$this->db->inTransaction();if($owns)$this->db->beginTransaction();
        try{$result=$work();if($owns)$this->db->commit();return $result;}
        catch(Throwable $e){if($owns&&$this->db->inTransaction())$this->db->rollBack();if($e instanceof PDOException&&($e->getCode()==='42S02'||str_contains($e->getMessage(),'no such table')))throw new RuntimeException('Apply pending Jarred queue migrations before sending.',503,$e);throw $e;}
    }
    /** Atomically reserve the turn and save its existing DM message/nonce. */
    public function reserve(int $uid,int $conversation,int $recipient,string $prompt,?int $server,array $surface,callable $save): int
    {
        return $this->transaction(function()use($uid,$conversation,$recipient,$prompt,$server,$surface,$save){
            $this->guard();$this->recover();
            $q=$this->db->prepare('SELECT id FROM jarred_reply_jobs WHERE active_conversation_id=?');$q->execute([$conversation]);
            if($q->fetchColumn())throw new RuntimeException('Jarred is already preparing a reply in this conversation.',409);
            $limit=max(5,min(100,(int)(getenv('JARRED_QUEUE_LIMIT')?:20)));
            if((int)$this->db->query('SELECT COUNT(*) FROM jarred_reply_jobs WHERE active_conversation_id IS NOT NULL')->fetchColumn()>=$limit)throw new RuntimeException('Jarred reply queue is full. Please try again shortly.',503);
            $now=$this->now();
            $q=$this->db->prepare('INSERT INTO jarred_reply_jobs(user_id,conversation_id,recipient_id,active_conversation_id,prompt_hash,server_id,surface_json,state,lease_until,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
            $q->execute([$uid,$conversation,$recipient,$conversation,hash('sha256',$prompt),$server,json_encode($surface,JSON_THROW_ON_ERROR),'preparing',gmdate('Y-m-d H:i:s',time()+60),$now,$now]);
            $job=(int)$this->db->lastInsertId();$message=(int)$save();
            $this->db->prepare('UPDATE jarred_reply_jobs SET request_message_id=? WHERE id=?')->execute([$message,$job]);
            return $message;
        });
    }
    public function activate(int $uid,int $message): void
    {
        $this->db->prepare("UPDATE jarred_reply_jobs SET state='queued',lease_until=NULL,updated_at=? WHERE user_id=? AND request_message_id=? AND state='preparing'")->execute([$this->now(),$uid,$message]);
    }
    public function latest(int $uid,int $conversation): ?array
    {
        try{$this->transaction(function(){$this->guard();$this->recover();});}catch(RuntimeException $e){if($e->getCode()===503)return null;throw $e;}
        $q=$this->db->prepare('SELECT id,state,request_message_id,reply_message_id,error_code,created_at FROM jarred_reply_jobs WHERE user_id=? AND conversation_id=? ORDER BY id DESC LIMIT 1');$q->execute([$uid,$conversation]);$r=$q->fetch(PDO::FETCH_ASSOC);
        if(!$r)return null;
        return ['id'=>(int)$r['id'],'state'=>$r['state'],'request_message_id'=>(int)$r['request_message_id'],'reply_message_id'=>$r['reply_message_id']===null?null:(int)$r['reply_message_id'],'error_code'=>$r['error_code']===null?null:(int)$r['error_code'],'created_at'=>$r['created_at']];
    }
    private function recover(): void
    {
        $now=$this->now();
        $this->db->prepare("UPDATE jarred_reply_jobs SET state='failed',active_conversation_id=NULL,worker_token=NULL,error_code=504,updated_at=? WHERE (state='preparing' AND lease_until<?) OR (state='queued' AND updated_at<?) OR (state='running' AND lease_until<? AND attempts>=2)")->execute([$now,$now,gmdate('Y-m-d H:i:s',time()-900),$now]);
        $this->db->prepare("UPDATE jarred_reply_jobs SET state='queued',worker_token=NULL,lease_until=NULL,updated_at=? WHERE state='running' AND lease_until<? AND attempts<2")->execute([$now,$now]);
    }
    public function claim(): ?array
    {
        return $this->transaction(function(){
            $this->guard();$this->recover();
            $workers=max(1,min(4,(int)(getenv('JARRED_JOB_WORKERS')?:2)));
            if((int)$this->db->query("SELECT COUNT(*) FROM jarred_reply_jobs WHERE state='running'")->fetchColumn()>=$workers)return null;
            $q=$this->db->query("SELECT * FROM jarred_reply_jobs WHERE state='queued' ORDER BY id LIMIT 1");$job=$q->fetch(PDO::FETCH_ASSOC);if(!$job)return null;
            $token=bin2hex(random_bytes(16));
            $this->db->prepare("UPDATE jarred_reply_jobs SET state='running',attempts=attempts+1,worker_token=?,lease_until=?,updated_at=? WHERE id=? AND state='queued'")->execute([$token,gmdate('Y-m-d H:i:s',time()+360),$this->now(),$job['id']]);
            $job['worker_token']=$token;return $job;
        });
    }
    /** Publish only the current lease, atomically with its assistant message. */
    public function finish(array $job,callable $publish): ?int
    {
        return $this->transaction(function()use($job,$publish){
            $q=$this->db->prepare('SELECT state,worker_token,lease_until FROM jarred_reply_jobs WHERE id=?'.$this->lockSuffix());$q->execute([$job['id']]);$current=$q->fetch(PDO::FETCH_ASSOC);
            if(!$current||$current['state']!=='running'||!hash_equals((string)$current['worker_token'],(string)$job['worker_token'])||$current['lease_until']<$this->now())return null;
            $reply=(int)$publish();
            $this->db->prepare("UPDATE jarred_reply_jobs SET state='done',reply_message_id=?,active_conversation_id=NULL,worker_token=NULL,lease_until=NULL,error_code=NULL,updated_at=? WHERE id=?")->execute([$reply,$this->now(),$job['id']]);return $reply;
        });
    }
    public function direct(int $uid,int $message,callable $publish): int
    {
        return $this->transaction(function()use($uid,$message,$publish){
            $q=$this->db->prepare("SELECT id FROM jarred_reply_jobs WHERE user_id=? AND request_message_id=? AND state='preparing'".$this->lockSuffix());$q->execute([$uid,$message]);$id=(int)$q->fetchColumn();if(!$id)throw new RuntimeException('Jarred turn is unavailable.',409);
            $reply=(int)$publish();$this->db->prepare("UPDATE jarred_reply_jobs SET state='done',reply_message_id=?,active_conversation_id=NULL,lease_until=NULL,updated_at=? WHERE id=?")->execute([$reply,$this->now(),$id]);return $reply;
        });
    }
    public function fail(array $job,int $code=503): void
    {
        $this->db->prepare("UPDATE jarred_reply_jobs SET state='failed',active_conversation_id=NULL,lease_until=NULL,worker_token=NULL,error_code=?,updated_at=? WHERE id=? AND state='running' AND worker_token=?")->execute([$code,$this->now(),$job['id'],$job['worker_token']]);
    }
    public function failPreparing(int $uid,int $message,int $code): void
    {
        $this->db->prepare("UPDATE jarred_reply_jobs SET state='failed',active_conversation_id=NULL,lease_until=NULL,error_code=?,updated_at=? WHERE user_id=? AND request_message_id=? AND state='preparing'")->execute([$code,$this->now(),$uid,$message]);
    }
    /** Retry the same saved question; never insert another user message. Caller rechecks message ownership. */
    public function retry(int $uid,int $conversation,int $message,?string $prompt=null): bool
    {
        return $this->transaction(function()use($uid,$conversation,$message,$prompt){
            $this->guard();$this->recover();
            $q=$this->db->prepare('SELECT id,state,prompt_hash FROM jarred_reply_jobs WHERE user_id=? AND conversation_id=? AND request_message_id=?');$q->execute([$uid,$conversation,$message]);$job=$q->fetch(PDO::FETCH_ASSOC);if(!$job)return false;
            if($job['state']==='done')throw new RuntimeException('This question already has a reply.',409);
            if($job['state']!=='failed')return true;
            $q=$this->db->prepare('SELECT id FROM jarred_reply_jobs WHERE active_conversation_id=?');$q->execute([$conversation]);if($q->fetchColumn())throw new RuntimeException('Jarred is already preparing a reply.',409);
            if((int)$this->db->query('SELECT COUNT(*) FROM jarred_reply_jobs WHERE active_conversation_id IS NOT NULL')->fetchColumn()>=max(5,min(100,(int)(getenv('JARRED_QUEUE_LIMIT')?:20))))throw new RuntimeException('Jarred reply queue is full.',503);
            $this->db->prepare("UPDATE jarred_reply_jobs SET state='queued',active_conversation_id=conversation_id,attempts=0,worker_token=NULL,lease_until=NULL,error_code=NULL,prompt_hash=?,updated_at=? WHERE id=?")->execute([$prompt===null?$job['prompt_hash']:hash('sha256',$prompt),$this->now(),$job['id']]);return true;
        });
    }
}
