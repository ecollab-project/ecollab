<?php
declare(strict_types=1);
require_once __DIR__.'/AccessPolicy.php';
require_once __DIR__.'/ChannelAccessService.php';

/** Reconcile transport state against current DB policy. Never relies on token expiry. */
final class RealtimeRevocationService
{
    private Closure $roomApi;
    private Closure $disconnect;
    private int $roomCursor=0;
    private int $userCursor=0;
    public function __construct(private PDO $db, callable $roomApi, callable $disconnect)
    {
        $this->roomApi=Closure::fromCallable($roomApi);
        $this->disconnect=Closure::fromCallable($disconnect);
    }

    public function roomAllowed(string $room,int $uid): bool
    {
        if(!(new AccessPolicy($this->db))->account($uid))return false;
        if(preg_match('/^ecollab-channel-([1-9][0-9]*)$/D',$room,$m)){
            try{(new ChannelAccessService($this->db))->voice((int)$m[1],$uid);return true;}
            catch(RuntimeException $e){if($e->getCode()!==403)throw $e;return false;}
        }
        if(preg_match('/^ecollab-dm-group-v2-([1-9][0-9]*)$/D',$room,$m)){
            $q=$this->db->prepare('SELECT 1 FROM dm_groups g JOIN dm_group_members gm ON gm.group_id=g.id WHERE g.id=? AND gm.user_id=?');
            $q->execute([(int)$m[1],$uid]);return (bool)$q->fetchColumn();
        }
        if(preg_match('/^ecollab-dm-call-([1-9][0-9]*)$/D',$room,$m)){
            $fresh=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite' ? "datetime(CURRENT_TIMESTAMP,'-90 seconds')" : 'DATE_SUB(CURRENT_TIMESTAMP,INTERVAL 90 SECOND)';
            $q=$this->db->prepare("SELECT 1 FROM dm_call_history WHERE id=? AND group_id IS NULL AND (caller_id=? OR callee_id=?) AND (status='answered' OR (status='ringing' AND started_at>$fresh))");
            $q->execute([(int)$m[1],$uid,$uid]);return (bool)$q->fetchColumn();
        }
        return false;
    }

    /** Bounded work per cycle; retries rescan all rooms/users over subsequent cycles. */
    public function reconcile(): array
    {
        $counts=['broker_disconnects'=>0,'media_removals'=>0,'rooms_checked'=>0,'failures'=>0];
        $q=$this->db->prepare('SELECT u.id FROM users u WHERE u.id>? AND NOT COALESCE(('.AccessPolicy::accountSql().'),0) ORDER BY u.id LIMIT 100');
        $q->execute([$this->userCursor]);$users=$q->fetchAll(PDO::FETCH_COLUMN);
        if(!$users)$this->userCursor=0;
        foreach($users as $uid){try{($this->disconnect)((int)$uid);$counts['broker_disconnects']++;}catch(Throwable){$counts['failures']++;}$this->userCursor=(int)$uid;}
        $rooms=($this->roomApi)('ListRooms',[],['roomList'=>true]);
        if(!isset($rooms['rooms'])||!is_array($rooms['rooms']))throw new RuntimeException('Invalid room-list response');
        $rooms=array_values(array_filter($rooms['rooms']??[],static fn($r)=>is_array($r)&&preg_match('/^ecollab-(?:channel-[1-9][0-9]*|dm-group-v2-[1-9][0-9]*|dm-call-[1-9][0-9]*)$/D',(string)($r['name']??''))));
        usort($rooms,static fn($a,$b)=>strcmp($a['name'],$b['name']));
        if($this->roomCursor>=count($rooms))$this->roomCursor=0;
        $batch=array_slice($rooms,$this->roomCursor,25);$this->roomCursor+=count($batch);
        foreach($batch as $room){
            $name=$room['name'];
            try{$participants=($this->roomApi)('ListParticipants',['room'=>$name],['roomAdmin'=>true,'room'=>$name]);}
            catch(Throwable){$counts['failures']++;continue;}
            if(!isset($participants['participants'])||!is_array($participants['participants'])){$counts['failures']++;continue;}
            foreach($participants['participants']??[] as $participant){
                $identity=(string)($participant['identity']??'');
                $uid=preg_match('/^user-([1-9][0-9]*)$/D',$identity,$m)?(int)$m[1]:0;
                if($uid>0&&$this->roomAllowed($name,$uid))continue;
                try{($this->roomApi)('RemoveParticipant',['room'=>$name,'identity'=>$identity],['roomAdmin'=>true,'room'=>$name]);$counts['media_removals']++;}
                catch(Throwable){$counts['failures']++;}
            }
            $counts['rooms_checked']++;
        }
        return $counts;
    }
}
