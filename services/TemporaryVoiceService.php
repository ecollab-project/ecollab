<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/database/config/db.php';

final class TemporaryVoiceService
{
    private PDO $db;
    public function __construct(?PDO $db = null) { $this->db = $db ?? Database::getInstance(); }

    /** All voice writers and deletion share the channel-row lock. Never nest transactions. */
    private function withChannelLock(int $channelId, callable $work): mixed
    {
        if ($this->db->inTransaction()) throw new LogicException('Voice presence requires its own transaction.');
        $this->db->beginTransaction();
        try {
            $suffix = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
            $q = $this->db->prepare('SELECT id FROM channels WHERE id=?'.$suffix);
            $q->execute([$channelId]);
            $exists = (bool)$q->fetchColumn();
            $q->closeCursor();
            $result = $work($exists);
            $this->db->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** Caller supplies the current authorization check, executed under the channel lock. */
    public function join(int $userId, int $channelId, callable $authorize): void
    {
        $old = $this->withChannelLock($channelId, function (bool $exists) use ($userId, $channelId, $authorize): int {
            if (!$exists) throw new RuntimeException('Voice channel access denied', 403);
            $authorize();
            $suffix = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
            $q = $this->db->prepare('SELECT voice_channel_id FROM users WHERE id=?'.$suffix);
            $q->execute([$userId]);
            $old = (int)$q->fetchColumn();
            $this->db->prepare('UPDATE users SET voice_channel_id=? WHERE id=?')->execute([$channelId, $userId]);
            $this->markJoined($channelId);
            return $old;
        });
        if ($old > 0 && $old !== $channelId) $this->markLeftAndSchedule($old);
    }

    /** Compare-and-set prevents a stale leave from clearing a newer, different room. */
    public function leave(int $userId, ?int $expectedChannel = null): void
    {
        if ($expectedChannel === null) {
            $q = $this->db->prepare('SELECT voice_channel_id FROM users WHERE id=?');
            $q->execute([$userId]);
            $expectedChannel = (int)$q->fetchColumn();
        }
        if ($expectedChannel <= 0) return;
        $this->withChannelLock($expectedChannel, function (bool $exists) use ($userId, $expectedChannel): void {
            $this->db->prepare('UPDATE users SET voice_channel_id=NULL WHERE id=? AND voice_channel_id=?')
                ->execute([$userId, $expectedChannel]);
            if ($exists) $this->deleteLockedIfEmpty($expectedChannel);
        });
    }

    public function handleJarredMessage(int $userId, int $conversationId, string $text, ?int $activeServerId): ?array
    {
        $text = trim($text);
        $state = $this->state($userId, $conversationId);

        if (!$state) {
            if (!preg_match('/\b(?:create|make|start|open|set up|setup)\b.*\b(?:temporary|temp)\b.*\b(?:voice|call|room|channel)\b|\b(?:temporary|temp)\b.*\b(?:voice|call|room|channel)\b/i', $text)) return null;
            if (!$activeServerId || !$this->canAccessServer($userId, $activeServerId)) {
                return ['reply'=>'I can create a temporary voice room, but I need you to be inside an eCollab server first.'];
            }
            $this->saveState($userId,$conversationId,'confirm',(int)$activeServerId,[]);
            return ['reply'=>'Sure. Do you want me to create a temporary voice room in this server?'];
        }

        if ($state['step'] === 'confirm') {
            if ($this->isNo($text)) { $this->clearState($userId,$conversationId); return ['reply'=>'No problem — I won\'t create one.']; }
            if (!$this->isYes($text)) return ['reply'=>'Just confirm with yes or no — should I create the temporary voice room?'];
            $this->saveState($userId,$conversationId,'privacy',(int)$state['server_id'],[]);
            return ['reply'=>'Got it. Should the temporary voice room be public or private?'];
        }

        if ($state['step'] === 'privacy') {
            $privacy = preg_match('/\bprivate\b/i',$text) ? 'private' : (preg_match('/\bpublic\b/i',$text) ? 'public' : null);
            if (!$privacy) return ['reply'=>'Choose public or private for the temporary voice room.'];
            if ($privacy === 'private') {
                $this->saveState($userId,$conversationId,'invitees',(int)$state['server_id'],['privacy'=>'private']);
                return ['reply'=>'Private it is. Who would you like to invite? Send their names or usernames.'];
            }
            $room=$this->createRoom($userId,(int)$state['server_id'],'public',[]);
            $this->clearState($userId,$conversationId);
            return ['reply'=>'Done — I created a public temporary voice room. Taking you there now.','action'=>$this->openAction($room)];
        }

        if ($state['step'] === 'invitees') {
            if (preg_match('/\b(?:none|no one|nobody|skip)\b/i',$text)) $invitees=[];
            else {
                $invitees=$this->resolveInvitees($userId,(int)$state['server_id'],$text);
                if (!$invitees) return ['reply'=>'I couldn\'t match those names to members of this server. Try their full name or username, or say “skip” to create it without invitations.'];
            }
            $room=$this->createRoom($userId,(int)$state['server_id'],'private',$invitees);
            $this->clearState($userId,$conversationId);
            $names=array_column($invitees,'name');
            $reply=$names ? 'Done — I created the private temporary voice room and invited '.implode(', ',$names).'. Taking you there now.' : 'Done — I created the private temporary voice room. Taking you there now.';
            return ['reply'=>$reply,'action'=>$this->openAction($room)];
        }
        return null;
    }

    private function createRoom(int $userId,int $serverId,string $privacy,array $invitees): array
    {
        if (!$this->canAccessServer($userId,$serverId)) throw new RuntimeException('You no longer have access to this server.');
        $name='Temporary Room';
        $base='temp-voice-'.$userId.'-'.time();
        $slug=substr($base,0,60);
        $pos=$this->db->prepare("SELECT COALESCE(MAX(position),0)+1 FROM channels WHERE server_id=?");
        $pos->execute([$serverId]);
        $this->db->beginTransaction();
        try {
            $ins=$this->db->prepare("INSERT INTO channels (server_id,name,slug,type,description,position,is_private,created_by,owner_id,visibility)
                VALUES (?,?,?,?,?,?,?,?,?,?)");
            $ins->execute([$serverId,$name,$slug,'voice','Temporary voice room created by Jarred',(int)$pos->fetchColumn(),$privacy==='private'?1:0,$userId,$userId,$privacy]);
            $channelId=(int)$this->db->lastInsertId();
            $this->db->prepare("INSERT INTO temporary_voice_rooms(channel_id,server_id,owner_id,privacy) VALUES(?,?,?,?)")
                ->execute([$channelId,$serverId,$userId,$privacy]);
            if ($privacy==='private') {
                $member=$this->db->prepare("INSERT IGNORE INTO channel_members(channel_id,user_id) VALUES(?,?)");
                $member->execute([$channelId,$userId]);
                foreach($invitees as $invitee) $member->execute([$channelId,(int)$invitee['id']]);
            }
            $this->db->commit();
        } catch(Throwable $e) { if($this->db->inTransaction())$this->db->rollBack(); throw $e; }

        foreach($invitees as $invitee) {
            try {
                $this->db->prepare("INSERT INTO notifications(recipient_id,actor_id,type,title,body,link_url,icon,is_read)
                    VALUES(?,?,'temporary_voice_invite','Temporary voice invitation',?,?,'🔊',0)")
                    ->execute([(int)$invitee['id'],$userId,'You were invited to a private temporary voice room.','/modules/chat/chat.php?temp_voice='.$channelId]);
            } catch(Throwable $e) { error_log('[TemporaryVoiceService] invite notification skipped: '.$e->getMessage()); }
        }
        return ['id'=>$channelId,'slug'=>$slug,'name'=>$name,'privacy'=>$privacy];
    }

    public function markJoined(int $channelId): void
    {
        $this->db->prepare("UPDATE temporary_voice_rooms SET empty_since=NULL,expires_at=NULL WHERE channel_id=?")->execute([$channelId]);
    }

    public function markLeftAndSchedule(int $channelId): void
    {
        if ($channelId <= 0) return;
        $this->withChannelLock($channelId, function (bool $exists) use ($channelId): void {
            if ($exists) $this->deleteLockedIfEmpty($channelId);
        });
    }

    /** Channel lock must be held before occupancy is read; join uses the same lock. */
    private function deleteLockedIfEmpty(int $channelId): bool
    {
        $q = $this->db->prepare('SELECT 1 FROM temporary_voice_rooms WHERE channel_id=?');
        $q->execute([$channelId]);
        if (!$q->fetchColumn()) return false;
        $q = $this->db->prepare('SELECT 1 FROM users WHERE voice_channel_id=? LIMIT 1');
        $q->execute([$channelId]);
        if ($q->fetchColumn()) return false;
        $this->db->prepare('DELETE FROM channel_members WHERE channel_id=?')->execute([$channelId]);
        $this->db->prepare('DELETE FROM temporary_voice_rooms WHERE channel_id=?')->execute([$channelId]);
        $this->db->prepare('DELETE FROM channels WHERE id=?')->execute([$channelId]);
        return true;
    }

    /** CLI maintenance only; bounded batches and a fresh expiry check under the lock. */
    public function cleanupExpired(int $limit = 100): void
    {
        $limit = max(1, min(1000, $limit));
        $q = $this->db->query("SELECT channel_id FROM temporary_voice_rooms WHERE expires_at IS NOT NULL AND expires_at<=CURRENT_TIMESTAMP ORDER BY channel_id LIMIT $limit");
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $id = (int)$id;
            $this->withChannelLock($id, function (bool $exists) use ($id): void {
                if (!$exists) return;
                $q = $this->db->prepare('SELECT 1 FROM temporary_voice_rooms WHERE channel_id=? AND expires_at<=CURRENT_TIMESTAMP');
                $q->execute([$id]);
                if (!$q->fetchColumn()) return; // A join may have cleared expiry since selection.
                if (!$this->deleteLockedIfEmpty($id)) $this->markJoined($id);
            });
        }
        $this->db->exec("DELETE FROM jarred_pending_actions WHERE expires_at<=CURRENT_TIMESTAMP LIMIT $limit");
    }

    private function resolveInvitees(int $userId,int $serverId,string $text): array
    {
        $tokens=array_values(array_filter(array_map('trim',preg_split('/\s*(?:,|\band\b|&)\s*/i',$text))));
        $out=[];$seen=[];
        foreach($tokens as $token){
            $token=preg_replace('/^(?:invite|add)\s+/i','',$token);
            if(!$token)continue;
            $s=$this->db->prepare("SELECT u.id,COALESCE(NULLIF(u.full_name,''),u.username) name,u.username FROM users u JOIN server_members sm ON sm.user_id=u.id AND sm.server_id=:sid WHERE u.id<>:self AND COALESCE(u.is_system,0)=0 AND (LOWER(u.username)=LOWER(:exact) OR LOWER(u.full_name)=LOWER(:exact2) OR u.full_name LIKE :likeq OR u.username LIKE :likeq2) ORDER BY CASE WHEN LOWER(u.username)=LOWER(:exact3) OR LOWER(u.full_name)=LOWER(:exact4) THEN 0 ELSE 1 END LIMIT 1");
            $like='%'.$token.'%';$s->execute([':sid'=>$serverId,':self'=>$userId,':exact'=>$token,':exact2'=>$token,':likeq'=>$like,':likeq2'=>$like,':exact3'=>$token,':exact4'=>$token]);
            $r=$s->fetch(PDO::FETCH_ASSOC);if($r&&!isset($seen[$r['id']])){$seen[$r['id']]=1;$out[]=['id'=>(int)$r['id'],'name'=>$r['name'],'username'=>$r['username']];}
        }
        return $out;
    }

    private function openAction(array $room): array { return ['type'=>'open_temporary_voice','channel_id'=>$room['id'],'channel_slug'=>$room['slug'],'channel_name'=>$room['name'],'privacy'=>$room['privacy']]; }
    private function canAccessServer(int $uid,int $sid): bool {$s=$this->db->prepare("SELECT 1 FROM server_members WHERE server_id=? AND user_id=? LIMIT 1");$s->execute([$sid,$uid]);return(bool)$s->fetchColumn();}
    private function isYes(string $s): bool {return(bool)preg_match('/^(?:yes|yeah|yep|yup|sure|ok|okay|go|do it|please)$/i',trim($s));}
    private function isNo(string $s): bool {return(bool)preg_match('/^(?:no|nope|nah|cancel|never mind|nevermind)$/i',trim($s));}
    private function state(int $uid,int $cid): ?array {$s=$this->db->prepare("SELECT * FROM jarred_pending_actions WHERE user_id=? AND conversation_id=? AND action_type='create_temp_voice' AND expires_at>NOW() LIMIT 1");$s->execute([$uid,$cid]);$r=$s->fetch(PDO::FETCH_ASSOC);return$r?:null;}
    private function saveState(int $uid,int $cid,string $step,int $sid,array $payload): void {$this->db->prepare("INSERT INTO jarred_pending_actions(user_id,conversation_id,action_type,step,server_id,payload_json,expires_at) VALUES(?,?,'create_temp_voice',?,?,?,DATE_ADD(NOW(),INTERVAL 15 MINUTE)) ON DUPLICATE KEY UPDATE step=VALUES(step),server_id=VALUES(server_id),payload_json=VALUES(payload_json),expires_at=VALUES(expires_at)")->execute([$uid,$cid,$step,$sid,json_encode($payload)]);}
    private function clearState(int $uid,int $cid): void {$this->db->prepare("DELETE FROM jarred_pending_actions WHERE user_id=? AND conversation_id=?")->execute([$uid,$cid]);}
}
