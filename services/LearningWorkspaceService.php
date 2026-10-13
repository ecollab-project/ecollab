<?php
declare(strict_types=1);
require_once __DIR__.'/../database/config/db.php';
require_once __DIR__.'/RealtimeOutbox.php';
/** Learning-only data. Actor comes from authentication, never request parameters. */
final class LearningWorkspaceService {
    private PDO $db;
    public function __construct(?PDO $db=null){$this->db=$db??Database::getInstance();}
    private function rows(string $sql,array $p=[]):array{$q=$this->db->prepare($sql);$q->execute($p);return $q->fetchAll(PDO::FETCH_ASSOC);}
    private function source(callable $fn):array{try{return ['available'=>true,'items'=>$fn()];}catch(Throwable $e){error_log('[LearningWorkspace] '.$e->getMessage());return ['available'=>false,'items'=>[]];}}
    public function owns(int $uid,int $sid):bool{return (bool)$this->rows('SELECT id FROM servers WHERE id=? AND owner_id=? AND status<>\'archived\'',[$sid,$uid]);}
    // Mirrors ChannelService private-channel access rules, including server ownership.
    private function access():string{return "(s.owner_id=? OR EXISTS(SELECT 1 FROM server_members sm WHERE sm.server_id=s.id AND sm.user_id=? AND (c.is_private=0 OR sm.server_role IN ('owner','admin') OR c.created_by=? OR EXISTS(SELECT 1 FROM channel_members cm WHERE cm.channel_id=c.id AND cm.user_id=?))))";}
    public function data(int $uid,bool $fac,int $sid=0):array{
        if($fac&&$sid&&!$this->owns($uid,$sid))throw new RuntimeException('Choose a space you own.',403);
        $d=['spaces'=>$this->source(fn()=>$this->rows($fac?"SELECT s.id,s.name,s.description,s.type,(SELECT COUNT(*) FROM server_members sm WHERE sm.server_id=s.id AND sm.user_id<>s.owner_id) members FROM servers s WHERE s.owner_id=? AND s.status<>'archived' ORDER BY s.name LIMIT 200":"SELECT s.id,s.name,s.description,s.type,(SELECT COUNT(*) FROM server_members m WHERE m.server_id=s.id) members FROM servers s WHERE s.status='active' AND (s.owner_id=? OR EXISTS(SELECT 1 FROM server_members sm WHERE sm.server_id=s.id AND sm.user_id=?)) ORDER BY s.name LIMIT 200",$fac?[$uid]:[$uid,$uid]))];
        $d['notes']=$this->source(fn()=>array_map(fn($n)=>$n+['revision'=>self::revision($n)],$this->rows('SELECT id,title,content,updated_at FROM student_notes WHERE user_id=? ORDER BY updated_at DESC,id DESC LIMIT 200',[$uid])));
        $d['notifications']=$this->source(fn()=>$this->rows('SELECT id,title,body,is_read,created_at FROM notifications WHERE recipient_id=? ORDER BY created_at DESC,id DESC LIMIT 100',[$uid]));
        $d['calendar']=$this->source(fn()=>$this->rows('SELECT id,title,description,event_type,starts_at,ends_at FROM calendar_events WHERE user_id=? AND starts_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) ORDER BY starts_at LIMIT 100',[$uid]));
        $filter=$fac?' AND s.id=?':'';$params=[$uid,$uid,$uid,$uid];if($fac)$params[]=$sid;
        $d['channels']=$this->source(fn()=>$this->rows('SELECT c.id,c.name,c.type,c.server_id,s.name server_name FROM channels c JOIN servers s ON s.id=c.server_id WHERE s.status=\'active\' AND '.$this->access().$filter.' ORDER BY s.name,c.position,c.id LIMIT 200',$params));
        $d['sessions']=$this->source(fn()=>$this->rows("SELECT ss.id,ss.title,ss.description,ss.scheduled_start,ss.scheduled_end,c.id channel_id,c.server_id,s.name server_name FROM study_room_sessions ss JOIN channels c ON c.id=ss.channel_id JOIN servers s ON s.id=c.server_id WHERE ss.status IN ('scheduled','active') AND ss.scheduled_start>=DATE_SUB(NOW(),INTERVAL 1 DAY) AND ".$this->access().$filter.' ORDER BY ss.scheduled_start LIMIT 100',$params));
        $d['resources']=$this->source(fn()=>$this->rows('SELECT f.id,f.original_name title,f.file_size,c.id channel_id,c.server_id,s.name server_name,f.created_at FROM uploaded_files f JOIN channels c ON c.id=f.channel_id JOIN servers s ON s.id=c.server_id WHERE f.deleted_at IS NULL AND '.$this->access().$filter.' ORDER BY f.created_at DESC LIMIT 200',$params));
        $d['activity']=$this->source(fn()=>$this->rows("SELECT DATE(created_at) day,COUNT(*) messages FROM messages WHERE sender_id=? AND is_deleted=0 AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) GROUP BY DATE(created_at) ORDER BY day",[$uid]));
        if(!$fac){$d['discover']=$this->source(function()use($uid){$columns=$this->rows('SHOW COLUMNS FROM servers');$hasVisibility=in_array('visibility',array_column($columns,'Field'),true);return $this->rows("SELECT s.id,s.name,s.description,s.type FROM servers s WHERE s.status='active' AND ".($hasVisibility?"s.visibility='public'":"s.type='public'")." AND s.owner_id<>? AND NOT EXISTS(SELECT 1 FROM server_members sm WHERE sm.server_id=s.id AND sm.user_id=?) AND NOT EXISTS(SELECT 1 FROM moderation_actions ma WHERE ma.server_id=s.id AND ma.target_user_id=? AND ma.action_type='ban' AND ma.is_active=1 AND (ma.expires_at IS NULL OR ma.expires_at>NOW())) ORDER BY s.name LIMIT 100",[$uid,$uid,$uid]);});}
        if($fac){$d['members']=$this->source(fn()=>$this->rows("SELECT u.id,u.username,u.full_name,sm.server_role,(SELECT COUNT(*) FROM messages m JOIN channels c ON c.id=m.channel_id WHERE c.server_id=sm.server_id AND m.sender_id=u.id AND m.is_deleted=0 AND m.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)) messages,(SELECT MAX(m.created_at) FROM messages m JOIN channels c ON c.id=m.channel_id WHERE c.server_id=sm.server_id AND m.sender_id=u.id AND m.is_deleted=0) last_message FROM server_members sm JOIN users u ON u.id=sm.user_id WHERE sm.server_id=? AND u.id<>? AND u.deleted_at IS NULL ORDER BY messages DESC,u.username LIMIT 500",[$sid,$uid]));}
        $offset=$this->clockOffset();
        foreach(['notes','notifications','calendar','sessions','resources','members'] as $key){
            if(!isset($d[$key]))continue;
            foreach($d[$key]['items'] as &$item)foreach(['updated_at','created_at','starts_at','ends_at','scheduled_start','scheduled_end','last_message'] as $field){
                if(!empty($item[$field]))$item[$field]=(new DateTimeImmutable($item[$field],new DateTimeZone('UTC')))->modify(sprintf('%+d seconds',-$offset))->format('Y-m-d\TH:i:s\Z');
            }
            unset($item);
        }
        $d['limits']=['spaces'=>200,'members'=>500,'channels'=>200,'resources'=>200,'notes'=>200,'sessions'=>100,'calendar'=>100,'notifications'=>100,'discover'=>100];
        return $d;
    }
    private function clockOffset():int{
        if($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')return 0;
        return (int)$this->rows('SELECT TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),NOW()) offset_seconds')[0]['offset_seconds'];
    }
    private function inputDate(string $raw):string{
        $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$raw,new DateTimeZone('Asia/Manila'));
        if(!$date||$date->format('Y-m-d\TH:i')!==$raw)throw new InvalidArgumentException('Enter a valid date and time (Philippines).');
        return $date->setTimezone(new DateTimeZone('UTC'))->modify(sprintf('%+d seconds',$this->clockOffset()))->format('Y-m-d H:i:s');
    }
    public static function revision(array $n):string{return hash('sha256',json_encode([$n['id'],$n['title'],$n['content'],$n['updated_at']],JSON_THROW_ON_ERROR));}
    public function mutate(int $uid,bool $fac,string $action,array $b):array{
        if($action==='join_space'){
            $sid=(int)($b['server_id']??0);$this->db->beginTransaction();try{
                $srv=$this->rows('SELECT * FROM servers WHERE id=?'.($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''),[$sid])[0]??null;
                if(!$srv||$srv['status']!=='active'||($srv['visibility']??$srv['type'])!=='public')throw new RuntimeException('This space is not publicly joinable.',403);
                if($this->rows("SELECT id FROM moderation_actions WHERE server_id=? AND target_user_id=? AND action_type='ban' AND is_active=1 AND (expires_at IS NULL OR expires_at>CURRENT_TIMESTAMP)",[$sid,$uid]))throw new RuntimeException('You cannot join this space.',403);
                if(!$this->rows('SELECT user_id FROM server_members WHERE server_id=? AND user_id=?',[$sid,$uid])){$this->db->prepare("INSERT INTO server_members(server_id,user_id,server_role) VALUES(?,?,'member')")->execute([$sid,$uid]);$this->db->prepare('UPDATE servers SET member_count=member_count+1 WHERE id=?')->execute([$sid]);}
                $this->db->commit();return ['server_id'=>$sid];
            }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        }
        if($action==='save_note'){
            $title=trim((string)($b['title']??''));$content=(string)($b['content']??'');if($title===''||strlen($title)>200||strlen($content)>60000)throw new InvalidArgumentException('Enter a title up to 200 characters and a note up to 60,000 bytes.');
            $id=(int)($b['id']??0);if(!$id){$this->db->prepare('INSERT INTO student_notes(user_id,title,content) VALUES(?,?,?)')->execute([$uid,$title,$content]);return ['id'=>(int)$this->db->lastInsertId()];}
            $this->db->beginTransaction();try{$n=$this->rows('SELECT id,title,content,updated_at FROM student_notes WHERE id=? AND user_id=?'.($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''),[$id,$uid])[0]??null;if(!$n)throw new RuntimeException('Note not found.',404);if(!hash_equals(self::revision($n),(string)($b['revision']??'')))throw new RuntimeException('This note changed. Reload before saving.',409);$this->db->prepare('UPDATE student_notes SET title=?,content=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?')->execute([$title,$content,$id,$uid]);$this->db->commit();return ['id'=>$id];}catch(Throwable $e){$this->db->rollBack();throw $e;}
        }
        if($action==='read_notifications'){$this->db->prepare('UPDATE notifications SET is_read=1,read_at=CURRENT_TIMESTAMP WHERE recipient_id=? AND is_read=0')->execute([$uid]);return [];}
        if($action==='calendar'){
            $title=trim((string)($b['title']??''));$type=(string)($b['event_type']??'personal');if($title===''||strlen($title)>120||!in_array($type,['personal','exam','deadline','study_session'],true))throw new InvalidArgumentException('Enter a valid event title and type.');
            $dt=$this->inputDate((string)($b['starts_at']??''));$details=(string)($b['description']??'');if(strlen($details)>10000)throw new InvalidArgumentException('Event details are too long.');
            $this->db->prepare('INSERT INTO calendar_events(user_id,title,description,event_type,starts_at) VALUES(?,?,?,?,?)')->execute([$uid,$title,$details,$type,$dt]);return [];
        }
        if(in_array($action,['session','announcement'],true)){
            $sid=(int)($b['server_id']??0);if(!$fac||!$this->owns($uid,$sid))throw new RuntimeException('Choose a space you own.',403);
            $title=trim((string)($b['title']??''));$text=trim((string)($b['description']??$b['message']??''));
            if($title===''||strlen($title)>120||strlen($text)>10000)throw new InvalidArgumentException('Enter a title up to 120 characters and details up to 10,000 bytes.');
            if($action==='session'){
                $cid=(int)($b['channel_id']??0);if(!$this->rows('SELECT id FROM channels WHERE id=? AND server_id=?',[$cid,$sid]))throw new InvalidArgumentException('Choose a channel in this space.');
                $start=$this->inputDate((string)($b['starts_at']??''));$end=$this->inputDate((string)($b['ends_at']??''));if($end<=$start)throw new InvalidArgumentException('End time must be after the start.');
                $this->db->prepare("INSERT INTO study_room_sessions(channel_id,title,description,status,scheduled_start,scheduled_end,created_by) VALUES(?,?,?,'scheduled',?,?,?)")->execute([$cid,$title,$text,$start,$end,$uid]);return [];
            }
            if($text==='')throw new InvalidArgumentException('Enter the announcement message.');
            $channels=$this->rows("SELECT id FROM channels WHERE server_id=? AND type='announcement' ORDER BY id LIMIT 1",[$sid]);
            if(!$channels)throw new InvalidArgumentException('Create an announcement channel in this space first.');
            $cid=(int)$channels[0]['id'];$this->db->beginTransaction();try{
                RealtimeOutbox::record($this->db,'channel',$cid,function()use($cid,$uid,$title,$text){$this->db->prepare("INSERT INTO messages(channel_id,sender_id,content,content_type) VALUES(?,?,?,'text')")->execute([$cid,$uid,'📢 '.$title."\n\n".$text]);return (int)$this->db->lastInsertId();});
                $link=(defined('BASE_URL')?BASE_URL:'').'/modules/chat/chat.php?server_id='.$sid.'&channel_id='.$cid;
                $this->db->prepare("INSERT INTO notifications(recipient_id,actor_id,type,title,body,link_url,icon) SELECT sm.user_id,?,'class_update',?,?,?,'📢' FROM server_members sm JOIN channels c ON c.server_id=sm.server_id AND c.id=? WHERE sm.server_id=? AND sm.user_id<>? AND (c.is_private=0 OR sm.server_role IN ('owner','admin') OR c.created_by=sm.user_id OR EXISTS(SELECT 1 FROM channel_members cm WHERE cm.channel_id=c.id AND cm.user_id=sm.user_id))")->execute([$uid,$title,$text,$link,$cid,$sid,$uid]);
                $this->db->commit();return ['channel_id'=>$cid];
            }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        }
        throw new InvalidArgumentException('Unknown learning action.');
    }
}
