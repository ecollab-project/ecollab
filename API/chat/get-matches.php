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

    // Keep the candidate pool intentionally small. The compatibility engine is
    // deterministic and explainable, so the API can rank the resulting profiles
    // without maintaining a second scoring implementation here.
    $stmt = $db->prepare("
        SELECT DISTINCT
            u.id,
            u.username,
            u.full_name,
            u.role,
            u.avatar_color_gradient,
            u.bio,
            u.is_online
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
    $matches = [];

    foreach ($users as $candidate) {
        $candidateId = (int)$candidate['id'];
        $candidateProfile = $loadProfile(
            $candidateId,
            $prefsStmt,
            $subjectsStmt,
            $interestsStmt,
            $hobbiesStmt
        );

        $score = $service->scoreProfiles($currentProfile, $candidateProfile);
        $name = (string)($candidate['full_name'] ?: $candidate['username']);
        $role = (string)($candidate['role'] ?? 'student');
        $type = in_array($role, ['facilitator', 'admin', 'super_admin', 'moderator'], true)
            ? 'professor'
            : 'student';
        $grad = (string)($candidate['avatar_color_gradient'] ?? '#a855f7,#ec4899');

        $matches[] = [
            'id' => $candidateId,
            'name' => $name,
            'initials' => strtoupper(substr($name, 0, 2)),
            'detail' => ucfirst($role) . ($candidate['bio'] ? ' • ' . substr((string)$candidate['bio'], 0, 60) : ''),
            'pct' => (int)round((float)$score['total']),
            'type' => $type,
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
            'grad' => $grad,
        ];
    }

    usort($matches, static function (array $a, array $b): int {
        return $b['pct'] <=> $a['pct'];
    });

    echo json_encode([
        'success' => true,
        'matches' => array_slice($matches, 0, 12),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    error_log('[Ecollab] peer matching error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'matches' => [],
        'message' => (defined('APP_DEBUG') && APP_DEBUG)
            ? $e->getMessage()
            : 'Unable to load peer matches.',
    ]);
}
