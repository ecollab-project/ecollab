<?php
declare(strict_types=1);
/**
 * API/chat/peer-match.php
 *
 * Tag-based peer compatibility matching engine.
 *
 * Actions (GET/POST ?action=<action>):
 *   get_profile         — fetch own tag profile
 *   save_profile        — save study prefs + subject/hobby/interest tags
 *   get_tags            — fetch all available tag categories
 *   get_matches         — scored match list with breakdown
 *   search_users        — free-text + tag-filtered user search
 *   send_request        — send a match request with optional note
 *   respond_request     — accept / decline a match request
 *   list_requests       — incoming + outgoing requests
 *   get_compatibility   — detailed score breakdown for two users
 *   submit_feedback     — post-study rating
 *   get_leaderboard     — top compatible peers in channel/server
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/security/AuditLogger.php';
require_once dirname(__DIR__, 2) . '/security/SecurityHeaders.php';
require_once dirname(__DIR__, 2) . '/security/csrf/csrf.php';
require_once dirname(__DIR__, 2) . '/security/rate-limit/RateLimiter.php';

header('Content-Type: application/json');
SecurityHeaders::send(isApi: true);
AuthMiddleware::startSession();
$user   = AuthMiddleware::requireAuth(true);
$db     = Database::getInstance();
$uid    = (int)$user['id'];
$uname  = $user['username'];
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

$body = [];
if (in_array($method, ['POST','PUT','PATCH','DELETE'], true)) {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    if (!in_array($action, ['respond_request'], true)) CSRF::verify();
}

function pm_ok(array $data = []): never  { echo json_encode(['ok' => true] + $data); exit; }
function pm_fail(string $m, int $c = 400): never {
    http_response_code($c); echo json_encode(['ok' => false, 'error' => $m]); exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// SCORE WEIGHTS (must sum to 100)
// ─────────────────────────────────────────────────────────────────────────────
const WEIGHT_SUBJECTS  = 35;  // most important — shared academic focus
const WEIGHT_STYLE     = 25;  // study style compatibility
const WEIGHT_INTERESTS = 25;  // intellectual interests
const WEIGHT_HOBBIES   = 15;  // personal hobbies for rapport

// ─────────────────────────────────────────────────────────────────────────────
// ROUTING
// ─────────────────────────────────────────────────────────────────────────────
match ($action) {
    'get_tags'          => pm_get_tags($db),
    'get_profile'       => pm_get_profile($db, $uid),
    'save_profile'      => pm_save_profile($db, $uid, $body),
    'get_matches'       => pm_get_matches($db, $uid),
    'search_users'      => pm_search_users($db, $uid),
    'send_request'      => pm_send_request($db, $uid, $uname, $body),
    'respond_request'   => pm_respond_request($db, $uid, $body),
    'list_requests'     => pm_list_requests($db, $uid),
    'get_compatibility' => pm_get_compatibility($db, $uid),
    'submit_feedback'   => pm_submit_feedback($db, $uid, $body),
    'get_leaderboard'   => pm_get_leaderboard($db, $uid),
    default             => pm_fail("Unknown action: $action", 400),
};

// ─────────────────────────────────────────────────────────────────────────────
// get_tags — all tag lists for the profile editor dropdowns
// ─────────────────────────────────────────────────────────────────────────────
function pm_get_tags(PDO $db): never {
    $subjects  = $db->query("SELECT id,name,slug,category,color,icon FROM pm_subjects ORDER BY category,name")->fetchAll();
    $hobbies   = $db->query("SELECT id,name,slug,category,icon FROM pm_hobby_tags ORDER BY category,name")->fetchAll();
    $interests = $db->query("SELECT id,name,slug,category,icon FROM pm_interest_tags ORDER BY category,name")->fetchAll();
    pm_ok(compact('subjects','hobbies','interests'));
}

// ─────────────────────────────────────────────────────────────────────────────
// get_profile — own tag profile
// ─────────────────────────────────────────────────────────────────────────────
function pm_get_profile(PDO $db, int $uid): never {
    // Study prefs
    $p = $db->prepare("SELECT * FROM pm_user_study_prefs WHERE user_id=:u LIMIT 1");
    $p->execute([':u'=>$uid]);
    $prefs = $p->fetch() ?: [];

    // Subjects
    $s = $db->prepare("SELECT us.*,sub.name,sub.color,sub.icon,sub.category,sub.slug
        FROM pm_user_subjects us JOIN pm_subjects sub ON sub.id=us.subject_id
        WHERE us.user_id=:u ORDER BY sub.category,sub.name");
    $s->execute([':u'=>$uid]);

    // Hobbies
    $h = $db->prepare("SELECT uh.*,ht.name,ht.icon,ht.category,ht.slug
        FROM pm_user_hobbies uh JOIN pm_hobby_tags ht ON ht.id=uh.hobby_id
        WHERE uh.user_id=:u ORDER BY ht.category,ht.name");
    $h->execute([':u'=>$uid]);

    // Interests
    $i = $db->prepare("SELECT ui.*,it.name,it.icon,it.category,it.slug
        FROM pm_user_interests ui JOIN pm_interest_tags it ON it.id=ui.interest_id
        WHERE ui.user_id=:u ORDER BY it.category,it.name");
    $i->execute([':u'=>$uid]);

    pm_ok([
        'prefs'     => $prefs,
        'subjects'  => $s->fetchAll(),
        'hobbies'   => $h->fetchAll(),
        'interests' => $i->fetchAll(),
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// save_profile — upsert all tags + study prefs, then recompute compatibility
// ─────────────────────────────────────────────────────────────────────────────
function pm_save_profile(PDO $db, int $uid, array $body): never {
    $db->beginTransaction();
    try {
        // ── Study prefs ──────────────────────────────────────────────────────
        $VALID_STYLE   = ['solo','group','mixed'];
        $VALID_SESSION = ['short','medium','long'];
        $VALID_TIME    = ['morning','afternoon','evening','night','flexible'];
        $VALID_MODE    = ['visual','auditory','reading','kinesthetic','mixed'];
        $VALID_PACE    = ['slow','moderate','fast','adaptive'];
        $VALID_COMM    = ['frequent','occasional','minimal'];
        $VALID_GOAL    = ['pass_exams','build_projects','find_study_partners','improve_skills','network_collaborate','research'];

        $prefs = $body['prefs'] ?? [];
        $db->prepare("
            INSERT INTO pm_user_study_prefs
              (user_id,study_style,session_length,time_preference,learning_mode,
               pace,comm_style,primary_goal,availability_days)
            VALUES (:u,:ss,:sl,:tp,:lm,:pc,:cs,:pg,:ad)
            ON DUPLICATE KEY UPDATE
              study_style=VALUES(study_style), session_length=VALUES(session_length),
              time_preference=VALUES(time_preference), learning_mode=VALUES(learning_mode),
              pace=VALUES(pace), comm_style=VALUES(comm_style),
              primary_goal=VALUES(primary_goal), availability_days=VALUES(availability_days)
        ")->execute([
            ':u'  => $uid,
            ':ss' => in_array($prefs['study_style']??'',$VALID_STYLE)   ? $prefs['study_style']   : 'mixed',
            ':sl' => in_array($prefs['session_length']??'',$VALID_SESSION)?$prefs['session_length']: 'medium',
            ':tp' => in_array($prefs['time_preference']??'',$VALID_TIME) ? $prefs['time_preference']: 'flexible',
            ':lm' => in_array($prefs['learning_mode']??'',$VALID_MODE)  ? $prefs['learning_mode']  : 'mixed',
            ':pc' => in_array($prefs['pace']??'',$VALID_PACE)           ? $prefs['pace']           : 'moderate',
            ':cs' => in_array($prefs['comm_style']??'',$VALID_COMM)     ? $prefs['comm_style']     : 'occasional',
            ':pg' => in_array($prefs['primary_goal']??'',$VALID_GOAL)   ? $prefs['primary_goal']   : 'improve_skills',
            ':ad' => min(127, max(0, (int)($prefs['availability_days']??127))),
        ]);

        // ── Subjects ─────────────────────────────────────────────────────────
        $db->prepare("DELETE FROM pm_user_subjects WHERE user_id=:u")->execute([':u'=>$uid]);
        if (!empty($body['subjects']) && is_array($body['subjects'])) {
            $sStmt = $db->prepare("INSERT IGNORE INTO pm_user_subjects (user_id,subject_id,role,proficiency)
                VALUES(:u,:sid,:role,:prof)");
            foreach (array_slice($body['subjects'],0,20) as $sub) {
                $sStmt->execute([
                    ':u'    => $uid,
                    ':sid'  => (int)($sub['id']??0),
                    ':role' => in_array($sub['role']??'',['studying','tutoring','both'])?$sub['role']:'studying',
                    ':prof' => in_array($sub['proficiency']??'',['beginner','intermediate','advanced','expert'])?$sub['proficiency']:'intermediate',
                ]);
            }
        }

        // ── Hobbies ──────────────────────────────────────────────────────────
        $db->prepare("DELETE FROM pm_user_hobbies WHERE user_id=:u")->execute([':u'=>$uid]);
        if (!empty($body['hobbies']) && is_array($body['hobbies'])) {
            $hStmt = $db->prepare("INSERT IGNORE INTO pm_user_hobbies (user_id,hobby_id) VALUES(:u,:hid)");
            foreach (array_slice($body['hobbies'],0,15) as $hid) {
                $hStmt->execute([':u'=>$uid,':hid'=>(int)($hid['id']??$hid)]);
            }
        }

        // ── Interests ────────────────────────────────────────────────────────
        $db->prepare("DELETE FROM pm_user_interests WHERE user_id=:u")->execute([':u'=>$uid]);
        if (!empty($body['interests']) && is_array($body['interests'])) {
            $iStmt = $db->prepare("INSERT IGNORE INTO pm_user_interests (user_id,interest_id) VALUES(:u,:iid)");
            foreach (array_slice($body['interests'],0,15) as $iid) {
                $iStmt->execute([':u'=>$uid,':iid'=>(int)($iid['id']??$iid)]);
            }
        }

        $db->commit();

        // Recompute compatibility scores asynchronously (quick sync compute here)
        pm_recompute_compatibility($db, $uid);

        pm_ok(['recomputed' => true]);
    } catch (\Throwable $e) {
        $db->rollBack();
        pm_fail('Save failed: ' . $e->getMessage(), 500);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// CORE SCORING ENGINE
// ─────────────────────────────────────────────────────────────────────────────
function pm_recompute_compatibility(PDO $db, int $uid): void {
    // Get all users who share at least one server with $uid
    $candidates = $db->prepare("
        SELECT DISTINCT u.id
        FROM users u
        JOIN server_members sm1 ON sm1.user_id = :uid
        JOIN server_members sm2 ON sm2.server_id = sm1.server_id AND sm2.user_id = u.id
        WHERE u.id != :uid2 AND u.deleted_at IS NULL AND u.status != 'banned'
        LIMIT 200
    ");
    $candidates->execute([':uid'=>$uid,':uid2'=>$uid]);

    foreach ($candidates->fetchAll(PDO::FETCH_COLUMN) as $otherId) {
        $otherId = (int)$otherId;
        $score   = pm_compute_score($db, $uid, $otherId);
        $a       = min($uid, $otherId);
        $b       = max($uid, $otherId);

        $db->prepare("
            INSERT INTO pm_compatibility
              (user_a_id,user_b_id,score_total,score_subjects,score_interests,
               score_hobbies,score_style,shared_subjects,shared_interests,
               shared_hobbies,match_tags,computed_at)
            VALUES(:a,:b,:tot,:sub,:int,:hob,:sty,:ss,:si,:sh,:tags,NOW())
            ON DUPLICATE KEY UPDATE
              score_total=VALUES(score_total), score_subjects=VALUES(score_subjects),
              score_interests=VALUES(score_interests), score_hobbies=VALUES(score_hobbies),
              score_style=VALUES(score_style), shared_subjects=VALUES(shared_subjects),
              shared_interests=VALUES(shared_interests), shared_hobbies=VALUES(shared_hobbies),
              match_tags=VALUES(match_tags), computed_at=NOW()
        ")->execute([
            ':a'    => $a, ':b'   => $b,
            ':tot'  => $score['total'],
            ':sub'  => $score['subjects'],
            ':int'  => $score['interests'],
            ':hob'  => $score['hobbies'],
            ':sty'  => $score['style'],
            ':ss'   => $score['shared_subjects'],
            ':si'   => $score['shared_interests'],
            ':sh'   => $score['shared_hobbies'],
            ':tags' => json_encode($score['tags']),
        ]);
    }
}

function pm_compute_score(PDO $db, int $a, int $b): array {
    // ── Subjects ─────────────────────────────────────────────────────────────
    $sA = $db->prepare("SELECT subject_id,role,proficiency FROM pm_user_subjects WHERE user_id=:u");
    $sA->execute([':u'=>$a]); $subsA = $sA->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
    $sA->execute([':u'=>$b]); $subsB = $sA->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
    $sharedSub = 0; $subScore = 0;
    foreach ($subsA as $sid => $sa) {
        if (!isset($subsB[$sid])) continue;
        $sb = $subsB[$sid];
        $sharedSub++;
        $roleBonus = 0;
        // Complementary roles score higher (one studying, one tutoring)
        if (($sa['role']==='studying' && $sb['role']==='tutoring') ||
            ($sa['role']==='tutoring' && $sb['role']==='studying'))  $roleBonus = 15;
        elseif ($sa['role'] === 'both' || $sb['role'] === 'both')    $roleBonus = 10;
        else $roleBonus = 5; // both studying — can study together
        $subScore += $roleBonus;
    }
    $subScore = min(100, $sharedSub > 0 ? $subScore + ($sharedSub * 8) : 0);

    // ── Interests ────────────────────────────────────────────────────────────
    $iA = $db->prepare("SELECT interest_id FROM pm_user_interests WHERE user_id=:u");
    $iA->execute([':u'=>$a]); $intsA = $iA->fetchAll(PDO::FETCH_COLUMN);
    $iA->execute([':u'=>$b]); $intsB = $iA->fetchAll(PDO::FETCH_COLUMN);
    $sharedInt  = count(array_intersect($intsA,$intsB));
    $unionInt   = count(array_unique(array_merge($intsA,$intsB)));
    $intScore   = $unionInt > 0 ? min(100, round(($sharedInt/$unionInt)*100 + $sharedInt*5)) : 0;

    // ── Hobbies ──────────────────────────────────────────────────────────────
    $hA = $db->prepare("SELECT hobby_id FROM pm_user_hobbies WHERE user_id=:u");
    $hA->execute([':u'=>$a]); $hobsA = $hA->fetchAll(PDO::FETCH_COLUMN);
    $hA->execute([':u'=>$b]); $hobsB = $hA->fetchAll(PDO::FETCH_COLUMN);
    $sharedHob  = count(array_intersect($hobsA,$hobsB));
    $unionHob   = count(array_unique(array_merge($hobsA,$hobsB)));
    $hobScore   = $unionHob > 0 ? min(100, round(($sharedHob/$unionHob)*100 + $sharedHob*6)) : 0;

    // ── Study style ──────────────────────────────────────────────────────────
    $pA = $db->prepare("SELECT * FROM pm_user_study_prefs WHERE user_id=:u LIMIT 1");
    $pA->execute([':u'=>$a]); $prefsA = $pA->fetch() ?: [];
    $pA->execute([':u'=>$b]); $prefsB = $pA->fetch() ?: [];
    $styleScore = 0;
    if ($prefsA && $prefsB) {
        $checks = [
            ['study_style',    30, fn($x,$y) => $x===$y ? 30 : ($x==='mixed'||$y==='mixed' ? 15 : 0)],
            ['time_preference',25, fn($x,$y) => $x===$y ? 25 : ($x==='flexible'||$y==='flexible' ? 15 : 0)],
            ['pace',           20, fn($x,$y) => $x===$y ? 20 : (abs(array_search($x,['slow','moderate','fast','adaptive'])-array_search($y,['slow','moderate','fast','adaptive']))==1?10:0)],
            ['comm_style',     15, fn($x,$y) => $x===$y ? 15 : 5],
            ['session_length', 10, fn($x,$y) => $x===$y ? 10 : (abs(array_search($x,['short','medium','long'])-array_search($y,['short','medium','long']))==1?5:0)],
        ];
        foreach ($checks as [$field,$max,$fn]) {
            $va = $prefsA[$field] ?? 'mixed';
            $vb = $prefsB[$field] ?? 'mixed';
            $styleScore += $fn($va,$vb);
        }
        // Availability overlap bonus
        $overlap = ($prefsA['availability_days']??127) & ($prefsB['availability_days']??127);
        $bits    = substr_count(decbin($overlap),'1');
        $styleScore = min(100,$styleScore + $bits * 2);
    } else {
        $styleScore = 40; // default if profile incomplete
    }

    // ── Weighted total ────────────────────────────────────────────────────────
    $total = round(
        ($subScore  * WEIGHT_SUBJECTS  +
         $intScore  * WEIGHT_INTERESTS +
         $hobScore  * WEIGHT_HOBBIES   +
         $styleScore * WEIGHT_STYLE) / 100
    );

    // ── Build match tags ─────────────────────────────────────────────────────
    $tags = [];
    if ($sharedSub > 0) {
        // Fetch names of shared subjects
        $names = $db->prepare("SELECT sub.name FROM pm_user_subjects usa
            JOIN pm_user_subjects usb ON usb.subject_id=usa.subject_id AND usb.user_id=:b
            JOIN pm_subjects sub ON sub.id=usa.subject_id
            WHERE usa.user_id=:a LIMIT 3");
        $names->execute([':a'=>$a,':b'=>$b]);
        foreach ($names->fetchAll(PDO::FETCH_COLUMN) as $n) $tags[] = $n;
    }
    if ($sharedInt > 0) {
        $names = $db->prepare("SELECT it.name FROM pm_user_interests uia
            JOIN pm_user_interests uib ON uib.interest_id=uia.interest_id AND uib.user_id=:b
            JOIN pm_interest_tags it ON it.id=uia.interest_id
            WHERE uia.user_id=:a LIMIT 2");
        $names->execute([':a'=>$a,':b'=>$b]);
        foreach ($names->fetchAll(PDO::FETCH_COLUMN) as $n) $tags[] = $n;
    }
    if ($prefsA && $prefsB && ($prefsA['study_style']??'') === ($prefsB['study_style']??'') && $prefsA['study_style'])
        $tags[] = ucfirst($prefsA['study_style']) . ' learner';

    return [
        'total'            => min(99, max(10, (int)$total)),
        'subjects'         => round($subScore,1),
        'interests'        => round($intScore,1),
        'hobbies'          => round($hobScore,1),
        'style'            => round($styleScore,1),
        'shared_subjects'  => $sharedSub,
        'shared_interests' => $sharedInt,
        'shared_hobbies'   => $sharedHob,
        'tags'             => array_slice(array_unique($tags),0,5),
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// get_matches — top compatible peers with score breakdown
// ─────────────────────────────────────────────────────────────────────────────
function pm_get_matches(PDO $db, int $uid): never {
    $limiter = new \RateLimiter();
    $rl = $limiter->attempt('pm_get_matches', (string)$uid, 20, 3600);
    if (!$rl['allowed']) pm_fail('Too many requests', 429);

    // Refresh compatibility for this user (fast path)
    pm_recompute_compatibility($db, $uid);

    $filters = [
        'min_score'    => max(0, min(100, (int)($_GET['min_score']   ?? 0))),
        'study_style'  => $_GET['study_style']  ?? '',
        'subject_id'   => (int)($_GET['subject_id']   ?? 0),
        'hobby_id'     => (int)($_GET['hobby_id']      ?? 0),
        'interest_id'  => (int)($_GET['interest_id']  ?? 0),
        'role'         => $_GET['role']         ?? '',  // 'student'|'facilitator'
        'sort'         => in_array($_GET['sort']??'',['score','subjects','style','hobbies','interests'])?$_GET['sort']:'score',
        'limit'        => min(50, max(5, (int)($_GET['limit'] ?? 20))),
    ];

    $where  = ["(c.user_a_id=:uid OR c.user_b_id=:uid2)"];
    $params = [':uid'=>$uid,':uid2'=>$uid];

    if ($filters['min_score'] > 0) {
        $where[]          = "c.score_total >= :mins";
        $params[':mins']  = $filters['min_score'];
    }

    $having = [];
    if ($filters['study_style']) {
        $where[]         = "p.study_style = :ss";
        $params[':ss']   = $filters['study_style'];
    }
    if ($filters['role'] && in_array($filters['role'],['student','facilitator'])) {
        $where[]         = "u.role = :role";
        $params[':role'] = $filters['role'];
    }

    $joinSubject = ''; $joinHobby = ''; $joinInterest = '';
    if ($filters['subject_id'] > 0) {
        $joinSubject       = "JOIN pm_user_subjects usfilt ON usfilt.user_id=other_uid AND usfilt.subject_id=:sfid";
        $params[':sfid']   = $filters['subject_id'];
    }
    if ($filters['hobby_id'] > 0) {
        $joinHobby         = "JOIN pm_user_hobbies uhfilt ON uhfilt.user_id=other_uid AND uhfilt.hobby_id=:hfid";
        $params[':hfid']   = $filters['hobby_id'];
    }
    if ($filters['interest_id'] > 0) {
        $joinInterest      = "JOIN pm_user_interests uifilt ON uifilt.user_id=other_uid AND uifilt.interest_id=:ifid";
        $params[':ifid']   = $filters['interest_id'];
    }

    $sortCol = match($filters['sort']) {
        'subjects'  => 'c.score_subjects',
        'style'     => 'c.score_style',
        'hobbies'   => 'c.score_hobbies',
        'interests' => 'c.score_interests',
        default     => 'c.score_total',
    };
    $whereStr = implode(' AND ', $where);

    $stmt = $db->prepare("
        SELECT
            IF(c.user_a_id=:uid3, c.user_b_id, c.user_a_id) AS other_uid,
            c.score_total, c.score_subjects, c.score_interests,
            c.score_hobbies, c.score_style,
            c.shared_subjects, c.shared_interests, c.shared_hobbies,
            c.match_tags
        FROM pm_compatibility c
        WHERE $whereStr
        ORDER BY $sortCol DESC
        LIMIT {$filters['limit']}
    ");
    $params[':uid3'] = $uid;
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    if (!$rows) pm_ok(['matches'=>[],'filters'=>$filters]);

    // Enrich with user data
    $otherIds = array_column($rows, 'other_uid');
    $placeholders = implode(',', array_fill(0, count($otherIds), '?'));
    $users = $db->prepare("
        SELECT u.id, u.username, u.full_name, u.role, u.avatar_color_gradient,
               u.bio, u.is_online, u.status,
               up.study_style, up.primary_goal, up.time_preference,
               (SELECT COUNT(*) FROM pm_match_requests WHERE
                 ((requester_id=:uid AND addressee_id=u.id) OR (requester_id=u.id AND addressee_id=:uid2))
                 AND status IN ('pending','accepted')) already_connected
        FROM users u
        LEFT JOIN pm_user_study_prefs up ON up.user_id=u.id
        WHERE u.id IN ($placeholders)
    ");
    $users->execute([$uid, $uid, ...$otherIds]);
    $userMap = [];
    foreach ($users->fetchAll() as $u) $userMap[(int)$u['id']] = $u;

    $matches = [];
    foreach ($rows as $row) {
        $oid  = (int)$row['other_uid'];
        $u    = $userMap[$oid] ?? null;
        if (!$u) continue;

        // Fetch top shared subject names
        $subNames = $db->prepare("
            SELECT sub.name,sub.icon FROM pm_user_subjects usa
            JOIN pm_user_subjects usb ON usb.subject_id=usa.subject_id AND usb.user_id=:b
            JOIN pm_subjects sub ON sub.id=usa.subject_id
            WHERE usa.user_id=:a ORDER BY sub.name LIMIT 5
        ");
        $subNames->execute([':a'=>$uid,':b'=>$oid]);
        $sharedSubjects = $subNames->fetchAll();

        // Fetch top shared hobby names
        $hobNames = $db->prepare("
            SELECT ht.name,ht.icon FROM pm_user_hobbies uha
            JOIN pm_user_hobbies uhb ON uhb.hobby_id=uha.hobby_id AND uhb.user_id=:b
            JOIN pm_hobby_tags ht ON ht.id=uha.hobby_id
            WHERE uha.user_id=:a ORDER BY ht.name LIMIT 3
        ");
        $hobNames->execute([':a'=>$uid,':b'=>$oid]);
        $sharedHobbies = $hobNames->fetchAll();

        // Fetch top shared interest names
        $intNames = $db->prepare("
            SELECT it.name,it.icon FROM pm_user_interests uia
            JOIN pm_user_interests uib ON uib.interest_id=uia.interest_id AND uib.user_id=:b
            JOIN pm_interest_tags it ON it.id=uia.interest_id
            WHERE uia.user_id=:a ORDER BY it.name LIMIT 3
        ");
        $intNames->execute([':a'=>$uid,':b'=>$oid]);
        $sharedInterests = $intNames->fetchAll();

        // Style compatibility label
        $styleLabel = pm_style_label($u);

        $matches[] = [
            'id'              => $oid,
            'name'            => $u['full_name'] ?: $u['username'],
            'username'        => $u['username'],
            'role'            => $u['role'],
            'type'            => in_array($u['role'],['facilitator','admin','super_admin','moderator']) ? 'professor' : 'student',
            'grad'            => $u['avatar_color_gradient'] ?? '#a855f7,#ec4899',
            'bio'             => mb_substr($u['bio'] ?? '',0,80),
            'is_online'       => (bool)$u['is_online'],
            'study_style'     => $u['study_style'] ?? '',
            'time_preference' => $u['time_preference'] ?? '',
            'primary_goal'    => $u['primary_goal'] ?? '',
            'style_label'     => $styleLabel,
            'already_connected' => (bool)($u['already_connected'] ?? false),
            // Scores
            'pct'             => (int)$row['score_total'],
            'score_subjects'  => (float)$row['score_subjects'],
            'score_interests' => (float)$row['score_interests'],
            'score_hobbies'   => (float)$row['score_hobbies'],
            'score_style'     => (float)$row['score_style'],
            // Shared tags
            'shared_subjects'  => $sharedSubjects,
            'shared_hobbies'   => $sharedHobbies,
            'shared_interests' => $sharedInterests,
            'tags'             => json_decode($row['match_tags'] ?? '[]', true) ?: [],
            'detail'           => ucfirst($u['role']) . ($u['bio'] ? ' · ' . mb_substr($u['bio'],0,40) : ''),
        ];
    }

    pm_ok(['matches'=>$matches,'filters'=>$filters]);
}

function pm_style_label(array $u): string {
    $style = $u['study_style'] ?? '';
    $time  = $u['time_preference'] ?? '';
    if (!$style && !$time) return '';
    $parts = [];
    if ($style) $parts[] = ucfirst($style) . ' learner';
    if ($time && $time !== 'flexible') $parts[] = ucfirst($time) . ' person';
    return implode(' · ', $parts);
}

// ─────────────────────────────────────────────────────────────────────────────
// search_users — free-text + tag filter
// ─────────────────────────────────────────────────────────────────────────────
function pm_search_users(PDO $db, int $uid): never {
    $q          = mb_substr(trim($_GET['q'] ?? ''), 0, 80);
    $subjectId  = (int)($_GET['subject_id']  ?? 0);
    $hobbyId    = (int)($_GET['hobby_id']    ?? 0);
    $interestId = (int)($_GET['interest_id'] ?? 0);
    $studyStyle = $_GET['study_style'] ?? '';

    $where = ["u.id != :uid", "u.deleted_at IS NULL", "u.status != 'banned'"];
    $params = [':uid' => $uid];

    if ($q) {
        $where[] = "(u.username LIKE :q OR u.full_name LIKE :q2 OR u.bio LIKE :q3)";
        $params[':q'] = "%$q%"; $params[':q2'] = "%$q%"; $params[':q3'] = "%$q%";
    }
    if ($subjectId) {
        $where[] = "EXISTS (SELECT 1 FROM pm_user_subjects WHERE user_id=u.id AND subject_id=:sid)";
        $params[':sid'] = $subjectId;
    }
    if ($hobbyId) {
        $where[] = "EXISTS (SELECT 1 FROM pm_user_hobbies WHERE user_id=u.id AND hobby_id=:hid)";
        $params[':hid'] = $hobbyId;
    }
    if ($interestId) {
        $where[] = "EXISTS (SELECT 1 FROM pm_user_interests WHERE user_id=u.id AND interest_id=:iid)";
        $params[':iid'] = $interestId;
    }
    if ($studyStyle && in_array($studyStyle,['solo','group','mixed'])) {
        $where[] = "EXISTS (SELECT 1 FROM pm_user_study_prefs WHERE user_id=u.id AND study_style=:ss)";
        $params[':ss'] = $studyStyle;
    }

    $whereStr = implode(' AND ', $where);
    $stmt = $db->prepare("
        SELECT u.id, u.username, u.full_name, u.role, u.avatar_color_gradient,
               u.bio, u.is_online,
               COALESCE(c.score_total,0) AS pct
        FROM users u
        LEFT JOIN pm_compatibility c ON
          (c.user_a_id=LEAST(u.id,:uid2) AND c.user_b_id=GREATEST(u.id,:uid3))
        WHERE $whereStr
        ORDER BY pct DESC, u.is_online DESC, u.full_name ASC
        LIMIT 30
    ");
    $params[':uid2'] = $uid; $params[':uid3'] = $uid;
    $stmt->execute($params);
    pm_ok(['users' => $stmt->fetchAll()]);
}

// ─────────────────────────────────────────────────────────────────────────────
// send_request — send a peer match request with optional note
// ─────────────────────────────────────────────────────────────────────────────
function pm_send_request(PDO $db, int $uid, string $uname, array $body): never {
    $addresseeId = (int)($body['addressee_id'] ?? 0);
    $note        = mb_substr(trim($body['note'] ?? ''), 0, 300);
    $matchedVia  = mb_substr(trim($body['matched_via'] ?? ''), 0, 80);

    if (!$addresseeId || $addresseeId === $uid) pm_fail('Invalid addressee');

    // Check user exists
    $u = $db->prepare("SELECT id, full_name, username FROM users WHERE id=:id AND deleted_at IS NULL AND status!='banned' LIMIT 1");
    $u->execute([':id'=>$addresseeId]);
    if (!$u->fetch()) pm_fail('User not found', 404);

    // Check existing request or friendship
    $existing = $db->prepare("SELECT id,status FROM pm_match_requests WHERE
        (requester_id=:me AND addressee_id=:them) OR (requester_id=:them2 AND addressee_id=:me2) LIMIT 1");
    $existing->execute([':me'=>$uid,':them'=>$addresseeId,':them2'=>$addresseeId,':me2'=>$uid]);
    $req = $existing->fetch();
    if ($req) {
        pm_ok(['status' => $req['status'], 'message' => 'Request already exists']);
    }

    // Get compatibility score
    $a = min($uid,$addresseeId); $b = max($uid,$addresseeId);
    $comp = $db->prepare("SELECT score_total FROM pm_compatibility WHERE user_a_id=:a AND user_b_id=:b LIMIT 1");
    $comp->execute([':a'=>$a,':b'=>$b]);
    $score = (float)($comp->fetchColumn() ?: 0);

    $db->prepare("INSERT INTO pm_match_requests (requester_id,addressee_id,score,note,matched_via)
        VALUES(:me,:them,:score,:note,:via)")
       ->execute([':me'=>$uid,':them'=>$addresseeId,':score'=>$score,':note'=>$note?:null,':via'=>$matchedVia?:null]);
    $reqId = (int)$db->lastInsertId();

    // Notification
    try {
        $db->prepare("INSERT INTO notifications (user_id,type,title,body,ref_id,is_read,created_at)
            VALUES(:uid,'pm_request',:title,:body,:ref,0,NOW())")
           ->execute([
               ':uid'  => $addresseeId,
               ':title'=> "$uname wants to study with you",
               ':body' => $note ?: 'New peer match request',
               ':ref'  => $reqId,
           ]);
    } catch (\Throwable) {}

    pm_ok(['request_id'=>$reqId,'score'=>$score,'status'=>'pending']);
}

// ─────────────────────────────────────────────────────────────────────────────
// respond_request — accept or decline
// ─────────────────────────────────────────────────────────────────────────────
function pm_respond_request(PDO $db, int $uid, array $body): never {
    $reqId  = (int)($body['request_id'] ?? 0);
    $action = in_array($body['response']??'',['accepted','declined']) ? $body['response'] : '';
    if (!$reqId || !$action) pm_fail('request_id and response required');

    $r = $db->prepare("SELECT * FROM pm_match_requests WHERE id=:id AND addressee_id=:uid AND status='pending' LIMIT 1");
    $r->execute([':id'=>$reqId,':uid'=>$uid]);
    $req = $r->fetch();
    if (!$req) pm_fail('Request not found or already responded', 404);

    $db->prepare("UPDATE pm_match_requests SET status=:s,responded_at=NOW() WHERE id=:id")
       ->execute([':s'=>$action,':id'=>$reqId]);

    // If accepted, also create a friendship record
    if ($action === 'accepted') {
        $db->prepare("INSERT IGNORE INTO friendships (requester_id,addressee_id,status,created_at)
            VALUES(:r,:a,'accepted',NOW())")
           ->execute([':r'=>(int)$req['requester_id'],':a'=>$uid]);
    }

    pm_ok(['status'=>$action]);
}

// ─────────────────────────────────────────────────────────────────────────────
// list_requests — inbox + outbox
// ─────────────────────────────────────────────────────────────────────────────
function pm_list_requests(PDO $db, int $uid): never {
    $incoming = $db->prepare("SELECT r.*,u.username,u.full_name,u.avatar_color_gradient,u.role
        FROM pm_match_requests r JOIN users u ON u.id=r.requester_id
        WHERE r.addressee_id=:uid ORDER BY r.created_at DESC LIMIT 30");
    $incoming->execute([':uid'=>$uid]);

    $outgoing = $db->prepare("SELECT r.*,u.username,u.full_name,u.avatar_color_gradient,u.role
        FROM pm_match_requests r JOIN users u ON u.id=r.addressee_id
        WHERE r.requester_id=:uid ORDER BY r.created_at DESC LIMIT 30");
    $outgoing->execute([':uid'=>$uid]);

    pm_ok(['incoming'=>$incoming->fetchAll(),'outgoing'=>$outgoing->fetchAll()]);
}

// ─────────────────────────────────────────────────────────────────────────────
// get_compatibility — detailed score breakdown between me and one other user
// ─────────────────────────────────────────────────────────────────────────────
function pm_get_compatibility(PDO $db, int $uid): never {
    $otherId = (int)($_GET['user_id'] ?? 0);
    if (!$otherId || $otherId === $uid) pm_fail('user_id required');

    // Compute fresh
    $score = pm_compute_score($db, $uid, $otherId);

    // Gather all shared tag names for display
    $fetchShared = function(string $tbl, string $col, string $nameTbl, string $nameCol, string $icon) use ($db, $uid, $otherId): array {
        $s = $db->prepare("SELECT n.$nameCol AS name, n.$icon AS icon
            FROM $tbl a JOIN $tbl b ON b.$col=a.$col AND b.user_id=:b
            JOIN $nameTbl n ON n.id=a.$col WHERE a.user_id=:a");
        $s->execute([':a'=>$uid,':b'=>$otherId]);
        return $s->fetchAll();
    };

    $sharedSubjects  = $fetchShared('pm_user_subjects','subject_id','pm_subjects','name','icon');
    $sharedHobbies   = $fetchShared('pm_user_hobbies','hobby_id','pm_hobby_tags','name','icon');
    $sharedInterests = $fetchShared('pm_user_interests','interest_id','pm_interest_tags','name','icon');

    $prefsA = $db->prepare("SELECT * FROM pm_user_study_prefs WHERE user_id=:u LIMIT 1");
    $prefsA->execute([':u'=>$uid]);   $myPrefs    = $prefsA->fetch() ?: [];
    $prefsA->execute([':u'=>$otherId]); $theirPrefs = $prefsA->fetch() ?: [];

    pm_ok([
        'score'            => $score,
        'shared_subjects'  => $sharedSubjects,
        'shared_hobbies'   => $sharedHobbies,
        'shared_interests' => $sharedInterests,
        'my_prefs'         => $myPrefs,
        'their_prefs'      => $theirPrefs,
        'weights'          => ['subjects'=>WEIGHT_SUBJECTS,'interests'=>WEIGHT_INTERESTS,
                               'hobbies'=>WEIGHT_HOBBIES,'style'=>WEIGHT_STYLE],
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// submit_feedback — post-study rating
// ─────────────────────────────────────────────────────────────────────────────
function pm_submit_feedback(PDO $db, int $uid, array $body): never {
    $matchId = (int)($body['match_id'] ?? 0);
    $rating  = max(1, min(5, (int)($body['rating'] ?? 3)));
    $comment = mb_substr(trim($body['comment'] ?? ''), 0, 500);
    $tags    = mb_substr(trim($body['tags'] ?? ''), 0, 200);
    if (!$matchId) pm_fail('match_id required');

    // Verify match involves this user
    $m = $db->prepare("SELECT id FROM pm_match_requests WHERE id=:id AND (requester_id=:u OR addressee_id=:u2) AND status='accepted' LIMIT 1");
    $m->execute([':id'=>$matchId,':u'=>$uid,':u2'=>$uid]);
    if (!$m->fetch()) pm_fail('Match not found or not accepted', 404);

    $db->prepare("INSERT INTO pm_match_feedback (match_id,reviewer_id,rating,comment,tags)
        VALUES(:mid,:uid,:r,:c,:t)
        ON DUPLICATE KEY UPDATE rating=VALUES(rating),comment=VALUES(comment),tags=VALUES(tags)")
       ->execute([':mid'=>$matchId,':uid'=>$uid,':r'=>$rating,':c'=>$comment?:null,':t'=>$tags?:null]);

    pm_ok();
}

// ─────────────────────────────────────────────────────────────────────────────
// get_leaderboard — top compatible peers across shared servers
// ─────────────────────────────────────────────────────────────────────────────
function pm_get_leaderboard(PDO $db, int $uid): never {
    $stmt = $db->prepare("
        SELECT
            IF(c.user_a_id=:uid, c.user_b_id, c.user_a_id) AS peer_id,
            c.score_total, c.shared_subjects, c.shared_interests, c.shared_hobbies,
            c.match_tags,
            u.full_name, u.username, u.avatar_color_gradient, u.role, u.is_online
        FROM pm_compatibility c
        JOIN users u ON u.id = IF(c.user_a_id=:uid2, c.user_b_id, c.user_a_id)
        WHERE (c.user_a_id=:uid3 OR c.user_b_id=:uid4)
          AND u.deleted_at IS NULL AND u.status != 'banned'
        ORDER BY c.score_total DESC
        LIMIT 10
    ");
    $stmt->execute([':uid'=>$uid,':uid2'=>$uid,':uid3'=>$uid,':uid4'=>$uid]);
    pm_ok(['leaderboard' => $stmt->fetchAll()]);
}
