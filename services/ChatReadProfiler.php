<?php
declare(strict_types=1);

/** CLI diagnostics: return counts, timings and plans, never message/user contents. */
final class ChatReadProfiler
{
    public static function inspect(PDO $db, int $channelId, ?int $userId = null, int $repeats = 3): array
    {
        if ($channelId < 1 || $repeats < 1 || $repeats > 10 || ($userId !== null && $userId < 1)) {
            throw new InvalidArgumentException('Use a positive channel/user ID and 1–10 samples');
        }
        if ($db->inTransaction()) throw new RuntimeException('Profiler requires its own read-only transaction');
        $db->exec('SET TRANSACTION READ ONLY');
        $db->beginTransaction();
        try {
            $stmt=$db->prepare('SELECT id,server_id,is_private,created_by FROM channels WHERE id=?');
            $stmt->execute([$channelId]);$channel=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!$channel) throw new RuntimeException('Channel not found');
            if ($userId === null) {
                // Default to a fixture student, never silently impersonate a real member/admin.
                $stmt=$db->prepare("SELECT u.id FROM users u JOIN server_members sm ON sm.user_id=u.id
                    WHERE sm.server_id=? AND u.deleted_at IS NULL AND u.role='student'
                    AND u.username REGEXP '^k6_[a-f0-9]{8}_[0-9]+$' ORDER BY u.id LIMIT 1");
                $stmt->execute([$channel['server_id']]);$userId=(int)$stmt->fetchColumn();
                if (!$userId) throw new RuntimeException('No fixture student found; supply an authorized user ID explicitly');
            }
            $stmt=$db->prepare("SELECT u.role,sm.server_role,
                EXISTS(SELECT 1 FROM channel_members cm WHERE cm.channel_id=:cid_access AND cm.user_id=u.id) AS member
                FROM users u LEFT JOIN server_members sm ON sm.server_id=:sid AND sm.user_id=u.id
                WHERE u.id=:uid AND u.deleted_at IS NULL");
            $stmt->execute([':cid_access'=>$channelId,':sid'=>$channel['server_id'],':uid'=>$userId]);
            $access=$stmt->fetch(PDO::FETCH_ASSOC);
            $admin=$access && in_array($access['role'],['admin','super_admin'],true);
            $manager=$access && (in_array($access['server_role'],['owner','admin'],true) || (int)$channel['created_by']===$userId);
            if (!$access || (!$admin && ($access['server_role']===null || ((int)$channel['is_private']===1 && !$access['member'] && !$manager)))) {
                throw new RuntimeException('Viewer is not authorized for this channel');
            }
            $queries=[
                'message_page'=>[
                    'SELECT m.id,m.channel_id,m.sender_id,m.content,m.content_type,m.parent_id,m.is_edited,m.is_pinned,
                        m.reaction_count,m.created_at,m.updated_at,u.username,u.full_name,u.avatar_url,u.avatar_color_gradient,
                        u.role,u.is_verified,pm.content AS parent_content,pu.full_name AS parent_author
                     FROM messages m JOIN users u ON u.id=m.sender_id
                     LEFT JOIN messages pm ON pm.id=m.parent_id AND pm.is_deleted=0
                     LEFT JOIN users pu ON pu.id=pm.sender_id
                     WHERE m.channel_id=? AND m.is_deleted=0 ORDER BY m.id DESC LIMIT 50',[$channelId]],
                'member_roster'=>[
                    "SELECT u.id,u.username,u.full_name,u.avatar_url,u.avatar_color_gradient,u.status,
                        CASE WHEN u.last_active_at>=DATE_SUB(NOW(),INTERVAL 10 MINUTE)
                        AND (us.activity_status IS NULL OR us.activity_status=1 OR u.id=?) THEN 1 ELSE 0 END AS is_online,
                        u.role,sm.server_role,sm.nickname
                     FROM users u JOIN server_members sm ON sm.user_id=u.id AND sm.server_id=?
                     LEFT JOIN user_settings us ON us.user_id=u.id
                     WHERE u.deleted_at IS NULL AND u.role NOT IN ('admin','super_admin')
                        AND u.status NOT IN ('banned','suspended','deactivated')
                     ORDER BY is_online DESC,sm.server_role ASC,u.full_name ASC",[$userId,$channel['server_id']]],
                'channel_list_unread'=>[
                    "SELECT c.id,c.name,c.type,
                        (SELECT COUNT(*) FROM messages m WHERE m.channel_id=c.id AND m.is_deleted=0
                        AND m.created_at>COALESCE((SELECT mr.last_read_at FROM message_reads mr
                            WHERE mr.user_id=? AND mr.channel_id=c.id),'1970-01-01')) AS unread_count,
                        CASE WHEN cs.channel_id IS NULL THEN 1 ELSE 0 END AS is_new
                     FROM channels c JOIN server_members sm ON sm.server_id=c.server_id AND sm.user_id=?
                     LEFT JOIN channel_seen cs ON cs.channel_id=c.id AND cs.user_id=?
                     WHERE c.server_id=? AND (c.is_private=0 OR EXISTS(SELECT 1 FROM channel_members cm
                        WHERE cm.channel_id=c.id AND cm.user_id=?) OR sm.server_role IN ('owner','admin') OR c.created_by=?)
                     ORDER BY c.position ASC,c.created_at ASC",[$userId,$userId,$userId,$channel['server_id'],$userId,$userId]],
            ];
            $result=['channel_id'=>$channelId,'server_id'=>(int)$channel['server_id'],'viewer_id'=>$userId,
                'samples'=>$repeats,'scope'=>'Read-only SQL timings; excludes HTTP, PHP-FPM queuing, session locks and read-marker writes',
                'queries'=>[]];
            $ids=[];
            foreach ($queries as $name=>[$sql,$params]) {
                [$stats,$rows]=self::measure($db,$sql,$params,$repeats);
                $result['queries'][$name]=$stats;
                if ($name==='message_page') $ids=array_column($rows,'id');
                unset($rows);
            }
            if ($ids) {
                $placeholders=implode(',',array_fill(0,count($ids),'?'));
                $extras=[
                    'reactions'=>["SELECT mr.message_id,mr.emoji,COUNT(*) AS cnt,
                        MAX(CASE WHEN mr.user_id=? THEN 1 ELSE 0 END) AS reacted_by_me
                        FROM message_reactions mr WHERE mr.message_id IN ($placeholders)
                        GROUP BY mr.message_id,mr.emoji ORDER BY mr.message_id,cnt DESC",array_merge([$userId],$ids)],
                    'attachments'=>["SELECT message_id,file_name,file_path,file_size,mime_type
                        FROM message_attachments WHERE message_id IN ($placeholders)",$ids],
                ];
                foreach ($extras as $name=>[$sql,$params]) {
                    [$stats]=self::measure($db,$sql,$params,$repeats);$result['queries'][$name]=$stats;
                }
            }
            return $result;
        } finally { if ($db->inTransaction()) $db->rollBack(); }
    }

    private static function measure(PDO $db,string $sql,array $params,int $repeats): array
    {
        $stmt=$db->prepare($sql);$times=[];$rows=[];
        for ($i=0;$i<$repeats;$i++) {
            $start=hrtime(true);$stmt->execute($params);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
            $times[]=round((hrtime(true)-$start)/1e6,3);$stmt->closeCursor();
        }
        $explain=$db->prepare('EXPLAIN '.$sql);$explain->execute($params);
        return [['rows_returned'=>count($rows),'milliseconds'=>$times,
            'average_ms'=>round(array_sum($times)/count($times),3),'plan'=>$explain->fetchAll(PDO::FETCH_ASSOC)],$rows];
    }
}
