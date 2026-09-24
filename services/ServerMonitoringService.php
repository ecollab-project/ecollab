<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/database/config/db.php';

final class ServerMonitoringService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function getForAdmin(int $serverId): ?array
    {
        return $this->build($serverId);
    }

    public function getForFacilitator(int $serverId, int $userId): ?array
    {
        if (!$this->facilitatorCanMonitor($serverId, $userId)) {
            return null;
        }
        return $this->build($serverId);
    }

    public function getFacilitatorServers(int $userId): array
    {
        try {
            $sql = "SELECT DISTINCT s.id, s.name, COALESCE(s.icon_emoji,'🖥') icon_emoji,
                    COALESCE(s.member_count,(SELECT COUNT(*) FROM server_members sm2 WHERE sm2.server_id=s.id)) member_count
                    FROM servers s
                    LEFT JOIN subject_classes sc ON sc.server_id=s.id
                    LEFT JOIN server_members sm ON sm.server_id=s.id AND sm.user_id=:uid
                    WHERE s.owner_id=:uid
                       OR sc.facilitator_id=:uid
                       OR sm.user_id=:uid
                       OR sm.server_role IN ('owner','admin','moderator')
                    ORDER BY s.name";
            $st=$this->db->prepare($sql); $st->execute([':uid'=>$userId]);
            return $st->fetchAll() ?: [];
        } catch (Throwable) { return []; }
    }

    private function facilitatorCanMonitor(int $serverId, int $userId): bool
    {
        try {
            $st=$this->db->prepare("SELECT 1
                FROM servers s
                LEFT JOIN subject_classes sc ON sc.server_id=s.id
                LEFT JOIN server_members sm ON sm.server_id=s.id AND sm.user_id=:uid
                WHERE s.id=:sid AND (s.owner_id=:uid OR sc.facilitator_id=:uid OR sm.user_id=:uid OR sm.server_role IN ('owner','admin','moderator'))
                LIMIT 1");
            $st->execute([':sid'=>$serverId,':uid'=>$userId]);
            return (bool)$st->fetchColumn();
        } catch (Throwable) { return false; }
    }

    private function build(int $serverId): ?array
    {
        $server=$this->server($serverId);
        if (!$server) return null;
        return [
            'server'=>$server,
            'stats'=>$this->stats($serverId),
            'daily'=>$this->daily($serverId),
            'channels'=>$this->channels($serverId),
            'members'=>$this->members($serverId),
            'recent'=>$this->recent($serverId),
            'reports'=>$this->reports($serverId),
            'heatmap'=>$this->heatmap($serverId),
        ];
    }

    private function server(int $id): ?array
    {
        try {
            $st=$this->db->prepare("SELECT s.*, COALESCE(u.username,'Unknown') owner_username,
                COALESCE(u.full_name,u.username,'Unknown') owner_name,
                COALESCE(sc.facilitator_id,0) facilitator_id,
                COALESCE(f.username,'—') facilitator_username
                FROM servers s
                LEFT JOIN users u ON u.id=s.owner_id
                LEFT JOIN subject_classes sc ON sc.server_id=s.id
                LEFT JOIN users f ON f.id=sc.facilitator_id
                WHERE s.id=:id LIMIT 1");
            $st->execute([':id'=>$id]);
            $r=$st->fetch();
            return $r ?: null;
        } catch (Throwable) { return null; }
    }

    private function stats(int $sid): array
    {
        $out=['members'=>0,'channels'=>0,'active_members'=>0,'messages'=>0,'threads'=>0,'reports'=>0];
        try {
            $st=$this->db->prepare("SELECT
                (SELECT COUNT(*) FROM server_members WHERE server_id=:s1) members,
                (SELECT COUNT(*) FROM channels WHERE server_id=:s2) channels,
                (SELECT COUNT(DISTINCT m.sender_id) FROM messages m JOIN channels c ON c.id=m.channel_id WHERE c.server_id=:s3 AND m.is_deleted=0 AND m.created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)) active_members,
                (SELECT COUNT(*) FROM messages m JOIN channels c ON c.id=m.channel_id WHERE c.server_id=:s4 AND m.is_deleted=0) messages,
                (SELECT COUNT(*) FROM threads t WHERE t.server_id=:s5 AND t.is_deleted=0) threads,
                (SELECT COUNT(*) FROM content_reports WHERE server_id=:s6) reports");
            $st->execute([':s1'=>$sid,':s2'=>$sid,':s3'=>$sid,':s4'=>$sid,':s5'=>$sid,':s6'=>$sid]);
            $r=$st->fetch(); if($r) $out=$r;
        } catch(Throwable) {}
        return $out;
    }

    private function daily(int $sid): array
    {
        $days=[]; for($i=6;$i>=0;$i--){$d=date('Y-m-d',strtotime("-$i days"));$days[$d]=['label'=>date('M j',strtotime($d)),'messages'=>0,'active'=>0,'new_members'=>0];}
        try {
            $st=$this->db->prepare("SELECT DATE(m.created_at) d, COUNT(*) messages, COUNT(DISTINCT m.sender_id) active
                FROM messages m JOIN channels c ON c.id=m.channel_id
                WHERE c.server_id=:sid AND m.is_deleted=0 AND m.created_at>=DATE_SUB(CURDATE(),INTERVAL 6 DAY)
                GROUP BY DATE(m.created_at)");
            $st->execute([':sid'=>$sid]); foreach($st->fetchAll() as $r){if(isset($days[$r['d']])){$days[$r['d']]['messages']=(int)$r['messages'];$days[$r['d']]['active']=(int)$r['active'];}}
            $st=$this->db->prepare("SELECT DATE(joined_at) d, COUNT(*) c FROM server_members WHERE server_id=:sid AND joined_at>=DATE_SUB(CURDATE(),INTERVAL 6 DAY) GROUP BY DATE(joined_at)");
            $st->execute([':sid'=>$sid]); foreach($st->fetchAll() as $r){if(isset($days[$r['d']]))$days[$r['d']]['new_members']=(int)$r['c'];}
        } catch(Throwable) {}
        return array_values($days);
    }

    private function channels(int $sid): array
    {
        try {
            $st=$this->db->prepare("SELECT c.id,c.name,c.type,c.is_locked,
                COUNT(m.id) messages,
                COUNT(DISTINCT CASE WHEN m.created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) THEN m.sender_id END) active_members
                FROM channels c
                LEFT JOIN messages m ON m.channel_id=c.id AND m.is_deleted=0
                WHERE c.server_id=:sid GROUP BY c.id ORDER BY messages DESC");
            $st->execute([':sid'=>$sid]); return $st->fetchAll() ?: [];
        } catch(Throwable) { return []; }
    }

    private function members(int $sid): array
    {
        try {
            $st=$this->db->prepare("SELECT u.id,u.username,COALESCE(u.full_name,u.username) full_name,u.status,
                CASE WHEN u.id=s.owner_id THEN 'owner' ELSE sm.server_role END server_role,
                (u.id=s.owner_id) is_owner,
                COUNT(m.id) messages, MAX(m.created_at) last_message
                FROM server_members sm
                JOIN servers s ON s.id=sm.server_id
                JOIN users u ON u.id=sm.user_id
                LEFT JOIN channels c ON c.server_id=sm.server_id
                LEFT JOIN messages m ON m.channel_id=c.id AND m.sender_id=u.id AND m.is_deleted=0
                WHERE sm.server_id=:sid
                GROUP BY u.id,u.username,u.full_name,u.status,sm.server_role,s.owner_id
                ORDER BY is_owner DESC,messages DESC LIMIT 20");
            $st->execute([':sid'=>$sid]); return $st->fetchAll() ?: [];
        } catch(Throwable) { return []; }
    }

    private function recent(int $sid): array
    {
        try {
            $st=$this->db->prepare("SELECT m.id,m.created_at,c.name channel_name,u.username,
                LEFT(m.content,120) content
                FROM messages m JOIN channels c ON c.id=m.channel_id JOIN users u ON u.id=m.sender_id
                WHERE c.server_id=:sid AND m.is_deleted=0 ORDER BY m.created_at DESC LIMIT 8");
            $st->execute([':sid'=>$sid]); return $st->fetchAll() ?: [];
        } catch(Throwable) { return []; }
    }

    private function reports(int $sid): array
    {
        try {
            $st=$this->db->prepare("SELECT cr.id,cr.reason,cr.status,cr.created_at,
                COALESCE(u.username,'Unknown') reported_user,
                COALESCE(c.name,'—') channel_name
                FROM content_reports cr
                LEFT JOIN users u ON u.id=cr.reported_user_id
                LEFT JOIN messages m ON m.id=cr.message_id
                LEFT JOIN channels c ON c.id=m.channel_id
                WHERE cr.server_id=:sid ORDER BY cr.created_at DESC LIMIT 8");
            $st->execute([':sid'=>$sid]); return $st->fetchAll() ?: [];
        } catch(Throwable) { return []; }
    }

    private function heatmap(int $sid): array
    {
        $grid=array_fill(0,28,0);
        try {
            $st=$this->db->prepare("SELECT WEEKDAY(m.created_at) wd,
                CASE WHEN HOUR(m.created_at)<6 THEN 0 WHEN HOUR(m.created_at)<12 THEN 1 WHEN HOUR(m.created_at)<18 THEN 2 ELSE 3 END band,
                COUNT(*) c
                FROM messages m JOIN channels ch ON ch.id=m.channel_id
                WHERE ch.server_id=:sid AND m.is_deleted=0 AND m.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)
                GROUP BY wd,band");
            $st->execute([':sid'=>$sid]); foreach($st->fetchAll() as $r){$idx=((int)$r['band']*7)+(int)$r['wd'];$grid[$idx]=(int)$r['c'];}
        } catch(Throwable) {}
        return $grid;
    }
}
