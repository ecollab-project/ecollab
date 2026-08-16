<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/services/PeerMatchingService.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);

try {
    $db = Database::getInstance();
    $uid = (int)$user['id'];

    $stmt = $db->prepare("
        SELECT DISTINCT
            u.id, u.username, u.full_name, u.role,
            u.avatar_color_gradient, u.bio, u.is_online
        FROM users u
        LEFT JOIN friendships f
          ON (f.requester_id = :uid1 AND f.addressee_id = u.id)
          OR (f.requester_id = u.id AND f.addressee_id = :uid2)
        WHERE u.id != :uid3
          AND u.deleted_at IS NULL
          AND u.status != 'banned'
          AND (f.id IS NULL OR f.status = 'rejected')
        ORDER BY u.is_online DESC, u.last_active_at DESC
        LIMIT 50
    ");
    $stmt->execute([
        ':uid1' => $uid,
        ':uid2' => $uid,
        ':uid3' => $uid,
    ]);

    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $prefsStmt = $db->prepare('SELECT * FROM pm_user_study_prefs WHERE user_id = ?');
    $subjectsStmt = $db->prepare('SELECT subject_id, role, proficiency FROM pm_user_subjects WHERE user_id = ?');
    $interestsStmt = $db->prepare('SELECT interest_id FROM pm_user_interests WHERE user_id = ?');
    $hobbiesStmt = $db->prepare('SELECT hobby_id FROM pm_user_hobbies WHERE user_id = ?');
    $cacheStmt = $db->prepare("
        INSERT INTO pm_compatibility
            (user_a_id, user_b_id, score_total, score_subjects, score_interests,
             score_hobbies, score_style, shared_subjects, shared_interests,
             shared_hobbies, match_tags)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            score_total = VALUES(score_total),
            score_subjects = VALUES(score_subjects),
            score_interests = VALUES(score_interests),
            score_hobbies = VALUES(score_hobbies),
            score_style = VALUES(score_style),
            shared_subjects = VALUES(shared_subjects),
            shared_interests = VALUES(shared_interests),
            shared_hobbies = VALUES(shared_hobbies),
            match_tags = VALUES(match_tags),
            computed_at = CURRENT_TIMESTAMP
    ");

    $loadProfile = static function (
        int $userId,
        PDOStatement $prefsStmt,
        PDOStatement $subjectsStmt,
        PDOStatement $interestsStmt,
        PDOStatement $hobbiesStmt
    ): array {
        $prefsStmt->execute([$userId]);
        $subjectsStmt->execute([$userId]);
        $interestsStmt->execute([$userId]);
        $hobbiesStmt->execute([$userId]);

        return [
            'prefs' => $prefsStmt->fetch(PDO::FETCH_ASSOC) ?: [],
            'subjects' => $subjectsStmt->fetchAll(PDO::FETCH_ASSOC),
            'interests' => $interestsStmt->fetchAll(PDO::FETCH_ASSOC),
            'hobbies' => $hobbiesStmt->fetchAll(PDO::FETCH_ASSOC),
        ];
    };

    $service = new PeerMatchingService();
    $currentProfile = $loadProfile($uid, $prefsStmt, $subjectsStmt, $interestsStmt, $hobbiesStmt);

    // A match should be based on real peer-profile data. Previously, users
    // with completely empty profiles could receive 13% because the study
    // preference scorer returned a neutral 50/100 value for missing data.
    // That made every unconfigured account look like a real match.
    $currentProfileReady = !empty($currentProfile['subjects'])
        || !empty($currentProfile['interests'])
        || !empty($currentProfile['hobbies']);

    $matches = [];

    if ($currentProfileReady) {
        foreach ($users as $candidate) {
            $candidateId = (int)$candidate['id'];
            $candidateProfile = $loadProfile($candidateId, $prefsStmt, $subjectsStmt, $interestsStmt, $hobbiesStmt);

            // Do not advertise users who have not configured any matcher
            // dimensions yet. They cannot produce a meaningful compatibility
            // score and were the source of the misleading 13% cards.
            $candidateReady = !empty($candidateProfile['subjects'])
                || !empty($candidateProfile['interests'])
                || !empty($candidateProfile['hobbies']);

            if (!$candidateReady) {
                continue;
            }

            $score = $service->scoreProfiles($currentProfile, $candidateProfile);

            $a = min($uid, $candidateId);
            $b = max($uid, $candidateId);
            $cacheStmt->execute([
                $a,
                $b,
                $score['total'],
                $score['subjects'],
                $score['interests'],
                $score['hobbies'],
                $score['style'],
                $score['shared_subjects'],
                $score['shared_interests'],
                $score['shared_hobbies'],
                json_encode($score['tags'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);

            $name = (string)($candidate['full_name'] ?: $candidate['username']);
            $role = (string)($candidate['role'] ?? 'student');
            $matches[] = [
                'id' => $candidateId,
                'name' => $name,
                'initials' => strtoupper(substr($name, 0, 2)),
                'detail' => ucfirst($role) . ($candidate['bio'] ? ' • ' . substr((string)$candidate['bio'], 0, 60) : ''),
                'pct' => (int)round((float)$score['total']),
                'type' => in_array($role, ['facilitator', 'admin', 'super_admin', 'moderator'], true) ? 'professor' : 'student',
                'online' => (bool)$candidate['is_online'],
                'tags' => $score['tags'],
                'components' => [
                    'subjects' => $score['subjects'],
                    'style' => $score['style'],
                    'interests' => $score['interests'],
                    'hobbies' => $score['hobbies'],
                ],
                'shared_subjects' => $score['shared_subjects'],
                'shared_interests' => $score['shared_interests'],
                'shared_hobbies' => $score['shared_hobbies'],
                'grad' => (string)($candidate['avatar_color_gradient'] ?? '#a855f7,#ec4899'),
            ];
        }
    }

    usort($matches, static fn(array $a, array $b): int => $b['pct'] <=> $a['pct']);

    echo json_encode([
        'success' => true,
        'profile_ready' => $currentProfileReady,
        'matches' => array_slice($matches, 0, 12),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    error_log('[Ecollab] peer matching error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'matches' => [],
        'message' => (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : 'Unable to load peer matches.',
    ]);
}
