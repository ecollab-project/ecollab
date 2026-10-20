<?php
declare(strict_types=1);
require_once __DIR__.'/../database/config/db.php';
/** Academic space activity, separate from host/system capacity metrics. */
final class AdminSpaceMonitorService {
    private array $unavailable=[];
    public function __construct(private ?PDO $db=null){$this->db??=Database::getInstance();}
    private function rows(string $sql,array $p=[]):array{$q=$this->db->prepare($sql);foreach(array_values($p)as $i=>$v)$q->bindValue($i+1,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);$q->execute();return $q->fetchAll(PDO::FETCH_ASSOC);}
    private function optional(string $name,callable $fn):mixed{try{return $fn();}catch(PDOException $e){$this->unavailable[]=$name;error_log('[Admin monitor] '.$name.' unavailable');return null;}}
    private function count(string $sql,array $p):int{return (int)$this->rows($sql,$p)[0]['n'];}
    public function data(int $sid,int $days=7):array{
        if($sid<1||!in_array($days,[7,30,90],true))throw new InvalidArgumentException('Choose a space and 7, 30, or 90 days.');
        $this->unavailable=[];$s=$this->rows('SELECT s.id,s.name,s.description,s.type,s.status,s.owner_id,u.username owner_username,u.full_name owner_name,u.role owner_role FROM servers s LEFT JOIN users u ON u.id=s.owner_id WHERE s.id=?',[$sid])[0]??null;
        if(!$s)throw new RuntimeException('Space not found.',404);
        $mysql=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$clock=$mysql?$this->rows('SELECT UTC_TIMESTAMP() utc_now,TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),NOW()) offset_seconds')[0]:['utc_now'=>gmdate('Y-m-d H:i:s'),'offset_seconds'=>0];
        $offset=(int)$clock['offset_seconds'];$utc=new DateTimeZone('UTC');$local=(new DateTimeImmutable($clock['utc_now'],$utc))->setTimezone(new DateTimeZone('Asia/Manila'));$start=$local->setTime(0,0)->modify('-'.($days-1).' days')->setTimezone($utc)->modify(sprintf('%+d seconds',$offset))->format('Y-m-d H:i:s');$end=$local->setTime(0,0)->modify('+1 day')->setTimezone($utc)->modify(sprintf('%+d seconds',$offset))->format('Y-m-d H:i:s');$dayShift=28800-$offset;
        $stats=['members'=>$this->optional('members_count',fn()=>$this->count('SELECT COUNT(*) n FROM server_members sm JOIN users u ON u.id=sm.user_id WHERE sm.server_id=? AND u.deleted_at IS NULL AND sm.user_id<>?',[$sid,(int)$s['owner_id']])),
        'channels'=>$this->optional('channels_count',fn()=>$this->count('SELECT COUNT(*) n FROM channels WHERE server_id=?',[$sid])),
        'messages'=>$this->optional('messages_count',fn()=>$this->count('SELECT COUNT(*) n FROM messages m JOIN channels c ON c.id=m.channel_id WHERE c.server_id=? AND m.is_deleted=0 AND m.created_at>=? AND m.created_at<?',[$sid,$start,$end])),
        'contributors'=>$this->optional('contributors_count',fn()=>$this->count('SELECT COUNT(DISTINCT m.sender_id) n FROM messages m JOIN channels c ON c.id=m.channel_id WHERE c.server_id=? AND m.is_deleted=0 AND m.sender_id<>? AND m.created_at>=? AND m.created_at<?',[$sid,(int)$s['owner_id'],$start,$end])),
        'reports_pending'=>$this->optional('reports_count',fn()=>$this->count("SELECT COUNT(*) n FROM content_reports WHERE server_id=? AND status IN ('pending','reviewing')",[$sid]))];
        $day=$mysql?"DATE(DATE_ADD(m.created_at,INTERVAL $dayShift SECOND))":"date(m.created_at,'$dayShift seconds')";
        $daily=$this->optional('daily',fn()=>$this->rows("SELECT $day day,COUNT(*) messages,COUNT(DISTINCT CASE WHEN m.sender_id<>? THEN m.sender_id END) contributors FROM messages m JOIN channels c ON c.id=m.channel_id WHERE c.server_id=? AND m.is_deleted=0 AND m.created_at>=? AND m.created_at<? GROUP BY $day ORDER BY day",[(int)$s['owner_id'],$sid,$start,$end]));
        if($daily!==null){$actual=array_column($daily,null,'day');$daily=[];for($i=$days-1;$i>=0;$i--){$key=$local->modify('-'.$i.' days')->format('Y-m-d');$daily[]=$actual[$key]??['day'=>$key,'messages'=>0,'contributors'=>0];}}
        $channels=$this->optional('channels',fn()=>$this->rows('SELECT c.id,c.name,c.type,c.is_private,c.is_locked,COUNT(m.id) messages,COUNT(DISTINCT CASE WHEN m.sender_id<>? THEN m.sender_id END) contributors FROM channels c LEFT JOIN messages m ON m.channel_id=c.id AND m.is_deleted=0 AND m.created_at>=? AND m.created_at<? WHERE c.server_id=? GROUP BY c.id,c.name,c.type,c.is_private,c.is_locked ORDER BY messages DESC,c.id LIMIT 200',[(int)$s['owner_id'],$start,$end,$sid]));
        $members=$this->optional('members',fn()=>$this->rows('SELECT u.id,u.username,u.full_name,sm.server_role,(u.id=?) is_owner,(SELECT COUNT(*) FROM messages m JOIN channels c ON c.id=m.channel_id WHERE c.server_id=sm.server_id AND m.sender_id=u.id AND m.is_deleted=0 AND m.created_at>=? AND m.created_at<?) messages,u.status FROM server_members sm JOIN users u ON u.id=sm.user_id WHERE sm.server_id=? AND u.deleted_at IS NULL ORDER BY is_owner DESC,messages DESC,u.username LIMIT 500',[(int)$s['owner_id'],$start,$end,$sid]));
        $recent=$this->optional('recent',fn()=>$this->rows('SELECT m.id,m.content,m.created_at,c.id channel_id,c.name channel_name,u.username FROM messages m JOIN channels c ON c.id=m.channel_id JOIN users u ON u.id=m.sender_id WHERE c.server_id=? AND m.is_deleted=0 ORDER BY m.created_at DESC,m.id DESC LIMIT 30',[$sid]));
        $reports=$this->optional('reports',fn()=>$this->rows('SELECT id,reason,status,created_at FROM content_reports WHERE server_id=? ORDER BY created_at DESC,id DESC LIMIT 30',[$sid]));
        foreach(['recent','reports']as $key){$rows=&$$key;if($rows!==null)foreach($rows as &$r)$r['created_at']=(new DateTimeImmutable($r['created_at'],$utc))->modify(sprintf('%+d seconds',-$offset))->format('Y-m-d\TH:i:s\Z');unset($r,$rows);}
        return ['server'=>$s,'stats'=>$stats,'daily'=>$daily,'channels'=>$channels,'members'=>$members,'recent'=>$recent,'reports'=>$reports,'days'=>$days,'timezone'=>'Asia/Manila','unavailable'=>$this->unavailable,'can_review_reports'=>$s['owner_role']===null||$s['owner_role']==='student','limits'=>['channels'=>200,'members'=>500,'recent'=>30,'reports'=>30]];
    }
}
