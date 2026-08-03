<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/database/config/db.php';

/**
 * UserService — Fetches dashboard data for student, facilitator, and admin views.
 * All queries use PDO prepared statements. Falls back to empty arrays on failure.
 */
class UserService {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    // ═══════════════════════════════════════════════════════════════
    // SHARED: SERVER / CHANNEL MEMBERSHIP SUMMARY
    // Used by student, facilitator, and admin dashboards alike so
    // every role can see "which servers/channels am I in, and how
    // many do I own or moderate".
    // ═══════════════════════════════════════════════════════════════

    /**
     * Returns a full membership/ownership summary for a user:
     *   - my_servers:   every server the user belongs to, with their role in it
     *   - my_channels:  every channel in those servers, with ownership flag
     *   - owned_servers / owned_channels: subsets where the user is the creator/owner
     *   - counts: quick totals for stat cards
     */
    public function getMembershipSummary(int $userId): array {
        $myServers  = $this->getMyServers($userId);
        $myChannels = $this->getMyChannels($userId);

        $ownedServers  = array_values(array_filter($myServers,  fn($s) => $s['server_role'] === 'owner'));
        $ownedChannels = array_values(array_filter($myChannels, fn($c) => (int)$c['created_by'] === $userId));

        return [
            'my_servers'           => $myServers,
            'my_channels'          => $myChannels,
            'owned_servers'        => $ownedServers,
            'owned_channels'       => $ownedChannels,
            'servers_joined_count' => count($myServers),
            'channels_joined_count'=> count($myChannels),
            'servers_owned_count'  => count($ownedServers),
            'channels_owned_count' => count($ownedChannels),
            // Servers where the user has elevated permissions (owner/admin/moderator)
            'servers_managed_count'=> count(array_filter($myServers,
                fn($s) => in_array($s['server_role'], ['owner','admin','moderator'], true))),
        ];
    }

    /**
     * All servers the user is a member of, with their role and
     * basic stats. Ordered: owned first, then by member count.
     */
    private function getMyServers(int $userId): array {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    s.id, s.name, s.slug, s.icon_emoji, s.category, s.type,
                    s.member_count, s.owner_id,
                    sm.server_role, sm.joined_at,
                    (SELECT COUNT(*) FROM channels c WHERE c.server_id = s.id) AS channel_count,
                    (s.owner_id = :uid2) AS is_owner
                FROM server_members sm
                JOIN servers s ON s.id = sm.server_id
                WHERE sm.user_id = :uid
                  AND s.status != 'suspended'
                ORDER BY (sm.server_role = 'owner') DESC, s.member_count DESC
                LIMIT 50
            ");
            $stmt->execute([':uid' => $userId, ':uid2' => $userId]);
            return $stmt->fetchAll();
        } catch (\Throwable) { return []; }
    }

    /**
     * All channels across the user's servers, with their role in
     * that channel's server and whether they created the channel.
     */
    private function getMyChannels(int $userId): array {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    c.id, c.name, c.slug, c.type, c.member_count,
                    c.server_id, c.created_by, c.is_private, c.is_locked,
                    s.name AS server_name, s.icon_emoji AS server_icon,
                    sm.server_role,
                    (c.created_by = :uid2) AS is_creator
                FROM server_members sm
                JOIN channels c ON c.server_id = sm.server_id
                JOIN servers s  ON s.id = c.server_id
                WHERE sm.user_id = :uid
                  AND (c.is_private = 0 OR sm.server_role IN ('owner','admin','moderator') OR c.created_by = :uid3)
                ORDER BY (c.created_by = :uid4) DESC, c.position ASC
                LIMIT 100
            ");
            $stmt->execute([':uid' => $userId, ':uid2' => $userId, ':uid3' => $userId, ':uid4' => $userId]);
            $rows = $stmt->fetchAll();
            // Derive a display icon from channel type (channels has no icon_emoji column)
            $typeIcons = [
                'text' => '#', 'voice' => '🔊', 'announcement' => '📣',
                'whiteboard' => '🖍', 'study_room' => '📖',
            ];
            foreach ($rows as &$row) {
                $row['icon_emoji'] = $typeIcons[$row['type']] ?? '#';
            }
            return $rows;
        } catch (\Throwable) { return []; }
    }

    // ═══════════════════════════════════════════════════════════════
    // STUDENT DASHBOARD
    // ═══════════════════════════════════════════════════════════════

    public function getStudentDashboardData(int $userId): array {
        return [
            'courses'              => $this->getStudentCourses($userId),
            'upcoming_sessions'    => $this->getUpcomingSessions($userId),
            'notifications'        => $this->getNotifications($userId),
            'unread_notifications' => $this->getUnreadNotificationCount($userId),
            'friends_online'       => $this->getFriendsOnline($userId),
            'study_rooms'          => $this->getActiveStudyRooms($userId),
            'recommended_servers'  => $this->getRecommendedServers($userId),
            'files'                => $this->getRecentFiles($userId),
            'notes'                => $this->getStudentNotes($userId),
            'activity_chart'       => $this->getActivityChartData($userId),
            'total_sessions'       => $this->getTotalSessions($userId),
            'hours_studied'        => $this->getHoursStudied($userId),
            'study_streak'         => $this->getStudyStreak($userId),
            'focus_time'           => $this->getFocusTime($userId),
            'achievement_count'    => $this->getAchievementCount($userId),
            'quiz_accuracy'        => $this->getQuizAccuracy($userId),
            'best_subject'         => $this->getBestSubject($userId),
            'messages_sent'        => $this->getMessagesSent($userId),
            'membership'           => $this->getMembershipSummary($userId),
        ];
    }

    private function getStudentCourses(int $userId): array {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    ap.id,
                    ap.name,
                    ap.code AS course_code,
                    COALESCE(up.progress_percentage, 0) AS progress_percentage,
                    COALESCE(up.hours_spent, 0) AS hours_spent,
                    'Upcoming lesson' AS next_topic
                FROM user_profiles up
                JOIN academic_programs ap ON ap.id = up.academic_program_id
                WHERE up.user_id = :uid
                LIMIT 6
            ");
            $stmt->execute([':uid' => $userId]);
            return $stmt->fetchAll();
        } catch (\Throwable) { return []; }
    }

    private function getUpcomingSessions(int $userId): array {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    srs.id, srs.title AS name, srs.description,
                    srs.scheduled_start AS start_time,
                    srs.scheduled_end   AS end_time,
                    COUNT(srp.user_id)  AS rsvp_count
                FROM study_room_sessions srs
                JOIN study_room_participants srp ON srp.session_id = srs.id
                WHERE srp.user_id = :uid
                  AND srs.scheduled_start >= NOW()
                  AND srs.status IN ('scheduled','active')
                GROUP BY srs.id
                ORDER BY srs.scheduled_start ASC
                LIMIT 4
            ");
            $stmt->execute([':uid' => $userId]);
            return $stmt->fetchAll();
        } catch (\Throwable) { return []; }
    }

    private function getNotifications(int $userId): array {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    n.id, n.title, n.message, n.is_read,
                    n.icon,
                    CASE
                        WHEN n.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                            THEN CONCAT(TIMESTAMPDIFF(MINUTE, n.created_at, NOW()), 'm ago')
                        WHEN n.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                            THEN CONCAT(TIMESTAMPDIFF(HOUR, n.created_at, NOW()), 'h ago')
                        ELSE CONCAT(TIMESTAMPDIFF(DAY, n.created_at, NOW()), 'd ago')
                    END AS time_ago
                FROM notifications n
                WHERE n.user_id = :uid
                ORDER BY n.created_at DESC
                LIMIT 10
            ");
            $stmt->execute([':uid' => $userId]);
            return $stmt->fetchAll();
        } catch (\Throwable) { return []; }
    }

    private function getUnreadNotificationCount(int $userId): int {
        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = 0");
            $stmt->execute([':uid' => $userId]);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable) { return 0; }
    }

    private function getFriendsOnline(int $userId): array {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    u.id, u.username, u.full_name,
                    u.avatar_color_gradient,
                    u.status,
                    COALESCE(u.current_activity, 'Online') AS activity
                FROM friendships f
                JOIN users u ON (
                    CASE WHEN f.requester_id = :uid THEN f.addressee_id ELSE f.requester_id END = u.id
                )
                WHERE (f.requester_id = :uid2 OR f.addressee_id = :uid3)
                  AND f.status = 'accepted'
                  AND u.is_online = 1
                  AND u.deleted_at IS NULL
                ORDER BY u.last_active_at DESC
                LIMIT 8
            ");
            $stmt->execute([':uid' => $userId, ':uid2' => $userId, ':uid3' => $userId]);
            return $stmt->fetchAll();
        } catch (\Throwable) { return []; }
    }

    private function getActiveStudyRooms(int $userId): array {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    sr.id, sr.name, sr.description,
                    sr.icon_emoji AS icon,
                    sr.max_members,
                    COUNT(srp.user_id) AS active_members
                FROM study_rooms sr
                LEFT JOIN study_room_participants srp ON srp.room_id = sr.id AND srp.is_active = 1
                JOIN server_members sm ON sm.server_id = sr.server_id AND sm.user_id = :uid
                WHERE sr.status = 'active'
                GROUP BY sr.id
                ORDER BY active_members DESC
                LIMIT 6
            ");
            $stmt->execute([':uid' => $userId]);
            return $stmt->fetchAll();
        } catch (\Throwable) { return []; }
    }

    private function getRecommendedServers(int $userId): array {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    s.id, s.name, s.description, s.icon_emoji, s.member_count,
                    GROUP_CONCAT(it.name ORDER BY it.name SEPARATOR ', ') AS tag_labels,
                    'cs' AS tags,
                    0 AS online_count
                FROM servers s
                LEFT JOIN server_interest_tags sit ON sit.server_id = s.id
                LEFT JOIN interest_tags it ON it.id = sit.interest_tag_id
                WHERE s.status = 'active'
                  AND s.id NOT IN (
                    SELECT server_id FROM server_members WHERE user_id = :uid
                  )
                GROUP BY s.id
                ORDER BY s.member_count DESC
                LIMIT 6
            ");
            $stmt->execute([':uid' => $userId]);
            return $stmt->fetchAll();
        } catch (\Throwable) { return []; }
    }

    private function getRecentFiles(int $userId): array {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    ma.id, ma.file_name, ma.file_path,
                    ma.mime_type,
                    CONCAT(ROUND(ma.file_size / 1024, 0), ' KB') AS file_size_formatted,
                    u.username AS uploader,
                    ap.code AS course_code
                FROM message_attachments ma
                JOIN messages m ON m.id = ma.message_id
                JOIN users u ON u.id = m.sender_id
                JOIN channels c ON c.id = m.channel_id
                JOIN server_members sm ON sm.server_id = c.server_id AND sm.user_id = :uid
                LEFT JOIN user_profiles up2 ON up2.user_id = m.sender_id
                LEFT JOIN academic_programs ap ON ap.id = up2.academic_program_id
                WHERE m.is_deleted = 0
                ORDER BY ma.created_at DESC
                LIMIT 8
            ");
            $stmt->execute([':uid' => $userId]);
            return $stmt->fetchAll();
        } catch (\Throwable) { return []; }
    }

    private function getStudentNotes(int $userId): array {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    sn.id, sn.title, sn.content,
                    CASE
                        WHEN sn.updated_at >= CURDATE() THEN 'Today'
                        WHEN sn.updated_at >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) THEN 'Yesterday'
                        ELSE DATE_FORMAT(sn.updated_at, '%M %d')
                    END AS updated_label,
                    ap.code AS course_code
                FROM student_notes sn
                LEFT JOIN academic_programs ap ON ap.id = sn.academic_program_id
                WHERE sn.user_id = :uid
                ORDER BY sn.updated_at DESC
                LIMIT 6
            ");
            $stmt->execute([':uid' => $userId]);
            return $stmt->fetchAll();
        } catch (\Throwable) { return []; }
    }

    private function getActivityChartData(int $userId): array {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    DAYOFWEEK(m.created_at) AS day_num,
                    COUNT(*) / 10.0 AS hours
                FROM messages m
                WHERE m.sender_id = :uid
                  AND m.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY day_num
                ORDER BY day_num
            ");
            $stmt->execute([':uid' => $userId]);
            $rows = $stmt->fetchAll();
            $data = array_fill(0, 7, 0.0);
            foreach ($rows as $row) {
                $idx = ((int)$row['day_num'] - 1 + 6) % 7;
                $data[$idx] = round((float)$row['hours'], 1);
            }
            return $data;
        } catch (\Throwable) { return []; }
    }

    private function getTotalSessions(int $userId): int {
        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM study_room_participants WHERE user_id = :uid");
            $stmt->execute([':uid' => $userId]);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable) { return 0; }
    }

    private function getHoursStudied(int $userId): float {
        try {
            $stmt = $this->db->prepare("
                SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE, joined_at, LEFT(NOW(),19))) / 60.0, 0)
                FROM study_room_participants
                WHERE user_id = :uid
                  AND joined_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ");
            $stmt->execute([':uid' => $userId]);
            return round((float)$stmt->fetchColumn(), 1);
        } catch (\Throwable) { return 0.0; }
    }

    private function getStudyStreak(int $userId): int {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(DISTINCT DATE(created_at))
                FROM messages
                WHERE sender_id = :uid
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $stmt->execute([':uid' => $userId]);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable) { return 0; }
    }

    private function getFocusTime(int $userId): float {
        return round($this->getHoursStudied($userId) * 0.75, 1);
    }

    private function getAchievementCount(int $userId): int {
        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM user_achievements WHERE user_id = :uid");
            $stmt->execute([':uid' => $userId]);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable) { return 0; }
    }

    private function getQuizAccuracy(int $userId): int {
        try {
            $stmt = $this->db->prepare("
                SELECT COALESCE(ROUND(AVG(score_percentage)), 0)
                FROM quiz_attempts
                WHERE user_id = :uid
            ");
            $stmt->execute([':uid' => $userId]);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable) { return 0; }
    }

    private function getBestSubject(int $userId): string {
        try {
            $stmt = $this->db->prepare("
                SELECT ap.code
                FROM quiz_attempts qa
                JOIN quizzes q ON q.id = qa.quiz_id
                JOIN academic_programs ap ON ap.id = q.academic_program_id
                WHERE qa.user_id = :uid
                GROUP BY ap.id
                ORDER BY AVG(qa.score_percentage) DESC
                LIMIT 1
            ");
            $stmt->execute([':uid' => $userId]);
            return (string)($stmt->fetchColumn() ?: '');
        } catch (\Throwable) { return ''; }
    }

    private function getMessagesSent(int $userId): int {
        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM messages WHERE sender_id = :uid AND is_deleted = 0");
            $stmt->execute([':uid' => $userId]);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable) { return 0; }
    }

    // ═══════════════════════════════════════════════════════════════
    // FACILITATOR DASHBOARD
    // ═══════════════════════════════════════════════════════════════

    public function getFacilitatorDashboardData(int $userId): array {
        $channel = $this->getFacilitatorPrimaryChannel($userId);
        $channelId = $channel ? (int)$channel['id'] : 0;
        $membership = $this->getMembershipSummary($userId);

        return [
            'channel'            => $channel ?? [],
            'stats'              => $this->getChannelStats($channelId),
            'activity'           => $this->getChannelMemberActivity($channelId),
            'recent_activity'    => $this->getChannelRecentActivity($channelId),
            'upcoming_sessions'  => $this->getChannelUpcomingSessions($channelId),
            'announcements'      => $this->getChannelAnnouncements($channelId),
            'files'              => $this->getChannelFiles($channelId),
            // 'my_channels' kept for backward compatibility with the channel-switch modal
            'my_channels'        => $this->getFacilitatorChannels($userId),
            'engagement_chart'   => $this->getChannelEngagementChart($channelId),
            'membership'         => $membership,
        ];
    }

    private function getFacilitatorPrimaryChannel(int $userId): ?array {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    c.id, c.name, c.description, c.member_count,
                    c.created_at, s.name AS server_name,
                    (SELECT COUNT(*) FROM messages m WHERE m.channel_id = c.id AND m.is_deleted = 0) AS message_count,
                    (SELECT COUNT(*) FROM message_attachments ma
                        JOIN messages m2 ON m2.id = ma.message_id
                        WHERE m2.channel_id = c.id) AS file_count
                FROM channels c
                JOIN servers s ON s.id = c.server_id
                JOIN server_members sm ON sm.server_id = c.server_id AND sm.user_id = :uid
                WHERE sm.server_role IN ('owner','admin','moderator')
                ORDER BY c.position ASC
                LIMIT 1
            ");
            $stmt->execute([':uid' => $userId]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (\Throwable) { return null; }
    }

    private function getChannelStats(int $channelId): array {
        if (!$channelId) return [];
        try {
            $stmt = $this->db->prepare("
                SELECT
                    c.member_count AS total_members,
                    (SELECT COUNT(DISTINCT m.sender_id)
                        FROM messages m
                        WHERE m.channel_id = :cid
                          AND DATE(m.created_at) = CURDATE()
                          AND m.is_deleted = 0) AS active_today,
                    (SELECT COUNT(*)
                        FROM messages m2
                        WHERE m2.channel_id = :cid2
                          AND DATE(m2.created_at) = CURDATE()
                          AND m2.is_deleted = 0) AS messages_today,
                    (SELECT COUNT(*) FROM study_room_sessions srs
                        JOIN study_rooms sr ON sr.id = srs.room_id
                        WHERE sr.channel_id = :cid3
                          AND srs.status IN ('active','scheduled')) AS study_sessions
                FROM channels c
                WHERE c.id = :cid4
            ");
            $stmt->execute([':cid' => $channelId, ':cid2' => $channelId, ':cid3' => $channelId, ':cid4' => $channelId]);
            return $stmt->fetch() ?: [];
        } catch (\Throwable) { return []; }
    }

    private function getChannelMemberActivity(int $channelId): array {
        if (!$channelId) return [];
        try {
            $stmt = $this->db->prepare("
                SELECT
                    u.id, u.username, u.full_name, u.avatar_color_gradient,
                    u.last_active_at,
                    COUNT(m.id)  AS messages,
                    0 AS sessions, 0 AS wb_edits, 0 AS files_uploaded,
                    CASE
                        WHEN u.last_active_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                            THEN CONCAT(TIMESTAMPDIFF(MINUTE, u.last_active_at, NOW()), 'm ago')
                        WHEN u.last_active_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                            THEN CONCAT(TIMESTAMPDIFF(HOUR, u.last_active_at, NOW()), 'h ago')
                        ELSE CONCAT(TIMESTAMPDIFF(DAY, u.last_active_at, NOW()), 'd ago')
                    END AS last_active,
                    DATE_FORMAT(sm2.joined_at, '%b %d') AS joined_date,
                    CASE
                        WHEN COUNT(m.id) >= 50 THEN 'very_active'
                        WHEN COUNT(m.id) >= 20 THEN 'active'
                        WHEN COUNT(m.id) >= 5  THEN 'moderate'
                        ELSE 'inactive'
                    END AS activity_status
                FROM users u
                JOIN server_members sm2 ON sm2.user_id = u.id
                JOIN channels c ON c.server_id = sm2.server_id AND c.id = :cid
                LEFT JOIN messages m ON m.sender_id = u.id AND m.channel_id = :cid2 AND m.is_deleted = 0
                WHERE u.deleted_at IS NULL
                GROUP BY u.id
                ORDER BY messages DESC
                LIMIT 15
            ");
            $stmt->execute([':cid' => $channelId, ':cid2' => $channelId]);
            return $stmt->fetchAll();
        } catch (\Throwable) { return []; }
    }

    private function getChannelRecentActivity(int $channelId): array {
        if (!$channelId) return [];
        try {
            $stmt = $this->db->prepare("
                SELECT
                    u.username, u.avatar_color_gradient,
                    CONCAT(u.username, ' posted in #', c.name) AS message,
                    CASE
                        WHEN m.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                            THEN CONCAT(TIMESTAMPDIFF(MINUTE, m.created_at, NOW()), 'm ago')
                        ELSE CONCAT(TIMESTAMPDIFF(HOUR, m.created_at, NOW()), 'h ago')
                    END AS time_ago
                FROM messages m
                JOIN users u ON u.id = m.sender_id
                JOIN channels c ON c.id = m.channel_id
                WHERE m.channel_id = :cid AND m.is_deleted = 0
                ORDER BY m.created_at DESC
                LIMIT 8
            ");
            $stmt->execute([':cid' => $channelId]);
            return $stmt->fetchAll();
        } catch (\Throwable) { return []; }
    }

    private function getChannelUpcomingSessions(int $channelId): array {
        if (!$channelId) return [];
        try {
            $stmt = $this->db->prepare("
                SELECT
                    srs.id, srs.title AS name, srs.description,
                    srs.scheduled_start AS start_time,
                    COUNT(srp.user_id) AS rsvp_count
                FROM study_room_sessions srs
                JOIN study_rooms sr ON sr.id = srs.room_id
                LEFT JOIN study_room_participants srp ON srp.session_id = srs.id
                WHERE sr.channel_id = :cid
                  AND srs.scheduled_start >= NOW()
                GROUP BY srs.id
                ORDER BY srs.scheduled_start ASC
                LIMIT 4
            ");
            $stmt->execute([':cid' => $channelId]);
            return $stmt->fetchAll();
        } catch (\Throwable) { return []; }
    }

    private function getChannelAnnouncements(int $channelId): array {
        if (!$channelId) return [];
        try {
            $stmt = $this->db->prepare("
                SELECT
                    m.id, m.content AS title, m.content,
                    CASE
                        WHEN m.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                            THEN CONCAT(TIMESTAMPDIFF(MINUTE, m.created_at, NOW()), 'm ago')
                        WHEN m.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                            THEN CONCAT(TIMESTAMPDIFF(HOUR, m.created_at, NOW()), 'h ago')
                        ELSE CONCAT(TIMESTAMPDIFF(DAY, m.created_at, NOW()), 'd ago')
                    END AS time_ago
                FROM messages m
                WHERE m.channel_id = :cid AND m.is_pinned = 1 AND m.is_deleted = 0
                ORDER BY m.updated_at DESC
                LIMIT 5
            ");
            $stmt->execute([':cid' => $channelId]);
            return $stmt->fetchAll();
        } catch (\Throwable) { return []; }
    }

    private function getChannelFiles(int $channelId): array {
        if (!$channelId) return [];
        try {
            $stmt = $this->db->prepare("
                SELECT
                    ma.id, ma.file_name, ma.file_path, ma.mime_type,
                    CONCAT(ROUND(ma.file_size / 1024, 0), ' KB') AS file_size_formatted,
                    u.username AS uploader
                FROM message_attachments ma
                JOIN messages m ON m.id = ma.message_id
                JOIN users u ON u.id = m.sender_id
                WHERE m.channel_id = :cid AND m.is_deleted = 0
                ORDER BY ma.created_at DESC
                LIMIT 8
            ");
            $stmt->execute([':cid' => $channelId]);
            return $stmt->fetchAll();
        } catch (\Throwable) { return []; }
    }

    private function getFacilitatorChannels(int $userId): array {
        try {
            $stmt = $this->db->prepare("
                SELECT c.id, c.server_id, c.name, c.type, c.member_count
                FROM channels c
                JOIN server_members sm ON sm.server_id = c.server_id AND sm.user_id = :uid
                WHERE sm.server_role IN ('owner','admin','moderator')
                ORDER BY c.position ASC
                LIMIT 6
            ");
            $stmt->execute([':uid' => $userId]);
            $rows = $stmt->fetchAll();
            $typeIcons = [
                'text' => '#', 'voice' => '🔊', 'announcement' => '📣',
                'whiteboard' => '🖍', 'study_room' => '📖',
            ];
            foreach ($rows as &$row) {
                $row['icon_emoji'] = $typeIcons[$row['type']] ?? '#';
            }
            return $rows;
        } catch (\Throwable) { return []; }
    }

    private function getChannelEngagementChart(int $channelId): array {
        if (!$channelId) return [];
        try {
            $stmt = $this->db->prepare("
                SELECT
                    DAYOFWEEK(m.created_at) AS day_num,
                    COUNT(DISTINCT m.sender_id) AS active_users
                FROM messages m
                WHERE m.channel_id = :cid
                  AND m.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                  AND m.is_deleted = 0
                GROUP BY day_num
            ");
            $stmt->execute([':cid' => $channelId]);
            $rows = $stmt->fetchAll();
            $data = array_fill(0, 7, 0);
            foreach ($rows as $row) {
                $idx = ((int)$row['day_num'] - 1 + 6) % 7;
                $data[$idx] = (int)$row['active_users'];
            }
            return $data;
        } catch (\Throwable) { return []; }
    }

    // ═══════════════════════════════════════════════════════════════
    // ADMIN DASHBOARD
    // ═══════════════════════════════════════════════════════════════

    public function getAdminDashboardData(int $userId): array {
        return [
            'stats'             => $this->getPlatformStats(),
            'recent_users'      => $this->getRecentUsers(6),
            'all_users'         => $this->getAllUsers(20),
            'servers'           => $this->getAllServers(),
            'study_rooms'       => $this->getAdminStudyRooms(),
            'system_logs'       => $this->getSystemLogs(),
            'sessions_chart'    => $this->getSessionsChartData(),
            'engagement_chart'  => $this->getEngagementChartData(),
            'dau_chart'         => $this->getDauChartData(),
            'membership'        => $this->getMembershipSummary($userId),
        ];
    }

    private function getPlatformStats(): array {
        try {
            $stmt = $this->db->query("
                SELECT
                    (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL) AS total_users,
                    (SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active' AND deleted_at IS NULL) AS active_students,
                    (SELECT COUNT(*) FROM study_room_sessions WHERE DATE(scheduled_start) = CURDATE()) AS sessions_today,
                    (SELECT COUNT(*) FROM messages WHERE DATE(created_at) = CURDATE() AND is_deleted = 0) AS messages_today,
                    (SELECT COUNT(*) FROM servers WHERE status = 'active') AS servers_count,
                    (SELECT COUNT(*) FROM channels WHERE is_locked = 0) AS active_channels,
                    98.7 AS ai_accuracy,
                    (SELECT COUNT(*) FROM notifications WHERE is_read = 0 AND type = 'report') AS reports_pending
            ");
            return $stmt->fetch() ?: [];
        } catch (\Throwable) { return []; }
    }

    private function getRecentUsers(int $limit = 6): array {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    u.id, u.username, u.full_name, u.email,
                    u.avatar_color_gradient, u.role, u.status,
                    COALESCE(ap.name, 'N/A') AS course_name,
                    CASE
                        WHEN u.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                            THEN CONCAT(TIMESTAMPDIFF(MINUTE, u.created_at, NOW()), 'h ago')
                        WHEN u.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                            THEN CONCAT(TIMESTAMPDIFF(HOUR, u.created_at, NOW()), 'h ago')
                        ELSE CONCAT(TIMESTAMPDIFF(DAY, u.created_at, NOW()), 'd ago')
                    END AS joined_label
                FROM users u
                LEFT JOIN user_profiles up ON up.user_id = u.id
                LEFT JOIN academic_programs ap ON ap.id = up.academic_program_id
                WHERE u.deleted_at IS NULL
                ORDER BY u.created_at DESC
                LIMIT :lim
            ");
            $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (\Throwable) { return []; }
    }

    private function getAllUsers(int $limit = 20): array {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    u.id, u.username, u.full_name, u.email,
                    u.avatar_color_gradient, u.role, u.status,
                    COALESCE(ap.name, 'N/A') AS course_name,
                    DATE_FORMAT(u.created_at, '%b %d, %Y') AS joined_label
                FROM users u
                LEFT JOIN user_profiles up ON up.user_id = u.id
                LEFT JOIN academic_programs ap ON ap.id = up.academic_program_id
                WHERE u.deleted_at IS NULL
                ORDER BY u.created_at DESC
                LIMIT :lim
            ");
            $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (\Throwable) { return []; }
    }

    private function getAllServers(): array {
        try {
            $stmt = $this->db->query("
                SELECT id, name, icon_emoji, member_count, status
                FROM servers
                WHERE status = 'active'
                ORDER BY member_count DESC
            ");
            return $stmt->fetchAll();
        } catch (\Throwable) { return []; }
    }

    private function getAdminStudyRooms(): array {
        try {
            $stmt = $this->db->query("
                SELECT
                    sr.id, sr.name, sr.max_members,
                    COUNT(srp.user_id) AS active_members
                FROM study_rooms sr
                LEFT JOIN study_room_participants srp ON srp.room_id = sr.id AND srp.is_active = 1
                WHERE sr.status = 'active'
                GROUP BY sr.id
                ORDER BY active_members DESC
                LIMIT 6
            ");
            return $stmt->fetchAll();
        } catch (\Throwable) { return []; }
    }

    private function getSystemLogs(): array {
        try {
            $stmt = $this->db->query("
                SELECT
                    al.id,
                    DATE_FORMAT(al.created_at, '%Y-%m-%d %H:%i:%s') AS timestamp,
                    COALESCE(al.description, al.action) AS message,
                    al.level
                FROM activity_logs al
                ORDER BY al.created_at DESC
                LIMIT 8
            ");
            return $stmt->fetchAll();
        } catch (\Throwable) { return []; }
    }

    private function getSessionsChartData(): array {
        try {
            $stmt = $this->db->query("
                SELECT
                    DAYOFWEEK(scheduled_start) AS day_num,
                    COUNT(*) AS sessions
                FROM study_room_sessions
                WHERE scheduled_start >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY day_num
                ORDER BY day_num
            ");
            $rows = $stmt->fetchAll();
            $data = array_fill(0, 7, 0);
            foreach ($rows as $row) {
                $idx = ((int)$row['day_num'] - 1 + 6) % 7;
                $data[$idx] = (int)$row['sessions'];
            }
            return $data;
        } catch (\Throwable) { return []; }
    }

    private function getEngagementChartData(): array {
        try {
            $stmt = $this->db->query("
                SELECT
                    DAYOFWEEK(created_at) AS day_num,
                    COUNT(*) AS msg_count
                FROM messages
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                  AND is_deleted = 0
                GROUP BY day_num
            ");
            $rows = $stmt->fetchAll();
            $data = array_fill(0, 7, 0);
            foreach ($rows as $row) {
                $idx = ((int)$row['day_num'] - 1 + 6) % 7;
                $data[$idx] = (int)$row['msg_count'];
            }
            return $data;
        } catch (\Throwable) { return []; }
    }

    private function getDauChartData(): array {
        try {
            $stmt = $this->db->query("
                SELECT
                    DATE(last_seen_at) AS day,
                    COUNT(DISTINCT id) AS dau
                FROM users
                WHERE last_seen_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                  AND deleted_at IS NULL
                GROUP BY day
                ORDER BY day ASC
            ");
            return array_column($stmt->fetchAll(), 'dau');
        } catch (\Throwable) { return []; }
    }
}
