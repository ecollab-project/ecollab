<?php
declare(strict_types=1);
require_once __DIR__.'/UserPreferences.php';

/** Bounded, aggregate signals. Neither message bodies nor student source code are read. */
final class PeerBehaviorService
{
    private const PAGES = ['chat','library','code_lab','collaboration','dashboard'];
    public function __construct(private PDO $db) {}
    private static function missing(PDOException $e): bool
    {
        return $e->getCode() === '42S02' || str_contains($e->getMessage(), 'no such table:');
    }
    public function allowed(int $uid): bool
    {
        $q=$this->db->prepare("SELECT 1 FROM users WHERE id=? AND status NOT IN ('banned','suspended','deactivated') AND deleted_at IS NULL AND is_system=0");
        $q->execute([$uid]);
        return (bool)$q->fetchColumn() && (int)UserPreferences::get($this->db,$uid)['ai_matching']===1;
    }
    /** At most one contribution per page category per UTC day, regardless of clicks. */
    public function record(int $uid,string $page): bool
    {
        if(!in_array($page,self::PAGES,true)) throw new InvalidArgumentException('Unknown activity category');
        if(!$this->allowed($uid)) return false;
        try {
            $sql=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'
                ? 'INSERT OR IGNORE INTO peer_activity_days (user_id,feature,event_day) VALUES (?,?,?)'
                : 'INSERT IGNORE INTO peer_activity_days (user_id,feature,event_day) VALUES (?,?,?)';
            $this->db->prepare($sql)->execute([$uid,'page:'.$page,gmdate('Y-m-d')]);
            return true;
        } catch(PDOException $e) { if(self::missing($e))return false; throw $e; }
    }
    public function profile(int $uid): ?array
    {
        if(!$this->allowed($uid))return null;
        try {
            $q=$this->db->prepare('SELECT features_json,event_count,refreshed_at FROM peer_behavior_profiles WHERE user_id=?');
            $q->execute([$uid]);$r=$q->fetch(PDO::FETCH_ASSOC);
            if(!$r || (int)$r['event_count']<5 || strtotime($r['refreshed_at'].' UTC')<time()-14*86400)return null;
            $f=json_decode($r['features_json'],true);
            return is_array($f)?['features'=>$f,'event_count'=>(int)$r['event_count']]:null;
        } catch(PDOException $e) { if(self::missing($e))return null; throw $e; }
    }
    public static function similarity(?array $a,?array $b): ?float
    {
        if(!$a||!$b||($a['event_count']??0)<5||($b['event_count']??0)<5)return null;
        $dot=0.;$aa=0.;$bb=0.;
        foreach($a['features'] as $k=>$v){$v=max(0.,(float)$v);$aa+=$v*$v;$dot+=$v*max(0.,(float)($b['features'][$k]??0));}
        foreach($b['features'] as $v){$v=max(0.,(float)$v);$bb+=$v*$v;}
        return $aa>0&&$bb>0 ? min(100.,100.*$dot/sqrt($aa*$bb)):null;
    }
    public function clear(int $uid): void
    {
        foreach(['peer_activity_days','peer_behavior_profiles','recommendation_book_events'] as $table){
            try{$this->db->prepare("DELETE FROM $table WHERE user_id=?")->execute([$uid]);}
            catch(PDOException $e){if(!self::missing($e))throw $e;}
        }
    }
    /** Rebuild from a rolling 90-day window. Caller holds the process/job lock. */
    public function refresh(): array
    {
        $since=gmdate('Y-m-d H:i:s',time()-90*86400);$now=gmdate('Y-m-d H:i:s');$vectors=[];$counts=[];
        $add=function(int $uid,string $key,int $n,bool $countEvent=true)use(&$vectors,&$counts):void{
            if($n<=0)return;$n=min(90,$n);$vectors[$uid][$key]=min(90,($vectors[$uid][$key]??0)+$n);if($countEvent)$counts[$uid]=($counts[$uid]??0)+$n;
        };
        $this->db->beginTransaction();
        try{
            $this->db->prepare('DELETE FROM peer_activity_days WHERE event_day<?')->execute([substr($since,0,10)]);
            // Only opted-in, active accounts enter snapshots. Each source query is filtered again below.
            $eligible=[];
            $q=$this->db->query("SELECT u.id FROM users u LEFT JOIN user_settings s ON s.user_id=u.id WHERE u.status NOT IN ('banned','suspended','deactivated') AND u.deleted_at IS NULL AND u.is_system=0 AND COALESCE(s.ai_matching,1)=1");
            foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id)$eligible[(int)$id]=true;
            $q=$this->db->query('SELECT user_id,feature,COUNT(*) n FROM peer_activity_days GROUP BY user_id,feature');
            foreach($q as $r)if(isset($eligible[(int)$r['user_id']]))$add((int)$r['user_id'],$r['feature'],(int)$r['n']);
            // Public channel names are topics; only daily participation counts are read, never content.
            $q=$this->db->prepare("SELECT m.sender_id user_id,c.name,COUNT(DISTINCT DATE(m.created_at)) n FROM messages m JOIN channels c ON c.id=m.channel_id JOIN servers s ON s.id=c.server_id AND s.status='active' JOIN server_members sm ON sm.server_id=c.server_id AND sm.user_id=m.sender_id WHERE c.is_private=0 AND m.content_type='text' AND m.is_deleted=0 AND m.created_at>=? GROUP BY m.sender_id,c.name");$q->execute([$since]);
            foreach($q as $r)if(isset($eligible[(int)$r['user_id']]))$add((int)$r['user_id'],'topic:'.mb_strtolower(mb_substr(trim($r['name']),0,80)),(int)$r['n']);
            $q=$this->db->prepare("SELECT a.user_id,t.language,a.score FROM code_lab_attempts a JOIN code_lab_tasks t ON t.id=a.task_id WHERE a.submitted_at>=? AND a.score IS NOT NULL AND a.score>=0 AND a.score<=100");$q->execute([$since]);
            $tasks=[];
            foreach($q as $r)if(isset($eligible[(int)$r['user_id']])){
                $key='task:'.$r['language'].':'.((float)$r['score']>=80?'proficient':((float)$r['score']>=50?'developing':'practicing'));
                $tasks[(int)$r['user_id']][$key]=($tasks[(int)$r['user_id']][$key]??0)+1;
            }
            foreach($tasks as $uid=>$features)foreach($features as $key=>$n)$add($uid,$key,$n);
            // Book events already require the matching preference and a server-issued offer.
            try{
                $this->db->prepare('DELETE FROM recommendation_book_events WHERE created_at<?')->execute([$since]);
                $users=$this->db->query('SELECT DISTINCT user_id FROM recommendation_book_events')->fetchAll(PDO::FETCH_COLUMN);
                foreach($users as $uid)if(!isset($eligible[(int)$uid]))$this->db->prepare('DELETE FROM recommendation_book_events WHERE user_id=?')->execute([$uid]);
                $q=$this->db->prepare('SELECT e.user_id,b.features_json,COUNT(*) n FROM recommendation_book_events e JOIN recommendation_books b ON b.work_key=e.work_key WHERE e.created_at>=? GROUP BY e.user_id,b.work_key,b.features_json');$q->execute([$since]);
                foreach($q as $r)if(isset($eligible[(int)$r['user_id']])){
                    $features=json_decode($r['features_json'],true)?:[];
                    foreach(array_slice($features,0,12) as $key)if(is_string($key))$add((int)$r['user_id'],'book:'.mb_substr($key,0,80),(int)$r['n'],false);
                    $uid=(int)$r['user_id'];$counts[$uid]=($counts[$uid]??0)+min(90,(int)$r['n']);
                }
            }catch(PDOException $e){if(!self::missing($e))throw $e;}
            $this->db->exec('DELETE FROM peer_behavior_profiles');
            $insert=$this->db->prepare('INSERT INTO peer_behavior_profiles (user_id,features_json,event_count,refreshed_at) VALUES (?,?,?,?)');
            foreach($vectors as $uid=>$features)$insert->execute([$uid,json_encode(array_map(fn($n)=>log(1+$n),$features),JSON_THROW_ON_ERROR),$counts[$uid],$now]);
            // Opt-outs also remove their previously collected clicks.
            $q=$this->db->query('SELECT DISTINCT user_id FROM peer_activity_days');
            foreach($q->fetchAll(PDO::FETCH_COLUMN) as $uid)if(!isset($eligible[(int)$uid]))$this->db->prepare('DELETE FROM peer_activity_days WHERE user_id=?')->execute([$uid]);
            $this->db->commit();return ['profiles'=>count($vectors),'window_days'=>90];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
