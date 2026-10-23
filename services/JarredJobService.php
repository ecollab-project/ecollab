<?php
declare(strict_types=1);
require_once __DIR__.'/RealtimeOutbox.php';
require_once __DIR__.'/UserPreferences.php';
require_once __DIR__.'/AccessPolicy.php';
require_once __DIR__.'/ChannelAccessService.php';
require_once __DIR__.'/DocumentAccessService.php';
final class JarredJobService
{
    public function __construct(private PDO $db) {}
    private function q(string $sql,array $args=[]): PDOStatement { $s=$this->db->prepare($sql);$s->execute($args);return $s; }
    private function lock(): string { return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' FOR UPDATE'; }
    /** Called before the source message insert, inside the API transaction. */
    public function reserveConversation(int $uid,int $cid): void
    {
        if (!$this->db->inTransaction()) throw new LogicException('A transaction is required');
        if (!$this->q('SELECT id FROM jarred_queue_gate WHERE id=1'.$this->lock())->fetchColumn()) throw new RuntimeException('Jarred queue migration is required',503);
        (new AccessPolicy($this->db))->assertAccount($uid);
        if (!$this->q('SELECT id FROM dm_conversations WHERE id=? AND (user_a=? OR user_b=?)'.$this->lock(),[$cid,$uid,$uid])->fetchColumn()) throw new RuntimeException('Conversation unavailable',403);
        if ($this->q("SELECT id FROM jarred_jobs WHERE conversation_id=? AND state IN ('queued','running') LIMIT 1",[$cid])->fetchColumn()) throw new RuntimeException('Jarred is still replying. Wait or cancel the pending reply.',409);
        if ((int)$this->q("SELECT COUNT(*) FROM jarred_jobs WHERE state IN ('queued','running')")->fetchColumn()>=100) throw new RuntimeException('Jarred queue is busy. Try again shortly.',503);
    }
    public function enqueue(int $uid,int $recipient,int $cid,int $source,?int $sid,array $surface): int
    {
        if (!$this->db->inTransaction()) throw new LogicException('A transaction is required');
        $this->q('INSERT INTO jarred_jobs(user_id,recipient_id,conversation_id,source_message_id,active_server_id,surface_json) VALUES(?,?,?,?,?,?)',[$uid,$recipient,$cid,$source,$sid,json_encode($surface,JSON_THROW_ON_ERROR)]);
        return (int)$this->db->lastInsertId();
    }
    private function authorize(array $j): void
    {
        if (!$this->q("SELECT c.id FROM dm_conversations c JOIN users u ON u.id=? AND u.deleted_at IS NULL AND u.status IN ('active','offline','idle')
            JOIN users r ON r.id=? AND r.is_system=1 AND r.username='ecollab_ai' AND r.deleted_at IS NULL
            JOIN dm_messages m ON m.id=? AND m.conversation_id=c.id AND m.sender_id=u.id AND m.is_deleted=0
            WHERE c.id=? AND ((c.user_a=u.id AND c.user_b=r.id) OR (c.user_b=u.id AND c.user_a=r.id))",[$j['user_id'],$j['recipient_id'],$j['source_message_id'],$j['conversation_id']])->fetchColumn()) throw new RuntimeException('Conversation unavailable',403);
        $this->authorizeSurface($j);
        UserPreferences::assertJarred(UserPreferences::get($this->db,(int)$j['user_id']));
    }
    private function authorizeSurface(array $j): void
    {
        $policy=new AccessPolicy($this->db);$uid=(int)$j['user_id'];
        $policy->assertAccount($uid);
        $sid=(int)($j['active_server_id']??0);
        if ($sid && !$policy->server($uid,$sid)) throw new RuntimeException('Server access revoked',403);
        $surface=json_decode($j['surface_json'],true,512,JSON_THROW_ON_ERROR);
        if (!empty($surface['document_id'])) DocumentAccessService::get($this->db,(int)$surface['document_id'],$uid);
        elseif (!empty($surface['whiteboard_id'])) {
            $q=$this->q('SELECT w.channel_id FROM collab_whiteboards b JOIN collab_workspaces w ON w.id=b.workspace_id WHERE b.id=?',[(int)$surface['whiteboard_id']]);
            $cid=(int)$q->fetchColumn();
            if (!CoworkspaceService::resolveWhiteboardAccess($this->db,(int)$surface['whiteboard_id'],$cid,$uid)) throw new RuntimeException('Whiteboard access revoked',403);
        } elseif (!empty($surface['channel_id'])) (new ChannelAccessService($this->db))->read((int)$surface['channel_id'],$uid);
    }

    public function status(int $uid,int $cid,?int $id=null): array
    {
        (new AccessPolicy($this->db))->assertAccount($uid);
        if (!$this->q('SELECT id FROM dm_conversations WHERE id=? AND (user_a=? OR user_b=?)',[$cid,$uid,$uid])->fetchColumn()) throw new RuntimeException('Conversation unavailable',403);
        $j=$this->q('SELECT * FROM jarred_jobs WHERE user_id=? AND conversation_id=?'.($id===null?'':' AND id=?').' ORDER BY id DESC LIMIT 1',$id===null?[$uid,$cid]:[$uid,$cid,$id])->fetch(PDO::FETCH_ASSOC);
        if (!$j) return ['job'=>null];
        $out=['id'=>(int)$j['id'],'state'=>$j['state'],'ai_action'=>null,'ai_message'=>null,'ai_usage'=>null];
        if ($j['state']==='done') {
            $this->authorize($j);
            $out['ai_usage']=$j['usage_json']===null?null:json_decode($j['usage_json'],true);
            $out['ai_action']=$j['action_json']===null?null:json_decode($j['action_json'],true);
            $out['ai_message']=$this->q('SELECT m.id,m.conversation_id,m.sender_id,m.body,m.created_at,u.full_name AS sender_name,u.username AS sender_username,u.avatar_url AS sender_avatar_url,u.avatar_color_gradient AS sender_gradient FROM dm_messages m JOIN users u ON u.id=m.sender_id WHERE m.id=? AND m.is_deleted=0',[$j['response_message_id']])->fetch(PDO::FETCH_ASSOC)?:null;
        }
        return ['job'=>$out];
    }
    public function cancel(int $uid,int $cid,int $id): void
    {
        $this->status($uid,$cid,$id);
        $this->q("UPDATE jarred_jobs SET state='cancelled',finished_at=CURRENT_TIMESTAMP,lease_token=NULL WHERE id=? AND user_id=? AND conversation_id=? AND state IN ('queued','running')",[$id,$uid,$cid]);
    }
    /** Only call while holding the process-wide DB advisory worker lock. */
    public function recover(): void
    {
        $this->q("UPDATE jarred_jobs SET state=CASE WHEN attempts>=3 THEN 'failed' ELSE 'queued' END,lease_token=NULL WHERE state='running'");
    }
    private function saveReply(array $j,string $text,?array $action,?array $usage=null): void
    {
        if ($text==='') throw new RuntimeException('Empty model response');
        $id=RealtimeOutbox::record($this->db,'dm',(int)$j['conversation_id'],function()use($j,$text){
            $this->q('INSERT INTO dm_messages(conversation_id,sender_id,body) VALUES(?,?,?)',[$j['conversation_id'],$j['recipient_id'],$text]);return (int)$this->db->lastInsertId();
        });
        $this->q("UPDATE jarred_jobs SET state='done',response_message_id=?,action_json=?,usage_json=?,finished_at=CURRENT_TIMESTAMP,lease_token=NULL WHERE id=?",[$id,$action===null?null:json_encode($action,JSON_THROW_ON_ERROR),$usage===null?null:json_encode($usage,JSON_THROW_ON_ERROR),$j['id']]);
        $this->q('UPDATE dm_conversations SET last_message=?,last_msg_at=CURRENT_TIMESTAMP WHERE id=?',[mb_substr($text,0,120),$j['conversation_id']]);
    }
    /** Action writes and checkpoint share a transaction; inference never holds one. */
    public function workOne(callable $action,callable $generate): bool
    {
        $this->db->beginTransaction();
        try {
            // created_at uses the database session clock. Never reinterpret it as UTC in PHP.
            $age = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'
                ? "CAST(strftime('%s','now') AS INTEGER)-CAST(strftime('%s',created_at) AS INTEGER)"
                : 'TIMESTAMPDIFF(SECOND,created_at,CURRENT_TIMESTAMP)';
            $j=$this->q("SELECT *, $age AS age_seconds FROM jarred_jobs WHERE state='queued' ORDER BY id LIMIT 1".$this->lock())->fetch(PDO::FETCH_ASSOC);
            if (!$j) { $this->db->commit();return false; }
            $token=bin2hex(random_bytes(16));
            $this->q("UPDATE jarred_jobs SET state='running',attempts=attempts+1,lease_token=? WHERE id=?",[$token,$j['id']]);
            $this->db->commit();
        } catch(Throwable $e) { if($this->db->inTransaction())$this->db->rollBack();throw $e; }
        try {
            $this->authorize($j);
            error_log(json_encode(['metric'=>'jarred_queue','age_seconds'=>max(0,(int)$j['age_seconds']),'attempt'=>(int)$j['attempts']+1]));
            if ((int)$j['age_seconds']>1800) throw new RuntimeException('Queue timeout',408);
            $j['body']=(string)$this->q('SELECT body FROM dm_messages WHERE id=?',[$j['source_message_id']])->fetchColumn();
            if (!(int)$j['action_checked']) {
                $this->db->beginTransaction();
                $owned=$this->q("SELECT id FROM jarred_jobs WHERE id=? AND state='running' AND lease_token=?".$this->lock(),[$j['id'],$token])->fetchColumn();
                if (!$owned) { $this->db->rollBack();return true; }
                $this->authorize($j);
                $result=$action($j);
                $this->authorize($j);
                $this->q('UPDATE jarred_jobs SET action_checked=1 WHERE id=?',[$j['id']]);
                if ($result!==null) $this->saveReply($j,(string)$result['reply'],$result['action']??null);
                $this->db->commit();
                if ($result!==null) return true;
            }
            $started=hrtime(true);$text=$generate($j);
            error_log(json_encode(['metric'=>'jarred_inference','duration_ms'=>round((hrtime(true)-$started)/1e6,2)]));
            $this->db->beginTransaction();
            $owned=$this->q("SELECT id FROM jarred_jobs WHERE id=? AND state='running' AND lease_token=?".$this->lock(),[$j['id'],$token])->fetchColumn();
            if ($owned) { $this->authorize($j);
                if (is_array($text) && isset($text['authorization_stamp'])) {
                    require_once __DIR__.'/JarredTools.php';
                    if (!hash_equals($text['authorization_stamp'],(new JarredTools())->authorizationStamp((int)$j['user_id']))) throw new RuntimeException('Context authorization changed',403);
                }
                $this->saveReply($j,is_array($text)?$text['text']:$text,null,is_array($text)?($text['usage']??null):null); }
            $this->db->commit();
        } catch(Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $terminal=in_array($e->getCode(),[403,408],true)||(int)$j['attempts']>=2;
            $this->q("UPDATE jarred_jobs SET state=?,lease_token=NULL WHERE id=? AND state='running' AND lease_token=?",[$terminal?'failed':'queued',$j['id'],$token]);
            error_log('[jarred_worker] '.($terminal?'failed':'retry'));
        }
        return true;
    }
}
