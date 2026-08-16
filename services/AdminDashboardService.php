<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/database/config/db.php';

/**
 * AdminDashboardService
 *
 * Handles read-only dashboard aggregation for administrators.
 *
 * This extraction keeps the existing UserService API intact while moving
 * admin-specific database queries into a dedicated service.
 */
class AdminDashboardService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Preserve the exact data structure previously returned by UserService.
     */
    public function getDashboardData(int $userId, array $membership): array
    {
        return [
            'stats'            => $this->getPlatformStats(),
            'recent_users'     => $this->getRecentUsers(6),
            'all_users'        => $this->getAllUsers(20),
            'servers'          => $this->getAllServers(),
            'study_rooms'      => $this->getAdminStudyRooms(),
            'system_logs'      => $this->getSystemLogs(),
            'sessions_chart'   => $this->getSessionsChartData(),
            'engagement_chart' => $this->getEngagementChartData(),
            'dau_chart'        => $this->getDauChartData(),
            'membership'       => $membership,
        ];
    }

    private function getPlatformStats(): array
    {
        try {
            $stmt = $this->db->query("
                SELECT
                    (SELECT COUNT(*)
                        FROM users
                        WHERE deleted_at IS NULL) AS total_users,

                    (SELECT COUNT(*)
                        FROM users
                        WHERE role = 'student'
                          AND status = 'active'
                          AND deleted_at IS NULL) AS active_students,

                    (SELECT COUNT(*)
                        FROM study_room_sessions
                        WHERE DATE(scheduled_start) = CURDATE()) AS sessions_today,

                    (SELECT COUNT(*)
                        FROM messages
                        WHERE DATE(created_at) = CURDATE()
                          AND is_deleted = 0) AS messages_today,

                    (SELECT COUNT(*)
                        FROM servers
                        WHERE status = 'active') AS servers_count,

                    (SELECT COUNT(*)
                        FROM channels
                        WHERE is_locked = 0) AS active_channels,

                    98.7 AS ai_accuracy,

                    (SELECT COUNT(*)
                        FROM notifications
                        WHERE is_read = 0
                          AND type = 'report') AS reports_pending
            ");

            return $stmt->fetch() ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function getRecentUsers(int $limit = 6): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    u.id,
                    u.username,
                    u.full_name,
                    u.email,
                    u.avatar_color_gradient,
                    u.role,
                    u.status,
                    COALESCE(ap.name, 'N/A') AS course_name,
                    CASE
                        WHEN u.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                            THEN CONCAT(
                                TIMESTAMPDIFF(MINUTE, u.created_at, NOW()),
                                'm ago'
                            )
                        WHEN u.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                            THEN CONCAT(
                                TIMESTAMPDIFF(HOUR, u.created_at, NOW()),
                                'h ago'
                            )
                        ELSE CONCAT(
                            TIMESTAMPDIFF(DAY, u.created_at, NOW()),
                            'd ago'
                        )
                    END AS joined_label
                FROM users u
                LEFT JOIN user_profiles up
                    ON up.user_id = u.id
                LEFT JOIN academic_programs ap
                    ON ap.id = up.academic_program_id
                WHERE u.deleted_at IS NULL
                ORDER BY u.created_at DESC
                LIMIT :lim
            ");

            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    private function getAllUsers(int $limit = 20): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    u.id,
                    u.username,
                    u.full_name,
                    u.email,
                    u.avatar_color_gradient,
                    u.role,
                    u.status,
                    COALESCE(ap.name, 'N/A') AS course_name,
                    DATE_FORMAT(
                        u.created_at,
                        '%b %d, %Y'
                    ) AS joined_label
                FROM users u
                LEFT JOIN user_profiles up
                    ON up.user_id = u.id
                LEFT JOIN academic_programs ap
                    ON ap.id = up.academic_program_id
                WHERE u.deleted_at IS NULL
                ORDER BY u.created_at DESC
                LIMIT :lim
            ");

            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    private function getAllServers(): array
    {
        try {
            $stmt = $this->db->query("
                SELECT
                    id,
                    name,
                    icon_emoji,
                    member_count,
                    status
                FROM servers
                WHERE status = 'active'
                ORDER BY member_count DESC
            ");

            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    private function getAdminStudyRooms(): array
    {
        try {
            $stmt = $this->db->query("
                SELECT
                    sr.id,
                    sr.name,
                    sr.max_members,
                    COUNT(srp.user_id) AS active_members
                FROM study_rooms sr
                LEFT JOIN study_room_participants srp
                    ON srp.room_id = sr.id
                   AND srp.is_active = 1
                WHERE sr.status = 'active'
                GROUP BY sr.id
                ORDER BY active_members DESC
                LIMIT 6
            ");

            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    private function getSystemLogs(): array
    {
        try {
            $stmt = $this->db->query("
                SELECT
                    al.id,
                    DATE_FORMAT(
                        al.created_at,
                        '%Y-%m-%d %H:%i:%s'
                    ) AS timestamp,
                    COALESCE(
                        al.description,
                        al.action
                    ) AS message,
                    al.level
                FROM activity_logs al
                ORDER BY al.created_at DESC
                LIMIT 8
            ");

            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    private function getSessionsChartData(): array
    {
        try {
            $stmt = $this->db->query("
                SELECT
                    DAYOFWEEK(scheduled_start) AS day_num,
                    COUNT(*) AS sessions
                FROM study_room_sessions
                WHERE scheduled_start >= DATE_SUB(
                    NOW(),
                    INTERVAL 7 DAY
                )
                GROUP BY day_num
                ORDER BY day_num
            ");

            $rows = $stmt->fetchAll();

            $data = array_fill(0, 7, 0);

            foreach ($rows as $row) {
                $idx = ((int) $row['day_num'] - 1 + 6) % 7;
                $data[$idx] = (int) $row['sessions'];
            }

            return $data;
        } catch (\Throwable) {
            return [];
        }
    }

    private function getEngagementChartData(): array
    {
        try {
            $stmt = $this->db->query("
                SELECT
                    DAYOFWEEK(created_at) AS day_num,
                    COUNT(*) AS msg_count
                FROM messages
                WHERE created_at >= DATE_SUB(
                    NOW(),
                    INTERVAL 7 DAY
                )
                  AND is_deleted = 0
                GROUP BY day_num
            ");

            $rows = $stmt->fetchAll();

            $data = array_fill(0, 7, 0);

            foreach ($rows as $row) {
                $idx = ((int) $row['day_num'] - 1 + 6) % 7;
                $data[$idx] = (int) $row['msg_count'];
            }

            return $data;
        } catch (\Throwable) {
            return [];
        }
    }

    private function getDauChartData(): array
    {
        try {
            $stmt = $this->db->query("
                SELECT
                    DATE(last_seen_at) AS day,
                    COUNT(DISTINCT id) AS dau
                FROM users
                WHERE last_seen_at >= DATE_SUB(
                    NOW(),
                    INTERVAL 7 DAY
                )
                  AND deleted_at IS NULL
                GROUP BY day
                ORDER BY day ASC
            ");

            return array_column(
                $stmt->fetchAll(),
                'dau'
            );
        } catch (\Throwable) {
            return [];
        }
    }
}
