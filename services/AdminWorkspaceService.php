<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/database/config/db.php';
require_once dirname(__DIR__).'/services/RealtimeOutbox.php';

/** Admin workspace data. Optional sources remain unavailable rather than becoming fabricated zeros. */
final class AdminWorkspaceService
{
    private array $columns=[];
    private array $unavailable=[];
    public function __construct(private ?PDO $db=null) { $this->db ??= Database::getInstance(); }
    private function rows(string $sql,array $params=[]): array {
        $stmt=$this->db->prepare($sql);
        foreach (array_values($params) as $i=>$value) $stmt->bindValue($i+1,$value,is_int($value)?PDO::PARAM_INT:($value===null?PDO::PARAM_NULL:PDO::PARAM_STR));
        $stmt->execute();return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    private function optional(string $name,callable $query): mixed {
        try {return $query();} catch (PDOException $e) {$this->unavailable[]=$name;error_log('Admin workspace source unavailable: '.$name);return null;}
    }
    private function count(string $sql,array $params=[]): int { $rows=$this->rows($sql,$params);return (int)array_values($rows[0])[0]; }
    private function cols(string $table): array {
        if (!preg_match('/^[a-z_]+$/D',$table)) throw new LogicException('Invalid table');
        if (!isset($this->columns[$table])) {
            $rows=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?$this->rows('PRAGMA table_info('.$table.')'):$this->rows('SHOW COLUMNS FROM '.$table);
            $this->columns[$table]=array_fill_keys(array_column($rows,$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'name':'Field'),true);
        }
        return $this->columns[$table];
    }
    public static function paging(array $query): array {
        $page=filter_var($query['page']??1,FILTER_VALIDATE_INT);$limit=filter_var($query['limit']??25,FILTER_VALIDATE_INT);
        if (!$page || $page<1 || $page>100000 || !$limit || $limit<1 || $limit>100) throw new InvalidArgumentException('Invalid page or page size.');
        return [$page,$limit,($page-1)*$limit];
    }
    private function population(string $alias='u'): string {return "$alias.deleted_at IS NULL AND $alias.email NOT LIKE 'k6_%@example.invalid'";}
    private function dayBounds(): array {
        $clock=$this->rows('SELECT NOW() AS local_now, UTC_TIMESTAMP() AS utc_now')[0];
        $utc=new DateTimeZone('UTC');
        $offset=(new DateTimeImmutable($clock['local_now'],$utc))->getTimestamp()-(new DateTimeImmutable($clock['utc_now'],$utc))->getTimestamp();
        $start=(new DateTimeImmutable($clock['utc_now'],$utc))->setTimezone(new DateTimeZone('Asia/Manila'))->setTime(0,0)->setTimezone($utc);
        return [$start->modify(($offset>=0?'+':'').$offset.' seconds')->format('Y-m-d H:i:s'),$start->modify('+1 day')->modify(($offset>=0?'+':'').$offset.' seconds')->format('Y-m-d H:i:s'),$offset];
    }
    public function overview(int $days=7): array {
        if (!in_array($days,[7,30,90],true)) throw new InvalidArgumentException('Choose 7, 30 or 90 days.');
        [$start,$end,$offset]=$this->dayBounds();$population=$this->population();
        $stats=['users'=>$this->count("SELECT COUNT(*) FROM users u WHERE $population"),
            'students'=>$this->count("SELECT COUNT(*) FROM users u WHERE $population AND role='student'"),
            'new_today'=>$this->count("SELECT COUNT(*) FROM users u WHERE $population AND created_at>=? AND created_at<?",[$start,$end]),
            'messages_today'=>$this->optional('messages',fn()=>$this->count("SELECT COUNT(*) FROM messages m JOIN users u ON u.id=m.sender_id WHERE $population AND m.is_deleted=0 AND m.created_at>=? AND m.created_at<?",[$start,$end])),
            'servers'=>$this->optional('servers',fn()=>$this->count("SELECT COUNT(*) FROM servers WHERE status='active'")),
            'study_sessions_today'=>$this->optional('study_sessions',fn()=>$this->count("SELECT COUNT(*) FROM study_sessions s JOIN users u ON u.id=s.user_id WHERE $population AND s.started_at>=? AND s.started_at<?",[$start,$end])),
            'reports_pending'=>$this->optional('reports',fn()=>$this->count("SELECT COUNT(*) FROM content_reports cr LEFT JOIN servers s ON s.id=cr.server_id LEFT JOIN users owner ON owner.id=s.owner_id WHERE cr.status IN ('pending','reviewing') AND (owner.role='student' OR owner.role IS NULL)")),
            'requests_pending'=>$this->optional('facilitator_requests',fn()=>$this->count("SELECT COUNT(*) FROM facilitator_requests WHERE status='pending'")),
            'feedback_open'=>$this->optional('feedback',fn()=>$this->count("SELECT COUNT(*) FROM feedback WHERE status IN ('open','reviewing')"))];
        $roles=$this->rows("SELECT role,COUNT(*) AS count FROM users u WHERE $population GROUP BY role ORDER BY count DESC");
        $recent=$this->users(['page'=>1,'limit'=>5])['items'];
        $since=(new DateTimeImmutable($start))->modify('-'.($days-1).' days')->format('Y-m-d H:i:s');
        $shift=28800-$offset;
        $day=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?"date(m.created_at,'$shift seconds')":"DATE(DATE_ADD(m.created_at, INTERVAL $shift SECOND))";
        $activity=$this->optional('message_activity',fn()=>$this->rows("SELECT $day AS day,COUNT(*) AS messages,COUNT(DISTINCT m.sender_id) AS contributors FROM messages m JOIN users u ON u.id=m.sender_id WHERE $population AND m.is_deleted=0 AND m.created_at>=? AND m.created_at<? GROUP BY $day ORDER BY day",[$since,$end]));
        return ['stats'=>$stats,'roles'=>$roles,'recent_users'=>$recent,'activity'=>$activity,'days'=>$days,'unavailable'=>array_values(array_unique($this->unavailable)),'timezone'=>'Asia/Manila'];
    }
    public function users(array $query): array {
        [$page,$limit,$offset]=self::paging($query);$where=$this->population();$params=[];
        if (($query['include_tests']??'0')==='1') $where='u.deleted_at IS NULL';
        $q=trim((string)($query['q']??''));if (strlen($q)>120) throw new InvalidArgumentException('Search is too long.');
        if ($q!=='') {$where.=' AND (u.username LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)';$params=array_fill(0,3,'%'.$q.'%');}
        foreach (['role'=>['student','facilitator','moderator','admin','super_admin'],'status'=>['active','offline','idle','banned','suspended','deactivated']] as $field=>$allowed) {
            if (!empty($query[$field])) {if (!in_array($query[$field],$allowed,true))throw new InvalidArgumentException('Invalid filter.');$where.=" AND u.$field=?";$params[]=$query[$field];}
        }
        $total=$this->count("SELECT COUNT(*) FROM users u WHERE $where",$params);
        $items=$this->rows("SELECT u.id,u.username,u.full_name,u.email,u.role,u.status,u.created_at,u.is_online,u.last_active_at,u.last_seen_at,ap.name AS course_name FROM users u LEFT JOIN user_profiles up ON up.user_id=u.id LEFT JOIN academic_programs ap ON ap.id=up.academic_program_id WHERE $where ORDER BY u.id DESC LIMIT ? OFFSET ?",[...$params,$limit,$offset]);
        return ['items'=>$items,'total'=>$total,'page'=>$page,'limit'=>$limit];
    }
    public function spaces(array $query): array {
        [$page,$limit,$offset]=self::paging($query);$kind=($query['kind']??'servers')==='channels'?'channels':'servers';$q=trim((string)($query['q']??''));
        if (strlen($q)>120) throw new InvalidArgumentException('Search is too long.');
        if ($kind==='servers') {
            $cols=$this->cols('servers');$visibility=isset($cols['visibility'])?'s.visibility':'s.type';
            $where=$q!==''?'s.name LIKE ?':'1=1';$params=$q!==''?['%'.$q.'%']:[];
            $total=$this->count("SELECT COUNT(*) FROM servers s WHERE $where",$params);
            $items=$this->rows("SELECT s.id,s.name,s.description,s.status,$visibility AS visibility,u.username AS owner,u.role AS owner_role,(SELECT COUNT(*) FROM server_members sm WHERE sm.server_id=s.id) AS members,(SELECT COUNT(*) FROM channels c WHERE c.server_id=s.id) AS channels FROM servers s LEFT JOIN users u ON u.id=s.owner_id WHERE $where ORDER BY s.id DESC LIMIT ? OFFSET ?",[...$params,$limit,$offset]);
        } else {
            $where=$q!==''?'(c.name LIKE ? OR s.name LIKE ?)':'1=1';$params=$q!==''?['%'.$q.'%','%'.$q.'%']:[];
            $total=$this->count("SELECT COUNT(*) FROM channels c JOIN servers s ON s.id=c.server_id WHERE $where",$params);
            $items=$this->rows("SELECT c.id,c.name,c.server_id,c.type,c.is_private,c.is_locked,s.name AS server_name FROM channels c JOIN servers s ON s.id=c.server_id WHERE $where ORDER BY c.id DESC LIMIT ? OFFSET ?",[...$params,$limit,$offset]);
        }
        return ['items'=>$items,'total'=>$total,'page'=>$page,'limit'=>$limit,'kind'=>$kind];
    }
    public function audit(array $query): array {
        [$page,$limit,$offset]=self::paging($query);$where='1=1';$params=[];
        $q=trim((string)($query['q']??''));if(strlen($q)>120)throw new InvalidArgumentException('Search is too long.');
        if($q!==''){$where='(al.action LIKE ? OR u.username LIKE ?)';$params=['%'.$q.'%','%'.$q.'%'];}
        if(!empty($query['level'])){if(!in_array($query['level'],['info','warning','warn','error','critical','success'],true))throw new InvalidArgumentException('Invalid log level.');$where.=' AND al.level=?';$params[]=$query['level'];}
        $cols=$this->cols('activity_logs');$description=isset($cols['description'])?'al.description':'al.action';
        $total=$this->count("SELECT COUNT(*) FROM activity_logs al LEFT JOIN users u ON u.id=al.user_id WHERE $where",$params);
        $items=$this->rows("SELECT al.id,al.action,$description AS description,al.level,al.created_at,u.username AS actor FROM activity_logs al LEFT JOIN users u ON u.id=al.user_id WHERE $where ORDER BY al.id DESC LIMIT ? OFFSET ?",[...$params,$limit,$offset]);
        return ['items'=>$items,'total'=>$total,'page'=>$page,'limit'=>$limit];
    }
    public function catalog(): array {
        return ['programs'=>$this->rows('SELECT ap.id,ap.code,ap.name,ap.is_active,i.name AS institution FROM academic_programs ap LEFT JOIN institutions i ON i.id=ap.institution_id ORDER BY ap.name'),
            'tags'=>$this->rows('SELECT id,name,category FROM interest_tags ORDER BY name')];
    }
    public function feedback(array $query): array {
        [$page,$limit,$offset]=self::paging($query);$status=$query['status']??'open';
        if (!in_array($status,['open','reviewing','resolved','closed','all'],true))throw new InvalidArgumentException('Invalid feedback status.');
        $where=$status==='all'?'1=1':'f.status=?';$params=$status==='all'?[]:[$status];
        return ['items'=>$this->rows("SELECT f.id,f.title,f.description,f.type,f.status,f.created_at,u.username AS author FROM feedback f LEFT JOIN users u ON u.id=f.user_id WHERE $where ORDER BY f.id DESC LIMIT ? OFFSET ?",[...$params,$limit,$offset]),'total'=>$this->count("SELECT COUNT(*) FROM feedback f WHERE $where",$params),'page'=>$page,'limit'=>$limit];
    }
    public function ai(): array {
        return ['matches'=>$this->optional('peer_matches',fn()=>$this->rows('SELECT status,COUNT(*) AS count,AVG(match_score) AS mean_score FROM peer_matches GROUP BY status')),
            'sessions'=>$this->optional('ai_sessions',fn()=>$this->count('SELECT COUNT(*) FROM ai_sessions')),
            'unavailable'=>$this->unavailable,'accuracy'=>null,'training'=>null];
    }
    private function log(int $actor,string $action,int $target,string $description,string $entity='user'): void {
        $fields=['user_id','action','level'];$values=[$actor,$action,'info'];$cols=$this->cols('activity_logs');
        foreach (['entity_type'=>$entity,'entity_id'=>$target,'description'=>$description] as $key=>$value) if (isset($cols[$key])) {$fields[]=$key;$values[]=$value;}
        $this->rows('INSERT INTO activity_logs ('.implode(',',$fields).') VALUES ('.implode(',',array_fill(0,count($fields),'?')).')',$values);
    }
    public static function targetAllowed(int $actor,array $target,string $operation): void {
        if((int)$target['id']===$actor || $target['role']==='super_admin' || ($operation==='ban' && $target['role']==='admin'))throw new RuntimeException('This account is protected from that action.',403);
    }
    public function mutate(string $action,array $body,array $actor): array {
        if (!in_array($action,['create_user','role','ban','unban','feedback_status','create_tag','announcement'],true))throw new InvalidArgumentException('Unsupported action.');
        $this->db->beginTransaction();
        try {
            $id=0;
            if($action==='create_user') {
                $username=trim((string)($body['username']??''));$email=strtolower(trim((string)($body['email']??'')));$name=trim((string)($body['full_name']??''));$password=(string)($body['password']??'');$role=$body['role']??'student';
                if(!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/D',$username)||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>120||$name===''||strlen($name)>120||strlen($password)<12||strlen($password)>72||!in_array($role,['student','facilitator','admin'],true))throw new InvalidArgumentException('Provide a valid username, email, full name, role and password of 12–72 characters.');
                $this->rows("INSERT INTO users(username,email,full_name,password_hash,role,status) VALUES(?,?,?,?,?,'offline')",[$username,$email,$name,password_hash($password,PASSWORD_BCRYPT,['cost'=>defined('BCRYPT_COST')?BCRYPT_COST:12]),$role]);
                $id=(int)$this->db->lastInsertId();$this->rows('INSERT INTO user_profiles(user_id) VALUES(?)',[$id]);
                $this->log((int)$actor['id'],'admin.user.created',$id,'Created user '.$username);
            } elseif(in_array($action,['role','ban','unban'],true)) {
                $id=filter_var($body['user_id']??null,FILTER_VALIDATE_INT);if(!$id||$id<1)throw new InvalidArgumentException('User ID required.');
                $lock=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' FOR UPDATE';
                $target=$this->rows('SELECT id,role,status FROM users WHERE id=? AND deleted_at IS NULL'.$lock,[$id])[0]??null;
                if(!$target)throw new RuntimeException('User not found.',404);self::targetAllowed((int)$actor['id'],$target,$action);
                if(($body['expected_role']??null)!==$target['role']||($body['expected_status']??null)!==$target['status'])throw new RuntimeException('User changed. Refresh the list before retrying.',409);
                if($action==='role') {
                    $role=$body['role']??'';if(!in_array($role,['student','facilitator','moderator','admin'],true))throw new InvalidArgumentException('Invalid role.');
                    $this->rows('UPDATE users SET role=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$role,$id]);
                    $this->log((int)$actor['id'],'admin.user.role_changed',$id,'Role changed from '.$target['role'].' to '.$role);
                } else {
                    $reason=trim((string)($body['reason']??''));if(strlen($reason)<3||strlen($reason)>300)throw new InvalidArgumentException('Provide an action reason (3–300 characters).');
                    if($action==='unban'&&$target['status']!=='banned')throw new InvalidArgumentException('Only banned accounts can be unbanned here.');
                    $this->rows("UPDATE users SET status=?,is_online=0,remember_token=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=?",[$action==='ban'?'banned':'offline',$id]);
                    $this->log((int)$actor['id'],'admin.user.'.$action,$id,$reason);
                }
            } elseif($action==='feedback_status') {
                $id=filter_var($body['feedback_id']??null,FILTER_VALIDATE_INT);$status=$body['status']??'';
                if(!$id||!in_array($status,['open','reviewing','resolved','closed'],true))throw new InvalidArgumentException('Invalid feedback action.');
                $stmt=$this->db->prepare('UPDATE feedback SET status=?,resolved_by=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status=?');$stmt->execute([$status,in_array($status,['resolved','closed'],true)?$actor['id']:null,$id,$body['expected_status']??'']);
                if($stmt->rowCount()!==1)throw new RuntimeException('Feedback changed or was not found. Refresh before retrying.',409);
                $this->log((int)$actor['id'],'admin.feedback.updated',$id,'Feedback status: '.$status,'feedback');
            } elseif($action==='announcement') {
                $id=filter_var($body['server_id']??null,FILTER_VALIDATE_INT);$title=trim((string)($body['title']??''));$message=trim((string)($body['message']??''));
                if(!$id||$id<1||$title===''||strlen($title)>150||$message===''||strlen($message)>3000)throw new InvalidArgumentException('Select a server and provide a title and message within the displayed limits.');
                $channels=$this->rows("SELECT id FROM channels WHERE server_id=? AND type='announcement'",[$id]);
                if(!$channels)throw new InvalidArgumentException('This server has no announcement channel.');
                foreach($channels as $channel) {
                    RealtimeOutbox::record($this->db,'channel',(int)$channel['id'],function()use($channel,$actor,$title,$message){
                        $this->rows("INSERT INTO messages(channel_id,sender_id,content,content_type,is_pinned,created_at,updated_at) VALUES(?,?,?,'text',1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)",[(int)$channel['id'],(int)$actor['id'],'[**'.$title.'**] '.$message]);return (int)$this->db->lastInsertId();
                    });
                }
                $this->log((int)$actor['id'],'admin.announcement.published',$id,'Published to '.count($channels).' announcement channels','server');
            } else {
                $name=trim((string)($body['name']??''));$category=trim((string)($body['category']??''));
                if($name===''||strlen($name)>60||strlen($category)>40)throw new InvalidArgumentException('Tag name and category are too long or missing.');
                $slug=strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/','-',$name),'-'));if($slug==='')throw new InvalidArgumentException('Tag needs an alphanumeric name.');
                $this->rows('INSERT INTO interest_tags(name,slug,category) VALUES(?,?,?)',[$name,$slug,$category?:null]);$id=(int)$this->db->lastInsertId();
                $this->log((int)$actor['id'],'admin.tag.created',$id,'Interest tag: '.$name,'interest_tag');
            }
            $this->db->commit();return ['id'=>$id];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
