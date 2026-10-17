<?php
declare(strict_types=1);
require_once __DIR__.'/../database/config/db.php';
/** Administrative reviews retain the existing student-owned-space scope. */
final class AdminReportService {
    public function __construct(private ?PDO $db=null){$this->db??=Database::getInstance();}
    private function rows(string $sql,array $p=[]):array{$q=$this->db->prepare($sql);foreach(array_values($p) as $i=>$v)$q->bindValue($i+1,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);$q->execute();return $q->fetchAll(PDO::FETCH_ASSOC);}
    private function from():string{return ' FROM content_reports cr LEFT JOIN messages m ON m.id=cr.message_id LEFT JOIN users reporter ON reporter.id=cr.reporter_id LEFT JOIN users reported ON reported.id=cr.reported_user_id LEFT JOIN servers s ON s.id=cr.server_id LEFT JOIN users owner ON owner.id=s.owner_id ';}
    public static function revision(array $r):string{return hash('sha256',json_encode([$r['id'],$r['status'],$r['reason'],$r['description'],$r['message_id'],$r['message_content'],$r['message_deleted'],$r['owner_role']],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE));}
    public function listing(array $query):array{
        $page=max(1,min(100000,(int)($query['page']??1)));$limit=min(50,max(1,(int)($query['limit']??20)));$status=(string)($query['status']??'pending');$sid=max(0,(int)($query['server_id']??0));$search=trim((string)($query['q']??''));
        if(!in_array($status,['pending','reviewing','resolved','dismissed','all'],true)||strlen($search)>120)throw new InvalidArgumentException('Invalid report filter.');
        $where="(owner.role='student' OR owner.role IS NULL)";$p=[];
        if($status!=='all'){$where.=' AND cr.status=?';$p[]=$status;}
        if($sid){$where.=' AND cr.server_id=?';$p[]=$sid;}
        if($search!==''){$where.=' AND (cr.description LIKE ? OR cr.reason LIKE ? OR reporter.username LIKE ? OR reported.username LIKE ? OR s.name LIKE ?)';array_push($p,...array_fill(0,5,'%'.$search.'%'));}
        $total=(int)$this->rows('SELECT COUNT(*) n'.$this->from().' WHERE '.$where,$p)[0]['n'];
        $items=$this->rows('SELECT cr.id,cr.reason,cr.description,cr.status,cr.created_at,cr.message_id,cr.server_id,cr.resolution_note,cr.resolved_at,m.channel_id,m.content message_content,m.is_deleted message_deleted,reporter.username reporter_username,reported.username reported_username,s.name server_name,owner.role owner_role'.$this->from().' WHERE '.$where.' ORDER BY cr.created_at DESC,cr.id DESC LIMIT ? OFFSET ?',[...$p,$limit,($page-1)*$limit]);
        foreach($items as &$r)$r['revision']=self::revision($r);unset($r);
        return ['items'=>$items,'total'=>$total,'page'=>$page,'limit'=>$limit,'scope'=>'student_owned','server_id'=>$sid];
    }
    public function resolve(array $body,int $actor):array{
        $id=(int)($body['report_id']??0);$resolution=(string)($body['resolution']??'');$note=trim((string)($body['note']??''));
        if(!$id||!in_array($resolution,['resolved','dismissed'],true)||strlen($note)>2000)throw new InvalidArgumentException('Select a report, a valid resolution, and a note up to 2,000 bytes.');
        $this->db->beginTransaction();try{
            $lock=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $r=$this->rows('SELECT cr.id,cr.reason,cr.description,cr.status,cr.message_id,m.content message_content,m.is_deleted message_deleted,owner.role owner_role'.$this->from().' WHERE cr.id=?'.$lock,[$id])[0]??null;
            if(!$r)throw new RuntimeException('Report not found.',404);
            if($r['owner_role']!==null&&$r['owner_role']!=='student')throw new RuntimeException('This report is handled by the facilitator of its space.',403);
            if(!in_array($r['status'],['pending','reviewing'],true)||!hash_equals(self::revision($r),(string)($body['revision']??'')))throw new RuntimeException('This report or its evidence changed. Refresh before reviewing it.',409);
            if($resolution==='resolved'&&$r['message_id'])$this->db->prepare('UPDATE messages SET is_deleted=1,deleted_at=CURRENT_TIMESTAMP WHERE id=?')->execute([(int)$r['message_id']]);
            $this->db->prepare('UPDATE content_reports SET status=?,resolved_by=?,resolved_at=CURRENT_TIMESTAMP,resolution_note=? WHERE id=?')->execute([$resolution,$actor,$note?:null,$id]);
            $this->db->prepare("INSERT INTO activity_logs(user_id,action,entity_type,entity_id,description,level,created_at) VALUES(?,'admin.report.reviewed','report',?,?,'info',CURRENT_TIMESTAMP)")->execute([$actor,$id,ucfirst($resolution).($note!==''?': '.$note:'')]);
            $this->db->commit();return ['id'=>$id,'status'=>$resolution];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
