<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/database/config/db.php';
require_once __DIR__ . '/MembershipService.php';

/**
 * Facilitator dashboard data service.
 *
 * Extracted from UserService as part of Phase 4.6.
 */
class FacilitatorDashboardService
{
    private PDO $db;
    private MembershipService $membershipService;

    public function __construct(
        ?PDO $db = null,
        ?MembershipService $membershipService = null
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->membershipService =
            $membershipService ?? new MembershipService($this->db);
    }

    public function getFacilitatorDashboardData(int $userId): array
    {
        $channel = $this->getFacilitatorPrimaryChannel($userId);
        $channelId = $channel
            ? (int)$channel['id']
            : 0;

        return [
            'channel' => $channel ?? [],
            'stats' => $this->getChannelStats($channelId),
            'activity' =>
            $this->getChannelMemberActivity($channelId),
            'recent_activity' =>
            $this->getChannelRecentActivity($channelId),
            'upcoming_sessions' =>
            $this->getChannelUpcomingSessions($channelId),
            'announcements' =>
            $this->getChannelAnnouncements($channelId),
            'files' =>
            $this->getChannelFiles($channelId),
            'my_channels' =>
            $this->getFacilitatorChannels($userId),
            'engagement_chart' =>
            $this->getChannelEngagementChart($channelId),
            'membership' =>
            $this->membershipService->getMembershipSummary($userId),
        ];
    }

    private function getFacilitatorPrimaryChannel(
        int $userId
    ): ?array {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    c.id,
                    c.name,
                    c.description,
                    c.member_count,
                    c.created_at,
                    s.name AS server_name,
                    (
                        SELECT COUNT(*)
                        FROM messages m
                        WHERE m.channel_id = c.id
                          AND m.is_deleted = 0
                    ) AS message_count,
                    (
                        SELECT COUNT(*)
                        FROM message_attachments ma
                        JOIN messages m2
                            ON m2.id = ma.message_id
                        WHERE m2.channel_id = c.id
                    ) AS file_count
                FROM channels c
                JOIN servers s
                    ON s.id = c.server_id
                JOIN server_members sm
                    ON sm.server_id = c.server_id
                    AND sm.user_id = :uid
                WHERE sm.server_role IN (
                    'owner',
                    'admin',
                    'moderator'
                )
                ORDER BY c.position ASC
                LIMIT 1
            ");

            $stmt->execute([':uid' => $userId]);

            $row = $stmt->fetch();

            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private function getChannelStats(int $channelId): array
    {
        if (!$channelId) {
            return [];
        }

        try {
            $stmt = $this->db->prepare("
                SELECT
                    c.member_count AS total_members,

                    (
                        SELECT COUNT(DISTINCT m.sender_id)
                        FROM messages m
                        WHERE m.channel_id = :cid
                          AND DATE(m.created_at) = CURDATE()
                          AND m.is_deleted = 0
                    ) AS active_today,

                    (
                        SELECT COUNT(*)
                        FROM messages m2
                        WHERE m2.channel_id = :cid2
                          AND DATE(m2.created_at) = CURDATE()
                          AND m2.is_deleted = 0
                    ) AS messages_today,

                    (
                        SELECT COUNT(*)
                        FROM study_room_sessions srs
                        JOIN study_rooms sr
                            ON sr.id = srs.room_id
                        WHERE sr.channel_id = :cid3
                          AND srs.status IN (
                              'active',
                              'scheduled'
                          )
                    ) AS study_sessions

                FROM channels c
                WHERE c.id = :cid4
            ");

            $stmt->execute([
                ':cid' => $channelId,
                ':cid2' => $channelId,
                ':cid3' => $channelId,
                ':cid4' => $channelId,
            ]);

            return $stmt->fetch() ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    private function getChannelMemberActivity(
        int $channelId
    ): array {
        if (!$channelId) {
            return [];
        }

        try {
            $stmt = $this->db->prepare("
                SELECT
                    u.id,
                    u.username,
                    u.full_name,
                    u.avatar_color_gradient,
                    u.last_active_at,
                    COUNT(m.id) AS messages,
                    0 AS sessions,
                    0 AS wb_edits,
                    0 AS files_uploaded,

                    CASE
                        WHEN u.last_active_at >=
                            DATE_SUB(NOW(), INTERVAL 1 HOUR)
                        THEN CONCAT(
                            TIMESTAMPDIFF(
                                MINUTE,
                                u.last_active_at,
                                NOW()
                            ),
                            'm ago'
                        )

                        WHEN u.last_active_at >=
                            DATE_SUB(NOW(), INTERVAL 24 HOUR)
                        THEN CONCAT(
                            TIMESTAMPDIFF(
                                HOUR,
                                u.last_active_at,
                                NOW()
                            ),
                            'h ago'
                        )

                        ELSE CONCAT(
                            TIMESTAMPDIFF(
                                DAY,
                                u.last_active_at,
                                NOW()
                            ),
                            'd ago'
                        )
                    END AS last_active,

                    DATE_FORMAT(
                        sm2.joined_at,
                        '%b %d'
                    ) AS joined_date,

                    CASE
                        WHEN COUNT(m.id) >= 50
                            THEN 'very_active'
                        WHEN COUNT(m.id) >= 20
                            THEN 'active'
                        WHEN COUNT(m.id) >= 5
                            THEN 'moderate'
                        ELSE 'inactive'
                    END AS activity_status

                FROM users u
                JOIN server_members sm2
                    ON sm2.user_id = u.id
                JOIN channels c
                    ON c.server_id = sm2.server_id
                    AND c.id = :cid
                LEFT JOIN messages m
                    ON m.sender_id = u.id
                    AND m.channel_id = :cid2
                    AND m.is_deleted = 0
                WHERE u.deleted_at IS NULL
                GROUP BY u.id
                ORDER BY messages DESC
                LIMIT 15
            ");

            $stmt->execute([
                ':cid' => $channelId,
                ':cid2' => $channelId,
            ]);

            return $stmt->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }

    private function getChannelRecentActivity(
        int $channelId
    ): array {
        if (!$channelId) {
            return [];
        }

        try {
            $stmt = $this->db->prepare("
                SELECT
                    u.username,
                    u.avatar_color_gradient,
                    CONCAT(
                        u.username,
                        ' posted in #',
                        c.name
                    ) AS message,

                    CASE
                        WHEN m.created_at >=
                            DATE_SUB(NOW(), INTERVAL 1 HOUR)
                        THEN CONCAT(
                            TIMESTAMPDIFF(
                                MINUTE,
                                m.created_at,
                                NOW()
                            ),
                            'm ago'
                        )
                        ELSE CONCAT(
                            TIMESTAMPDIFF(
                                HOUR,
                                m.created_at,
                                NOW()
                            ),
                            'h ago'
                        )
                    END AS time_ago

                FROM messages m
                JOIN users u
                    ON u.id = m.sender_id
                JOIN channels c
                    ON c.id = m.channel_id
                WHERE m.channel_id = :cid
                  AND m.is_deleted = 0
                ORDER BY m.created_at DESC
                LIMIT 8
            ");

            $stmt->execute([':cid' => $channelId]);

            return $stmt->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }

    private function getChannelUpcomingSessions(
        int $channelId
    ): array {
        if (!$channelId) {
            return [];
        }

        try {
            $stmt = $this->db->prepare("
                SELECT
                    srs.id,
                    srs.title AS name,
                    srs.description,
                    srs.scheduled_start AS start_time,
                    COUNT(srp.user_id) AS rsvp_count
                FROM study_room_sessions srs
                JOIN study_rooms sr
                    ON sr.id = srs.room_id
                LEFT JOIN study_room_participants srp
                    ON srp.session_id = srs.id
                WHERE sr.channel_id = :cid
                  AND srs.scheduled_start >= NOW()
                GROUP BY srs.id
                ORDER BY srs.scheduled_start ASC
                LIMIT 4
            ");

            $stmt->execute([':cid' => $channelId]);

            return $stmt->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }

    private function getChannelAnnouncements(
        int $channelId
    ): array {
        if (!$channelId) {
            return [];
        }

        try {
            $stmt = $this->db->prepare("
                SELECT
                    m.id,
                    m.content AS title,
                    m.content,

                    CASE
                        WHEN m.created_at >=
                            DATE_SUB(NOW(), INTERVAL 1 HOUR)
                        THEN CONCAT(
                            TIMESTAMPDIFF(
                                MINUTE,
                                m.created_at,
                                NOW()
                            ),
                            'm ago'
                        )

                        WHEN m.created_at >=
                            DATE_SUB(NOW(), INTERVAL 24 HOUR)
                        THEN CONCAT(
                            TIMESTAMPDIFF(
                                HOUR,
                                m.created_at,
                                NOW()
                            ),
                            'h ago'
                        )

                        ELSE CONCAT(
                            TIMESTAMPDIFF(
                                DAY,
                                m.created_at,
                                NOW()
                            ),
                            'd ago'
                        )
                    END AS time_ago

                FROM messages m
                WHERE m.channel_id = :cid
                  AND m.is_pinned = 1
                  AND m.is_deleted = 0
                ORDER BY m.updated_at DESC
                LIMIT 5
            ");

            $stmt->execute([':cid' => $channelId]);

            return $stmt->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }

    private function getChannelFiles(
        int $channelId
    ): array {
        if (!$channelId) {
            return [];
        }

        try {
            $stmt = $this->db->prepare("
                SELECT
                    ma.id,
                    ma.file_name,
                    ma.file_path,
                    ma.mime_type,
                    CONCAT(
                        ROUND(ma.file_size / 1024, 0),
                        ' KB'
                    ) AS file_size_formatted,
                    u.username AS uploader
                FROM message_attachments ma
                JOIN messages m
                    ON m.id = ma.message_id
                JOIN users u
                    ON u.id = m.sender_id
                WHERE m.channel_id = :cid
                  AND m.is_deleted = 0
                ORDER BY ma.created_at DESC
                LIMIT 8
            ");

            $stmt->execute([':cid' => $channelId]);

            return $stmt->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }

    private function getFacilitatorChannels(
        int $userId
    ): array {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    c.id,
                    c.server_id,
                    c.name,
                    c.type,
                    c.member_count
                FROM channels c
                JOIN server_members sm
                    ON sm.server_id = c.server_id
                    AND sm.user_id = :uid
                WHERE sm.server_role IN (
                    'owner',
                    'admin',
                    'moderator'
                )
                ORDER BY c.position ASC
                LIMIT 6
            ");

            $stmt->execute([':uid' => $userId]);

            $rows = $stmt->fetchAll();

            $typeIcons = [
                'text' => '#',
                'voice' => '🔊',
                'announcement' => '📣',
                'whiteboard' => '🖍',
                'study_room' => '📖',
            ];

            foreach ($rows as &$row) {
                $row['icon_emoji'] =
                    $typeIcons[$row['type']] ?? '#';
            }

            unset($row);

            return $rows;
        } catch (Throwable) {
            return [];
        }
    }

    private function getChannelEngagementChart(
        int $channelId
    ): array {
        if (!$channelId) {
            return [];
        }

        try {
            $stmt = $this->db->prepare("
                SELECT
                    DAYOFWEEK(m.created_at) AS day_num,
                    COUNT(DISTINCT m.sender_id)
                        AS active_users
                FROM messages m
                WHERE m.channel_id = :cid
                  AND m.created_at >=
                      DATE_SUB(NOW(), INTERVAL 7 DAY)
                  AND m.is_deleted = 0
                GROUP BY day_num
            ");

            $stmt->execute([':cid' => $channelId]);

            $rows = $stmt->fetchAll();
            $data = array_fill(0, 7, 0);

            foreach ($rows as $row) {
                $idx =
                    ((int)$row['day_num'] - 1 + 6) % 7;

                $data[$idx] =
                    (int)$row['active_users'];
            }

            return $data;
        } catch (Throwable) {
            return [];
        }
    }
}
