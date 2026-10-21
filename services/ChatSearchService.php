<?php
declare(strict_types=1);
final class ChatSearchService
{
    public function __construct(private PDO $db) {}
    public function search(int $uid, int $sid, int $cid, string $term, ?array $cursor=null): array
    {
        if (mb_strlen($term)>120 || strlen($term)>480 || $cid<0) throw new InvalidArgumentException('Search must be at most 120 characters.',400);
        if (mb_strlen($term)<2) return ['results'=>[], 'next_cursor'=>null];
        $member=$this->db->prepare("SELECT 1 FROM server_members sm JOIN servers s ON s.id=sm.server_id AND s.status='active'
            JOIN users u ON u.id=sm.user_id AND u.deleted_at IS NULL AND u.status IN ('active','offline','idle')
            WHERE sm.server_id=? AND sm.user_id=? LIMIT 1");
        $member->execute([$sid,$uid]);
        if (!$member->fetchColumn()) throw new RuntimeException('Server access denied',403);
        $sql="SELECT m.id,m.channel_id,m.sender_id,m.content,m.created_at,c.name AS channel_name,u.username,u.full_name
            FROM messages m JOIN channels c ON c.id=m.channel_id JOIN users u ON u.id=m.sender_id
            WHERE c.server_id=? AND m.is_deleted=0";
        $args=[$sid];
        if ($cid>0) { $sql.=' AND m.channel_id=?'; $args[]=$cid; }
        $sql.=" AND (m.content LIKE ? OR u.username LIKE ? OR u.full_name LIKE ?)
            AND (COALESCE(c.is_private,0)=0 OR EXISTS(SELECT 1 FROM channel_members cm WHERE cm.channel_id=c.id AND cm.user_id=?) OR c.created_by=?)
            AND NOT EXISTS(SELECT 1 FROM moderation_actions ma WHERE ma.target_user_id=? AND (ma.server_id=c.server_id OR ma.server_id IS NULL)
                AND ma.is_active=1 AND ma.action_type='ban' AND (ma.expires_at IS NULL OR ma.expires_at>CURRENT_TIMESTAMP))";
        array_push($args,'%'.$term.'%','%'.$term.'%','%'.$term.'%',$uid,$uid,$uid);
        if ($cursor!==null) {
            if (!is_string($cursor['at']??null) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$cursor['at']) || !is_int($cursor['id']??null) || $cursor['id']<1) throw new InvalidArgumentException('Invalid search cursor',400);
            $sql.=' AND (m.created_at<? OR (m.created_at=? AND m.id<?))';
            array_push($args,$cursor['at'],$cursor['at'],$cursor['id']);
        }
        $sql.=' ORDER BY m.created_at DESC,m.id DESC LIMIT 51';
        $q=$this->db->prepare($sql); $q->execute($args); $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        $more=count($rows)>50; $rows=array_slice($rows,0,50); $last=end($rows);
        return ['results'=>$rows,'next_cursor'=>$more?base64_encode(json_encode(['at'=>$last['created_at'],'id'=>(int)$last['id']])):null];
    }
}
