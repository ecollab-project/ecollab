<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/services/PeerMatchingService.php';
require_once dirname(__DIR__, 2) . '/security/SecurityHeaders.php';
require_once dirname(__DIR__, 2) . '/security/rate-limit/RateLimiter.php';

header('Content-Type: application/json; charset=utf-8');
SecurityHeaders::send(isApi: true);
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);

try {
    $db = Database::getInstance();
    $uid = (int)$user['id'];
    $action = (string)($_GET['action'] ?? '');
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];

    $json = static function (array $payload, int $status = 200): never {
        http_response_code($status);
        echo json_encode(['ok' => true, ...$payload], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    };

    $fail = static function (string $message, int $status = 400): never {
        http_response_code($status);
        echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    };

    if ($method === 'POST') {
        AuthMiddleware::verifyCsrf();
    }

    $loadProfile = static function (int $userId, PDO $db): array {
        $prefs = $db->prepare('SELECT * FROM pm_user_study_prefs WHERE user_id = ?');
        $prefs->execute([$userId]);
        $subjects = $db->prepare('SELECT subject_id, role, proficiency FROM pm_user_subjects WHERE user_id = ?');
        $subjects->execute([$userId]);
        $interests = $db->prepare('SELECT interest_id FROM pm_user_interests WHERE user_id = ?');
        $interests->execute([$userId]);
        $hobbies = $db->prepare('SELECT hobby_id FROM pm_user_hobbies WHERE user_id = ?');
        $hobbies->execute([$userId]);
        return [
            'prefs' => $prefs->fetch(PDO::FETCH_ASSOC) ?: [],
            'subjects' => $subjects->fetchAll(PDO::FETCH_ASSOC),
            'interests' => $interests->fetchAll(PDO::FETCH_ASSOC),
            'hobbies' => $hobbies->fetchAll(PDO::FETCH_ASSOC),
        ];
    };

    $hydrateTags = static function (array $profile, PDO $db): array {
        $subjects = $profile['subjects'];
        $interests = $profile['interests'];
        $hobbies = $profile['hobbies'];

        if ($subjects) {
            $ids = array_values(array_unique(array_map(static fn($r) => (int)$r['subject_id'], $subjects)));
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("SELECT id, name, category, icon FROM pm_subjects WHERE id IN ($in)");
            $stmt->execute($ids);
            $map = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $map[(int)$row['id']] = $row;
            foreach ($subjects as &$row) {
                $tag = $map[(int)$row['subject_id']] ?? [];
                $row['id'] = (int)$row['subject_id'];
                $row['name'] = $tag['name'] ?? '';
                $row['category'] = $tag['category'] ?? '';
                $row['icon'] = $tag['icon'] ?? '📚';
            }
            unset($row);
        }

        if ($interests) {
            $ids = array_values(array_unique(array_map(static fn($r) => (int)$r['interest_id'], $interests)));
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("SELECT id, name, category, icon FROM pm_interest_tags WHERE id IN ($in)");
            $stmt->execute($ids);
            $map = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $map[(int)$row['id']] = $row;
            foreach ($interests as &$row) {
                $tag = $map[(int)$row['interest_id']] ?? [];
                $row['id'] = (int)$row['interest_id'];
                $row['name'] = $tag['name'] ?? '';
                $row['category'] = $tag['category'] ?? '';
                $row['icon'] = $tag['icon'] ?? '💡';
            }
            unset($row);
        }

        if ($hobbies) {
            $ids = array_values(array_unique(array_map(static fn($r) => (int)$r['hobby_id'], $hobbies)));
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("SELECT id, name, category, icon FROM pm_hobby_tags WHERE id IN ($in)");
            $stmt->execute($ids);
            $map = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $map[(int)$row['id']] = $row;
            foreach ($hobbies as &$row) {
                $tag = $map[(int)$row['hobby_id']] ?? [];
                $row['id'] = (int)$row['hobby_id'];
                $row['name'] = $tag['name'] ?? '';
                $row['category'] = $tag['category'] ?? '';
                $row['icon'] = $tag['icon'] ?? '🎯';
            }
            unset($row);
        }

        $profile['subjects'] = $subjects;
        $profile['interests'] = $interests;
        $profile['hobbies'] = $hobbies;
        return $profile;
    };

    switch ($action) {
        case 'get_tags':
            $json([
                'subjects' => $db->query('SELECT id, name, category, icon FROM pm_subjects ORDER BY category, name')->fetchAll(PDO::FETCH_ASSOC),
                'hobbies' => $db->query('SELECT id, name, category, icon FROM pm_hobby_tags ORDER BY category, name')->fetchAll(PDO::FETCH_ASSOC),
                'interests' => $db->query('SELECT id, name, category, icon FROM pm_interest_tags ORDER BY category, name')->fetchAll(PDO::FETCH_ASSOC),
            ]);

        case 'get_profile':
            $json($hydrateTags($loadProfile($uid, $db), $db));

        case 'save_profile':
            if ($method !== 'POST') $fail('POST required.', 405);
            $prefs = is_array($body['prefs'] ?? null) ? $body['prefs'] : [];
            $subjects = is_array($body['subjects'] ?? null) ? $body['subjects'] : [];
            $hobbies = is_array($body['hobbies'] ?? null) ? $body['hobbies'] : [];
            $interests = is_array($body['interests'] ?? null) ? $body['interests'] : [];

            $stmt = $db->prepare("INSERT INTO pm_user_study_prefs
                (user_id, study_style, session_length, time_preference, learning_mode, pace, comm_style, primary_goal, availability_days)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    study_style=VALUES(study_style), session_length=VALUES(session_length),
                    time_preference=VALUES(time_preference), learning_mode=VALUES(learning_mode),
                    pace=VALUES(pace), comm_style=VALUES(comm_style), primary_goal=VALUES(primary_goal),
                    availability_days=VALUES(availability_days)");
            $stmt->execute([
                $uid,
                $prefs['study_style'] ?? 'mixed',
                $prefs['session_length'] ?? 'medium',
                $prefs['time_preference'] ?? 'flexible',
                $prefs['learning_mode'] ?? 'mixed',
                $prefs['pace'] ?? 'moderate',
                $prefs['comm_style'] ?? 'occasional',
                $prefs['primary_goal'] ?? 'improve_skills',
                max(0, min(127, (int)($prefs['availability_days'] ?? 127))),
            ]);

            $db->beginTransaction();
            try {
                $db->prepare('DELETE FROM pm_user_subjects WHERE user_id = ?')->execute([$uid]);
                $db->prepare('DELETE FROM pm_user_hobbies WHERE user_id = ?')->execute([$uid]);
                $db->prepare('DELETE FROM pm_user_interests WHERE user_id = ?')->execute([$uid]);

                $sub = $db->prepare('INSERT INTO pm_user_subjects (user_id, subject_id, role, proficiency) VALUES (?, ?, ?, ?)');
                foreach (array_slice($subjects, 0, 20) as $row) {
                    $id = (int)($row['id'] ?? 0);
                    if ($id > 0) $sub->execute([$uid, $id, $row['role'] ?? 'studying', $row['proficiency'] ?? 'intermediate']);
                }
                $hob = $db->prepare('INSERT INTO pm_user_hobbies (user_id, hobby_id) VALUES (?, ?)');
                foreach (array_slice($hobbies, 0, 15) as $row) {
                    $id = (int)($row['id'] ?? 0);
                    if ($id > 0) $hob->execute([$uid, $id]);
                }
                $int = $db->prepare('INSERT INTO pm_user_interests (user_id, interest_id) VALUES (?, ?)');
                foreach (array_slice($interests, 0, 15) as $row) {
                    $id = (int)($row['id'] ?? 0);
                    if ($id > 0) $int->execute([$uid, $id]);
                }
                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }
            $db->prepare('DELETE FROM pm_compatibility WHERE user_a_id = ? OR user_b_id = ?')->execute([$uid, $uid]);
            $json(['saved' => true]);

        case 'get_matches':
            $limiter = new RateLimiter();
            $rl = $limiter->attempt('pm_get_matches', (string)$uid, 20, 3600);
            if (!$rl['allowed']) $fail('Too many requests', 429);

            $profile = $loadProfile($uid, $db);
            $ready = !empty($profile['subjects']) || !empty($profile['interests']) || !empty($profile['hobbies']);
            if (!$ready) $json(['matches' => [], 'profile_ready' => false]);

            $limit = max(1, min(50, (int)($_GET['limit'] ?? 24)));
            $style = trim((string)($_GET['study_style'] ?? ''));
            $role = trim((string)($_GET['role'] ?? ''));
            $minScore = max(0, min(100, (float)($_GET['min_score'] ?? 0)));
            $sort = (string)($_GET['sort'] ?? 'score');

            $users = $db->prepare("SELECT u.id, u.username, u.full_name, u.role, u.avatar_color_gradient, u.bio, u.is_online
                FROM users u
                WHERE u.id != ? AND u.deleted_at IS NULL AND u.status != 'banned'
                ORDER BY u.is_online DESC, u.last_active_at DESC LIMIT 100");
            $users->execute([$uid]);
            $service = new PeerMatchingService();
            $matches = [];

            foreach ($users->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
                $cid = (int)$candidate['id'];
                $candidateProfile = $loadProfile($cid, $db);
                $candidateReady = !empty($candidateProfile['subjects']) || !empty($candidateProfile['interests']) || !empty($candidateProfile['hobbies']);
                if (!$candidateReady) continue;
                if ($style !== '' && ($candidateProfile['prefs']['study_style'] ?? '') !== $style) continue;
                if ($role !== '' && (string)$candidate['role'] !== $role) continue;

                $score = $service->scoreProfiles($profile, $candidateProfile);
                if ($score['total'] < $minScore) continue;

                $a = min($uid, $cid); $b = max($uid, $cid);
                $cache = $db->prepare("INSERT INTO pm_compatibility
                    (user_a_id,user_b_id,score_total,score_subjects,score_interests,score_hobbies,score_style,shared_subjects,shared_interests,shared_hobbies,match_tags)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE score_total=VALUES(score_total),score_subjects=VALUES(score_subjects),score_interests=VALUES(score_interests),score_hobbies=VALUES(score_hobbies),score_style=VALUES(score_style),shared_subjects=VALUES(shared_subjects),shared_interests=VALUES(shared_interests),shared_hobbies=VALUES(shared_hobbies),match_tags=VALUES(match_tags),computed_at=CURRENT_TIMESTAMP");
                $cache->execute([$a,$b,$score['total'],$score['subjects'],$score['interests'],$score['hobbies'],$score['style'],$score['shared_subjects'],$score['shared_interests'],$score['shared_hobbies'],json_encode($score['tags'], JSON_UNESCAPED_UNICODE)]);

                $name = (string)($candidate['full_name'] ?: $candidate['username']);
                $mySubjectIds = array_map(static fn($x) => (int)$x['subject_id'], $profile['subjects']);
                $myInterestIds = array_map(static fn($x) => (int)$x['interest_id'], $profile['interests']);
                $myHobbyIds = array_map(static fn($x) => (int)$x['hobby_id'], $profile['hobbies']);
                $sharedSubjects = array_values(array_filter($candidateProfile['subjects'], static fn($s) => in_array((int)$s['subject_id'], $mySubjectIds, true)));
                $sharedInterests = array_values(array_filter($candidateProfile['interests'], static fn($s) => in_array((int)$s['interest_id'], $myInterestIds, true)));
                $sharedHobbies = array_values(array_filter($candidateProfile['hobbies'], static fn($s) => in_array((int)$s['hobby_id'], $myHobbyIds, true)));

                $friendStmt = $db->prepare("SELECT status FROM friendships WHERE (requester_id=? AND addressee_id=?) OR (requester_id=? AND addressee_id=?) LIMIT 1");
                $friendStmt->execute([$uid,$cid,$cid,$uid]);
                $friendship = $friendStmt->fetchColumn();
                $requestStmt = $db->prepare("SELECT status FROM pm_match_requests WHERE requester_id=? AND addressee_id=? LIMIT 1");
                $requestStmt->execute([$uid,$cid]);
                $requestStatus = $requestStmt->fetchColumn() ?: null;

                $matches[] = [
                    'id'=>$cid,
                    'name'=>$name,
                    'detail'=>ucfirst((string)($candidate['role'] ?? 'student')),
                    'bio'=>(string)($candidate['bio'] ?? ''),
                    'pct'=>(int)round($score['total']),
                    'type'=>in_array($candidate['role'], ['facilitator','admin','super_admin','moderator'], true) ? 'professor' : 'student',
                    'role'=>(string)($candidate['role'] ?? 'student'),
                    'is_online'=>(bool)$candidate['is_online'],
                    'online'=>(bool)$candidate['is_online'],
                    'style_label'=>ucfirst((string)($candidateProfile['prefs']['study_style'] ?? '')),
                    'study_style'=>$candidateProfile['prefs']['study_style'] ?? null,
                    'score_total'=>$score['total'],
                    'score_subjects'=>$score['subjects'],
                    'score_style'=>$score['style'],
                    'score_interests'=>$score['interests'],
                    'score_hobbies'=>$score['hobbies'],
                    'shared_subjects'=>$sharedSubjects,
                    'shared_interests'=>$sharedInterests,
                    'shared_hobbies'=>$sharedHobbies,
                    'tags'=>$score['tags'],
                    'components'=>['subjects'=>$score['subjects'],'style'=>$score['style'],'interests'=>$score['interests'],'hobbies'=>$score['hobbies']],
                    'already_connected'=>$friendship === 'accepted',
                    'request_status'=>$requestStatus,
                    'grad'=>(string)($candidate['avatar_color_gradient'] ?? '#a855f7,#ec4899'),
                ];
            }

            usort($matches, static function (array $a, array $b) use ($sort): int {
                return match ($sort) {
                    'subjects' => ($b['score_subjects'] <=> $a['score_subjects']) ?: ($b['pct'] <=> $a['pct']),
                    'style' => ($b['score_style'] <=> $a['score_style']) ?: ($b['pct'] <=> $a['pct']),
                    'interests' => ($b['score_interests'] <=> $a['score_interests']) ?: ($b['pct'] <=> $a['pct']),
                    'hobbies' => ($b['score_hobbies'] <=> $a['score_hobbies']) ?: ($b['pct'] <=> $a['pct']),
                    default => $b['pct'] <=> $a['pct'],
                };
            });
            $json(['matches'=>array_slice($matches, 0, $limit), 'profile_ready'=>true]);

        case 'search_users':
            $q = trim((string)($_GET['q'] ?? ''));
            $subjectId = (int)($_GET['subject_id'] ?? 0);
            $hobbyId = (int)($_GET['hobby_id'] ?? 0);
            $interestId = (int)($_GET['interest_id'] ?? 0);
            $studyStyle = trim((string)($_GET['study_style'] ?? ''));
            if ($q === '' && !$subjectId && !$hobbyId && !$interestId && $studyStyle === '') $json(['users'=>[]]);

            $where = ['u.id != ?', 'u.deleted_at IS NULL', "u.status != 'banned'"];
            $params = [$uid];
            if ($q !== '') { $where[] = '(u.full_name LIKE ? OR u.username LIKE ? OR u.bio LIKE ?)'; $params[]="%$q%"; $params[]="%$q%"; $params[]="%$q%"; }
            if ($subjectId) { $where[] = 'EXISTS (SELECT 1 FROM pm_user_subjects ps WHERE ps.user_id=u.id AND ps.subject_id=?)'; $params[]=$subjectId; }
            if ($hobbyId) { $where[] = 'EXISTS (SELECT 1 FROM pm_user_hobbies ph WHERE ph.user_id=u.id AND ph.hobby_id=?)'; $params[]=$hobbyId; }
            if ($interestId) { $where[] = 'EXISTS (SELECT 1 FROM pm_user_interests pi WHERE pi.user_id=u.id AND pi.interest_id=?)'; $params[]=$interestId; }
            if ($studyStyle !== '') { $where[] = 'EXISTS (SELECT 1 FROM pm_user_study_prefs pp WHERE pp.user_id=u.id AND pp.study_style=?)'; $params[]=$studyStyle; }
            $stmt = $db->prepare('SELECT u.id,u.username,u.full_name,u.role,u.avatar_color_gradient,u.bio,u.is_online FROM users u WHERE '.implode(' AND ',$where).' ORDER BY u.is_online DESC,u.last_active_at DESC LIMIT 50');
            $stmt->execute($params);
            $users = [];
            $service = new PeerMatchingService();
            $mine = $loadProfile($uid,$db);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
                $cp = $loadProfile((int)$candidate['id'],$db);
                $score = $service->scoreProfiles($mine,$cp);
                $users[] = [...$candidate,'pct'=>(int)round($score['total'])];
            }
            $json(['users'=>$users]);

        case 'list_requests':
            $stmt = $db->prepare("SELECT r.*, u.username,u.full_name,u.avatar_color_gradient FROM pm_match_requests r JOIN users u ON u.id=r.requester_id WHERE r.addressee_id=? ORDER BY r.created_at DESC");
            $stmt->execute([$uid]);
            $incoming = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $db->prepare("SELECT r.*, u.username,u.full_name,u.avatar_color_gradient FROM pm_match_requests r JOIN users u ON u.id=r.addressee_id WHERE r.requester_id=? ORDER BY r.created_at DESC");
            $stmt->execute([$uid]);
            $outgoing = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $json(['incoming'=>$incoming,'outgoing'=>$outgoing]);

        case 'send_request':
            if ($method !== 'POST') $fail('POST required.', 405);
            $peerId = (int)($body['addressee_id'] ?? 0);
            if ($peerId <= 0 || $peerId === $uid) $fail('A valid study buddy is required.');
            $check = $db->prepare("SELECT id FROM users WHERE id=? AND deleted_at IS NULL AND status!='banned'");
            $check->execute([$peerId]);
            if (!$check->fetchColumn()) $fail('Study buddy not found.',404);
            $a=min($uid,$peerId); $b=max($uid,$peerId);
            $scoreStmt=$db->prepare('SELECT score_total FROM pm_compatibility WHERE user_a_id=? AND user_b_id=?');
            $scoreStmt->execute([$a,$b]);
            $score=(float)($scoreStmt->fetchColumn() ?: 0);
            $stmt=$db->prepare("INSERT INTO pm_match_requests (requester_id,addressee_id,score,note,matched_via,status) VALUES (?,?,?,?,?,'pending') ON DUPLICATE KEY UPDATE score=VALUES(score),note=VALUES(note),matched_via=VALUES(matched_via),status=IF(status='declined','pending',status)");
            $stmt->execute([$uid,$peerId,$score,$body['note'] ?? null,$body['matched_via'] ?? null]);
            $json(['status'=>'pending','score'=>$score]);

        case 'respond_request':
            if ($method !== 'POST') $fail('POST required.', 405);
            $reqId=(int)($body['request_id'] ?? 0);
            $response=(string)($body['response'] ?? '');
            if (!in_array($response,['accepted','declined'],true)) $fail('Invalid response.');
            $stmt=$db->prepare('SELECT * FROM pm_match_requests WHERE id=? AND addressee_id=?');
            $stmt->execute([$reqId,$uid]);
            $req=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!$req) $fail('Request not found.',404);
            $db->prepare('UPDATE pm_match_requests SET status=?,responded_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$response,$reqId]);
            if ($response === 'accepted') {
                $friend=$db->prepare("INSERT INTO friendships (requester_id,addressee_id,status) VALUES (?,?, 'accepted') ON DUPLICATE KEY UPDATE status='accepted'");
                $friend->execute([(int)$req['requester_id'],$uid]);
            }
            $json(['status'=>$response]);

        case 'get_compatibility':
            $peerId=(int)($_GET['user_id'] ?? 0);
            if ($peerId <= 0 || $peerId === $uid) $fail('Invalid user.');
            $mine=$hydrateTags($loadProfile($uid,$db),$db);
            $their=$hydrateTags($loadProfile($peerId,$db),$db);
            $score=(new PeerMatchingService())->scoreProfiles($mine,$their);
            $json([
                'score'=>['total'=>$score['total'],'subjects'=>$score['subjects'],'style'=>$score['style'],'interests'=>$score['interests'],'hobbies'=>$score['hobbies']],
                'weights'=>['subjects'=>35,'style'=>25,'interests'=>25,'hobbies'=>15],
                'shared_subjects'=>array_values(array_filter($their['subjects'], static fn($s)=>in_array((int)$s['subject_id'],array_map(static fn($x)=>(int)$x['subject_id'],$mine['subjects']),true))),
                'shared_interests'=>array_values(array_filter($their['interests'], static fn($s)=>in_array((int)$s['interest_id'],array_map(static fn($x)=>(int)$x['interest_id'],$mine['interests']),true))),
                'shared_hobbies'=>array_values(array_filter($their['hobbies'], static fn($s)=>in_array((int)$s['hobby_id'],array_map(static fn($x)=>(int)$x['hobby_id'],$mine['hobbies']),true))),
                'my_prefs'=>$mine['prefs'], 'their_prefs'=>$their['prefs'],
            ]);

        case 'submit_feedback':
            if ($method !== 'POST') $fail('POST required.', 405);
            $matchId = (int)($body['match_id'] ?? 0);
            $rating = max(1, min(5, (int)($body['rating'] ?? 0)));
            if ($matchId <= 0 || $rating <= 0) $fail('A valid match and rating are required.');
            $check = $db->prepare('SELECT id FROM pm_match_requests WHERE id = ? AND (requester_id = ? OR addressee_id = ?) LIMIT 1');
            $check->execute([$matchId, $uid, $uid]);
            if (!$check->fetchColumn()) $fail('Match not found.', 404);
            $stmt = $db->prepare("INSERT INTO pm_match_feedback (match_id, reviewer_id, rating, comment, tags) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE rating=VALUES(rating), comment=VALUES(comment), tags=VALUES(tags)");
            $stmt->execute([$matchId, $uid, $rating, mb_substr((string)($body['comment'] ?? ''), 0, 500), mb_substr((string)($body['tags'] ?? ''), 0, 200)]);
            $json(['saved' => true]);

        case 'get_leaderboard':
            $stmt=$db->prepare("SELECT c.*, CASE WHEN c.user_a_id=? THEN c.user_b_id ELSE c.user_a_id END AS peer_id, u.username,u.full_name,u.avatar_color_gradient,u.is_online FROM pm_compatibility c JOIN users u ON u.id=CASE WHEN c.user_a_id=? THEN c.user_b_id ELSE c.user_a_id END WHERE (c.user_a_id=? OR c.user_b_id=?) ORDER BY c.score_total DESC LIMIT 20");
            $stmt->execute([$uid,$uid,$uid,$uid]);
            $json(['leaderboard'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);

        default:
            $fail('Unknown peer matching action.', 404);
    }
} catch (Throwable $e) {
    error_log('[Ecollab] peer matching endpoint: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'Peer matching service unavailable.']);
}
