<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/database/config/db.php';
require_once __DIR__ . '/MembershipService.php';

/**
 * Student dashboard data service.
 *
 * The dashboard is intentionally derived from the same server/chat data used
 * by the chat module. A "course" on this dashboard is a server/community,
 * not a hard-coded academic-program card.
 */
class StudentDashboardService
{
    private PDO $db;
    private MembershipService $membershipService;

    public function __construct(?PDO $db = null, ?MembershipService $membershipService = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->membershipService = $membershipService ?? new MembershipService($this->db);
    }

    public function getStudentDashboardData(int $userId): array
    {
        return [
            'courses' => $this->getStudentCourses($userId),
            'upcoming_sessions' => $this->getUpcomingSessions($userId),
            'notifications' => $this->getNotifications($userId),
            'unread_notifications' => $this->getUnreadNotificationCount($userId),
            'friends_online' => $this->getFriendsOnline($userId),
            'study_rooms' => $this->getActiveStudyRooms($userId),
            'recommended_servers' => $this->getRecommendedServers($userId),
            'files' => $this->getRecentFiles($userId),
            'notes' => $this->getStudentNotes($userId),
            'coworkspaces' => $this->getCoworkspaces($userId),
            'activity_chart' => $this->getActivityChartData($userId),
            'total_sessions' => $this->getTotalSessions($userId),
            'hours_studied' => $this->getHoursStudied($userId),
            'study_streak' => $this->getStudyStreak($userId),
            'focus_time' => $this->getFocusTime($userId),
            'achievement_count' => $this->getAchievementCount($userId),
            'quiz_accuracy' => $this->getQuizAccuracy($userId),
            'best_subject' => $this->getBestSubject($userId),
            'messages_sent' => $this->getMessagesSent($userId),
            'chat_unread' => $this->getChatUnreadCount($userId),
            'chat_recent' => $this->getRecentChat($userId),
            'membership' => $this->membershipService->getMembershipSummary($userId),
        ];
    }

    /** A dashboard course is a server/community the student belongs to. */
    private function getStudentCourses(int $userId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    s.id,
                    s.name,
                    s.name AS course_code,
                    s.description,
                    s.icon_emoji,
                    s.type,
                    s.owner_id,
                    CASE WHEN s.owner_id = :owner_uid THEN 1 ELSE 0 END AS is_owned,
                    sm.server_role,
                    s.member_count,
                    (
                        SELECT COUNT(*) FROM server_members sm2
                        WHERE sm2.server_id = s.id
                    ) AS people_count,
                    (
                        SELECT COUNT(*) FROM channels c
                        WHERE c.server_id = s.id
                    ) AS channel_count,
                    (
                        SELECT COUNT(*) FROM channels c2
                        WHERE c2.server_id = s.id AND c2.type IN ('text','announcement')
                    ) AS text_channel_count
                FROM servers s
                INNER JOIN server_members sm
                    ON sm.server_id = s.id AND sm.user_id = :member_uid
                WHERE s.status = 'active'
                ORDER BY sm.joined_at DESC, s.name ASC
            ");
            $stmt->execute([':owner_uid' => $userId, ':member_uid' => $userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    private function getUpcomingSessions(int $userId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT srs.id, srs.title AS name, srs.description,
                       srs.scheduled_start AS start_time, srs.scheduled_end AS end_time,
                       COUNT(srp.user_id) AS rsvp_count
                FROM study_room_sessions srs
                INNER JOIN study_room_participants mine
                    ON mine.session_id = srs.id AND mine.user_id = :uid_mine
                LEFT JOIN study_room_participants srp
                    ON srp.session_id = srs.id
                WHERE srs.scheduled_start >= NOW()
                  AND srs.status IN ('scheduled','active')
                GROUP BY srs.id
                ORDER BY srs.scheduled_start ASC
                LIMIT 4
            ");
            $stmt->execute([':uid_mine' => $userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    private function getNotifications(int $userId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT n.id, n.title, n.body AS message, n.is_read, n.icon,
                       n.type, n.link_url,
                       CASE
                         WHEN n.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                           THEN CONCAT(TIMESTAMPDIFF(MINUTE,n.created_at,NOW()),'m ago')
                         WHEN n.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                           THEN CONCAT(TIMESTAMPDIFF(HOUR,n.created_at,NOW()),'h ago')
                         ELSE CONCAT(TIMESTAMPDIFF(DAY,n.created_at,NOW()),'d ago')
                       END AS time_ago
                FROM notifications n
                WHERE n.recipient_id = :uid
                ORDER BY n.created_at DESC
                LIMIT 12
            ");
            $stmt->execute([':uid' => $userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $chatUnread = $this->getChatUnreadCount($userId);
            if ($chatUnread > 0) {
                array_unshift($rows, [
                    'id' => 'chat-unread',
                    'title' => 'Unread chat activity',
                    'message' => $chatUnread . ' unread message' . ($chatUnread === 1 ? '' : 's') . ' across your chats.',
                    'is_read' => 0,
                    'icon' => '💬',
                    'type' => 'message',
                    'link_url' => '/modules/chat/chat.php',
                    'time_ago' => 'now',
                ]);
            }
            return array_slice($rows, 0, 12);
        } catch (Throwable) {
            return [];
        }
    }

    private function getUnreadNotificationCount(int $userId): int
    {
        try {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM notifications WHERE recipient_id = :uid AND is_read = 0');
            $stmt->execute([':uid' => $userId]);
            return (int)$stmt->fetchColumn() + $this->getChatUnreadCount($userId);
        } catch (Throwable) {
            return $this->getChatUnreadCount($userId);
        }
    }

    private function getFriendsOnline(int $userId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT u.id,u.username,u.full_name,u.avatar_color_gradient,u.status,
                       COALESCE(u.current_activity,'Online') AS activity
                FROM friendships f
                JOIN users u ON CASE WHEN f.requester_id=:uid THEN f.addressee_id ELSE f.requester_id END=u.id
                WHERE (f.requester_id=:uid2 OR f.addressee_id=:uid3)
                  AND f.status='accepted' AND u.is_online=1 AND u.deleted_at IS NULL
                ORDER BY u.last_active_at DESC LIMIT 8
            ");
            $stmt->execute([':uid'=>$userId,':uid2'=>$userId,':uid3'=>$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) { return []; }
    }

    private function getActiveStudyRooms(int $userId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT sr.id,sr.name,sr.description,sr.icon_emoji AS icon,sr.max_members,
                       COUNT(srp.user_id) AS active_members
                FROM study_rooms sr
                LEFT JOIN study_room_participants srp ON srp.room_id=sr.id AND srp.is_active=1
                JOIN server_members sm ON sm.server_id=sr.server_id AND sm.user_id=:uid
                WHERE sr.status='active'
                GROUP BY sr.id ORDER BY active_members DESC LIMIT 6
            ");
            $stmt->execute([':uid'=>$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) { return []; }
    }

    /** Return every public server, ranked by overlap with the user's actual interest tags. */
    private function getRecommendedServers(int $userId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    s.id,s.name,s.description,s.icon_emoji,s.member_count,s.category,s.type,
                    COUNT(DISTINCT CASE WHEN ui.user_id IS NOT NULL THEN sit.interest_tag_id END) AS tag_match_count,
                    GROUP_CONCAT(DISTINCT it.name ORDER BY it.name SEPARATOR ', ') AS tag_labels,
                    GROUP_CONCAT(DISTINCT it.slug ORDER BY it.slug SEPARATOR ' ') AS tags,
                    (
                        SELECT COUNT(*) FROM server_members som
                        JOIN users sou ON sou.id=som.user_id
                        WHERE som.server_id=s.id AND sou.is_online=1
                    ) AS online_count
                FROM servers s
                LEFT JOIN server_tags sit ON sit.server_id=s.id
                LEFT JOIN interest_tags it ON it.id=sit.interest_tag_id
                LEFT JOIN user_interests ui
                    ON ui.user_id=:uid
                   AND ui.interest_tag_id=sit.interest_tag_id
                WHERE s.status='active'
                  AND s.type='public'
                  AND NOT EXISTS (
                      SELECT 1 FROM server_members mine
                      WHERE mine.server_id=s.id AND mine.user_id=:uid_member
                  )
                GROUP BY s.id
                ORDER BY tag_match_count DESC, s.member_count DESC, s.name ASC
            ");
            $stmt->execute([':uid'=>$userId,':uid_member'=>$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    /** Server-wide resources: uploaded files plus message attachments from every joined server. */
    private function getRecentFiles(int $userId): array
    {
        $items = [];
        try {
            $stmt = $this->db->prepare("
                SELECT uf.id,uf.file_name,uf.original_name,uf.file_path,uf.file_size,uf.mime_type,
                       uf.server_id, s.name AS server_name, uf.channel_id,
                       u.username AS uploader, uf.created_at
                FROM uploaded_files uf
                JOIN server_members sm ON sm.server_id=uf.server_id AND sm.user_id=:uid
                JOIN servers s ON s.id=uf.server_id
                JOIN users u ON u.id=uf.uploader_id
                WHERE uf.deleted_at IS NULL
                ORDER BY uf.created_at DESC LIMIT 20
            ");
            $stmt->execute([':uid'=>$userId]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {}

        try {
            $stmt = $this->db->prepare("
                SELECT ma.id,ma.file_name AS original_name,ma.file_name,ma.file_path,ma.file_size,ma.mime_type,
                       c.server_id,s.name AS server_name,c.id AS channel_id,
                       u.username AS uploader,ma.created_at
                FROM message_attachments ma
                JOIN messages m ON m.id=ma.message_id AND m.is_deleted=0
                JOIN channels c ON c.id=m.channel_id
                JOIN servers s ON s.id=c.server_id
                JOIN server_members sm ON sm.server_id=s.id AND sm.user_id=:uid
                JOIN users u ON u.id=m.sender_id
                ORDER BY ma.created_at DESC LIMIT 20
            ");
            $stmt->execute([':uid'=>$userId]);
            $items = array_merge($items, $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable) {}

        usort($items, static fn(array $a,array $b)=>strcmp((string)($b['created_at']??''),(string)($a['created_at']??'')));
        return array_slice($items,0,20);
    }

    private function getStudentNotes(int $userId): array
    {
        try {
            $stmt=$this->db->prepare("SELECT sn.id,sn.title,sn.content,
                CASE WHEN sn.updated_at>=CURDATE() THEN 'Today'
                     WHEN sn.updated_at>=DATE_SUB(CURDATE(),INTERVAL 1 DAY) THEN 'Yesterday'
                     ELSE DATE_FORMAT(sn.updated_at,'%M %d') END AS updated_label,
                ap.code AS course_code
                FROM student_notes sn LEFT JOIN academic_programs ap ON ap.id=sn.academic_program_id
                WHERE sn.user_id=:uid ORDER BY sn.updated_at DESC LIMIT 6");
            $stmt->execute([':uid'=>$userId]); return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(Throwable){return [];}
    }

    private function getCoworkspaces(int $userId): array
    {
        try {
            $stmt=$this->db->prepare("SELECT w.id,w.name,w.visibility,w.channel_id,c.name AS channel_name,c.server_id,s.name AS server_name,
                COUNT(DISTINCT wm.user_id) AS member_count
                FROM collab_workspaces w
                JOIN channels c ON c.id=w.channel_id
                JOIN servers s ON s.id=c.server_id
                JOIN server_members sm ON sm.server_id=s.id AND sm.user_id=:uid
                LEFT JOIN collab_workspace_members wm ON wm.workspace_id=w.id
                WHERE w.archived=0 GROUP BY w.id ORDER BY w.updated_at DESC LIMIT 20");
            $stmt->execute([':uid'=>$userId]); return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(Throwable){return [];}
    }

    /** Real activity: study-session minutes plus actual chat messages for each of the last seven days. */
    private function getActivityChartData(int $userId): array
    {
        $data=array_fill(0,7,0.0);
        try {
            $stmt=$this->db->prepare("SELECT DATE(started_at) d,COALESCE(SUM(COALESCE(duration_mins,0)),0)/60.0 h
                FROM study_sessions WHERE user_id=:uid AND started_at>=DATE_SUB(CURDATE(),INTERVAL 6 DAY)
                GROUP BY DATE(started_at)");
            $stmt->execute([':uid'=>$userId]);
            foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $r){$idx=(int)((strtotime($r['d'])-strtotime('-6 days'))/86400);if($idx>=0&&$idx<7)$data[$idx]+=round((float)$r['h'],1);}
        } catch(Throwable) {}
        try {
            $stmt=$this->db->prepare("SELECT DATE(created_at) d,COUNT(*) c FROM messages
                WHERE sender_id=:uid AND is_deleted=0 AND created_at>=DATE_SUB(CURDATE(),INTERVAL 6 DAY)
                GROUP BY DATE(created_at)");
            $stmt->execute([':uid'=>$userId]);
            foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $r){$idx=(int)((strtotime($r['d'])-strtotime('-6 days'))/86400);if($idx>=0&&$idx<7)$data[$idx]+=round((int)$r['c']/10,1);}
        } catch(Throwable) {}
        return $data;
    }

    private function getTotalSessions(int $userId): int
    {
        try{$s=$this->db->prepare('SELECT COUNT(*) FROM study_sessions WHERE user_id=:uid');$s->execute([':uid'=>$userId]);return (int)$s->fetchColumn();}catch(Throwable){return 0;}
    }

    private function getHoursStudied(int $userId): float
    {
        try{$s=$this->db->prepare("SELECT COALESCE(SUM(COALESCE(duration_mins,0))/60.0,0) FROM study_sessions WHERE user_id=:uid AND started_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)");$s->execute([':uid'=>$userId]);return round((float)$s->fetchColumn(),1);}catch(Throwable){return 0.0;}
    }

    private function getStudyStreak(int $userId): int
    {
        try{$s=$this->db->prepare("SELECT COUNT(DISTINCT DATE(created_at)) FROM messages WHERE sender_id=:uid AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)");$s->execute([':uid'=>$userId]);return (int)$s->fetchColumn();}catch(Throwable){return 0;}
    }

    private function getFocusTime(int $userId): float
    {
        try{$s=$this->db->prepare("SELECT COALESCE(SUM(COALESCE(duration_mins,0))/60.0,0) FROM study_sessions WHERE user_id=:uid AND started_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)");$s->execute([':uid'=>$userId]);return round((float)$s->fetchColumn(),1);}catch(Throwable){return 0.0;}
    }

    private function getAchievementCount(int $userId): int
    {
        try{$s=$this->db->prepare('SELECT COUNT(*) FROM user_achievements WHERE user_id=:uid');$s->execute([':uid'=>$userId]);return (int)$s->fetchColumn();}catch(Throwable){return 0;}
    }

    private function getQuizAccuracy(int $userId): int
    {
        try{$s=$this->db->prepare('SELECT COALESCE(ROUND(AVG(score_percentage)),0) FROM quiz_attempts WHERE user_id=:uid');$s->execute([':uid'=>$userId]);return (int)$s->fetchColumn();}catch(Throwable){return 0;}
    }

    private function getBestSubject(int $userId): string
    {
        try{$s=$this->db->prepare("SELECT ap.code FROM quiz_attempts qa JOIN quizzes q ON q.id=qa.quiz_id JOIN academic_programs ap ON ap.id=q.academic_program_id WHERE qa.user_id=:uid GROUP BY ap.id ORDER BY AVG(qa.score_percentage) DESC LIMIT 1");$s->execute([':uid'=>$userId]);return (string)($s->fetchColumn()?:'');}catch(Throwable){return '';}
    }

    private function getMessagesSent(int $userId): int
    {
        try{
            $s=$this->db->prepare("SELECT (SELECT COUNT(*) FROM messages WHERE sender_id=:uid1 AND is_deleted=0)+(SELECT COUNT(*) FROM direct_messages WHERE sender_id=:uid2 AND is_deleted=0)");
            $s->execute([':uid1'=>$userId,':uid2'=>$userId]); return (int)$s->fetchColumn();
        }catch(Throwable){return 0;}
    }

    private function getChatUnreadCount(int $userId): int
    {
        $count=0;
        try{$s=$this->db->prepare('SELECT COUNT(*) FROM direct_messages WHERE recipient_id=:uid AND is_read=0 AND is_deleted=0');$s->execute([':uid'=>$userId]);$count+=(int)$s->fetchColumn();}catch(Throwable){}
        try{$s=$this->db->prepare("SELECT COUNT(*) FROM messages m JOIN server_members sm ON sm.server_id=(SELECT server_id FROM channels WHERE id=m.channel_id) AND sm.user_id=:uid LEFT JOIN message_reads mr ON mr.channel_id=m.channel_id AND mr.user_id=:uid2 WHERE m.is_deleted=0 AND m.sender_id<>:uid3 AND m.created_at>COALESCE(mr.last_read_at,'1970-01-01')");$s->execute([':uid'=>$userId,':uid2'=>$userId,':uid3'=>$userId]);$count+=(int)$s->fetchColumn();}catch(Throwable){}
        return $count;
    }

    private function getRecentChat(int $userId): array
    {
        try{$s=$this->db->prepare("SELECT m.id,m.content,m.created_at,c.name AS channel_name,s.name AS server_name
            FROM messages m JOIN channels c ON c.id=m.channel_id JOIN servers s ON s.id=c.server_id
            JOIN server_members sm ON sm.server_id=s.id AND sm.user_id=:uid
            WHERE m.is_deleted=0 ORDER BY m.created_at DESC LIMIT 8");$s->execute([':uid'=>$userId]);return $s->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable){return [];}
    }
}
