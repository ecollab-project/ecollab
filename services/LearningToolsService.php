<?php
declare(strict_types=1);
require_once __DIR__.'/../database/config/db.php';
final class LearningToolsService {
    public function __construct(private ?PDO $db=null){$this->db??=Database::getInstance();}
    private function rows(string $sql,array $p=[]):array{$q=$this->db->prepare($sql);foreach(array_values($p)as $i=>$v)$q->bindValue($i+1,$v,is_int($v)?PDO::PARAM_INT:($v===null?PDO::PARAM_NULL:PDO::PARAM_STR));$q->execute();return $q->fetchAll(PDO::FETCH_ASSOC);}
    private function lock():string{return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';}
    private function owner(int $uid,int $sid,bool $lock=false):array{$s=$this->rows('SELECT * FROM servers WHERE id=? AND owner_id=? AND status<>\'archived\''.($lock?$this->lock():''),[$sid,$uid])[0]??null;if(!$s)throw new RuntimeException('Choose a space you own.',403);return $s;}
    private function monitorServer(int $uid,int $sid):array{
        $s=$this->rows('SELECT id,name,owner_id FROM servers WHERE id=?',[$sid])[0]??null;
        if(!$s)throw new RuntimeException('Space not found.',404);
        if((int)$s['owner_id']===$uid)return $s;
        $allowed=$this->rows("SELECT s.id FROM servers s LEFT JOIN subject_classes sc ON sc.server_id=s.id AND sc.facilitator_id=? LEFT JOIN server_members sm ON sm.server_id=s.id AND sm.user_id=? WHERE s.id=? AND (sc.facilitator_id IS NOT NULL OR sm.server_role IN ('owner','admin','moderator')) LIMIT 1",[$uid,$uid,$sid]);
        if(!$allowed)throw new RuntimeException('You cannot monitor this space.',403);return $s;
    }
    private function now():string{return $this->rows('SELECT CURRENT_TIMESTAMP value')[0]['value'];}
    private function offset():int{return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?(int)$this->rows('SELECT TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),NOW()) value')[0]['value']:0;}
    public static function revision(array $r):string{return hash('sha256',json_encode($r,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE));}
    private function reportRevision(array $r):string{return self::revision(array_map(fn($k)=>$r[$k],['id','status','reported_user_id','message_id','reason','description','message_content','target_role']));}
    private function report(int $id,int $sid,bool $lock=false):?array{return $this->rows('SELECT r.id,r.status,r.reported_user_id,r.message_id,r.reason,r.description,m.content message_content,u.role target_role FROM content_reports r LEFT JOIN messages m ON m.id=r.message_id LEFT JOIN users u ON u.id=r.reported_user_id WHERE r.id=? AND r.server_id=?'.($lock?$this->lock():''),[$id,$sid])[0]??null;}
    public function view(int $uid,bool $fac,string $view,int $sid):array{
        if($view==='profile'){$p=$this->rows('SELECT u.full_name,u.bio,u.username,up.year_level,up.study_style,up.primary_goal,up.weekly_goal_hours,ap.name program_name FROM users u LEFT JOIN user_profiles up ON up.user_id=u.id LEFT JOIN academic_programs ap ON ap.id=up.academic_program_id WHERE u.id=? AND u.deleted_at IS NULL',[$uid])[0]??null;if(!$p)throw new RuntimeException('Profile not found.',404);return ['profile'=>$p];}
        if($view==='workspace'){
            if($fac)$this->owner($uid,$sid);
            $access="(s.owner_id=? OR EXISTS(SELECT 1 FROM server_members sm WHERE sm.server_id=s.id AND sm.user_id=? AND (c.is_private=0 OR sm.server_role IN ('owner','admin') OR c.created_by=? OR EXISTS(SELECT 1 FROM channel_members cm WHERE cm.channel_id=c.id AND cm.user_id=?))))";
            $where=$fac?'s.owner_id=? AND s.id=?':$access;$p=$fac?[$uid,$sid]:[$uid,$uid,$uid,$uid];
            $parts=[];foreach(['collab_documents'=>'document','collab_whiteboards'=>'whiteboard']as $table=>$kind)$parts[]="SELECT d.id,d.title,'$kind' kind,w.id workspace_id,c.id channel_id,c.server_id,s.name server_name,d.updated_at FROM $table d JOIN collab_workspaces w ON w.id=d.workspace_id JOIN channels c ON c.id=w.channel_id JOIN servers s ON s.id=c.server_id WHERE d.visibility='public' AND w.visibility='public' AND w.archived=0 AND $where";
            return ['items'=>$this->rows(implode(' UNION ALL ',$parts).' ORDER BY updated_at DESC LIMIT 200',[...$p,...$p])];
        }
        if(!$fac)throw new RuntimeException('Facilitator access required.',403);$s=$view==='monitor'?$this->monitorServer($uid,$sid):$this->owner($uid,$sid);
        if($view==='settings'){return ['settings'=>['id'=>$sid,'name'=>$s['name'],'description'=>$s['description'],'visibility'=>$s['visibility']??$s['type'],'lock_channels'=>!$this->rows("SELECT id FROM channels WHERE server_id=? AND type<>'announcement' AND is_locked=0 LIMIT 1",[$sid]),'revision'=>self::revision([$s['id'],$s['name'],$s['description'],$s['visibility']??$s['type']])]];}
        if($view==='reports'){$rows=$this->rows('SELECT r.id,r.status,r.reported_user_id,r.message_id,r.reason,r.description,m.content message_content,u.role target_role,r.created_at,r.resolution_note,COALESCE(u.full_name,u.username) reported_name FROM content_reports r LEFT JOIN users u ON u.id=r.reported_user_id LEFT JOIN messages m ON m.id=r.message_id WHERE r.server_id=? AND (r.reported_user_id IS NULL OR r.reported_user_id<>?) ORDER BY r.created_at DESC,r.id DESC LIMIT 200',[$sid,$uid]);foreach($rows as &$r){$r['revision']=$this->reportRevision($r);$r['can_moderate']=$r['reported_user_id']&&$r['reported_user_id']!=$uid&&!in_array($r['target_role'],['admin','super_admin','moderator'],true);}unset($r);return ['reports'=>$rows];}
        if($view==='banned'){$rows=$this->rows("SELECT ma.id,ma.target_user_id,ma.reason,ma.created_at,ma.expires_at,u.full_name,u.username FROM moderation_actions ma JOIN users u ON u.id=ma.target_user_id WHERE ma.server_id=? AND ma.action_type='ban' AND ma.is_active=1 AND (ma.expires_at IS NULL OR ma.expires_at>CURRENT_TIMESTAMP) AND NOT EXISTS(SELECT 1 FROM moderation_actions newer WHERE newer.server_id=ma.server_id AND newer.target_user_id=ma.target_user_id AND newer.action_type='ban' AND newer.is_active=1 AND newer.id>ma.id AND (newer.expires_at IS NULL OR newer.expires_at>CURRENT_TIMESTAMP)) ORDER BY ma.created_at DESC LIMIT 200",[$sid]);foreach($rows as &$r)$r['revision']=self::revision($r);unset($r);return ['users'=>$rows];}
        if($view==='monitor'){
            $offset=$this->offset();$now=(new DateTimeImmutable($this->now(),new DateTimeZone('UTC')))->modify(sprintf('%+d seconds',-$offset));$local=$now->setTimezone(new DateTimeZone('Asia/Manila'));$start=$local->setTime(0,0)->modify('-29 days')->setTimezone(new DateTimeZone('UTC'))->modify(sprintf('%+d seconds',$offset))->format('Y-m-d H:i:s');$shift=28800-$offset;$day=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?"DATE(DATE_ADD(m.created_at,INTERVAL $shift SECOND))":"date(m.created_at,'$shift seconds')";
            $actual=$this->rows("SELECT $day day,COUNT(*) messages,COUNT(DISTINCT CASE WHEN m.sender_id<>? THEN m.sender_id END) contributors FROM messages m JOIN channels c ON c.id=m.channel_id WHERE c.server_id=? AND m.is_deleted=0 AND m.created_at>=? GROUP BY $day ORDER BY day",[(int)$s['owner_id'],$sid,$start]);$map=array_column($actual,null,'day');$daily=[];for($i=29;$i>=0;$i--){$key=$local->modify('-'.$i.' days')->format('Y-m-d');$daily[]=$map[$key]??['day'=>$key,'messages'=>0,'contributors'=>0];}return ['daily'=>$daily,'server_name'=>$s['name'],'can_manage'=>(int)$s['owner_id']===$uid,'timezone'=>'Asia/Manila'];
        }
        throw new InvalidArgumentException('Unknown learning tool.');
    }
    public function transport(array $data):array{
        $offset=$this->offset();$walk=function(array $rows)use(&$walk,$offset):array{foreach($rows as $key=>&$value){if(is_array($value))$value=$walk($value);elseif(is_string($value)&&in_array($key,['created_at','updated_at','expires_at'],true)&&$value!=='')$value=(new DateTimeImmutable($value,new DateTimeZone('UTC')))->modify(sprintf('%+d seconds',-$offset))->format('Y-m-d\TH:i:s\Z');}unset($value);return $rows;};return $walk($data);
    }
    public function mutate(int $uid,bool $fac,string $action,array $b):array{
        if($action==='profile'){
            $name=trim((string)($b['full_name']??''));$bio=trim((string)($b['bio']??''));$style=(string)($b['study_style']??'');$goal=(string)($b['primary_goal']??'');$year=(int)($b['year_level']??0);
            if($name===''||preg_match_all('/./us',$name)>80||strlen($bio)>2000||!in_array($style,['','solo','group','mixed'],true)||!in_array($goal,['','pass_exams','build_projects','find_study_partners','improve_skills','network_collaborate'],true)||$year<0||$year>8)throw new InvalidArgumentException('Enter valid profile and study preferences.');
            $this->db->beginTransaction();try{$this->db->prepare('UPDATE users SET full_name=?,bio=? WHERE id=?')->execute([$name,$bio,$uid]);if(!$this->rows('SELECT user_id FROM user_profiles WHERE user_id=?',[$uid]))$this->db->prepare('INSERT INTO user_profiles(user_id) VALUES(?)')->execute([$uid]);$this->db->prepare('UPDATE user_profiles SET bio=?,study_style=?,primary_goal=?,year_level=? WHERE user_id=?')->execute([$bio,$style?:null,$goal?:null,$year?:null,$uid]);$this->db->commit();return ['full_name'=>$name];}catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        }
        if(!$fac)throw new RuntimeException('Facilitator access required.',403);$sid=(int)($b['server_id']??0);$this->db->beginTransaction();try{$s=$this->owner($uid,$sid,true);
            if($action==='settings'){
                $name=trim((string)($b['name']??''));$desc=trim((string)($b['description']??''));$v=(string)($b['visibility']??'private');if($name===''||strlen($name)>120||strlen($desc)>4000||!in_array($v,['public','private'],true))throw new InvalidArgumentException('Enter a space name, description, and valid visibility.');
                if(!hash_equals(self::revision([$s['id'],$s['name'],$s['description'],$s['visibility']??$s['type']]),(string)($b['revision']??'')))throw new RuntimeException('This space changed. Refresh before saving.',409);
                $column=array_key_exists('visibility',$s)?'visibility':'type';$this->db->prepare('UPDATE servers SET name=?,description=?,'.$column.'=? WHERE id=? AND owner_id=?')->execute([$name,$desc,$v,$sid,$uid]);if(isset($b['lock_channels']))$this->db->prepare("UPDATE channels SET is_locked=? WHERE server_id=? AND type<>'announcement'")->execute([!empty($b['lock_channels'])?1:0,$sid]);$this->db->prepare("UPDATE channels SET is_locked=1 WHERE server_id=? AND type='announcement'")->execute([$sid]);
            }elseif($action==='review_report'){
                $r=$this->report((int)($b['report_id']??0),$sid,true);if(!$r)throw new RuntimeException('Report not found.',404);if((int)$r['reported_user_id']===$uid)throw new RuntimeException('You cannot review a report about yourself.',403);if(!in_array($r['status'],['pending','reviewing'],true)||!hash_equals( $this->reportRevision($r),(string)($b['revision']??'')))throw new RuntimeException('This report or its evidence changed. Refresh first.',409);
                $kind=(string)($b['kind']??'');$reason=trim((string)($b['reason']??''));if(!in_array($kind,['dismiss','resolve','mute','suspend','ban'],true)||strlen($reason)>2000)throw new InvalidArgumentException('Choose a valid review action and note.');
                if(in_array($kind,['mute','suspend','ban'],true)){
                    $target=(int)$r['reported_user_id'];if(!$target||$target===$uid||in_array($r['target_role'],['admin','super_admin','moderator'],true))throw new RuntimeException('This account is protected from this moderation action.',403);
                    if(!$this->rows('SELECT user_id FROM server_members WHERE server_id=? AND user_id=?',[$sid,$target]))throw new RuntimeException('This account is no longer a member. Refresh the report.',409);if($reason==='')throw new InvalidArgumentException('A moderation reason is required.');
                    $minutes=$kind==='ban'?null:1440;$expires=$minutes?(new DateTimeImmutable($this->now()))->modify('+24 hours')->format('Y-m-d H:i:s'):null;
                    $this->db->prepare('UPDATE moderation_actions SET is_active=0 WHERE server_id=? AND target_user_id=? AND action_type=? AND is_active=1')->execute([$sid,$target,$kind]);
                    $this->db->prepare('INSERT INTO moderation_actions(moderator_id,target_user_id,server_id,action_type,reason,duration_mins,expires_at,is_active) VALUES(?,?,?,?,?,?,?,1)')->execute([$uid,$target,$sid,$kind,$reason,$minutes,$expires]);
                    if($kind==='ban'){$this->db->prepare('DELETE FROM server_members WHERE server_id=? AND user_id=?')->execute([$sid,$target]);$this->db->prepare('UPDATE servers SET member_count=(SELECT COUNT(*) FROM server_members WHERE server_id=?) WHERE id=?')->execute([$sid,$sid]);}
                }
                $this->db->prepare('UPDATE content_reports SET status=?,resolved_by=?,resolved_at=CURRENT_TIMESTAMP,resolution_note=? WHERE id=? AND server_id=?')->execute([$kind==='dismiss'?'dismissed':'resolved',$uid,ucfirst($kind).($reason?': '.$reason:''),(int)$r['id'],$sid]);
            }elseif($action==='unban'){
                $id=(int)($b['ban_id']??0);$row=$this->rows("SELECT ma.id,ma.target_user_id,ma.reason,ma.created_at,ma.expires_at,u.full_name,u.username FROM moderation_actions ma JOIN users u ON u.id=ma.target_user_id WHERE ma.id=? AND ma.server_id=? AND ma.action_type='ban' AND ma.is_active=1 AND (ma.expires_at IS NULL OR ma.expires_at>CURRENT_TIMESTAMP) AND NOT EXISTS(SELECT 1 FROM moderation_actions newer WHERE newer.server_id=ma.server_id AND newer.target_user_id=ma.target_user_id AND newer.action_type='ban' AND newer.is_active=1 AND newer.id>ma.id AND (newer.expires_at IS NULL OR newer.expires_at>CURRENT_TIMESTAMP))".$this->lock(),[$id,$sid])[0]??null;
                if(!$row||!hash_equals(self::revision($row),(string)($b['revision']??'')))throw new RuntimeException('This ban changed. Refresh before restoring access.',409);
                $this->db->prepare("UPDATE moderation_actions SET is_active=0 WHERE server_id=? AND target_user_id=? AND action_type='ban'")->execute([$sid,(int)$row['target_user_id']]);
            }else throw new InvalidArgumentException('Unknown tool action.');
            $this->db->prepare("INSERT INTO activity_logs(user_id,action,entity_type,entity_id,description,level,created_at) VALUES(?,?,'server',?,?,'info',CURRENT_TIMESTAMP)")->execute([$uid,'facilitator.'.$action,$sid,$action==='review_report'?'Reviewed report #'.(int)$b['report_id']:$action]);$this->db->commit();return [];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
