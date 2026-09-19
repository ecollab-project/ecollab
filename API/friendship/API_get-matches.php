<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/services/PeerMatchingService.php';

header('Content-Type: application/json; charset=utf-8');
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);

try {
    $db = Database::getInstance();
    $uid = (int)$user['id'];

    $pref = $db->prepare('SELECT ai_matching FROM user_settings WHERE user_id=:id LIMIT 1');
    $pref->execute([':id' => $uid]);
    if ($pref->fetchColumn() === 0) {
        echo json_encode(['success' => true, 'matches' => [], 'ai_matching_disabled' => true]);
        exit;
    }

    $users = $db->prepare("SELECT DISTINCT u.id,u.username,u.full_name,u.role,u.avatar_color_gradient,u.bio,u.is_online
        FROM users u
        LEFT JOIN friendships f ON (f.requester_id=:uid1 AND f.addressee_id=u.id) OR (f.requester_id=u.id AND f.addressee_id=:uid2)
        WHERE u.id!=:uid3 AND u.deleted_at IS NULL AND u.status!='banned'
          AND (f.id IS NULL OR f.status='rejected')
        ORDER BY u.is_online DESC,u.last_active_at DESC LIMIT 50");
    $users->execute([':uid1'=>$uid, ':uid2'=>$uid, ':uid3'=>$uid]);

    $prefs = $db->prepare('SELECT * FROM pm_user_study_prefs WHERE user_id=?');
    $subjects = $db->prepare('SELECT subject_id,role,proficiency FROM pm_user_subjects WHERE user_id=?');
    $interests = $db->prepare('SELECT interest_id FROM pm_user_interests WHERE user_id=?');
    $hobbies = $db->prepare('SELECT hobby_id FROM pm_user_hobbies WHERE user_id=?');
    $load = static function (int $id) use ($prefs,$subjects,$interests,$hobbies): array {
        $prefs->execute([$id]); $subjects->execute([$id]); $interests->execute([$id]); $hobbies->execute([$id]);
        return ['prefs'=>$prefs->fetch(PDO::FETCH_ASSOC)?:[],'subjects'=>$subjects->fetchAll(PDO::FETCH_ASSOC),'interests'=>$interests->fetchAll(PDO::FETCH_ASSOC),'hobbies'=>$hobbies->fetchAll(PDO::FETCH_ASSOC)];
    };

    $me = $load($uid);
    $ready = !empty($me['subjects']) || !empty($me['interests']) || !empty($me['hobbies']);
    $service = new PeerMatchingService();
    $matches = [];

    if ($ready) foreach ($users->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
        $profile = $load((int)$candidate['id']);
        if (empty($profile['subjects']) && empty($profile['interests']) && empty($profile['hobbies'])) continue;
        $score = $service->scoreProfiles($me, $profile);
        $name = (string)($candidate['full_name'] ?: $candidate['username']);
        $role = (string)($candidate['role'] ?? 'student');
        $matches[] = [
            'id'=>(int)$candidate['id'],
            'name'=>$name,
            'detail'=>ucfirst($role).($candidate['bio'] ? ' • '.mb_substr((string)$candidate['bio'],0,40) : ''),
            'pct'=>(int)round((float)$score['total']),
            'type'=>in_array($role,['facilitator','admin','super_admin','moderator'],true)?'professor':'student',
            'tags'=>$score['tags'],
            'grad'=>(string)($candidate['avatar_color_gradient'] ?? '#a855f7,#ec4899')
        ];
    }
    usort($matches, static fn(array $x,array $y): int => $y['pct'] <=> $x['pct']);
    echo json_encode(['success'=>true,'profile_ready'=>$ready,'matches'=>array_slice($matches,0,12)]);
} catch (Throwable $e) {
    error_log('[Ecollab] friendship peer matching error: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'matches'=>[]]);
}
