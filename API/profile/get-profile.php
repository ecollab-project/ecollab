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

    // ── Compatibility score ────────────────────────────────────────────────
    $compatScore = null;
    if ($uid !== $me['id']) {
        // Check peer_matches table first
        $pmStmt = $db->prepare("
            SELECT match_score FROM peer_matches
            WHERE (user_a_id = :me AND user_b_id = :them)
               OR (user_a_id = :them2 AND user_b_id = :me2)
            ORDER BY created_at DESC LIMIT 1
        ");
        $pmStmt->execute([':me' => $me['id'], ':them' => $uid, ':them2' => $uid, ':me2' => $me['id']]);
        $dbScore = $pmStmt->fetchColumn();

        if ($dbScore !== false) {
            $compatScore = (int)round((float)$dbScore);
        } else {
            // Compute on the fly
            $score = 60;
            $score += min(30, count($mutualServers) * 10);
            if (count($interests) > 0) {
                // Get my interests
                $myIntStmt = $db->prepare("SELECT interest_tag_id FROM user_interests WHERE user_id = :uid");
                $myIntStmt->execute([':uid' => $me['id']]);
                $myInts = $myIntStmt->fetchAll(PDO::FETCH_COLUMN);
                $theirIntStmt = $db->prepare("SELECT interest_tag_id FROM user_interests WHERE user_id = :uid");
                $theirIntStmt->execute([':uid' => $uid]);
                $theirInts = $theirIntStmt->fetchAll(PDO::FETCH_COLUMN);
                $shared = count(array_intersect($myInts, $theirInts));
                $score += min(20, $shared * 5);
            }
            // Role compatibility
            $myRole    = $me['role'] ?? 'student';
            $theirRole = $user['role'] ?? 'student';
            if ($myRole === 'student' && in_array($theirRole, ['facilitator', 'admin'])) $score += 10;
            $compatScore = min(99, $score);
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
            'avatar_color_gradient' => $user['avatar_color_gradient'] ?? '',
            'bio'                   => $user['bio'] ?? '',
            'interests'             => implode(', ', $interests),
            'hobbies'               => implode(', ', $hobbies),
            'study_style'           => ucfirst($profile['study_style'] ?? ''),
            'goals'                 => $goalLabel,
            'year_level'            => $profile['year_level'] ? 'Year ' . $profile['year_level'] : '',
            'academic_program'      => $program,
            'mutual_servers'        => $mutualServers,
            'compatibility_score'   => $compatScore,
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
