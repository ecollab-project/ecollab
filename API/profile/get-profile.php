<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$me = AuthMiddleware::requireAuth(true);

try {
    $db     = Database::getInstance();
    $userId = (int)($_GET['user_id'] ?? 0);
    $name   = trim($_GET['name'] ?? '');

    // ── Find user ──────────────────────────────────────────────────────────
    if ($userId) {
        $stmt = $db->prepare("SELECT * FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([':id' => $userId]);
    } elseif ($name !== '') {
        $stmt = $db->prepare("SELECT * FROM users WHERE (full_name = :n OR username = :n2) AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([':n' => $name, ':n2' => $name]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'user_id or name required']);
        exit;
    }
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) { http_response_code(404); echo json_encode(['error' => 'User not found']); exit; }
    $uid = (int)$user['id'];

    // ── Extended profile ───────────────────────────────────────────────────
    $pStmt = $db->prepare("SELECT * FROM user_profiles WHERE user_id = :uid LIMIT 1");
    $pStmt->execute([':uid' => $uid]);
    $profile = $pStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // ── Academic program ───────────────────────────────────────────────────
    $program = '';
    if (!empty($profile['academic_program_id'])) {
        $apStmt = $db->prepare("SELECT name FROM academic_programs WHERE id = :id LIMIT 1");
        $apStmt->execute([':id' => $profile['academic_program_id']]);
        $program = $apStmt->fetchColumn() ?: '';
    }

    // ── Interests (via interest_tags join) ─────────────────────────────────
    $intStmt = $db->prepare("
        SELECT it.name FROM user_interests ui
        JOIN interest_tags it ON it.id = ui.interest_tag_id
        WHERE ui.user_id = :uid
        LIMIT 15
    ");
    $intStmt->execute([':uid' => $uid]);
    $interests = $intStmt->fetchAll(PDO::FETCH_COLUMN);

    // ── Hobbies ────────────────────────────────────────────────────────────
    $hobStmt = $db->prepare("SELECT hobby, genre FROM user_hobbies WHERE user_id = :uid LIMIT 8");
    $hobStmt->execute([':uid' => $uid]);
    $hobbies = array_map(function($h) {
        return $h['genre'] ? $h['hobby'] . ' (' . $h['genre'] . ')' : $h['hobby'];
    }, $hobStmt->fetchAll(PDO::FETCH_ASSOC));

    // ── Mutual servers ─────────────────────────────────────────────────────
    $mutStmt = $db->prepare("
        SELECT s.name FROM servers s
        JOIN server_members sm1 ON sm1.server_id = s.id AND sm1.user_id = :me
        JOIN server_members sm2 ON sm2.server_id = s.id AND sm2.user_id = :them
        WHERE s.status = 'active'
        LIMIT 8
    ");
    $mutStmt->execute([':me' => $me['id'], ':them' => $uid]);
    $mutualServers = $mutStmt->fetchAll(PDO::FETCH_COLUMN);

    // ── Connection status ──────────────────────────────────────────────────
    $friendStmt = $db->prepare("
        SELECT status FROM friendships
        WHERE (requester_id = :me AND addressee_id = :them)
           OR (requester_id = :them2 AND addressee_id = :me2)
        LIMIT 1
    ");
    $friendStmt->execute([':me' => $me['id'], ':them' => $uid, ':them2' => $uid, ':me2' => $me['id']]);
    $friendStatus = $friendStmt->fetchColumn() ?: 'none';

    // ── Compatibility score (same profile-to-profile engine used by peer matching) ──
    $compatScore = null;
    $compatBreakdown = null;
    if ($uid !== (int)$me['id']) {
        require_once dirname(__DIR__, 2) . '/services/PeerMatchingService.php';
        $pairA = min((int)$me['id'], $uid);
        $pairB = max((int)$me['id'], $uid);
        $pcStmt = $db->prepare("SELECT score_total, score_subjects, score_style, score_interests, score_hobbies
                                FROM pm_compatibility
                                WHERE user_a_id = :a AND user_b_id = :b LIMIT 1");
        $pcStmt->execute([':a'=>$pairA, ':b'=>$pairB]);
        $pc = $pcStmt->fetch(PDO::FETCH_ASSOC);
        if ($pc) {
            $compatScore = (int)round((float)$pc['score_total']);
            $compatBreakdown = [
                'subjects'=>(int)round((float)$pc['score_subjects']),
                'style'=>(int)round((float)$pc['score_style']),
                'interests'=>(int)round((float)$pc['score_interests']),
                'hobbies'=>(int)round((float)$pc['score_hobbies']),
            ];
        }
    }

    // ── Primary goal label ─────────────────────────────────────────────────
    $goalLabels = [
        'pass_exams'           => 'Ace my exams',
        'build_projects'       => 'Build projects',
        'find_study_partners'  => 'Find study partners',
        'improve_skills'       => 'Improve skills',
        'network_collaborate'  => 'Network & collaborate',
    ];
    $goalLabel = $goalLabels[$profile['primary_goal'] ?? ''] ?? '';

    echo json_encode([
        'success' => true,
        'profile' => [
            'id'                    => $uid,
            'username'              => $user['username'],
            'full_name'             => $user['full_name'],
            'role'                  => $user['role'],
            'avatar_url'            => $user['avatar_url'] ?? '',
            'avatar_color_gradient' => $user['avatar_color_gradient'] ?? '',
            'bio'                   => $user['bio'] ?? '',
            'interests'             => implode(', ', $interests),
            'hobbies'               => implode(', ', $hobbies),
            'study_style'           => ucfirst($profile['study_style'] ?? ''),
            'goals'                 => $goalLabel,
            'year_level'            => $profile['year_level'] ? 'Year ' . $profile['year_level'] : '',
            'academic_program'      => $program,
            'academic_program_id'   => isset($profile['academic_program_id']) ? (int)$profile['academic_program_id'] : null,
            'year_level_value'      => isset($profile['year_level']) ? (int)$profile['year_level'] : null,
            'study_style_value'     => $profile['study_style'] ?? '',
            'primary_goal'          => $profile['primary_goal'] ?? '',
            'weekly_goal_hours'     => (float)($profile['weekly_goal_hours'] ?? 20),
            'timezone'              => $profile['timezone'] ?? 'Asia/Manila',
            'github_url'            => $profile['github_url'] ?? '',
            'linkedin_url'          => $profile['linkedin_url'] ?? '',
            'portfolio_url'         => $profile['portfolio_url'] ?? '',
            'mutual_servers'        => $mutualServers,
            'compatibility_score'   => $compatScore,
            'compatibility_breakdown'=> $compatBreakdown,
            'connection_status'     => $friendStatus,
            'streak_days'           => (int)($profile['current_streak_days'] ?? 0),
            'study_hours'           => (float)($profile['total_study_hours'] ?? 0),
        ],
    ]);

} catch (Throwable $e) {
    error_log('[get-profile] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'Server error']);
}
