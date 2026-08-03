<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
header('Content-Type: application/json');
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);
try {
    $db  = Database::getInstance();
    $uid = $user['id'];
    // Get users sharing mutual servers, order by shared interests
    $stmt = $db->prepare("
        SELECT DISTINCT u.id, u.username, u.full_name, u.role, u.avatar_color_gradient,
               u.bio,
               COUNT(DISTINCT sm2.server_id) AS mutual_servers
        FROM users u
        JOIN server_members sm1 ON sm1.user_id = :uid
        JOIN server_members sm2 ON sm2.server_id = sm1.server_id AND sm2.user_id = u.id
        LEFT JOIN friendships f ON (f.requester_id = :uid2 AND f.addressee_id = u.id) OR (f.requester_id = u.id AND f.addressee_id = :uid3)
        WHERE u.id != :uid4 AND u.deleted_at IS NULL AND u.status != 'banned'
          AND (f.id IS NULL OR f.status = 'rejected')
        GROUP BY u.id
        ORDER BY mutual_servers DESC, u.is_online DESC
        LIMIT 12
    ");
    $stmt->execute([':uid'=>$uid,':uid2'=>$uid,':uid3'=>$uid,':uid4'=>$uid]);
    $users = $stmt->fetchAll();
    $roleMap = ['student'=>'student','facilitator'=>'professor','admin'=>'professor','moderator'=>'professor','super_admin'=>'professor'];
    $matches = array_map(function($u) use ($uid) {
        $grad  = $u['avatar_color_gradient'] ?? '#a855f7,#ec4899';
        $type  = in_array($u['role'],['facilitator','admin','super_admin','moderator']) ? 'professor' : 'student';
        $score = min(99, 60 + (int)$u['mutual_servers'] * 10);
        return [
            'id'     => (int)$u['id'],
            'name'   => $u['full_name'] ?: $u['username'],
            'detail' => ucfirst($u['role']) . ($u['bio'] ? ' • ' . substr($u['bio'],0,40) : ''),
            'pct'    => $score,
            'type'   => $type,
            'tags'   => [],
            'grad'   => $grad,
        ];
    }, $users);
    echo json_encode(['success'=>true,'matches'=>$matches]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'matches'=>[]]);
}
