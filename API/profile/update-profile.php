<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

AuthMiddleware::verifyCsrf();

try {
    $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $fullName = trim((string)($body['full_name'] ?? ''));
    $bio = trim((string)($body['bio'] ?? ''));
    $gradient = trim((string)($body['avatar_color_gradient'] ?? ''));
    $studyStyle = trim((string)($body['study_style'] ?? ''));
    $primaryGoal = trim((string)($body['primary_goal'] ?? ''));
    $yearLevel = isset($body['year_level']) && $body['year_level'] !== '' ? (int)$body['year_level'] : null;
    $weeklyGoal = isset($body['weekly_goal_hours']) ? max(0, min(168, (float)$body['weekly_goal_hours'])) : 20.0;
    $timezone = trim((string)($body['timezone'] ?? 'Asia/Manila'));
    $githubUrl = trim((string)($body['github_url'] ?? ''));
    $linkedinUrl = trim((string)($body['linkedin_url'] ?? ''));
    $portfolioUrl = trim((string)($body['portfolio_url'] ?? ''));

    if ($fullName === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Display name is required']);
        exit;
    }
    if (mb_strlen($fullName) > 80) {
        http_response_code(400);
        echo json_encode(['error' => 'Display name must be 80 characters or fewer']);
        exit;
    }
    if (mb_strlen($bio) > 500) {
        http_response_code(400);
        echo json_encode(['error' => 'Bio must be 500 characters or fewer']);
        exit;
    }

    if ($gradient === '' || !preg_match('/^#[0-9a-fA-F]{6}(?:,#[0-9a-fA-F]{6})?$/', $gradient)) {
        $gradient = '#a855f7,#ec4899';
    }

    $db = Database::getInstance();
    $stmt = $db->prepare('UPDATE users SET full_name = :full_name, bio = :bio, avatar_color_gradient = :gradient WHERE id = :id LIMIT 1');
    $stmt->execute([
        ':full_name' => $fullName,
        ':bio' => $bio,
        ':gradient' => $gradient,
        ':id' => (int)$user['id'],
    ]);

    $allowedStyles = ['solo','group','mixed'];
    $allowedGoals = ['pass_exams','build_projects','find_study_partners','improve_skills','network_collaborate'];
    $studyStyle = in_array($studyStyle, $allowedStyles, true) ? $studyStyle : null;
    $primaryGoal = in_array($primaryGoal, $allowedGoals, true) ? $primaryGoal : null;
    if ($yearLevel !== null && ($yearLevel < 1 || $yearLevel > 8)) $yearLevel = null;
    foreach ([$githubUrl,$linkedinUrl,$portfolioUrl] as $url) {
        if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) === false) {
            http_response_code(400); echo json_encode(['error'=>'Profile links must be valid URLs']); exit;
        }
    }
    $profileStmt = $db->prepare("INSERT INTO user_profiles
        (user_id, year_level, study_style, primary_goal, weekly_goal_hours, timezone, github_url, linkedin_url, portfolio_url, bio)
        VALUES (:uid,:year_level,:study_style,:primary_goal,:weekly_goal,:timezone,:github,:linkedin,:portfolio,:bio)
        ON DUPLICATE KEY UPDATE year_level=VALUES(year_level), study_style=VALUES(study_style), primary_goal=VALUES(primary_goal),
        weekly_goal_hours=VALUES(weekly_goal_hours), timezone=VALUES(timezone), github_url=VALUES(github_url),
        linkedin_url=VALUES(linkedin_url), portfolio_url=VALUES(portfolio_url), bio=VALUES(bio)");
    $profileStmt->execute([
        ':uid'=>(int)$user['id'], ':year_level'=>$yearLevel, ':study_style'=>$studyStyle, ':primary_goal'=>$primaryGoal,
        ':weekly_goal'=>$weeklyGoal, ':timezone'=>$timezone ?: 'Asia/Manila', ':github'=>$githubUrl ?: null,
        ':linkedin'=>$linkedinUrl ?: null, ':portfolio'=>$portfolioUrl ?: null, ':bio'=>$bio,
    ]);

    $_SESSION['full_name'] = $fullName;

    echo json_encode([
        'success' => true,
        'message' => 'Profile updated successfully',
        'profile' => [
            'full_name' => $fullName,
            'bio' => $bio,
            'avatar_color_gradient' => $gradient,
            'study_style' => $studyStyle,
            'primary_goal' => $primaryGoal,
            'year_level' => $yearLevel,
            'weekly_goal_hours' => $weeklyGoal,
            'timezone' => $timezone,
            'github_url' => $githubUrl,
            'linkedin_url' => $linkedinUrl,
            'portfolio_url' => $portfolioUrl,
        ],
    ]);
} catch (Throwable $e) {
    error_log('[update-profile] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'Server error']);
}
